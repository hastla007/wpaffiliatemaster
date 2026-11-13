<?php
/**
 * Public, versioned sales contract (v1).
 *
 * Sales sources (Pass Captain, WooCommerce adapter, connector add-ons) report
 * normalized sales and refunds. Affiliate Captain never reads tables of other
 * plugins and never receives full order objects or payment secrets.
 *
 * Entry points:
 *  - wpac_record_sale( array $payload ) / do_action( 'wpac_v1_record_sale', $payload )
 *  - wpac_record_refund( array $payload ) / do_action( 'wpac_v1_record_refund', $payload )
 *
 * See docs/CONTRACT.md for the payload schema.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Sales;

use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ContractApi {

	public static function register_hooks() {
		add_action(
			'wpac_v1_record_sale',
			static function ( $payload ) {
				self::record_sale( (array) $payload );
			}
		);
		add_action(
			'wpac_v1_record_refund',
			static function ( $payload ) {
				self::record_refund( (array) $payload );
			}
		);
		add_filter( 'wpac_run_job', array( __CLASS__, 'run_job' ), 10, 3 );
	}

	/**
	 * Whether a contract version is compatible (same major version).
	 *
	 * @param string $version Version.
	 * @return bool
	 */
	public static function is_compatible( $version ) {
		return (int) $version === (int) WPAC_CONTRACT_VERSION;
	}

	/**
	 * Record a sale; failures are queued for retry.
	 *
	 * @param array      $payload Payload.
	 * @param array|null $retry   Optional retry job [ type, payload ] used instead of the raw payload
	 *                            (e.g. WooCommerce re-reads the order on retry).
	 * @return array|\WP_Error
	 */
	public static function record_sale( array $payload, $retry = null ) {
		try {
			$sale = self::normalize_sale( $payload );
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		// Mark the sale as in flight: a refund arriving meanwhile (parallel webhook) is queued
		// instead of being dropped as "no commission" (Jobs::sale_pending()).
		$inflight = Jobs::inflight_key( $sale['source'], $sale['order_id'] );
		$owner    = Db::lock( $inflight, 10 * MINUTE_IN_SECONDS ); // '' when a parallel request books it: keep its marker.
		try {
			return Ledger::record_sale( $sale );
		} catch ( \Throwable $e ) {
			$code = $e instanceof IntegrationException ? $e->error_code : 'exception';
			if ( is_array( $retry ) && ! empty( $retry['type'] ) ) {
				$queued = Jobs::enqueue( $retry['type'], $sale['source'] . ':' . $sale['order_id'], (array) $retry['payload'], $code, $e->getMessage() );
			} else {
				$queued = Jobs::enqueue( 'sale', $sale['source'] . ':' . $sale['order_id'], self::strip_personal_data( $payload ), $code, $e->getMessage() );
			}
			if ( ! $queued ) {
				return self::not_queued( 'sale', $sale, $code, $e );
			}
			return new \WP_Error( 'wpac_queued', __( 'The sale could not be processed and was queued for retry.', 'affiliate-captain' ) );
		} finally {
			Db::unlock( $inflight, $owner );
		}
	}

	/**
	 * Neither processed nor stored for retry (e.g. the database is down): say so – the
	 * caller must not assume a retry – and leave a trace in the plugin log (WooCommerce →
	 * Status → Logs, source "affiliate-captain"; PHP error log without WooCommerce).
	 *
	 * @param string          $what Sale or refund.
	 * @param array           $data Normalized sale/refund.
	 * @param string          $code Error code.
	 * @param \Throwable|null $e    Original error.
	 * @return \WP_Error
	 */
	private static function not_queued( $what, array $data, $code, $e ) {
		$context = array(
			'what'   => $what,
			'source' => (string) $data['source'],
			'order'  => (string) $data['order_id'],
			'error'  => (string) $code,
		);
		if ( $e instanceof \Throwable ) {
			Log::exception( 'Sale or refund could not be processed and could not be queued for retry', $e, $context );
		} else {
			Log::error( 'Sale or refund could not be processed and could not be queued for retry', $context );
		}
		/**
		 * Fires when a sale or refund could neither be processed nor queued (report it to the integration's own retry).
		 *
		 * @param string $what Sale or refund.
		 * @param array  $data Normalized payload.
		 */
		do_action( 'wpac_contract_not_queued', $what, $data );
		return new \WP_Error( 'wpac_not_queued', __( 'The sale or refund could not be processed and could not be stored for a retry. Please send it again later.', 'affiliate-captain' ) );
	}

	/**
	 * Remove personal data before a payload is persisted in the retry queue.
	 * The customer e-mail is only used for the self-referral check and must not be stored;
	 * on retry the check falls back to the customer user ID.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public static function strip_personal_data( array $payload ) {
		if ( isset( $payload['customer'] ) && is_array( $payload['customer'] ) ) {
			unset( $payload['customer']['email'], $payload['customer']['name'], $payload['customer']['address'], $payload['customer']['postcode'] );
		}
		return $payload;
	}

	/**
	 * Record a refund; failures are queued for retry.
	 *
	 * @param array $payload Payload.
	 * @return array|\WP_Error
	 */
	public static function record_refund( array $payload ) {
		try {
			$refund = self::normalize_refund( $payload );
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		try {
			$result = Ledger::record_refund( $refund );
			if ( 'no_commission' === $result['status'] && ! Jobs::sale_pending( $refund['source'], $refund['order_id'] ) && Ledger::attribution_for( $refund['source'], $refund['order_id'] ) ) {
				// The sale was booked between our read and the pending check: apply the refund now.
				$result = Ledger::record_refund( $refund );
			}
			if ( 'no_commission' === $result['status'] && Jobs::sale_pending( $refund['source'], $refund['order_id'] ) ) {
				// The sale is still in the retry queue: keep the refund until it is booked,
				// otherwise the full commission would be paid.
				if ( ! Jobs::enqueue( 'refund', $refund['source'] . ':' . $refund['order_id'] . ':' . $refund['refund_id'], $payload, 'sale_pending', 'Waiting for the queued sale of this order.' ) ) {
					return self::not_queued( 'refund', $refund, 'sale_pending', null );
				}
				return array(
					'status'         => 'queued',
					'adjustment_ids' => array(),
				);
			}
			return $result;
		} catch ( \Throwable $e ) {
			if ( ! Jobs::enqueue( 'refund', $refund['source'] . ':' . $refund['order_id'] . ':' . $refund['refund_id'], $payload, $e instanceof IntegrationException ? $e->error_code : 'exception', $e->getMessage() ) ) {
				return self::not_queued( 'refund', $refund, $e instanceof IntegrationException ? $e->error_code : 'exception', $e );
			}
			return new \WP_Error( 'wpac_queued', __( 'The refund could not be processed and was queued for retry.', 'affiliate-captain' ) );
		}
	}

	/**
	 * Job handler.
	 *
	 * @param bool   $handled Handled.
	 * @param string $type    Type.
	 * @param array  $payload Payload.
	 * @return bool
	 * @throws IntegrationException When processing fails.
	 */
	public static function run_job( $handled, $type, $payload ) {
		if ( 'sale' === $type ) {
			Ledger::record_sale( self::normalize_sale( $payload ) );
			return true;
		}
		if ( 'refund' === $type ) {
			$refund = self::normalize_refund( $payload );
			$result = Ledger::record_refund( $refund );
			if ( 'no_commission' === $result['status'] && Jobs::sale_pending( $refund['source'], $refund['order_id'] ) ) {
				throw new IntegrationException( 'sale_pending', 'Waiting for the queued sale of this order.' );
			}
			return true;
		}
		return $handled;
	}

	/**
	 * Validate + normalize a sale payload.
	 *
	 * @param array $p Payload.
	 * @return array
	 * @throws IntegrationException On invalid payloads.
	 */
	public static function normalize_sale( array $p ) {
		$contract = isset( $p['contract'] ) ? (string) $p['contract'] : '1.0.0';
		if ( ! self::is_compatible( $contract ) ) {
			throw new IntegrationException( 'incompatible_contract', esc_html( 'Unsupported contract version ' . $contract ) );
		}
		$source   = isset( $p['source'] ) ? sanitize_key( $p['source'] ) : '';
		$order_id = isset( $p['order_id'] ) ? substr( sanitize_text_field( (string) $p['order_id'] ), 0, 64 ) : '';
		if ( '' === $source || '' === $order_id ) {
			throw new IntegrationException( 'missing_reference', 'source and order_id are required' );
		}
		$currency = isset( $p['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $p['currency'] ) ) : '';
		if ( 3 !== strlen( $currency ) ) {
			throw new IntegrationException( 'invalid_currency', 'currency must be an ISO 4217 code' );
		}
		if ( empty( $p['items'] ) || ! is_array( $p['items'] ) ) {
			throw new IntegrationException( 'missing_items', 'items are required' );
		}

		$items = array();
		foreach ( $p['items'] as $i => $raw ) {
			$raw = (array) $raw;
			if ( ! isset( $raw['net_minor'] ) || ! is_numeric( $raw['net_minor'] ) || (int) $raw['net_minor'] != $raw['net_minor'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
				throw new IntegrationException( 'invalid_amount', 'net_minor must be an integer (minor units)' );
			}
			$items[] = array(
				'item_id'      => isset( $raw['item_id'] ) ? substr( sanitize_text_field( (string) $raw['item_id'] ), 0, 64 ) : (string) $i,
				'product_id'   => isset( $raw['product_id'] ) ? (int) $raw['product_id'] : 0,
				'parent_id'    => isset( $raw['parent_id'] ) ? (int) $raw['parent_id'] : 0,
				'sku'          => isset( $raw['sku'] ) ? sanitize_text_field( (string) $raw['sku'] ) : '',
				'category_ids' => isset( $raw['category_ids'] ) ? array_map( 'intval', (array) $raw['category_ids'] ) : array(),
				'tag_ids'      => isset( $raw['tag_ids'] ) ? array_map( 'intval', (array) $raw['tag_ids'] ) : array(),
				'name'         => isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : '',
				'quantity'     => isset( $raw['quantity'] ) ? max( 1, (int) $raw['quantity'] ) : 1,
				'net_minor'    => max( 0, (int) $raw['net_minor'] ),
				'meta'         => isset( $raw['meta'] ) && is_array( $raw['meta'] ) ? $raw['meta'] : array(),
			);
		}

		$touch = null;
		if ( ! empty( $p['touch'] ) && is_array( $p['touch'] ) && ! empty( $p['touch']['code'] ) ) {
			$touch = array(
				'code'       => sanitize_text_field( (string) $p['touch']['code'] ),
				'method'     => isset( $p['touch']['method'] ) ? sanitize_key( $p['touch']['method'] ) : 'link',
				'campaign'   => isset( $p['touch']['campaign'] ) ? sanitize_text_field( (string) $p['touch']['campaign'] ) : '',
				'content_id' => isset( $p['touch']['content_id'] ) ? (int) $p['touch']['content_id'] : 0,
				'ts'         => isset( $p['touch']['ts'] ) ? (int) $p['touch']['ts'] : 0,
			);
		}

		return array(
			'contract'            => $contract,
			'source'              => $source,
			'order_id'            => $order_id,
			'currency'            => $currency,
			'paid_at'             => self::datetime( isset( $p['paid_at'] ) ? $p['paid_at'] : '' ),
			'hold_until'          => self::datetime( isset( $p['hold_until'] ) ? $p['hold_until'] : '' ),
			'items'               => $items,
			'coupon_codes'        => isset( $p['coupon_codes'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', (array) $p['coupon_codes'] ) ) ) : array(),
			'partner_code'        => isset( $p['partner_code'] ) ? sanitize_text_field( (string) $p['partner_code'] ) : '',
			'partner_code_method' => isset( $p['partner_code_method'] ) ? sanitize_key( $p['partner_code_method'] ) : '',
			'affiliate_id'        => isset( $p['affiliate_id'] ) ? (int) $p['affiliate_id'] : 0,
			'attribution_method'  => isset( $p['attribution_method'] ) ? sanitize_key( $p['attribution_method'] ) : '',
			'touch'               => $touch,
			'customer'            => array(
				'user_id'  => isset( $p['customer']['user_id'] ) ? (int) $p['customer']['user_id'] : 0,
				// Used only for the self-referral comparison, never stored.
				'email'    => isset( $p['customer']['email'] ) ? sanitize_email( (string) $p['customer']['email'] ) : '',
				// Optional billing name/street/postcode: only for the self-referral comparison with
				// the partner's payout profile, never stored.
				'name'     => isset( $p['customer']['name'] ) ? substr( sanitize_text_field( (string) $p['customer']['name'] ), 0, 190 ) : '',
				'address'  => isset( $p['customer']['address'] ) ? substr( sanitize_text_field( (string) $p['customer']['address'] ), 0, 190 ) : '',
				'postcode' => isset( $p['customer']['postcode'] ) ? substr( sanitize_text_field( (string) $p['customer']['postcode'] ), 0, 20 ) : '',
			),
			'idempotency_key'     => isset( $p['idempotency_key'] ) ? substr( sanitize_text_field( (string) $p['idempotency_key'] ), 0, 191 ) : $source . ':' . $order_id,
		);
	}

	/**
	 * Validate + normalize a refund payload.
	 *
	 * @param array $p Payload.
	 * @return array
	 * @throws IntegrationException On invalid payloads.
	 */
	public static function normalize_refund( array $p ) {
		$contract = isset( $p['contract'] ) ? (string) $p['contract'] : '1.0.0';
		if ( ! self::is_compatible( $contract ) ) {
			throw new IntegrationException( 'incompatible_contract', esc_html( 'Unsupported contract version ' . $contract ) );
		}
		$source    = isset( $p['source'] ) ? sanitize_key( $p['source'] ) : '';
		$order_id  = isset( $p['order_id'] ) ? substr( sanitize_text_field( (string) $p['order_id'] ), 0, 64 ) : '';
		$refund_id = isset( $p['refund_id'] ) ? substr( sanitize_text_field( (string) $p['refund_id'] ), 0, 64 ) : '';
		if ( '' === $source || '' === $order_id || '' === $refund_id ) {
			throw new IntegrationException( 'missing_reference', 'source, order_id and refund_id are required' );
		}
		$type = isset( $p['type'] ) && in_array( $p['type'], array( 'refund', 'cancellation', 'chargeback' ), true ) ? $p['type'] : 'refund';
		$full = ! empty( $p['full'] ) || in_array( $type, array( 'cancellation', 'chargeback' ), true );

		$items = array();
		if ( ! $full ) {
			foreach ( (array) ( isset( $p['items'] ) ? $p['items'] : array() ) as $raw ) {
				$raw = (array) $raw;
				if ( ! isset( $raw['item_id'], $raw['net_minor'] ) ) {
					continue;
				}
				$items[] = array(
					'item_id'   => substr( sanitize_text_field( (string) $raw['item_id'] ), 0, 64 ),
					'net_minor' => abs( (int) $raw['net_minor'] ),
				);
			}
			if ( ! $items ) {
				throw new IntegrationException( 'missing_items', 'partial refunds need items with net_minor' );
			}
		}

		return array(
			'contract'  => $contract,
			'source'    => $source,
			'order_id'  => $order_id,
			'refund_id' => $refund_id,
			'type'      => $type,
			'full'      => $full,
			'items'     => $items,
			'reason'    => isset( $p['reason'] ) ? sanitize_text_field( (string) $p['reason'] ) : '',
		);
	}

	/**
	 * Normalize a date/time to UTC "Y-m-d H:i:s" ('' when empty/invalid).
	 *
	 * @param mixed $value Value (string or timestamp).
	 * @return string
	 */
	private static function datetime( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}

	/**
	 * Integrations registered via the `wpac_registered_integrations` filter.
	 * Each entry: slug => [ name, version, contract, active ].
	 *
	 * @return array
	 */
	public static function integrations() {
		$list = array(
			'wordpress' => array(
				'name'     => 'WordPress Content',
				'version'  => get_bloginfo( 'version' ),
				'contract' => WPAC_CONTRACT_VERSION,
				'active'   => true,
			),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$list['woocommerce'] = array(
				'name'     => 'WooCommerce',
				'version'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'contract' => WPAC_CONTRACT_VERSION,
				'active'   => true,
			);
		}
		/**
		 * Register an integration (e.g. Pass Captain, Voucher Captain, connector add-on)
		 * so its status and contract compatibility show up in the admin.
		 *
		 * @param array $list slug => [ name, version, contract, active ].
		 */
		return (array) apply_filters( 'wpac_registered_integrations', $list );
	}

	/**
	 * Default currency for integrations that do not send one.
	 *
	 * @return string
	 */
	public static function default_currency() {
		return (string) Settings::get( 'currency' );
	}
}
