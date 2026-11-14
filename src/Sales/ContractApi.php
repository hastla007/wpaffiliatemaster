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
		add_action(
			'wpac_v1_record_reinstatement',
			static function ( $payload ) {
				self::record_reinstatement( (array) $payload );
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
		// instead of being dropped as "no commission" (Jobs::sale_pending()). A MySQL user lock of
		// this connection – nothing is written to the database for orders without a partner.
		$owner = Jobs::begin_inflight( $sale['source'], $sale['order_id'] ); // false when a parallel request books it: keep its marker.
		try {
			$result = Ledger::record_sale( $sale );
			self::after_booking( $sale, $result );
			return $result;
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
			if ( $owner ) {
				Jobs::end_inflight( $sale['source'], $sale['order_id'] );
			}
		}
	}

	/**
	 * After a sale was booked: refunds already deducted from its net amounts are closed, the
	 * other refunds that waited for it (queued or parked) run now.
	 *
	 * With `netted_refund_ids` the source says which refunds it deducted. Without it (sources
	 * written for contract 1.0–1.2, which deduct earlier refunds as documented), refunds that
	 * were parked because the sale was unknown are treated as deducted, so nothing is deducted twice.
	 *
	 * @param array $sale   Normalized sale.
	 * @param array $result Result of Ledger::record_sale().
	 */
	public static function after_booking( array $sale, $result ) {
		if ( ! is_array( $result ) || 'created' !== $result['status'] ) {
			return;
		}
		if ( is_array( $sale['netted_refund_ids'] ) ) {
			Jobs::supersede_refunds( $sale['source'], $sale['order_id'], $sale['netted_refund_ids'] );
		} else {
			Jobs::supersede_parked( $sale['source'], $sale['order_id'] );
		}
		Jobs::wake_refunds( $sale['source'] . ':' . $sale['order_id'] );
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
			if ( Jobs::is_superseded( $refund['source'], $refund['order_id'], $refund['refund_id'] ) ) {
				// Already deducted from the net amounts of the booked sale.
				return array(
					'status'         => 'superseded',
					'adjustment_ids' => array(),
				);
			}
			$result = Ledger::record_refund( $refund );
			if ( 'no_commission' === $result['status'] && ! Jobs::sale_pending( $refund['source'], $refund['order_id'] ) && Ledger::attribution_for( $refund['source'], $refund['order_id'] ) ) {
				// The sale was booked between our read and the pending check: apply the refund now.
				$result = Ledger::record_refund( $refund );
			}
			if ( 'no_commission' === $result['status'] && ! Jobs::sale_pending( $refund['source'], $refund['order_id'] ) && ! Ledger::attribution_for( $refund['source'], $refund['order_id'] ) ) {
				// Unknown order (the sale was not reported yet): park the refund until the sale is
				// booked (see after_booking()); otherwise the full commission would be paid later.
				if ( ! Jobs::enqueue( 'refund', $refund['source'] . ':' . $refund['order_id'] . ':' . $refund['refund_id'], $payload, 'no_sale', 'Waiting for the sale of this order to be reported.', 300, 'parked' ) ) {
					return self::not_queued( 'refund', $refund, 'no_sale', null );
				}
				return array(
					'status'         => 'parked',
					'adjustment_ids' => array(),
				);
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
			$sale = self::normalize_sale( $payload );
			self::after_booking( $sale, Ledger::record_sale( $sale ) );
			return true;
		}
		if ( 'refund' === $type ) {
			$refund = self::normalize_refund( $payload );
			if ( Jobs::is_superseded( $refund['source'], $refund['order_id'], $refund['refund_id'] ) ) {
				return true;
			}
			$result = Ledger::record_refund( $refund );
			if ( 'no_commission' === $result['status'] && Jobs::sale_pending( $refund['source'], $refund['order_id'] ) ) {
				throw new IntegrationException( 'sale_pending', 'Waiting for the queued sale of this order.' );
			}
			return true;
		}
		if ( 'reinstate' === $type ) {
			$r = self::normalize_reinstatement( $payload );
			Ledger::reinstate( $r['source'], $r['order_id'], $r['reason'], $r['refund_id'] );
			return true;
		}
		return $handled;
	}

	/** Largest amount per line in minor units (10 million in a 2-decimal currency). Larger values overflow the integer math of pro-rata refunds. */
	const MAX_MINOR = 1000000000;

	/** Most lines per sale or refund. */
	const MAX_ITEMS = 500;

	/** ISO 4217 currency codes accepted by the contract. */
	const CURRENCIES = 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BYN BZD CAD CDF CHF CLP CNY COP CRC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VES VND VUV WST XAF XCD XOF XPF YER ZAR ZMW ZWL';

	/**
	 * A reference (order, item or refund ID) as string: scalars only, never arrays/objects
	 * ("Array" would be booked), shortened to the column length without breaking UTF-8.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Column length.
	 * @return string
	 * @throws IntegrationException When the value is not a scalar.
	 */
	private static function reference( $value, $max = 64 ) {
		if ( null === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			throw new IntegrationException( 'invalid_reference', 'order_id, item_id and refund_id must be strings or numbers' );
		}
		return Db::cut( sanitize_text_field( (string) $value ), $max );
	}

	/**
	 * An amount in minor units: integers (also as digit strings or whole floats from filters),
	 * within ±MAX_MINOR. "1e3", "12.5" or huge numbers are refused.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws IntegrationException When the value is no valid amount.
	 */
	public static function minor( $value ) {
		if ( is_int( $value ) ) {
			$n = $value;
		} elseif ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) <= self::MAX_MINOR ) {
			$n = (int) $value;
		} elseif ( is_string( $value ) && preg_match( '/^\s*-?\d{1,18}\s*$/', $value ) ) {
			$n = (int) $value;
		} else {
			throw new IntegrationException( 'invalid_amount', 'net_minor must be an integer (minor units)' );
		}
		if ( abs( $n ) > self::MAX_MINOR ) {
			throw new IntegrationException( 'invalid_amount', 'net_minor is out of range (max. ' . self::MAX_MINOR . ' minor units per line)' );
		}
		return $n;
	}

	/**
	 * Validate + normalize a sale payload.
	 *
	 * @param array $p Payload.
	 * @return array
	 * @throws IntegrationException On invalid payloads.
	 */
	public static function normalize_sale( array $p ) {
		$contract = isset( $p['contract'] ) && is_scalar( $p['contract'] ) ? (string) $p['contract'] : '1.0.0';
		if ( ! self::is_compatible( $contract ) ) {
			throw new IntegrationException( 'incompatible_contract', esc_html( 'Unsupported contract version ' . $contract ) );
		}
		$source   = isset( $p['source'] ) && is_scalar( $p['source'] ) ? substr( sanitize_key( (string) $p['source'] ), 0, 40 ) : '';
		$order_id = self::reference( isset( $p['order_id'] ) ? $p['order_id'] : null );
		if ( '' === $source || '' === $order_id ) {
			throw new IntegrationException( 'missing_reference', 'source and order_id are required' );
		}
		$currency = isset( $p['currency'] ) && is_scalar( $p['currency'] ) ? strtoupper( trim( (string) $p['currency'] ) ) : '';
		/**
		 * Currencies accepted in sale payloads (ISO 4217 codes).
		 *
		 * @param string[] $codes Codes.
		 */
		$currencies = (array) apply_filters( 'wpac_contract_currencies', explode( ' ', self::CURRENCIES ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! in_array( $currency, $currencies, true ) ) {
			throw new IntegrationException( 'invalid_currency', 'currency must be an ISO 4217 code' );
		}
		if ( empty( $p['items'] ) || ! is_array( $p['items'] ) ) {
			throw new IntegrationException( 'missing_items', 'items are required' );
		}
		if ( count( $p['items'] ) > self::MAX_ITEMS ) {
			throw new IntegrationException( 'too_many_items', 'at most ' . self::MAX_ITEMS . ' items per sale' );
		}

		$items = array();
		$seen  = array();
		foreach ( $p['items'] as $i => $raw ) {
			if ( ! is_array( $raw ) && ! is_object( $raw ) ) {
				throw new IntegrationException( 'invalid_item', 'each item must be an object' );
			}
			$raw = (array) $raw;
			if ( ! isset( $raw['net_minor'] ) ) {
				throw new IntegrationException( 'invalid_amount', 'net_minor must be an integer (minor units)' );
			}
			$net     = self::minor( $raw['net_minor'] );
			$item_id = isset( $raw['item_id'] ) ? self::reference( $raw['item_id'] ) : (string) $i;
			if ( '' === $item_id ) {
				$item_id = (string) $i;
			}
			// item_id is the key for refunds and part of the booking key: it must be unique.
			if ( isset( $seen[ $item_id ] ) ) {
				throw new IntegrationException( 'duplicate_item', 'item_id ' . $item_id . ' occurs more than once' );
			}
			$seen[ $item_id ] = true;
			$meta             = isset( $raw['meta'] ) && is_array( $raw['meta'] ) ? $raw['meta'] : array();
			if ( $meta && strlen( (string) wp_json_encode( $meta ) ) > 4096 ) {
				throw new IntegrationException( 'invalid_meta', 'item meta is limited to 4 KB' );
			}
			$items[] = array(
				'item_id'               => $item_id,
				'product_id'            => isset( $raw['product_id'] ) && is_scalar( $raw['product_id'] ) ? max( 0, (int) $raw['product_id'] ) : 0,
				'parent_id'             => isset( $raw['parent_id'] ) && is_scalar( $raw['parent_id'] ) ? max( 0, (int) $raw['parent_id'] ) : 0,
				'sku'                   => isset( $raw['sku'] ) && is_scalar( $raw['sku'] ) ? Db::cut( sanitize_text_field( (string) $raw['sku'] ), 190 ) : '',
				'category_ids'          => isset( $raw['category_ids'] ) ? self::ids( $raw['category_ids'] ) : array(),
				// Optional: parent categories (rules on a parent category apply to subcategories).
				'category_ancestor_ids' => isset( $raw['category_ancestor_ids'] ) ? self::ids( $raw['category_ancestor_ids'] ) : array(),
				'tag_ids'               => isset( $raw['tag_ids'] ) ? self::ids( $raw['tag_ids'] ) : array(),
				'name'                  => isset( $raw['name'] ) && is_scalar( $raw['name'] ) ? Db::cut( sanitize_text_field( (string) $raw['name'] ), 255 ) : '',
				'quantity'              => isset( $raw['quantity'] ) && is_scalar( $raw['quantity'] ) ? min( 100000, max( 1, (int) $raw['quantity'] ) ) : 1,
				'net_minor'             => max( 0, $net ),
				'meta'                  => $meta,
			);
		}

		$touch = null;
		if ( ! empty( $p['touch'] ) && is_array( $p['touch'] ) && ! empty( $p['touch']['code'] ) && is_scalar( $p['touch']['code'] ) ) {
			$touch = array(
				'code'       => Db::cut( sanitize_text_field( (string) $p['touch']['code'] ), 64 ),
				'method'     => isset( $p['touch']['method'] ) && is_scalar( $p['touch']['method'] ) ? sanitize_key( (string) $p['touch']['method'] ) : 'link',
				'campaign'   => isset( $p['touch']['campaign'] ) && is_scalar( $p['touch']['campaign'] ) ? Db::cut( sanitize_text_field( (string) $p['touch']['campaign'] ), 100 ) : '',
				'content_id' => isset( $p['touch']['content_id'] ) && is_scalar( $p['touch']['content_id'] ) ? (int) $p['touch']['content_id'] : 0,
				'ts'         => isset( $p['touch']['ts'] ) && is_scalar( $p['touch']['ts'] ) ? (int) $p['touch']['ts'] : 0,
			);
		}

		$netted = null;
		if ( isset( $p['netted_refund_ids'] ) ) {
			$netted = array();
			foreach ( array_slice( (array) $p['netted_refund_ids'], 0, self::MAX_ITEMS ) as $rid ) {
				$rid = self::reference( $rid );
				if ( '' !== $rid ) {
					$netted[] = $rid;
				}
			}
		}

		return array(
			'contract'            => $contract,
			'source'              => $source,
			'order_id'            => $order_id,
			'currency'            => $currency,
			'paid_at'             => self::datetime( isset( $p['paid_at'] ) ? $p['paid_at'] : '', 'paid_at' ),
			'hold_until'          => self::datetime( isset( $p['hold_until'] ) ? $p['hold_until'] : '', 'hold_until' ),
			'items'               => $items,
			'coupon_codes'        => isset( $p['coupon_codes'] ) ? array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', array_filter( (array) $p['coupon_codes'], 'is_scalar' ) ) ) ) ), 0, 50 ) : array(),
			'partner_code'        => isset( $p['partner_code'] ) && is_scalar( $p['partner_code'] ) ? Db::cut( sanitize_text_field( (string) $p['partner_code'] ), 64 ) : '',
			'partner_code_method' => isset( $p['partner_code_method'] ) && is_scalar( $p['partner_code_method'] ) ? sanitize_key( (string) $p['partner_code_method'] ) : '',
			'affiliate_id'        => isset( $p['affiliate_id'] ) && is_scalar( $p['affiliate_id'] ) ? max( 0, (int) $p['affiliate_id'] ) : 0,
			// Method shown for an explicit affiliate_id (e.g. "renewal"; default "manual").
			'attribution_method'  => isset( $p['attribution_method'] ) && is_scalar( $p['attribution_method'] ) ? substr( sanitize_key( (string) $p['attribution_method'] ), 0, 20 ) : '',
			'touch'               => $touch,
			'customer'            => array(
				'user_id'  => isset( $p['customer']['user_id'] ) && is_scalar( $p['customer']['user_id'] ) ? (int) $p['customer']['user_id'] : 0,
				// Used only for the self-referral comparison, never stored.
				'email'    => isset( $p['customer']['email'] ) && is_scalar( $p['customer']['email'] ) ? sanitize_email( (string) $p['customer']['email'] ) : '',
				// Optional billing name/street/postcode: only for the self-referral comparison with
				// the partner's payout profile, never stored.
				'name'     => isset( $p['customer']['name'] ) && is_scalar( $p['customer']['name'] ) ? Db::cut( sanitize_text_field( (string) $p['customer']['name'] ), 190 ) : '',
				'address'  => isset( $p['customer']['address'] ) && is_scalar( $p['customer']['address'] ) ? Db::cut( sanitize_text_field( (string) $p['customer']['address'] ), 190 ) : '',
				'postcode' => isset( $p['customer']['postcode'] ) && is_scalar( $p['customer']['postcode'] ) ? Db::cut( sanitize_text_field( (string) $p['customer']['postcode'] ), 20 ) : '',
			),
			// The source asks for a manual review (e.g. renewal of a subscription whose first order is under review).
			'review'              => ! empty( $p['review'] ),
			// Refund IDs already deducted from net_minor (null: not stated, see after_booking()).
			'netted_refund_ids'   => $netted,
			'idempotency_key'     => isset( $p['idempotency_key'] ) && is_scalar( $p['idempotency_key'] ) ? Db::cut( sanitize_text_field( (string) $p['idempotency_key'] ), 191 ) : $source . ':' . $order_id,
		);
	}

	/**
	 * Category/tag IDs: positive integers, unique, at most 200.
	 *
	 * @param mixed $raw Value.
	 * @return int[]
	 */
	private static function ids( $raw ) {
		$out = array();
		foreach ( (array) $raw as $v ) {
			if ( is_scalar( $v ) && (int) $v > 0 ) {
				$out[ (int) $v ] = (int) $v;
			}
		}
		return array_slice( array_values( $out ), 0, 200 );
	}

	/**
	 * Validate + normalize a refund payload.
	 *
	 * @param array $p Payload.
	 * @return array
	 * @throws IntegrationException On invalid payloads.
	 */
	public static function normalize_refund( array $p ) {
		$contract = isset( $p['contract'] ) && is_scalar( $p['contract'] ) ? (string) $p['contract'] : '1.0.0';
		if ( ! self::is_compatible( $contract ) ) {
			throw new IntegrationException( 'incompatible_contract', esc_html( 'Unsupported contract version ' . $contract ) );
		}
		$source    = isset( $p['source'] ) && is_scalar( $p['source'] ) ? substr( sanitize_key( (string) $p['source'] ), 0, 40 ) : '';
		$order_id  = self::reference( isset( $p['order_id'] ) ? $p['order_id'] : null );
		$refund_id = self::reference( isset( $p['refund_id'] ) ? $p['refund_id'] : null );
		if ( '' === $source || '' === $order_id || '' === $refund_id ) {
			throw new IntegrationException( 'missing_reference', 'source, order_id and refund_id are required' );
		}
		$type = isset( $p['type'] ) && in_array( $p['type'], array( 'refund', 'cancellation', 'chargeback' ), true ) ? $p['type'] : 'refund';
		$full = ! empty( $p['full'] ) || in_array( $type, array( 'cancellation', 'chargeback' ), true );

		$items = array();
		if ( ! $full ) {
			$raw_items = isset( $p['items'] ) ? (array) $p['items'] : array();
			if ( count( $raw_items ) > self::MAX_ITEMS ) {
				throw new IntegrationException( 'too_many_items', 'at most ' . self::MAX_ITEMS . ' items per refund' );
			}
			foreach ( $raw_items as $raw ) {
				if ( ! is_array( $raw ) && ! is_object( $raw ) ) {
					continue;
				}
				$raw = (array) $raw;
				if ( ! isset( $raw['item_id'], $raw['net_minor'] ) ) {
					continue;
				}
				$items[] = array(
					'item_id'   => self::reference( $raw['item_id'] ),
					'net_minor' => abs( self::minor( $raw['net_minor'] ) ),
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
			'reason'    => isset( $p['reason'] ) && is_scalar( $p['reason'] ) ? Db::cut( sanitize_text_field( (string) $p['reason'] ), 255 ) : '',
		);
	}

	/**
	 * Validate + normalize a reinstatement payload (chargeback won, cancellation withdrawn).
	 *
	 * @param array $p Payload.
	 * @return array
	 * @throws IntegrationException On invalid payloads.
	 */
	public static function normalize_reinstatement( array $p ) {
		$contract = isset( $p['contract'] ) && is_scalar( $p['contract'] ) ? (string) $p['contract'] : '1.0.0';
		if ( ! self::is_compatible( $contract ) ) {
			throw new IntegrationException( 'incompatible_contract', 'Unsupported contract version ' . $contract );
		}
		$source   = isset( $p['source'] ) && is_scalar( $p['source'] ) ? substr( sanitize_key( (string) $p['source'] ), 0, 40 ) : '';
		$order_id = self::reference( isset( $p['order_id'] ) ? $p['order_id'] : null );
		if ( '' === $source || '' === $order_id ) {
			throw new IntegrationException( 'missing_reference', 'source and order_id are required' );
		}
		return array(
			'contract'  => $contract,
			'source'    => $source,
			'order_id'  => $order_id,
			'refund_id' => self::reference( isset( $p['refund_id'] ) ? $p['refund_id'] : null ),
			'reason'    => isset( $p['reason'] ) && is_scalar( $p['reason'] ) && '' !== trim( (string) $p['reason'] ) ? Db::cut( sanitize_text_field( (string) $p['reason'] ), 255 ) : __( 'Reversal withdrawn by the sales source (e.g. chargeback won)', 'affiliate-captain' ),
		);
	}

	/**
	 * Reinstate the commission of an order whose cancellation or chargeback turned out to be
	 * wrong (chargeback won, cancellation withdrawn). Partial refunds stay. Idempotent.
	 *
	 * @param array $payload { contract, source, order_id, refund_id (optional: only this reversal), reason }.
	 * @return array|\WP_Error { status: reinstated|unchanged, count }
	 */
	public static function record_reinstatement( array $payload ) {
		try {
			$r = self::normalize_reinstatement( $payload );
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		try {
			$n = Ledger::reinstate( $r['source'], $r['order_id'], $r['reason'], $r['refund_id'] );
			return array(
				'status' => $n ? 'reinstated' : 'unchanged',
				'count'  => (int) $n,
			);
		} catch ( \Throwable $e ) {
			$code = $e instanceof IntegrationException ? $e->error_code : 'exception';
			if ( ! Jobs::enqueue( 'reinstate', $r['source'] . ':' . $r['order_id'] . ':' . $r['refund_id'], $payload, $code, $e->getMessage() ) ) {
				return self::not_queued( 'reinstatement', $r, $code, $e );
			}
			return new \WP_Error( 'wpac_queued', __( 'The reinstatement could not be processed and was queued for retry.', 'affiliate-captain' ) );
		}
	}

	/**
	 * Normalize a date/time to UTC "Y-m-d H:i:s" ('' when empty).
	 *
	 * Accepts "Y-m-d H:i:s" (UTC), ISO 8601, compact "Ymd"/"YmdHis" and Unix timestamps in
	 * seconds or milliseconds. Dates before 2000 or more than ten years ahead are refused: they
	 * would release a commission at once or never.
	 *
	 * @param mixed  $value Value (string or timestamp).
	 * @param string $field Field name (error message).
	 * @return string
	 * @throws IntegrationException On unreadable or implausible dates.
	 */
	public static function datetime( $value, $field = 'date' ) {
		if ( '' === $value || null === $value || false === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			throw new IntegrationException( 'invalid_date', esc_html( $field . ' must be a date or a Unix timestamp' ) );
		}
		$str = trim( (string) $value );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $str, $m ) ) {
			$ts = checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? strtotime( "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00 UTC" ) : false; // Ymd.
		} elseif ( preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $str, $m ) ) {
			$ts = checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? strtotime( "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} UTC" ) : false; // YmdHis.
		} elseif ( preg_match( '/^\d+(\.\d+)?$/', $str ) ) {
			$ts = (int) $str;
			if ( $ts > 100000000000 ) {
				$ts = (int) floor( $ts / 1000 ); // Milliseconds (JavaScript, many APIs).
			}
		} else {
			// Without a zone the value is UTC (as documented), not the server's zone.
			$ts = preg_match( '/(Z|[+-]\d{2}:?\d{2}|UTC|GMT)$/i', $str ) ? strtotime( $str ) : strtotime( $str . ' UTC' );
		}
		if ( ! $ts || $ts < 946684800 || $ts > time() + 10 * 365 * DAY_IN_SECONDS ) {
			throw new IntegrationException( 'invalid_date', esc_html( $field . ' is not a valid date between 2000 and ten years from now' ) );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
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
