<?php
/**
 * REST API (namespace wpac/v1).
 *
 * Authentication: standard WordPress (cookie + X-WP-Nonce, or Application
 * Passwords). Public endpoints only reveal the contract version and whether a
 * partner code is valid (rate limited, no partner data → no enumeration).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Rest;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Reports\Reports;
use WPAffiliateCaptain\Sales\ContractApi;
use WPAffiliateCaptain\Tracking\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	const NS = 'wpac/v1';

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Capability check factory.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	private static function can( $cap ) {
		return static function () use ( $cap ) {
			return current_user_can( $cap );
		};
	}

	/**
	 * Beacon: referral seen by a cached page (see Tracker::enqueue_beacon()).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	public static function touch( $r ) {
		if ( ! Settings::get( 'js_tracking' ) ) {
			return new \WP_Error( 'wpac_disabled', __( 'JavaScript tracking is disabled.', 'wp-affiliate-captain' ), array( 'status' => 404 ) );
		}
		$res = rest_ensure_response( array( 'ok' => true ) ); // Same answer for unknown codes (no enumeration).
		$res->header( 'Cache-Control', 'no-store' );
		// Like a page view, but a script can send many: limited per client (same answer when over the limit).
		if ( ! RateLimiter::allow( 'touch', 30, MINUTE_IN_SECONDS ) ) {
			return $res;
		}
		// REST requests do not load the WooCommerce session: without it the touch of a visitor with a
		// cart would not reach the checkout.
		if ( function_exists( 'WC' ) && function_exists( 'wc_load_cart' ) && ! WC()->session ) {
			wc_load_cart();
		}
		$path = wp_parse_url( (string) $r['path'], PHP_URL_PATH );
		// Only the host of the previous page is kept (never its path or query, which may contain personal data).
		$ref  = trim( (string) $r['referrer'] );
		$host = false !== strpos( $ref, '://' ) ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : $ref;
		$host = preg_match( '/^[a-z0-9.-]{1,253}$/i', $host ) ? strtolower( $host ) : '';
		\WPAffiliateCaptain\Tracking\Tracker::capture_code(
			sanitize_text_field( (string) $r['code'] ),
			sanitize_text_field( (string) $r['campaign'] ),
			absint( $r['content_id'] ),
			array(
				'utm_source' => sanitize_text_field( (string) $r['utm_source'] ),
				'utm_medium' => sanitize_text_field( (string) $r['utm_medium'] ),
				'path'       => is_string( $path ) ? $path : '',
				'referrer'   => '' !== $host ? 'https://' . $host . '/' : '',
			)
		);
		return $res;
	}

	/**
	 * Health for external monitoring: administrators, or a monitor with the token from the
	 * settings (header X-WPAC-Monitor-Token). The token only gets the names of failing checks.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function health( $r ) {
		$sent  = (string) $r->get_header( 'x-wpac-monitor-token' );
		$admin = current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM );
		// Only a hash of the token is stored (compared with hash_equals()).
		if ( ! $admin && ! Settings::monitor_token_matches( $sent ) ) {
			return new \WP_Error( 'wpac_forbidden', __( 'Not allowed.', 'wp-affiliate-captain' ), array( 'status' => 403 ) );
		}
		$problems = \WPAffiliateCaptain\Core\Monitor::problems( true );
		$body     = array(
			'ok'     => ! $problems,
			'failed' => array_keys( $problems ),
		);
		if ( $admin ) {
			$body['checks'] = \WPAffiliateCaptain\Core\Monitor::checks();
		}
		$res = rest_ensure_response( $body );
		$res->set_status( $problems ? 503 : 200 ); // Monitors react to the status code.
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // Checked in the callback (token or capability).
				'callback'            => array( __CLASS__, 'health' ),
			)
		);
		register_rest_route(
			self::NS,
			'/contract',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					// The plugin version only for logged-in users who manage the program: an exact
					// version tells anonymous scanners which known issues to try.
					$out = array( 'contract' => WPAC_CONTRACT_VERSION );
					if ( current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM ) ) {
						$out['plugin'] = WPAC_VERSION;
					}
					return $out;
				},
			)
		);
		register_rest_route(
			self::NS,
			'/touch',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Public like a page view; only active with the beacon setting.
				'callback'            => array( __CLASS__, 'touch' ),
				'args'                => array(
					'code'       => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9_\\-]{3,64}$',
					),
					'campaign'   => array( 'type' => 'string' ),
					'utm_source' => array( 'type' => 'string' ),
					'utm_medium' => array( 'type' => 'string' ),
					'content_id' => array( 'type' => 'integer' ),
					'path'       => array( 'type' => 'string' ),
					'referrer'   => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/codes/(?P<code>[A-Za-z0-9_\-]{3,64})',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'check_code' ),
			)
		);
		register_rest_route(
			self::NS,
			'/sales',
			array(
				'methods'             => 'POST',
				'permission_callback' => self::can( Capabilities::MANAGE_PROGRAM ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					return self::result( ContractApi::record_sale( (array) $r->get_json_params() ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/refunds',
			array(
				'methods'             => 'POST',
				'permission_callback' => self::can( Capabilities::MANAGE_PROGRAM ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					return self::result( ContractApi::record_refund( (array) $r->get_json_params() ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/affiliates',
			array(
				'methods'             => 'GET',
				'permission_callback' => self::can( Capabilities::MANAGE_AFFILIATES ),
				'callback'            => array( __CLASS__, 'affiliates' ),
				'args'                => self::paging_args( array( 'status' => array( 'type' => 'string' ), 'search' => array( 'type' => 'string' ) ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/affiliates/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => self::can( Capabilities::MANAGE_AFFILIATES ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$a = AffiliateRepository::find( (int) $r['id'] );
					return $a ? self::affiliate_data( $a, true ) : new \WP_Error( 'wpac_not_found', __( 'Not found.', 'wp-affiliate-captain' ), array( 'status' => 404 ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/commissions',
			array(
				'methods'             => 'GET',
				'permission_callback' => self::can( Capabilities::MANAGE_AFFILIATES ),
				'callback'            => array( __CLASS__, 'commissions' ),
				'args'                => self::paging_args(
					array(
						'affiliate_id' => array( 'type' => 'integer' ),
						'status'       => array( 'type' => 'string' ),
						'source'       => array( 'type' => 'string' ),
						'order_id'     => array( 'type' => 'string' ),
						'from'         => array( 'type' => 'string' ),
						'to'           => array( 'type' => 'string' ),
					)
				),
			)
		);
		register_rest_route(
			self::NS,
			'/reports/(?P<view>partners|content|campaign)',
			array(
				'methods'             => 'GET',
				'permission_callback' => self::can( Capabilities::MANAGE_AFFILIATES ),
				'callback'            => array( __CLASS__, 'report' ),
				'args'                => array(
					'from' => array( 'type' => 'string' ),
					'to'   => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/me',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'is_partner' ),
				'callback'            => array( __CLASS__, 'me' ),
			)
		);
		register_rest_route(
			self::NS,
			'/me/commissions',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'is_partner' ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$aff                  = self::partner();
					list( $rows, $total ) = Ledger::query(
						array(
							'affiliate_id' => (int) $aff->id,
							'limit'        => (int) $r['per_page'],
							'offset'       => ( (int) $r['page'] - 1 ) * (int) $r['per_page'],
						)
					);
					$out = array();
					foreach ( $rows as $c ) {
						$out[] = self::commission_data( $c, false );
					}
					return self::paged( $out, $total );
				},
				'args'                => self::paging_args(),
			)
		);
		register_rest_route(
			self::NS,
			'/me/payouts',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'is_partner' ),
				'callback'            => static function () {
					list( $rows ) = PayoutService::query(
						array(
							'affiliate_id' => (int) self::partner()->id,
							'limit'        => 200,
						)
					);
					$out = array();
					foreach ( $rows as $p ) {
						if ( 'cancelled' === $p->status ) {
							continue;
						}
						$out[] = array(
							'number'       => $p->number,
							'period_end'   => $p->period_end,
							'currency'     => $p->currency,
							'amount_minor' => (int) $p->amount_minor,
							'amount'       => Money::to_decimal( (int) $p->amount_minor, $p->currency ),
							'status'       => 'exported' === $p->status ? 'pending' : $p->status,
							'paid_at'      => $p->paid_at,
						);
					}
					return $out;
				},
			)
		);
	}

	/**
	 * Common paging args.
	 *
	 * @param array $extra Extra args.
	 * @return array
	 */
	private static function paging_args( array $extra = array() ) {
		return $extra + array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 50,
				'minimum' => 1,
				'maximum' => 200,
			),
		);
	}

	/**
	 * Response with total headers.
	 *
	 * @param array $data  Data.
	 * @param int   $total Total.
	 * @return \WP_REST_Response
	 */
	private static function paged( array $data, $total ) {
		$res = new \WP_REST_Response( $data );
		$res->header( 'X-WP-Total', (string) (int) $total );
		return $res;
	}

	/**
	 * WP_Error → REST error with status.
	 *
	 * @param mixed $result Result.
	 * @return mixed
	 */
	private static function result( $result ) {
		if ( is_wp_error( $result ) ) {
			if ( 'wpac_queued' === $result->get_error_code() ) {
				// Accepted for asynchronous retry – not an error for the client.
				return new \WP_REST_Response(
					array(
						'status'  => 'queued',
						'message' => $result->get_error_message(),
					),
					202
				);
			}
			$result->add_data( array( 'status' => 400 ) );
		}
		return $result;
	}

	/**
	 * Current partner.
	 *
	 * @return object|null
	 */
	private static function partner() {
		return AffiliateRepository::find_by_user( get_current_user_id() );
	}

	/**
	 * Permission: logged-in partner (active or paused).
	 *
	 * @return bool
	 */
	public static function is_partner() {
		$aff = is_user_logged_in() ? self::partner() : null;
		return $aff && in_array( $aff->status, array( 'active', 'paused' ), true );
	}

	/**
	 * Public code check: only says whether the code is valid.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array|\WP_Error
	 */
	public static function check_code( \WP_REST_Request $r ) {
		if ( ! RateLimiter::allow( 'rest_code', 20, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wpac_rate_limited', __( 'Too many attempts. Please wait a minute and try again.', 'wp-affiliate-captain' ), array( 'status' => 429 ) );
		}
		return array( 'valid' => (bool) wpac_resolve_partner_code( (string) $r['code'], array( 'context' => 'rest' ) ) );
	}

	/**
	 * Affiliate list.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function affiliates( \WP_REST_Request $r ) {
		list( $rows, $total ) = AffiliateRepository::query(
			array(
				'status' => sanitize_key( (string) $r['status'] ),
				'search' => sanitize_text_field( (string) $r['search'] ),
				'limit'  => (int) $r['per_page'],
				'offset' => ( (int) $r['page'] - 1 ) * (int) $r['per_page'],
			)
		);
		$out = array();
		foreach ( $rows as $a ) {
			$out[] = self::affiliate_data( $a, false );
		}
		return self::paged( $out, $total );
	}

	/**
	 * Affiliate payload (never tax/bank data).
	 *
	 * @param object $a    Affiliate.
	 * @param bool   $full Include codes.
	 * @return array
	 */
	private static function affiliate_data( $a, $full ) {
		$data = array(
			'id'         => (int) $a->id,
			'name'       => $a->name,
			'email'      => $a->email,
			'slug'       => $a->slug,
			'status'     => $a->status,
			'group_id'   => (int) $a->group_id,
			'website'    => $a->website,
			'channel'    => $a->channel,
			'created_at' => $a->created_at,
		);
		if ( $full ) {
			$data['codes'] = array_map(
				static function ( $c ) {
					return array(
						'code'        => $c->code,
						'coupon_code' => $c->coupon_code,
						'status'      => $c->status,
						'usage_count' => (int) $c->usage_count,
						'usage_limit' => (int) $c->usage_limit,
						'expires_at'  => $c->expires_at,
					);
				},
				CodeRepository::for_affiliate( (int) $a->id )
			);
		}
		return $data;
	}

	/**
	 * Commission payload.
	 *
	 * @param object $c     Commission.
	 * @param bool   $admin Admin view.
	 * @return array
	 */
	private static function commission_data( $c, $admin ) {
		$data = array(
			'id'           => (int) $c->id,
			'date'         => $c->created_at,
			'order'        => 'wpac' === $c->source ? '' : $c->source_order_id,
			'kind'         => $c->kind,
			// Partners see the internal reason of corrections/write-offs only as a label (as in the portal).
			'description'  => $admin || in_array( $c->kind, array( 'sale', 'bonus' ), true ) ? $c->description : ( isset( Ledger::kind_labels()[ $c->kind ] ) ? Ledger::kind_labels()[ $c->kind ] : $c->kind ),
			'currency'     => $c->currency,
			'amount_minor' => (int) $c->amount_minor,
			'amount'       => Money::to_decimal( (int) $c->amount_minor, $c->currency ),
			'status'       => $c->status,
			'hold_until'   => $c->hold_until,
		);
		if ( $admin ) {
			$data += array(
				'affiliate_id' => (int) $c->affiliate_id,
				'source'       => $c->source,
				'item_id'      => $c->source_item_id,
				'base_minor'   => (int) $c->base_minor,
				'rate_type'    => $c->rate_type,
				'rate'         => $c->rate,
				'rule_id'      => (int) $c->rule_id,
				'parent_id'    => (int) $c->parent_id,
				'payout_id'    => (int) $c->payout_id,
			);
		}
		return $data;
	}

	/**
	 * Commission list (admin).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function commissions( \WP_REST_Request $r ) {
		list( $rows, $total ) = Ledger::query(
			array(
				'affiliate_id' => (int) $r['affiliate_id'],
				'status'       => sanitize_key( (string) $r['status'] ),
				'source'       => sanitize_key( (string) $r['source'] ),
				'order_id'     => sanitize_text_field( (string) $r['order_id'] ),
				'from'         => sanitize_text_field( (string) $r['from'] ),
				'to'           => sanitize_text_field( (string) $r['to'] ),
				'limit'        => (int) $r['per_page'],
				'offset'       => ( (int) $r['page'] - 1 ) * (int) $r['per_page'],
			)
		);
		$out = array();
		foreach ( $rows as $c ) {
			$out[] = self::commission_data( $c, true );
		}
		return self::paged( $out, $total );
	}

	/**
	 * Reports.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	public static function report( \WP_REST_Request $r ) {
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['from'] ) ? (string) $r['from'] : gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['to'] ) ? (string) $r['to'] : gmdate( 'Y-m-d' );
		$rows = 'partners' === $r['view'] ? Reports::partners( $from, $to ) : Reports::by_dimension( 'content' === $r['view'] ? 'content_id' : 'campaign', $from, $to );
		return array(
			'from'        => $from,
			'to'          => $to,
			'currency'    => (string) Settings::get( 'currency' ),
			'unconverted' => array_keys( Reports::$unconverted ),
			'rows'        => $rows,
		);
	}

	/**
	 * Partner summary.
	 *
	 * @return array
	 */
	public static function me() {
		$aff      = self::partner();
		$currency = (string) Settings::get( 'currency' );
		$totals   = Ledger::totals( array( 'affiliate_id' => (int) $aff->id ) );
		$sum      = array();
		foreach ( Ledger::STATUSES as $s ) {
			$sum[ $s ] = isset( $totals[ $s ][ $currency ] ) ? (int) $totals[ $s ][ $currency ] : 0;
		}
		$code = CodeRepository::main_code( (int) $aff->id );
		return array(
			'id'                => (int) $aff->id,
			'name'              => $aff->name,
			'status'            => $aff->status,
			'code'              => $code,
			'link'              => $code ? add_query_arg( (string) Settings::get( 'referral_param' ), $code, home_url( '/' ) ) : '',
			'landing_url'       => Landing::url( $aff ),
			'currency'          => $currency,
			'totals_minor'      => $sum,
			'available_minor'   => PayoutRequests::available( (int) $aff->id ),
			'min_payout_minor'  => (int) Settings::get( 'min_payout_minor' ),
			'codes'             => array_values(
				array_map(
					static function ( $c ) {
						return array(
							'code'   => $c->code,
							'status' => CodeRepository::validate( $c ) ? 'active' : 'inactive',
						);
					},
					CodeRepository::for_affiliate( (int) $aff->id )
				)
			),
		);
	}
}
