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
		// "consent": the visitor gave marketing consent after the landing; the referral is stored
		// again (cookie), the visit was already counted. Available whenever cookies need consent.
		$consent_only = ! empty( $r['consent'] );
		if ( ! Settings::get( 'js_tracking' ) && ! ( $consent_only && 'consent' === Settings::get( 'cookie_mode' ) ) ) {
			return new \WP_Error( 'wpac_disabled', __( 'JavaScript tracking is disabled.', 'affiliate-captain' ), array( 'status' => 404 ) );
		}
		$carry = (string) $r['carry'];
		$body  = array( 'ok' => true ); // Same answer for unknown codes (no enumeration).
		// Without consent the landing page carries the referral in its links (Tracker::carry_token()).
		// The token is made from the code as sent, also for unknown codes (same answer).
		if ( ! $consent_only && '' === $carry && \WPAffiliateCaptain\Tracking\Tracker::carry_wanted() ) {
			$body['carry'] = \WPAffiliateCaptain\Tracking\Tracker::carry_token(
				sanitize_text_field( (string) $r['code'] ),
				\WPAffiliateCaptain\Tracking\Tracker::clean_utm( (string) $r['campaign'] ),
				\WPAffiliateCaptain\Tracking\Tracker::clean_sub( (string) $r['sub'] ),
				time()
			);
		}
		$res = rest_ensure_response( $body );
		$res->header( 'Cache-Control', 'no-store' );
		// Only pages of this shop report (same answer otherwise). No per-IP limit here: behind a CDN all
		// visitors share one address and the referral must never be dropped – visit statistics are
		// limited per client in Tracker::capture_code(), unknown codes write nothing.
		if ( ! self::own_origin( (string) $r->get_header( 'origin' ) ) ) {
			return $res;
		}
		if ( '' !== $carry ) {
			// A later page reports the consent for a carried referral: only the signed token counts
			// (never a new visit); an expired or forged token is ignored.
			$carried = \WPAffiliateCaptain\Tracking\Tracker::carry_enabled() ? \WPAffiliateCaptain\Tracking\Tracker::parse_carry( $carry ) : null;
			if ( $carried ) {
				if ( function_exists( 'WC' ) && function_exists( 'wc_load_cart' ) && ! WC()->session && ! \WPAffiliateCaptain\Tracking\Tracker::has_login_cookie() ) {
					wc_load_cart();
				}
				\WPAffiliateCaptain\Tracking\Tracker::capture_code(
					$carried['code'],
					$carried['campaign'],
					0,
					array(
						'sub_id' => $carried['sub_id'],
						'ts'     => $carried['ts'],
						'count'  => false,
					)
				);
			}
			return $res;
		}
		// REST requests do not load the WooCommerce session: without it the touch of a visitor with a
		// cart would not reach the checkout. Not for logged-in customers: without a nonce the REST
		// request runs as a guest, and WooCommerce would end (empty) their session.
		if ( function_exists( 'WC' ) && function_exists( 'wc_load_cart' ) && ! WC()->session && ! \WPAffiliateCaptain\Tracking\Tracker::has_login_cookie() ) {
			wc_load_cart();
		}
		$path = wp_parse_url( (string) $r['path'], PHP_URL_PATH );
		// Only the host of the previous page is kept (never its path or query, which may contain personal data).
		$ref  = trim( (string) $r['referrer'] );
		$host = false !== strpos( $ref, '://' ) ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : $ref;
		$host = preg_match( '/^[a-z0-9.-]{1,253}$/i', $host ) ? strtolower( $host ) : '';
		\WPAffiliateCaptain\Tracking\Tracker::capture_code(
			sanitize_text_field( (string) $r['code'] ),
			\WPAffiliateCaptain\Tracking\Tracker::clean_utm( (string) $r['campaign'] ),
			absint( $r['content_id'] ),
			array(
				'utm_source' => \WPAffiliateCaptain\Tracking\Tracker::clean_utm( (string) $r['utm_source'] ),
				'utm_medium' => \WPAffiliateCaptain\Tracking\Tracker::clean_utm( (string) $r['utm_medium'] ),
				'sub_id'     => \WPAffiliateCaptain\Tracking\Tracker::clean_sub( (string) $r['sub'] ),
				'path'       => is_string( $path ) ? $path : '',
				'referrer'   => '' !== $host ? 'https://' . $host . '/' : '',
				'count'      => ! $consent_only,
			)
		);
		return $res;
	}

	/**
	 * Whether a beacon request comes from a page of this shop (Origin header of this host or
	 * inside the cookie domain). Requests without Origin (older browsers) are accepted.
	 *
	 * @param string $origin Origin header.
	 * @return bool
	 */
	public static function own_origin( $origin ) {
		if ( '' === $origin ) {
			return true;
		}
		$ok = \WPAffiliateCaptain\Tracking\Tracker::is_own_host( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		/**
		 * Whether a beacon from this origin is accepted (e.g. a headless frontend on another domain).
		 *
		 * @param bool   $ok     Decision.
		 * @param string $origin Origin header.
		 */
		return (bool) apply_filters( 'wpac_touch_allowed_origin', $ok, $origin );
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
			return new \WP_Error( 'wpac_forbidden', __( 'Not allowed.', 'affiliate-captain' ), array( 'status' => 403 ) );
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
					// As lenient as the page request: the code is normalised when it is resolved
					// (e.g. "ANNA." from a link at the end of a sentence).
					'code'       => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 100,
					),
					'campaign'   => array( 'type' => 'string' ),
					'utm_source' => array( 'type' => 'string' ),
					'utm_medium' => array( 'type' => 'string' ),
					'sub'        => array( 'type' => 'string' ),
					'content_id' => array( 'type' => 'integer' ),
					'path'       => array( 'type' => 'string' ),
					'referrer'   => array( 'type' => 'string' ),
					'consent'    => array( 'type' => 'boolean' ),
					// Signed referral carried in the links of a landing page until consent.
					'carry'      => array(
						'type'      => 'string',
						'maxLength' => 600,
					),
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
				'args'                => self::contract_args(
					array(
						'currency' => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[A-Za-z]{3}$',
						),
						'items'    => array(
							'type'     => 'array',
							'required' => true,
							'minItems' => 1,
							'maxItems' => ContractApi::MAX_ITEMS,
							'items'    => array( 'type' => 'object' ),
						),
					)
				),
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
				'args'                => self::contract_args(
					array(
						'refund_id' => array(
							'type'      => array( 'string', 'integer' ),
							'required'  => true,
							'maxLength' => 64,
						),
						'items'     => array(
							'type'     => 'array',
							'maxItems' => ContractApi::MAX_ITEMS,
							'items'    => array( 'type' => 'object' ),
						),
					)
				),
			)
		);
		register_rest_route(
			self::NS,
			'/reinstatements',
			array(
				'methods'             => 'POST',
				'permission_callback' => self::can( Capabilities::MANAGE_PROGRAM ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					return self::result( ContractApi::record_reinstatement( (array) $r->get_json_params() ) );
				},
				'args'                => self::contract_args(
					array(
						'refund_id' => array(
							'type'      => array( 'string', 'integer' ),
							'maxLength' => 64,
						),
					)
				),
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
					return $a ? self::affiliate_data( $a, true ) : new \WP_Error( 'wpac_not_found', __( 'Not found.', 'affiliate-captain' ), array( 'status' => 404 ) );
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
	 * Schema of the contract routes (the contract validates everything again in detail).
	 *
	 * @param array $extra Route specific args.
	 * @return array
	 */
	private static function contract_args( array $extra ) {
		return $extra + array(
			'contract' => array(
				'type'      => 'string',
				'maxLength' => 20,
			),
			'source'   => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 40,
			),
			'order_id' => array(
				'type'      => array( 'string', 'integer' ),
				'required'  => true,
				'maxLength' => 64,
			),
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
			if ( 'wpac_not_queued' === $result->get_error_code() ) {
				// Server problem (e.g. database unavailable): the sender must try again later.
				$res = new \WP_REST_Response(
					array(
						'code'    => 'wpac_not_queued',
						'message' => $result->get_error_message(),
						'data'    => array( 'status' => 503 ),
					),
					503
				);
				$res->header( 'Retry-After', '300' );
				return $res;
			}
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		if ( is_array( $result ) && isset( $result['status'] ) && in_array( $result['status'], array( 'queued', 'parked' ), true ) ) {
			// Stored for later processing (waiting for its sale): the same 202 as a queued sale.
			return new \WP_REST_Response( $result, 202 );
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
		// Public GET: never from a CDN/proxy cache (a cached "valid" would outlive a deactivated code).
		if ( ! RateLimiter::allow( 'rest_code', 20, MINUTE_IN_SECONDS ) ) {
			$res = new \WP_Error( 'wpac_rate_limited', __( 'Too many attempts. Please wait a minute and try again.', 'affiliate-captain' ), array( 'status' => 429 ) );
			if ( function_exists( 'rest_convert_error_to_response' ) ) {
				$res = rest_convert_error_to_response( $res );
			}
		} else {
			$res = rest_ensure_response( array( 'valid' => (bool) wpac_resolve_partner_code( (string) $r['code'], array( 'context' => 'rest' ) ) ) );
		}
		if ( $res instanceof \WP_REST_Response ) {
			$res->header( 'Cache-Control', 'no-store, private' );
		}
		return $res;
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
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['from'] ) ? (string) $r['from'] : wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['to'] ) ? (string) $r['to'] : wp_date( 'Y-m-d' );
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
			// All booking currencies (orders in USD are booked in USD): status => currency => minor.
			'totals_by_currency'    => $totals,
			'available_by_currency' => array_map(
				static function ( $a ) {
					return (int) $a['gross'];
				},
				PayoutRequests::available_all( (int) $aff->id )
			),
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
