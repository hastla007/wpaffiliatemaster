<?php
/**
 * Workflow event layer (provider side for Workflow Captain, AFF-WF-02): for every post-commit
 * action of the core one additional, normalised action wpac_workflow_event( $envelope ).
 *
 * The envelope has a stable ID (UUID version 5 from site, type, object and revision: a replay of
 * the same event has the same ID), a version, the time (UTC), the object and a redacted payload
 * (whitelist per type; never buyer data, bank, tax or payout details, tokens, free texts). See
 * docs/WORKFLOW-PROVIDER.md.
 *
 * Events are notifications only: they never book, approve or pay anything, and a missing or
 * failing listener never affects the ledger. Without a listener on wpac_workflow_event nothing is
 * built (one has_action() per source action).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Promotion\Creatives;

defined( 'ABSPATH' ) || exit;

final class WorkflowEvents {

	/** Version of the envelope (field "version"); a new major version only for breaking changes. */
	const VERSION = '1';

	/** Namespace of the event IDs (UUID version 5). Never change: replays would get new IDs. */
	const UUID_NAMESPACE = '6f1d8f3e-4b8a-5c2e-9a57-0c3b7d2e91a4';

	/** Source action => [ event type, number of arguments ]. */
	const SOURCES = array(
		'wpac_affiliate_created'        => array( 'affiliate.created', 2 ),
		'wpac_affiliate_status_changed' => array( 'affiliate.status_changed', 3 ),
		'wpac_affiliate_recruited'      => array( 'affiliate.recruited', 2 ),
		'wpac_affiliate_erased'         => array( 'affiliate.erased', 2 ),
		'wpac_affiliate_attributed'     => array( 'sale.attributed', 4 ),
		'wpac_commission_created'       => array( 'commission.created', 1 ),
		'wpac_commission_adjusted'      => array( 'commission.adjusted', 2 ),
		'wpac_commission_approved'      => array( 'commission.status_changed', 1 ),
		'wpac_commission_rejected'      => array( 'commission.status_changed', 1 ),
		'wpac_commission_paid'          => array( 'commission.status_changed', 1 ),
		'wpac_commission_pending'       => array( 'commission.status_changed', 1 ),
		'wpac_commission_review'        => array( 'commission.status_changed', 1 ),
		'wpac_payout_created'           => array( 'payout.created', 2 ),
		'wpac_payout_paid'              => array( 'payout.paid', 1 ),
		'wpac_payout_cancelled'         => array( 'payout.cancelled', 1 ),
		'wpac_correction_generated'     => array( 'correction.generated', 1 ),
		'wpac_creative_created'         => array( 'creative.created', 2 ),
		'wpac_creative_updated'         => array( 'creative.updated', 2 ),
		'wpac_code_requested'           => array( 'code_request.created', 3 ),
		'wpac_code_request_approved'    => array( 'code_request.approved', 4 ),
		'wpac_code_request_rejected'    => array( 'code_request.rejected', 4 ),
	);

	/** Fields "data" may contain, per event type (whitelist). */
	const FIELDS = array(
		'affiliate.created'         => array( 'affiliate_id', 'status', 'group_id', 'referred_by' ),
		'affiliate.status_changed'  => array( 'affiliate_id', 'status', 'previous_status', 'group_id' ),
		'affiliate.recruited'       => array( 'affiliate_id', 'recruiter_id' ),
		'affiliate.erased'          => array( 'affiliate_id', 'deferred' ),
		'sale.attributed'           => array( 'source', 'order_id', 'affiliate_id', 'method' ),
		'commission.created'        => array( 'commission_id', 'affiliate_id', 'kind', 'status', 'currency', 'amount_minor', 'source', 'order_id', 'parent_id' ),
		'commission.adjusted'       => array( 'source', 'order_id', 'refund_id', 'refund_type', 'deleted', 'commission_ids', 'affiliate_ids' ),
		'commission.status_changed' => array( 'commission_id', 'affiliate_id', 'kind', 'status', 'currency', 'amount_minor', 'payout_id' ),
		'payout.created'            => array( 'payout_id', 'affiliate_id', 'number', 'status', 'currency', 'amount_minor', 'method' ),
		'payout.paid'               => array( 'payout_id', 'affiliate_id', 'number', 'status', 'currency', 'amount_minor', 'method' ),
		'payout.cancelled'          => array( 'payout_id', 'affiliate_id', 'number', 'status', 'currency', 'amount_minor', 'method' ),
		'correction.generated'      => array( 'correction_id', 'payout_id', 'affiliate_id', 'number', 'currency' ),
		'creative.created'          => array( 'creative_id', 'family_id', 'version', 'status', 'type', 'title', 'group_id' ),
		'creative.updated'          => array( 'creative_id', 'family_id', 'version', 'status', 'type', 'title', 'group_id' ),
		'code_request.created'      => array( 'code_request_id', 'affiliate_id', 'code' ),
		'code_request.approved'     => array( 'code_request_id', 'affiliate_id', 'code', 'main' ),
		'code_request.rejected'     => array( 'code_request_id', 'affiliate_id', 'code' ),
	);

	/**
	 * Keys that never appear anywhere in an envelope (defence in depth behind the whitelist):
	 * buyer and partner contact data, bank/tax/payout details, secrets, free texts, file paths.
	 */
	const FORBIDDEN = array( 'email', 'customer', 'customer_email', 'name', 'legal_name', 'address', 'postcode', 'city', 'phone', 'iban', 'bic', 'account_holder', 'tax_id', 'vat_id', 'tax_status', 'payment_details', 'paypal_email', 'profile', 'token', 'secret', 'password', 'claim', 'review', 'review_text', 'description', 'reason', 'note', 'reference', 'path', 'file', 'ip', 'user_agent', 'application_data' );

	/** @var bool */
	private static $registered = false;

	/**
	 * Listen to the source actions (late, after the core's own listeners).
	 */
	public static function register_hooks() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		foreach ( self::SOURCES as $action => $spec ) {
			add_action(
				$action,
				static function ( ...$args ) use ( $action ) {
					self::handle( $action, $args );
				},
				1000,
				$spec[1]
			);
		}
	}

	/**
	 * Event types and their data fields (for discovery, wpac_workflow_event_types()).
	 *
	 * @return array type => fields
	 */
	public static function types() {
		return self::FIELDS;
	}

	/**
	 * Whether events are built at all: someone listens and the off-switch is not set.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! has_action( 'wpac_workflow_event' ) ) {
			return false; // Cheap: no listener, nothing built.
		}
		/**
		 * Off-switch of the workflow events (AFF-WF-02).
		 *
		 * @since 0.15.x
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'wpac_workflow_events_enabled', true );
	}

	/**
	 * A source action fired: dispatch its event after the COMMIT (dropped on rollback).
	 *
	 * @param string $action Source action.
	 * @param array  $args   Its arguments.
	 */
	public static function handle( $action, array $args ) {
		if ( ! isset( self::SOURCES[ $action ] ) || ! self::enabled() ) {
			return;
		}
		Db::after_commit(
			static function () use ( $action, $args ) {
				self::dispatch( $action, $args );
			}
		);
	}

	/**
	 * Build and fire the envelope. A failing listener is logged, never passed on.
	 *
	 * @param string $action Source action.
	 * @param array  $args   Its arguments.
	 */
	public static function dispatch( $action, array $args ) {
		try {
			$envelope = self::envelope( $action, $args );
		} catch ( \Throwable $e ) {
			Log::exception( 'Workflow event could not be built', $e, array( 'action' => $action ) );
			return;
		}
		if ( null === $envelope ) {
			return;
		}
		try {
			/**
			 * Normalised event for Workflow Captain and other automation (docs/WORKFLOW-PROVIDER.md).
			 * Fires after the COMMIT; never fires for a rolled-back booking. Notification only:
			 * listeners must not book commissions or start payouts from it.
			 *
			 * @since 0.15.x
			 * @param array $envelope id, type, version, occurred_at, site_id, source, object, data.
			 */
			do_action( 'wpac_workflow_event', $envelope );
		} catch ( \Throwable $e ) {
			Log::exception( 'Workflow event listener failed', $e, array( 'type' => $envelope['type'] ) );
		}
	}

	/**
	 * Envelope of a source action, or null when its object is gone.
	 *
	 * @param string $action Source action.
	 * @param array  $args   Its arguments.
	 * @return array|null
	 */
	public static function envelope( $action, array $args ) {
		if ( ! isset( self::SOURCES[ $action ] ) ) {
			return null;
		}
		$type  = self::SOURCES[ $action ][0];
		$built = self::build( $action, $type, array_pad( $args, self::SOURCES[ $action ][1], null ) );
		if ( null === $built ) {
			return null;
		}
		list( $object_type, $object_id, $revision, $data ) = $built;
		$data = self::whitelist( $type, $data );
		/**
		 * Redact the data of a workflow event further. Only removing fields has an effect: keys
		 * that were not there and changed values are ignored.
		 *
		 * @since 0.15.x
		 * @param array  $data Data.
		 * @param string $type Event type.
		 */
		$filtered = apply_filters( 'wpac_workflow_event_data', $data, $type );
		$data     = is_array( $filtered ) ? array_intersect_key( $data, $filtered ) : array();
		$site     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return array(
			'id'          => self::uuid( $site . '|' . $type . '|' . $object_type . '|' . $object_id . '|' . $revision ),
			'type'        => $type,
			'version'     => self::VERSION,
			'occurred_at' => Db::now(),
			'site_id'     => $site,
			'source'      => 'affiliate-captain',
			'object'      => array(
				'type' => $object_type,
				'id'   => (string) $object_id,
			),
			'data'        => self::strip( $data ),
		);
	}

	/**
	 * Object, revision and raw data per source action.
	 *
	 * @param string $action Source action.
	 * @param string $type   Event type.
	 * @param array  $a      Arguments.
	 * @return array|null [ object type, object id, revision, data ]
	 */
	private static function build( $action, $type, array $a ) {
		switch ( $type ) {
			case 'affiliate.created':
			case 'affiliate.status_changed':
				$id  = (int) $a[0];
				$aff = AffiliateRepository::find( $id );
				if ( ! $aff ) {
					return null;
				}
				$data = array(
					'affiliate_id' => $id,
					'status'       => (string) $a[1],
					'group_id'     => (int) $aff->group_id,
					'referred_by'  => isset( $aff->referred_by ) ? (int) $aff->referred_by : 0,
				);
				if ( 'affiliate.created' === $type ) {
					return array( 'affiliate', $id, 'created', $data );
				}
				$data['previous_status'] = (string) $a[2];
				return array( 'affiliate', $id, (string) $a[2] . '>' . (string) $a[1] . '@' . (string) $aff->updated_at, $data );
			case 'affiliate.recruited':
				return array(
					'affiliate',
					(int) $a[0],
					'recruited',
					array(
						'affiliate_id' => (int) $a[0],
						'recruiter_id' => (int) $a[1],
					),
				);
			case 'affiliate.erased':
				// Only the ID: the partner's personal data are gone.
				return array(
					'affiliate',
					(int) $a[0],
					$a[1] ? 'erased-deferred' : 'erased',
					array(
						'affiliate_id' => (int) $a[0],
						'deferred'     => (bool) $a[1],
					),
				);
			case 'sale.attributed':
				$order = (string) $a[3] . ':' . (string) $a[0];
				return array(
					'order',
					$order,
					'attributed-' . (int) $a[1],
					array(
						'source'       => (string) $a[3],
						'order_id'     => (string) $a[0],
						'affiliate_id' => (int) $a[1],
						'method'       => (string) $a[2],
					),
				);
			case 'commission.created':
			case 'commission.status_changed':
				$c = PublicApi::get_commission( (int) $a[0] );
				if ( ! $c ) {
					return null;
				}
				$data = array(
					'commission_id' => (int) $c['id'],
					'affiliate_id'  => (int) $c['affiliate_id'],
					'kind'          => (string) $c['kind'],
					'status'        => (string) $c['status'],
					'currency'      => (string) $c['currency'],
					'amount_minor'  => (int) $c['amount_minor'],
					'source'        => (string) $c['source'],
					'order_id'      => (string) $c['order_id'],
					'parent_id'     => (int) $c['parent_id'],
					'payout_id'     => (int) $c['payout_id'],
				);
				if ( 'commission.created' === $type ) {
					return array( 'commission', (int) $c['id'], 'created', $data );
				}
				// The status of the source action (the row may have moved on since).
				$data['status'] = substr( $action, strlen( 'wpac_commission_' ) );
				return array( 'commission', (int) $c['id'], $data['status'] . '@' . (string) $c['updated_at'], $data );
			case 'commission.adjusted':
				$refund = is_array( $a[0] ) ? $a[0] : array();
				$ids    = array_values( array_map( 'intval', (array) $a[1] ) );
				$affs   = array();
				foreach ( $ids as $cid ) {
					$c = PublicApi::get_commission( $cid );
					if ( $c ) {
						$affs[ (int) $c['affiliate_id'] ] = true;
					}
				}
				$src = isset( $refund['source'] ) ? (string) $refund['source'] : '';
				$ord = isset( $refund['order_id'] ) ? (string) $refund['order_id'] : '';
				$rid = isset( $refund['refund_id'] ) ? (string) $refund['refund_id'] : '';
				$del = ! empty( $refund['deleted'] );
				return array(
					'refund',
					$src . ':' . $ord . ':' . $rid,
					( $del ? 'deleted:' : 'booked:' ) . implode( ',', $ids ),
					array(
						'source'         => $src,
						'order_id'       => $ord,
						'refund_id'      => $rid,
						'refund_type'    => isset( $refund['type'] ) ? (string) $refund['type'] : 'refund',
						'deleted'        => $del,
						'commission_ids' => $ids,
						'affiliate_ids'  => array_keys( $affs ),
					),
				);
			case 'payout.created':
			case 'payout.paid':
			case 'payout.cancelled':
				$p = PayoutService::find( (int) $a[0] );
				if ( ! $p ) {
					return null;
				}
				// No e-mail lookup: the PayPal address is never part of an event.
				$v = PayoutService::contract_view( $p, array( (int) $p->affiliate_id => '' ) );
				return array(
					'payout',
					(int) $p->id,
					substr( $type, strlen( 'payout.' ) ),
					array(
						'payout_id'    => (int) $v['id'],
						'affiliate_id' => (int) $v['affiliate_id'],
						'number'       => (string) $v['number'],
						'status'       => (string) $v['status'],
						'currency'     => (string) $v['currency'],
						'amount_minor' => (int) $v['amount_minor'],
						'method'       => (string) $v['method'],
					),
				);
			case 'correction.generated':
				$k = Corrections::find( (int) $a[0] );
				if ( ! $k ) {
					return null;
				}
				return array(
					'correction',
					(int) $k->id,
					'generated',
					array(
						'correction_id' => (int) $k->id,
						'payout_id'     => (int) $k->payout_id,
						'affiliate_id'  => (int) $k->affiliate_id,
						'number'        => (string) $k->number,
						'currency'      => (string) $k->currency,
					),
				);
			case 'creative.created':
			case 'creative.updated':
				$info = is_array( $a[1] ) && isset( $a[1]['status'] ) ? $a[1] : Creatives::hook_info( (int) $a[0] );
				if ( ! isset( $info['status'] ) ) {
					return null;
				}
				return array(
					'creative',
					(int) $a[0],
					'creative.created' === $type ? 'created' : (string) $info['status'],
					array(
						'creative_id' => (int) $a[0],
						'family_id'   => (int) $info['family_id'],
						'version'     => (int) $info['version'],
						'status'      => (string) $info['status'],
						'type'        => (string) $info['type'],
						'title'       => (string) $info['title'],
						'group_id'    => (int) $info['group_id'],
					),
				);
			case 'code_request.created':
			case 'code_request.approved':
			case 'code_request.rejected':
				// Arguments: created (partner, code, row), approved (partner, code, row, make_main),
				// rejected (partner, code, reason, row – the row since 0.15.x).
				$row  = 'code_request.rejected' === $type ? (int) $a[3] : (int) $a[2];
				$step = substr( $type, strlen( 'code_request.' ) );
				$data = array(
					'code_request_id' => $row,
					'affiliate_id'    => (int) $a[0],
					'code'            => (string) $a[1],
				);
				if ( 'code_request.approved' === $type ) {
					$data['main'] = (bool) $a[3];
				}
				// Old callers of wpac_code_request_rejected without the row ID: the code identifies it.
				$object = $row ? $row : (int) $a[0] . ':' . (string) $a[1];
				return array( 'code_request', $object, $step, $data );
		}
		return null;
	}

	/**
	 * Keep only the whitelisted fields of a type.
	 *
	 * @param string $type Event type.
	 * @param array  $data Data.
	 * @return array
	 */
	private static function whitelist( $type, array $data ) {
		return isset( self::FIELDS[ $type ] ) ? array_intersect_key( $data, array_flip( self::FIELDS[ $type ] ) ) : array();
	}

	/**
	 * Remove forbidden keys at any depth.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function strip( array $data ) {
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), self::FORBIDDEN, true ) ) {
				unset( $data[ $k ] );
			} elseif ( is_array( $v ) ) {
				$data[ $k ] = self::strip( $v );
			}
		}
		return $data;
	}

	/**
	 * UUID version 5 (RFC 4122) of a name in the plugin's namespace: deterministic, so a replay
	 * of the same event gets the same ID.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function uuid( $name ) {
		$ns   = pack( 'H*', str_replace( '-', '', self::UUID_NAMESPACE ) );
		$hash = sha1( $ns . $name );
		return sprintf(
			'%08s-%04s-%04x-%04x-%12s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
			substr( $hash, 20, 12 )
		);
	}
}
