<?php
/**
 * WordPress personal data exporter/eraser for affiliate data.
 *
 * Financial records (commissions, payouts) are retained for bookkeeping and
 * reported as retained; personal profile fields and bank details are erased
 * when no unpaid balance requires them.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Privacy;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public static function register_hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
		add_action( self::VISITS_ERASE_HOOK, array( __CLASS__, 'visit_erasure_event' ), 10, 0 );
	}

	/** Single event that continues deleting the link visits of erased partners (R14-D3). */
	const VISITS_ERASE_HOOK = 'wpac_erase_visits';

	/** Option: partner IDs whose link visits are still being deleted. */
	const VISITS_ERASE_OPTION = 'wpac_visit_erasure_pending';

	/** Transient prefix: partner whose erasure request (by e-mail hash) still deletes visits. */
	const VISITS_ERASE_REQUEST = 'wpac_erase_req_';

	/**
	 * Delete the link visits of a partner in short transactions within a time budget (R14-D3):
	 * one DELETE of millions of rows ran for many minutes in one transaction (the erasure request
	 * in admin-ajax timed out, a second click started the next DELETE, a cancelled one rolled
	 * back for as long). What is left is deleted by a single event a few minutes later; the
	 * partner is listed in VISITS_ERASE_OPTION until done.
	 *
	 * @param int   $affiliate_id Partner.
	 * @param float $budget       Seconds (0 = until done).
	 * @return bool Whether no visit of the partner is left.
	 */
	public static function delete_partner_visits( $affiliate_id, $budget = 10.0 ) {
		global $wpdb;
		$affiliate_id = (int) $affiliate_id;
		/**
		 * Visits deleted per statement when a partner is erased (one short transaction each).
		 *
		 * @since 0.14.0
		 * @param int $batch Rows (default 5000).
		 */
		$batch = max( 1, (int) apply_filters( 'wpac_visit_erase_batch', 5000 ) );
		$done  = false;
		$table = Db::table( 'visits' );
		$deadline = $budget > 0 ? microtime( true ) + (float) $budget : 0.0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		\WPAffiliateCaptain\Core\Cron::delete_in_batches( $wpdb->prepare( "DELETE FROM {$table} WHERE affiliate_id = %d", $affiliate_id ), $batch, 0, $deadline, $done );
		if ( $done ) {
			// Then the partner's rows of the daily aggregate (R14-D9; the backfill skips partners
			// listed here while their raw visits are deleted).
			$done = \WPAffiliateCaptain\Reports\VisitsDaily::delete_partner( $affiliate_id, $batch, $deadline );
		}
		$pending = array_map( 'intval', (array) get_option( self::VISITS_ERASE_OPTION, array() ) );
		$listed  = in_array( $affiliate_id, $pending, true );
		if ( $done && $listed ) {
			$pending = array_values( array_diff( $pending, array( $affiliate_id ) ) );
			if ( $pending ) {
				update_option( self::VISITS_ERASE_OPTION, $pending, false );
			} else {
				delete_option( self::VISITS_ERASE_OPTION );
			}
		} elseif ( ! $done ) {
			if ( ! $listed ) {
				$pending[] = $affiliate_id;
				update_option( self::VISITS_ERASE_OPTION, $pending, false );
			}
			\WPAffiliateCaptain\Core\Scheduler::once( self::VISITS_ERASE_HOOK, 3 * MINUTE_IN_SECONDS );
		}
		return $done;
	}

	/**
	 * Hook callback of VISITS_ERASE_HOOK (actions return nothing): see continue_visit_erasure().
	 */
	public static function visit_erasure_event() {
		self::continue_visit_erasure();
	}

	/**
	 * Continue deleting the visits of erased partners (single event, daily backup run).
	 *
	 * @return bool Whether nothing is left.
	 */
	public static function continue_visit_erasure() {
		/**
		 * Seconds one background run may delete visits of erased partners.
		 *
		 * @since 0.14.0
		 * @param float $budget Seconds (default 20, 0 = until done).
		 */
		$budget = (float) apply_filters( 'wpac_visit_erase_budget', 20 );
		$end    = $budget > 0 ? microtime( true ) + $budget : 0.0;
		foreach ( array_map( 'intval', (array) get_option( self::VISITS_ERASE_OPTION, array() ) ) as $id ) {
			$left = $end > 0 ? $end - microtime( true ) : 0.0;
			if ( $end > 0 && $left <= 0 ) {
				\WPAffiliateCaptain\Core\Scheduler::once( self::VISITS_ERASE_HOOK, 3 * MINUTE_IN_SECONDS );
				return false;
			}
			if ( ! self::delete_partner_visits( $id, $end > 0 ? max( 0.001, $left ) : 0.0 ) ) {
				return false; // Rescheduled by delete_partner_visits().
			}
		}
		return ! get_option( self::VISITS_ERASE_OPTION );
	}

	/**
	 * Find affiliate by e-mail (affiliate record or linked user).
	 *
	 * @param string $email E-mail.
	 * @return object|null
	 */
	private static function affiliate( $email ) {
		$aff = AffiliateRepository::find_by_email( $email );
		if ( ! $aff ) {
			$user = get_user_by( 'email', $email );
			$aff  = $user ? AffiliateRepository::find_by_user( $user->ID ) : null;
		}
		return $aff;
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['affiliate-captain'] = array(
			'exporter_friendly_name' => __( 'Affiliate Captain partner data', 'affiliate-captain' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['affiliate-captain'] = array(
			'eraser_friendly_name' => __( 'Affiliate Captain partner data', 'affiliate-captain' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** Items of the long lists (commissions, attributions, payouts, account entries) per export page. */
	const EXPORT_CHUNK = 500;

	/**
	 * Export (Art. 15 GDPR), in pages: page 1 holds the account, profile, codes, terms, requests,
	 * objections, VIES result, reminders and the customer data, and every page carries up to
	 * EXPORT_CHUNK entries of the long lists (all commissions, attributions, payouts and account
	 * entries – nothing is cut off). WordPress calls the next page until done is true.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page (1-based).
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$user  = get_user_by( 'email', $email );
		$aff   = self::affiliate( $email );
		$items = 1 === $page ? self::export_base( $email, $user, $aff ) : array();
		$lists = self::export_lists( $email, $user, $aff );
		$start = ( $page - 1 ) * self::EXPORT_CHUNK;
		$end   = $start + self::EXPORT_CHUNK;
		$pos   = 0;
		foreach ( $lists as $list ) {
			$count = (int) $list['count'];
			$from  = max( $start, $pos );
			$to    = min( $end, $pos + $count );
			if ( $from < $to ) {
				foreach ( self::export_rows( $list, $from - $pos, $to - $from ) as $row ) {
					$items[] = call_user_func( $list['item'], $row );
				}
			}
			$pos += $count;
		}
		return array(
			'data' => $items,
			'done' => $end >= $pos,
		);
	}

	/**
	 * Rows of an export list.
	 *
	 * @param array $list   List (table, where, params).
	 * @param int   $offset Offset.
	 * @param int   $limit  Limit.
	 * @return array
	 */
	private static function export_rows( array $list, $offset, $limit ) {
		global $wpdb;
		$params   = $list['params'];
		$params[] = (int) $limit;
		$params[] = (int) $offset;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and WHERE with placeholders built in export_lists(); $params holds one value per placeholder.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $list['table'] . ' WHERE ' . $list['where'] . ' ORDER BY id ASC LIMIT %d OFFSET %d', $params ) );
	}

	/**
	 * Orders of a customer: the orders that created a lifetime link and, with WooCommerce, the
	 * orders of the customer account and billing address.
	 *
	 * @param string        $email E-mail.
	 * @param \WP_User|bool $user  User.
	 * @param array         $links Customer links.
	 * @return string[] Order IDs (WooCommerce source).
	 */
	private static function customer_orders( $email, $user, array $links ) {
		$ids = array();
		foreach ( $links as $l ) {
			if ( \WPAffiliateCaptain\Integrations\WooCommerce::SOURCE === (string) $l->source && '' !== (string) $l->source_order_id ) {
				$ids[] = (string) $l->source_order_id;
			}
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			$queries = array( array( 'billing_email' => $email ) );
			if ( $user ) {
				$queries[] = array( 'customer_id' => (int) $user->ID );
			}
			foreach ( $queries as $q ) {
				foreach ( (array) wc_get_orders( $q + array( 'return' => 'ids', 'limit' => -1, 'type' => 'shop_order' ) ) as $id ) {
					$ids[] = (string) (int) $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The long lists of the export with their size.
	 *
	 * @param string        $email E-mail.
	 * @param \WP_User|bool $user  User.
	 * @param object|null   $aff   Partner.
	 * @return array[] table, where, params, count, item (callable row => export item).
	 */
	private static function export_lists( $email, $user, $aff ) {
		global $wpdb;
		$lists = array();
		if ( $aff ) {
			$id   = (int) $aff->id;
			$defs = array(
				'commissions'     => array( __CLASS__, 'commission_item' ),
				'attributions'    => array( __CLASS__, 'attribution_item' ),
				'payouts'         => array( __CLASS__, 'payout_item' ),
				'payout_corrections' => array( __CLASS__, 'correction_item' ),
				'balance_entries' => array( __CLASS__, 'balance_entry_item' ),
			);
			foreach ( $defs as $table => $item ) {
				$lists[] = array(
					'table'  => Db::table( $table ),
					'where'  => 'affiliate_id = %d',
					'params' => array( $id ),
					'item'   => $item,
				);
			}
			// Reasons of status changes of the partner's commissions (e.g. why one was rejected).
			$lists[] = array(
				'table'  => Db::table( 'commission_events' ),
				'where'  => "reason <> '' AND commission_id IN (SELECT id FROM " . Db::table( 'commissions' ) . ' WHERE affiliate_id = %d)',
				'params' => array( $id ),
				'item'   => array( __CLASS__, 'commission_event_item' ),
			);
			// Change log entries about the partner (account, payout details, codes, terms …).
			$lists[] = array(
				'table'  => Db::table( 'audit' ),
				'where'  => "object_type = 'affiliate' AND object_id = %d AND action IN (" . implode( ',', array_fill( 0, count( self::audit_export_fields() ), '%s' ) ) . ')',
				'params' => array_merge( array( $id ), array_keys( self::audit_export_fields() ) ),
				'item'   => array( __CLASS__, 'audit_item' ),
			);
		}
		// Customers: the orders attributed to a partner (which partner, how, when).
		$links  = \WPAffiliateCaptain\Tracking\CustomerLinks::for_customer( $user ? (int) $user->ID : 0, $email );
		$orders = self::customer_orders( $email, $user, $links );
		if ( $orders ) {
			$lists[] = array(
				'table'  => Db::table( 'attributions' ),
				'where'  => 'source = %s AND source_order_id IN (' . implode( ',', array_fill( 0, count( $orders ), '%s' ) ) . ')' . ( $aff ? ' AND affiliate_id <> %d' : '' ),
				'params' => array_merge( array( \WPAffiliateCaptain\Integrations\WooCommerce::SOURCE ), $orders, $aff ? array( (int) $aff->id ) : array() ),
				'item'   => array( __CLASS__, 'customer_attribution_item' ),
			);
		}
		foreach ( $lists as $i => $list ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- See export_rows(): the placeholders are in $list['where'].
			$lists[ $i ]['count'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $list['table'] . ' WHERE ' . $list['where'], $list['params'] ) );
		}
		return $lists;
	}

	/**
	 * Export item of a commission.
	 *
	 * @param object $r Row.
	 * @return array
	 */
	public static function commission_item( $r ) {
		// Same mapping as the partner area: refunds, write-offs and manual bookings carry the shop's
		// or the operator's free text (e.g. a refund reason naming the customer, Art. 15(4) GDPR),
		// so only sales and bonuses show their description, everything else its label.
		$kind        = (string) $r->kind;
		$description = in_array( $kind, array( 'sale', 'bonus' ), true ) ? (string) $r->description : sprintf( '%s (%s)', __( 'Correction', 'affiliate-captain' ), \WPAffiliateCaptain\Commissions\Ledger::kind_label( $kind ) );
		return array(
			'group_id'    => 'wpac-commissions',
			'group_label' => __( 'Commissions', 'affiliate-captain' ),
			'item_id'     => 'wpac-commission-' . $r->id,
			'data'        => array(
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $r->created_at ),
				array( 'name' => __( 'Order', 'affiliate-captain' ), 'value' => $r->source . ' #' . $r->source_order_id ),
				array( 'name' => __( 'Type', 'affiliate-captain' ), 'value' => \WPAffiliateCaptain\Commissions\Ledger::kind_label( $kind ) ),
				array( 'name' => __( 'Description', 'affiliate-captain' ), 'value' => $description ),
				array( 'name' => __( 'Commission base', 'affiliate-captain' ), 'value' => Money::format( (int) $r->base_minor, $r->currency ) ),
				array( 'name' => __( 'Amount', 'affiliate-captain' ), 'value' => Money::format( (int) $r->amount_minor, $r->currency ) ),
				array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => $r->status ),
				array( 'name' => __( 'Held until', 'affiliate-captain' ), 'value' => (string) $r->hold_until ),
			),
		);
	}

	/**
	 * Export item of a commission status change with a reason.
	 *
	 * @param object $e Row.
	 * @return array
	 */
	public static function commission_event_item( $e ) {
		return array(
			'group_id'    => 'wpac-commission-events',
			'group_label' => __( 'Commission status changes', 'affiliate-captain' ),
			'item_id'     => 'wpac-commission-event-' . $e->id,
			'data'        => array(
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => (string) $e->created_at ),
				array( 'name' => __( 'Commission', 'affiliate-captain' ), 'value' => '#' . (int) $e->commission_id ),
				array( 'name' => __( 'Event', 'affiliate-captain' ), 'value' => (string) $e->event ),
				array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => trim( (string) $e->from_status . ' → ' . (string) $e->to_status, ' →' ) ),
				array( 'name' => __( 'Reason', 'affiliate-captain' ), 'value' => self::export_event_reason( $e ) ),
			),
		);
	}

	/**
	 * Reason of a commission status change as the partner may see it (Art. 15(4) GDPR): fraud
	 * notes ("review: …", hidden in the partner area as well) and the free texts of refunds,
	 * increases, write-offs and manual bookings (the shop's refund reason can name the customer)
	 * become a neutral label; a rejection reason is shown as in the partner area.
	 *
	 * @param object $e Event row.
	 * @return string
	 */
	public static function export_event_reason( $e ) {
		$reason = trim( (string) $e->reason );
		if ( '' === $reason ) {
			return '';
		}
		if ( 0 === strpos( $reason, 'review:' ) ) {
			return __( 'Checked by the operator before approval', 'affiliate-captain' );
		}
		$prefix = strtolower( (string) strtok( $reason, ': ' ) );
		$labels = array(
			'refund'                => 'refund',
			'cancellation'          => 'cancellation',
			'chargeback'            => 'chargeback',
			'increase'              => 'increase',
			'reinstatement'         => 'reinstatement',
			'manual'                => 'adjustment',
			'write-off'             => 'writeoff',
			'repayment'             => 'repayment',
			'correction'            => 'correction',
			'tier_bonus'            => 'bonus',
			'tier_bonus_correction' => 'bonus',
		);
		if ( isset( $labels[ $prefix ] ) ) {
			return \WPAffiliateCaptain\Commissions\Ledger::kind_label( $labels[ $prefix ] );
		}
		if ( in_array( (string) $e->event, array( 'payout_assigned', 'payout_released' ), true ) ) {
			// Credit note number only (a cancellation reason is internal).
			return (string) strtok( $reason, ':' );
		}
		if ( 'rejected' === (string) $e->to_status ) {
			return $reason; // Shown in the partner area as well.
		}
		return __( 'Internal note (not shown)', 'affiliate-captain' );
	}

	/**
	 * Export item of a change log entry about the partner.
	 *
	 * @param object $a Row.
	 * @return array
	 */
	public static function audit_item( $a ) {
		$allowed = self::audit_export_fields();
		$details = array();
		if ( isset( $allowed[ (string) $a->action ] ) ) {
			$all = json_decode( (string) $a->details, true );
			foreach ( $allowed[ (string) $a->action ] as $field ) {
				if ( is_array( $all ) && array_key_exists( $field, $all ) ) {
					$details[ $field ] = $all[ $field ];
				}
			}
		}
		return array(
			'group_id'    => 'wpac-audit',
			'group_label' => __( 'Change log entries about you', 'affiliate-captain' ),
			'item_id'     => 'wpac-audit-' . $a->id,
			'data'        => array(
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => (string) $a->created_at ),
				array( 'name' => __( 'Action', 'affiliate-captain' ), 'value' => (string) $a->action ),
				array( 'name' => __( 'Details', 'affiliate-captain' ), 'value' => $details ? (string) wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) : '' ),
			),
		);
	}

	/**
	 * Change log entries the export contains, with the detail fields safe for the partner
	 * (positive list, Art. 15(4) GDPR). Not listed - and so not exported - are e.g. fraud flags
	 * (attribution.flagged), the IDs of other partners with the same IBAN
	 * (payout_profile.iban_shared), order and customer references and database errors.
	 *
	 * @return array action => detail fields
	 */
	public static function audit_export_fields() {
		return array(
			'affiliate.created'                   => array( 'status', 'referred_by' ),
			'affiliate.updated'                   => array( 'fields' ),
			'affiliate.status'                    => array( 'from', 'to', 'reason' ),
			'affiliate.referred_by'               => array( 'from', 'to' ), // Partner IDs of the recruiter (R15-P5).
			'affiliate.email_changed'             => array(),
			'affiliate.public_change_requested'   => array(),
			'affiliate.public_change_approved'    => array(),
			'affiliate.public_change_rejected'    => array(),
			'affiliate.user_link_requested'       => array(),
			'affiliate.user_linked'               => array( 'user' ),
			'affiliate.user_unlinked'             => array( 'user' ),
			'agreement.accepted'                  => array( 'version', 'hash' ),
			'agreement.declined'                  => array( 'version', 'hash' ),
			'code.exported'                       => array( 'count' ),
			'code.requested'                      => array( 'code' ),
			'code.request_withdrawn'              => array( 'code' ),
			'code.request_approved'               => array( 'code' ),
			'code.request_rejected'               => array( 'code', 'reason' ), // Sent to the partner by e-mail.
			'commission.manual'                   => array( 'commission', 'amount', 'currency' ),
			'commission.written_off'              => array( 'amount', 'currency' ),
			'commission.repayment'                => array( 'amount', 'currency' ),
			'commission.dunning'                  => array( 'level', 'amount', 'currency' ),
			'customer_links.removed'              => array( 'count' ),
			'customer_links.unlinked'             => array( 'count' ),
			'mail.subscribed'                     => array( 'type' ),
			'mail.unsubscribed'                   => array( 'type' ),
			'payout_profile.updated'              => array( 'tax_status', 'payout_method', 'iban_changed', 'details_changed' ),
			'payout_profile.password_refused'     => array(),
			'payout_profile.tax_changed'          => array( 'old', 'new' ),
			'payout_profile.tax_change_requested' => array( 'old', 'new' ),
			'payout_profile.tax_change_approved'  => array( 'old', 'new' ),
			'payout_profile.tax_change_rejected'  => array( 'new' ),
			'payout_profile.vies_checked'         => array( 'status', 'consultation' ),
			'payout_request.created'              => array( 'amount', 'currency' ),
			'payout_request.rejected'             => array( 'reason' ), // Sent to the partner by e-mail.
			'privacy.request'                     => array( 'type' ),
			'privacy.restricted'                  => array( 'kind' ),
			'privacy.restriction_lifted'          => array(),
			'privacy.profile_deleted'             => array(),
			'privacy.erased'                      => array(),
			'privacy.retention_expired'           => array(),
			'privacy.retention_anonymised'        => array(),
		);
	}

	/**
	 * Export item of an order attributed to the partner.
	 *
	 * @param object $a Row.
	 * @return array
	 */
	public static function attribution_item( $a ) {
		return array(
			'group_id'    => 'wpac-attributions',
			'group_label' => __( 'Attributed orders', 'affiliate-captain' ),
			'item_id'     => 'wpac-attribution-' . $a->id,
			'data'        => array(
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $a->created_at ),
				array( 'name' => __( 'Order', 'affiliate-captain' ), 'value' => $a->source . ' #' . $a->source_order_id ),
				array( 'name' => __( 'Method', 'affiliate-captain' ), 'value' => (string) $a->method ),
				array( 'name' => __( 'Campaign', 'affiliate-captain' ), 'value' => (string) $a->campaign ),
				array( 'name' => __( 'Note', 'affiliate-captain' ), 'value' => isset( $a->note ) ? (string) $a->note : '' ),
			),
		);
	}

	/**
	 * Export item of an order of the person as a customer that was attributed to a partner.
	 *
	 * @param object $a Row.
	 * @return array
	 */
	public static function customer_attribution_item( $a ) {
		return array(
			'group_id'    => 'wpac-customer-attributions',
			'group_label' => __( 'Your orders credited to a partner', 'affiliate-captain' ),
			'item_id'     => 'wpac-customer-attribution-' . $a->id,
			'data'        => array(
				array( 'name' => __( 'Order', 'affiliate-captain' ), 'value' => '#' . $a->source_order_id ),
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $a->created_at ),
				array( 'name' => __( 'Partner ID', 'affiliate-captain' ), 'value' => (string) $a->affiliate_id ),
				array( 'name' => __( 'Method', 'affiliate-captain' ), 'value' => (string) $a->method ),
				array( 'name' => __( 'Campaign', 'affiliate-captain' ), 'value' => (string) $a->campaign ),
				array( 'name' => __( 'Marketing consent at the time', 'affiliate-captain' ), 'value' => (int) $a->consent ? __( 'yes', 'affiliate-captain' ) : __( 'no', 'affiliate-captain' ) ),
			),
		);
	}

	/**
	 * Export item of a payout.
	 *
	 * @param object $p Row.
	 * @return array
	 */
	public static function payout_item( $p ) {
		return array(
			'group_id'    => 'wpac-payouts',
			'group_label' => __( 'Payouts', 'affiliate-captain' ),
			'item_id'     => 'wpac-payout-' . $p->id,
			'data'        => array(
				array( 'name' => _x( 'Number', 'document number', 'affiliate-captain' ), 'value' => $p->number ),
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $p->created_at ),
				array( 'name' => __( 'Net amount', 'affiliate-captain' ), 'value' => Money::format( (int) $p->net_minor, $p->currency ) ),
				array( 'name' => __( 'VAT', 'affiliate-captain' ), 'value' => Money::format( (int) $p->vat_minor, $p->currency ) ),
				array( 'name' => __( 'Amount', 'affiliate-captain' ), 'value' => Money::format( (int) $p->amount_minor, $p->currency ) ),
				array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => $p->status ),
				array( 'name' => __( 'Paid on', 'affiliate-captain' ), 'value' => (string) $p->paid_at ),
				array( 'name' => __( 'Cancellation number', 'affiliate-captain' ), 'value' => (string) $p->storno_number ),
				array( 'name' => __( 'Note', 'affiliate-captain' ), 'value' => (string) $p->note ),
			),
		);
	}

	/**
	 * Export item of a correction credit note.
	 *
	 * @param object $k Row.
	 * @return array
	 */
	public static function correction_item( $k ) {
		$p = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $k->payout_id );
		return array(
			'group_id'    => 'wpac-payout-corrections',
			'group_label' => __( 'Correction credit notes', 'affiliate-captain' ),
			'item_id'     => 'wpac-correction-' . $k->id,
			'data'        => array(
				array( 'name' => _x( 'Number', 'document number', 'affiliate-captain' ), 'value' => (string) $k->number ),
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => (string) $k->created_at ),
				array( 'name' => __( 'Corrects credit note', 'affiliate-captain' ), 'value' => $p ? (string) $p->number : '' ),
				array( 'name' => __( 'Corrected net', 'affiliate-captain' ), 'value' => Money::format( (int) $k->net_minor, $k->currency ) ),
				array( 'name' => __( 'Difference (gross)', 'affiliate-captain' ), 'value' => Money::format( (int) $k->net_minor + (int) $k->vat_minor - (int) $k->prev_net_minor - (int) $k->prev_vat_minor, $k->currency ) ),
				array( 'name' => __( 'Reason', 'affiliate-captain' ), 'value' => (string) $k->reason ),
			),
		);
	}

	/**
	 * Export item of a partner account entry (credit, payout, repayment …).
	 *
	 * @param object $e Row.
	 * @return array
	 */
	public static function balance_entry_item( $e ) {
		$types = \WPAffiliateCaptain\Commissions\Balance::type_labels();
		return array(
			'group_id'    => 'wpac-balance-entries',
			'group_label' => __( 'Partner account entries', 'affiliate-captain' ),
			'item_id'     => 'wpac-balance-entry-' . $e->id,
			'data'        => array(
				array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $e->created_at ),
				array( 'name' => __( 'Type', 'affiliate-captain' ), 'value' => isset( $types[ $e->type ] ) ? $types[ $e->type ] : (string) $e->type ),
				array( 'name' => __( 'Amount', 'affiliate-captain' ), 'value' => Money::format( (int) $e->amount_minor, $e->currency ) ),
				array( 'name' => __( 'Balance', 'affiliate-captain' ), 'value' => Money::format( (int) $e->balance_minor, $e->currency ) ),
				array( 'name' => __( 'Reference', 'affiliate-captain' ), 'value' => (string) $e->reference ),
			),
		);
	}

	/**
	 * First export page: customer links, account, codes, terms, payout details, application and
	 * account details, payout requests, objections, VIES result, payment reminders, balances.
	 *
	 * @param string        $email E-mail.
	 * @param \WP_User|bool $user  User.
	 * @param object|null   $aff   Partner.
	 * @return array
	 */
	private static function export_base( $email, $user, $aff ) {
		$items = array();
		$links = \WPAffiliateCaptain\Tracking\CustomerLinks::for_customer( $user ? (int) $user->ID : 0, $email );
		// Customers: orders on which the partner's coupon was applied automatically after the
		// partner link (order meta _wpac_auto_coupon, R15-P6).
		foreach ( self::customer_orders( $email, $user, $links ) as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
			$auto  = $order && method_exists( $order, 'get_meta' ) ? $order->get_meta( \WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::META_ORDER ) : null;
			if ( ! is_array( $auto ) || empty( $auto['coupon'] ) ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'wpac-customer-auto-coupon',
				'group_label' => __( 'Partner coupon applied automatically', 'affiliate-captain' ),
				'item_id'     => 'wpac-customer-auto-coupon-' . (int) $order_id,
				'data'        => array(
					array( 'name' => __( 'Order', 'affiliate-captain' ), 'value' => '#' . $order_id ),
					array( 'name' => __( 'Coupon', 'affiliate-captain' ), 'value' => (string) $auto['coupon'] ),
					array( 'name' => __( 'Partner ID', 'affiliate-captain' ), 'value' => (string) ( isset( $auto['affiliate_id'] ) ? (int) $auto['affiliate_id'] : 0 ) ),
				),
			);
		}
		// Customers: every stored lifetime link (keyed hash), also one still waiting for a review.
		foreach ( $links as $link ) {
			$items[] = array(
				'group_id'    => 'wpac-customer-link',
				'group_label' => __( 'Partner referral (lifetime link)', 'affiliate-captain' ),
				'item_id'     => 'wpac-customer-link-' . $link->id,
				'data'        => array(
					array( 'name' => __( 'Partner ID', 'affiliate-captain' ), 'value' => (string) $link->affiliate_id ),
					array( 'name' => __( 'Since', 'affiliate-captain' ), 'value' => $link->created_at ),
					array( 'name' => __( 'Valid until', 'affiliate-captain' ), 'value' => $link->expires_at ? $link->expires_at : '—' ),
					array( 'name' => __( 'Created by order', 'affiliate-captain' ), 'value' => '' !== (string) $link->source_order_id ? '#' . $link->source_order_id : '—' ),
					array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => ! empty( $link->pending ) ? __( 'waiting for the order to be completed', 'affiliate-captain' ) : __( 'active', 'affiliate-captain' ) ),
				),
			);
		}
		if ( ! $aff ) {
			return $items;
		}
		$id      = (int) $aff->id;
		$items[] = array(
			'group_id'    => 'wpac-affiliate',
			'group_label' => __( 'Partner account', 'affiliate-captain' ),
			'item_id'     => 'wpac-affiliate-' . $id,
			'data'        => array(
				array( 'name' => __( 'Name', 'affiliate-captain' ), 'value' => $aff->name ),
				array( 'name' => __( 'E-mail', 'affiliate-captain' ), 'value' => $aff->email ),
				array( 'name' => __( 'Website', 'affiliate-captain' ), 'value' => $aff->website ),
				array( 'name' => __( 'Channel', 'affiliate-captain' ), 'value' => $aff->channel ),
				array( 'name' => __( 'Application', 'affiliate-captain' ), 'value' => (string) $aff->application_note ),
				array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => $aff->status ),
				array( 'name' => __( 'Created', 'affiliate-captain' ), 'value' => $aff->created_at ),
				// Only the ID of the recruiting partner: their name and address are another person's data.
				array( 'name' => __( 'Recruited by (partner ID)', 'affiliate-captain' ), 'value' => ! empty( $aff->referred_by ) ? '#' . (int) $aff->referred_by : '—' ),
			),
		);
		foreach ( CodeRepository::for_affiliate( $id ) as $c ) {
			$items[] = array(
				'group_id'    => 'wpac-codes',
				'group_label' => __( 'Partner codes', 'affiliate-captain' ),
				'item_id'     => 'wpac-code-' . $c->id,
				'data'        => array(
					array( 'name' => __( 'Code', 'affiliate-captain' ), 'value' => $c->code ),
					array( 'name' => __( 'Used', 'affiliate-captain' ), 'value' => (string) $c->usage_count ),
				),
			);
		}
		// Codes the partner requested (open, approved, rejected, withdrawn), since 0.15.0.
		$req_labels = \WPAffiliateCaptain\Codes\CodeRequests::status_labels();
		foreach ( \WPAffiliateCaptain\Codes\CodeRequests::history( $id ) as $n => $r ) {
			$status  = isset( $r['status'] ) ? (string) $r['status'] : '';
			$items[] = array(
				'group_id'    => 'wpac-code-requests',
				'group_label' => __( 'Requested partner codes', 'affiliate-captain' ),
				'item_id'     => 'wpac-code-request-' . (int) $n,
				'data'        => array(
					array( 'name' => __( 'Code', 'affiliate-captain' ), 'value' => isset( $r['code'] ) ? (string) $r['code'] : '' ),
					array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => isset( $req_labels[ $status ] ) ? $req_labels[ $status ] : $status ),
					array( 'name' => __( 'Requested on', 'affiliate-captain' ), 'value' => isset( $r['requested_at'] ) ? (string) $r['requested_at'] : '' ),
					array( 'name' => __( 'Decided on', 'affiliate-captain' ), 'value' => isset( $r['decided_at'] ) ? (string) $r['decided_at'] : '' ),
					array( 'name' => __( 'Reason', 'affiliate-captain' ), 'value' => isset( $r['reason'] ) ? (string) $r['reason'] : '' ),
				),
			);
		}
		foreach ( Agreements::for_affiliate( $id ) as $a ) {
			if ( Agreements::CONSENT === (string) $a->result ) {
				// Consents of the application form with their wording and time (SET-AC-18).
				$items[] = array(
					'group_id'    => 'wpac-consents',
					'group_label' => __( 'Consents in the application', 'affiliate-captain' ),
					'item_id'     => 'wpac-consent-' . $a->id,
					'data'        => array(
						array( 'name' => __( 'Text', 'affiliate-captain' ), 'value' => Agreements::consent_text( (string) $a->doc_hash ) ),
						array( 'name' => __( 'Checkbox', 'affiliate-captain' ), 'value' => substr( (string) $a->version, strlen( 'consent:' ) ) ),
						array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $a->created_at ),
					),
				);
				continue;
			}
			$items[] = array(
				'group_id'    => 'wpac-agreements',
				'group_label' => __( 'Terms acceptance', 'affiliate-captain' ),
				'item_id'     => 'wpac-agreement-' . $a->id,
				'data'        => array(
					array( 'name' => __( 'Version', 'affiliate-captain' ), 'value' => $a->version ),
					array( 'name' => __( 'Document hash', 'affiliate-captain' ), 'value' => $a->doc_hash ),
					array( 'name' => __( 'Result', 'affiliate-captain' ), 'value' => $a->result ),
					array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $a->created_at ),
				),
			);
		}
		$items = array_merge( $items, self::export_tax_items( $id ) );
		$items = array_merge( $items, self::export_account_items( $id ) );
		$items = array_merge( $items, self::export_details_items( $aff, $id ) );
		foreach ( \WPAffiliateCaptain\Payouts\PayoutRequests::query( '', $id, 0 ) as $r ) {
			$items[] = array(
				'group_id'    => 'wpac-payout-requests',
				'group_label' => __( 'Payout requests', 'affiliate-captain' ),
				'item_id'     => 'wpac-request-' . $r->id,
				'data'        => array(
					array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $r->created_at ),
					array( 'name' => __( 'Amount', 'affiliate-captain' ), 'value' => Money::format( (int) $r->amount_minor, $r->currency ) ),
					array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => $r->status ),
					array( 'name' => __( 'Note', 'affiliate-captain' ), 'value' => (string) $r->note ),
				),
			);
		}
		return $items;
	}

	/**
	 * Export: payout details and the VIES result of a partner.
	 *
	 * @param int $id Partner ID.
	 * @return array
	 */
	private static function export_tax_items( $id ) {
		$items = array();
		$profile = ProfileRepository::get( $id );
		if ( $profile ) {
			$data = array();
			foreach ( ProfileRepository::snapshot( $profile ) as $k => $v ) {
				$data[] = array(
					'name'  => $k,
					'value' => (string) $v,
				);
			}
			// The export file lies unencrypted in the uploads folder (public URL with a random name,
			// e-mailed link, backups), so the IBAN and the details of another payment method are
			// masked here like everywhere else (R13-K8; reverses R12-M16's full IBAN). The note says
			// where the complete details are available.
			$iban = ProfileRepository::iban( $profile );
			if ( null !== $iban && '' !== $iban ) {
				$data[] = array(
					'name'  => __( 'IBAN', 'affiliate-captain' ),
					'value' => \WPAffiliateCaptain\Core\Iban::mask( (string) $profile->iban_last4, (string) $profile->iban_country ),
				);
			}
			$details = ProfileRepository::payment_details( $profile );
			if ( null !== $details ) {
				$data[] = array(
					'name'  => __( 'Payment details', 'affiliate-captain' ),
					'value' => ProfileRepository::mask_details( $details ),
				);
			}
			// Bank transfer outside SEPA (SET-AC-30): the account number masked like the IBAN; bank
			// code, name and country name the bank, not the account (masked account, bank name and
			// country come with the snapshot above).
			$bank = ProfileRepository::bank_details( $profile );
			if ( null !== $bank ) {
				$data[] = array(
					'name'  => __( 'Account number', 'affiliate-captain' ),
					'value' => ProfileRepository::bank_mask( (string) $profile->iban_last4 ),
				);
				$data[] = array(
					'name'  => ProfileRepository::bank_code_label( $bank['bank_country'] ),
					'value' => $bank['bank_code'],
				);
			}
			if ( ( null !== $iban && '' !== $iban ) || null !== $details || null !== $bank ) {
				$data[] = array(
					'name'  => __( 'Note', 'affiliate-captain' ),
					'value' => __( 'Bank and payment details are stored encrypted and are masked in this file, which is not encrypted. You can check and change them in the partner area (payout details); on request the operator tells you the complete details stored.', 'affiliate-captain' ),
				);
			}
			$items[] = array(
				'group_id'    => 'wpac-payout-profile',
				'group_label' => __( 'Payout details', 'affiliate-captain' ),
				'item_id'     => 'wpac-profile-' . $id,
				'data'        => $data,
			);
		}
		// Earlier tax data (decide the VAT of payouts of earlier services) and a pending change.
		foreach ( \WPAffiliateCaptain\Payouts\TaxHistory::profile( $id ) as $n => $h ) {
			$tdata = array();
			foreach ( (array) $h as $k => $v ) {
				$tdata[] = array(
					'name'  => (string) $k,
					'value' => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ),
				);
			}
			$items[] = array(
				'group_id'    => 'wpac-tax-history',
				'group_label' => __( 'Earlier tax data', 'affiliate-captain' ),
				'item_id'     => 'wpac-tax-history-' . $id . '-' . (int) $n,
				'data'        => $tdata,
			);
		}
		$tax_pending = \WPAffiliateCaptain\Payouts\TaxHistory::pending( $id );
		if ( $tax_pending ) {
			$items[] = array(
				'group_id'    => 'wpac-tax-history',
				'group_label' => __( 'Earlier tax data', 'affiliate-captain' ),
				'item_id'     => 'wpac-tax-pending-' . $id,
				'data'        => array(
					array( 'name' => __( 'Requested change of the tax data (waiting for approval)', 'affiliate-captain' ), 'value' => (string) wp_json_encode( $tax_pending['new'] ) ),
					array( 'name' => __( 'Requested on', 'affiliate-captain' ), 'value' => isset( $tax_pending['requested_at'] ) ? (string) $tax_pending['requested_at'] : '' ),
				),
			);
		}
		// Result of the VAT ID check with VIES (name and address as registered, consultation number).
		$vies = \WPAffiliateCaptain\Payouts\Vies::result( $id );
		if ( $vies ) {
			$vdata = array();
			$names = array(
				'vat_id'       => __( 'VAT ID', 'affiliate-captain' ),
				'status'       => __( 'Result', 'affiliate-captain' ),
				'name'         => __( 'Name registered with VIES', 'affiliate-captain' ),
				'address'      => __( 'Address registered with VIES', 'affiliate-captain' ),
				'consultation' => __( 'Consultation number', 'affiliate-captain' ),
				'checked_at'   => __( 'Checked on', 'affiliate-captain' ),
			);
			foreach ( $names as $key => $label ) {
				if ( isset( $vies[ $key ] ) && '' !== (string) $vies[ $key ] ) {
					$vdata[] = array(
						'name'  => $label,
						'value' => is_scalar( $vies[ $key ] ) ? (string) $vies[ $key ] : wp_json_encode( $vies[ $key ] ),
					);
				}
			}
			$items[] = array(
				'group_id'    => 'wpac-vies',
				'group_label' => __( 'VAT ID check (VIES)', 'affiliate-captain' ),
				'item_id'     => 'wpac-vies-' . $id,
				'data'        => $vdata,
			);
		}
		return $items;
	}

	/**
	 * Export: objections, payment reminders, account balances and link visits of a partner.
	 *
	 * @param int $id Partner ID.
	 * @return array
	 */
	private static function export_account_items( $id ) {
		global $wpdb;
		$items = array();
		// Objections to credit notes.
		foreach ( \WPAffiliateCaptain\Payouts\Objections::all() as $payout_id => $o ) {
			if ( ! isset( $o['affiliate_id'] ) || (int) $o['affiliate_id'] !== $id ) {
				continue;
			}
			$p       = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
			$items[] = array(
				'group_id'    => 'wpac-objections',
				'group_label' => __( 'Objections to credit notes', 'affiliate-captain' ),
				'item_id'     => 'wpac-objection-' . (int) $payout_id,
				'data'        => array(
					array( 'name' => __( 'Credit note', 'affiliate-captain' ), 'value' => $p ? (string) $p->number : '#' . (int) $payout_id ),
					array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => isset( $o['at'] ) ? (string) $o['at'] : '' ),
					array( 'name' => __( 'Reason', 'affiliate-captain' ), 'value' => isset( $o['reason'] ) ? (string) $o['reason'] : '' ),
					array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => isset( $o['status'] ) ? (string) $o['status'] : '' ),
					array( 'name' => __( 'Resolution', 'affiliate-captain' ), 'value' => isset( $o['resolution'] ) ? (string) $o['resolution'] : '' ),
					array( 'name' => __( 'Resolved on', 'affiliate-captain' ), 'value' => isset( $o['resolved_at'] ) ? (string) $o['resolved_at'] : '' ),
				),
			);
		}
		// Payment requests and reminders for a negative balance (one option per currency).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$currencies = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT currency FROM ' . Db::table( 'commissions' ) . ' WHERE affiliate_id = %d', $id ) );
		$currencies = array_unique( array_map( 'strtoupper', array_merge( $currencies, \WPAffiliateCaptain\Commissions\Balance::currencies( $id ), array( (string) Settings::get( 'currency' ) ) ) ) );
		foreach ( $currencies as $currency ) {
			foreach ( \WPAffiliateCaptain\Commissions\Dunning::history( $id, $currency ) as $n => $h ) {
				$items[] = array(
					'group_id'    => 'wpac-dunning',
					'group_label' => __( 'Payment requests and reminders', 'affiliate-captain' ),
					'item_id'     => 'wpac-dunning-' . $id . '-' . $currency . '-' . (int) $n,
					'data'        => array(
						array( 'name' => __( 'Level', 'affiliate-captain' ), 'value' => isset( $h['level'] ) ? (string) $h['level'] : '' ),
						array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => isset( $h['at'] ) ? (string) $h['at'] : '' ),
						array( 'name' => __( 'Balance', 'affiliate-captain' ), 'value' => isset( $h['amount'] ) ? Money::format( (int) $h['amount'], $currency ) : '' ),
					),
				);
			}
		}
		// Account balances per currency (the entries follow as a list).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT currency, balance_minor, updated_at FROM ' . Db::table( 'balances' ) . ' WHERE affiliate_id = %d ORDER BY currency', $id ) ) as $b ) {
			$items[] = array(
				'group_id'    => 'wpac-balances',
				'group_label' => __( 'Partner account balance', 'affiliate-captain' ),
				'item_id'     => 'wpac-balance-' . $id . '-' . $b->currency,
				'data'        => array(
					array( 'name' => __( 'Currency', 'affiliate-captain' ), 'value' => (string) $b->currency ),
					array( 'name' => __( 'Balance', 'affiliate-captain' ), 'value' => Money::format( (int) $b->balance_minor, $b->currency ) ),
					array( 'name' => __( 'Updated', 'affiliate-captain' ), 'value' => (string) $b->updated_at ),
				),
			);
		}
		$vt = Db::table( 'visits' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, MIN(created_at) first_at, MAX(created_at) last_at FROM {$vt} WHERE affiliate_id = %d", $id ) );
		if ( $visits && (int) $visits->n ) {
			$items[] = array(
				'group_id'    => 'wpac-visits',
				'group_label' => __( 'Link visits (without IP address)', 'affiliate-captain' ),
				'item_id'     => 'wpac-visits-' . $id,
				'data'        => array(
					array( 'name' => __( 'Number of visits', 'affiliate-captain' ), 'value' => (string) (int) $visits->n ),
					array( 'name' => __( 'First visit', 'affiliate-captain' ), 'value' => (string) $visits->first_at ),
					array( 'name' => __( 'Last visit', 'affiliate-captain' ), 'value' => (string) $visits->last_at ),
				),
			);
		}
		return $items;
	}

	/**
	 * Export: application and account details of a partner (pending changes, previous e-mail).
	 *
	 * @param object $aff Partner.
	 * @param int    $id  Partner ID.
	 * @return array
	 */
	private static function export_details_items( $aff, $id ) {
		$items = array();
		$app   = json_decode( (string) $aff->application_data, true );
		$group = (int) $aff->group_id ? \WPAffiliateCaptain\Affiliates\Groups::find( (int) $aff->group_id ) : null;
		$extra = array(
			array( 'name' => __( 'Vanity slug', 'affiliate-captain' ), 'value' => $aff->slug ),
			array( 'name' => __( 'Last reason', 'affiliate-captain' ), 'value' => (string) $aff->status_reason ),
			array( 'name' => __( 'Welcome message on landing page', 'affiliate-captain' ), 'value' => (string) $aff->landing_message ),
			array( 'name' => __( 'Partner group', 'affiliate-captain' ), 'value' => (int) $aff->group_id ? ( $group ? (string) $group->name : (string) $aff->group_id ) : '—' ),
			array( 'name' => __( 'Linked user account', 'affiliate-captain' ), 'value' => (int) $aff->user_id ? '#' . (int) $aff->user_id : '—' ),
		);
		$optouts = array();
		foreach ( \WPAffiliateCaptain\Affiliates\Notifications::OPTOUT_TYPES as $type ) {
			if ( \WPAffiliateCaptain\Affiliates\Notifications::opted_out( $id, $type ) ) {
				$optouts[] = $type;
			}
		}
		$extra[] = array( 'name' => __( 'Unsubscribed from these e-mails', 'affiliate-captain' ), 'value' => $optouts ? implode( ', ', $optouts ) : '—' );
		$locale  = \WPAffiliateCaptain\Core\Locale::partner( $id );
		$extra[] = array( 'name' => __( 'Language of e-mails and credit notes', 'affiliate-captain' ), 'value' => '' !== $locale ? $locale : __( 'Site language', 'affiliate-captain' ) );
		$restriction = Restriction::get( $id );
		if ( $restriction ) {
			$extra[] = array( 'name' => __( 'Processing restricted since', 'affiliate-captain' ), 'value' => (string) $restriction['since'] );
		}
		$pending = \WPAffiliateCaptain\Core\Pending::get( 'email_change_' . $id );
		$pending = null !== $pending ? $pending : get_transient( 'wpac_email_change_' . $id ); // Older version.
		if ( is_array( $pending ) ) {
			$extra[] = array( 'name' => __( 'Requested new e-mail address (not confirmed)', 'affiliate-captain' ), 'value' => (string) $pending['email'] );
		}
		$public = AffiliateRepository::pending_public_change( $id );
		if ( $public ) {
			$extra[] = array( 'name' => __( 'Requested new public name (waiting for approval)', 'affiliate-captain' ), 'value' => isset( $public['name'] ) ? (string) $public['name'] : '' );
			$extra[] = array( 'name' => __( 'Requested new website (waiting for approval)', 'affiliate-captain' ), 'value' => isset( $public['website'] ) ? (string) $public['website'] : '' );
			$extra[] = array( 'name' => __( 'Requested on', 'affiliate-captain' ), 'value' => isset( $public['requested_at'] ) ? (string) $public['requested_at'] : '' );
		}
		$prev = AffiliateRepository::previous_email( $id );
		if ( '' !== $prev ) {
			$extra[] = array( 'name' => __( 'Previous e-mail address (security notices for 30 days)', 'affiliate-captain' ), 'value' => $prev );
		}
		foreach ( is_array( $app ) ? $app : array() as $field ) {
			$extra[] = array(
				'name'  => isset( $field['label'] ) ? $field['label'] : '',
				'value' => isset( $field['value'] ) ? (string) $field['value'] : '',
			);
		}
		$items[] = array(
			'group_id'    => 'wpac-affiliate-details',
			'group_label' => __( 'Application', 'affiliate-captain' ),
			'item_id'     => 'wpac-affiliate-details-' . $id,
			'data'        => $extra,
		);
		return $items;
	}

	/**
	 * Erase.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		// A partner whose link visits did not fit into the first request (millions of rows): the
		// following pages of the same request delete the rest (the partner row is anonymised by
		// now, so the e-mail address no longer finds it, R14-D3).
		$request = self::VISITS_ERASE_REQUEST . md5( strtolower( trim( (string) $email ) ) );
		$waiting = (int) $page > 1 ? (int) get_transient( $request ) : 0;
		if ( $waiting > 0 ) {
			$done = self::delete_partner_visits( $waiting, self::erase_budget() );
			if ( $done ) {
				delete_transient( $request );
			}
			return array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => $done,
			);
		}
		// Customers: remove lifetime partner links (stored as hashes).
		$user    = get_user_by( 'email', $email );
		$removed = \WPAffiliateCaptain\Tracking\CustomerLinks::forget( $user ? (int) $user->ID : 0, $email ) > 0;

		// Customers: the referral snapshot on their orders (_wpac_touch: code, campaign, sub ID,
		// visit ID) is only read to attribute an order; once the order is attributed, or nothing
		// waits for a retry, it is not needed any more (R12-M15).
		list( $customer_removed, $customer_messages, $customer_retained ) = self::erase_customer( $email, $user );
		$removed = $removed || $customer_removed;

		$aff = self::affiliate( $email );
		if ( ! $aff ) {
			return array(
				'items_removed'  => $removed,
				'items_retained' => $customer_retained,
				'messages'       => $customer_messages,
				'done'           => true,
			);
		}
		$messages   = array_merge( $customer_messages, self::erase_partner( $aff, self::erase_budget() ) );
		// Visits left (time budget): WordPress calls the eraser again with the next page.
		$visits_done = ! in_array( (int) $aff->id, array_map( 'intval', (array) get_option( self::VISITS_ERASE_OPTION, array() ) ), true );
		if ( ! $visits_done ) {
			set_transient( $request, (int) $aff->id, HOUR_IN_SECONDS );
		}
		$messages[] = null === Retention::cutoff()
			? __( 'Commission and payout records, credit notes and the partner data they contain (name, address, VAT ID) are retained for statutory bookkeeping obligations (e.g. §147 AO); their processing is restricted. They are not deleted automatically while the retention period is set to "keep forever" or a tax audit / legal hold is active.', 'affiliate-captain' )
			: __( 'Commission and payout records, credit notes and the partner data they contain (name, address, VAT ID) are retained for statutory bookkeeping obligations (e.g. §147 AO); their processing is restricted and they are deleted after the retention period.', 'affiliate-captain' );
		Audit::log( 'privacy.erased', 'affiliate', (int) $aff->id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => $visits_done,
		);
	}

	/**
	 * Seconds one eraser request may delete link visits before WordPress calls it again.
	 *
	 * @return float
	 */
	private static function erase_budget() {
		/**
		 * Seconds one call of the WordPress privacy eraser may delete link visits of a partner
		 * (admin-ajax request); the rest follows with the next page of the request.
		 *
		 * @since 0.14.0
		 * @param float $budget Seconds (default 10).
		 */
		return max( 0.000001, (float) apply_filters( 'wpac_erase_request_budget', 10 ) );
	}

	/**
	 * Erase what the plugin holds about a person as a customer (besides the lifetime links): the
	 * referral snapshot on their orders. Attributions of their orders stay as booking records of
	 * the partner commission and are reported as retained with the reason.
	 *
	 * @param string        $email E-mail.
	 * @param \WP_User|bool $user  User.
	 * @return array [ removed, messages, retained ]
	 */
	private static function erase_customer( $email, $user ) {
		global $wpdb;
		$links   = \WPAffiliateCaptain\Tracking\CustomerLinks::for_customer( $user ? (int) $user->ID : 0, $email );
		$orders  = self::customer_orders( $email, $user, $links );
		$removed = false;
		$kept    = 0;
		$unpaid  = 0;
		// Referral snapshot, the partner code the customer typed in, a manual assignment by an
		// admin (R13-N3) and the note that the partner's coupon was applied automatically after
		// the partner link (R15-P6): only read to attribute the order or shown with it.
		$keys = array( \WPAffiliateCaptain\Integrations\WooCommerce::META_TOUCH, \WPAffiliateCaptain\Integrations\WooCommerce::META_CODE, \WPAffiliateCaptain\Integrations\WooCommerce::META_MANUAL, \WPAffiliateCaptain\Integrations\WooCommerce\OrderSync::META_MANUAL_OWN, \WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon::META_ORDER );
		foreach ( $orders as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
			if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
				continue;
			}
			$present = array();
			foreach ( $keys as $key ) {
				if ( ! in_array( $order->get_meta( $key ), array( '', null, false, 0, '0' ), true ) ) {
					$present[] = $key;
				}
			}
			if ( ! $present ) {
				continue;
			}
			$source = \WPAffiliateCaptain\Integrations\WooCommerce::SOURCE;
			if ( ! \WPAffiliateCaptain\Commissions\Ledger::attribution_for( $source, (string) $order_id ) ) {
				if ( \WPAffiliateCaptain\Core\Jobs::sale_pending( $source, (string) $order_id ) ) {
					++$kept; // The booking of the order waits for a retry that reads the snapshot.
					continue;
				}
				if ( self::order_awaits_payment( $order ) ) {
					++$unpaid; // Attributed once it is paid (VVT: removed once the order is attributed).
					continue;
				}
			}
			foreach ( $present as $key ) {
				$order->delete_meta_data( $key );
			}
			$order->save_meta_data();
			$removed = true;
		}
		$attributed = 0;
		if ( $orders ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name; values are placeholders.
			$attributed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'attributions' ) . ' WHERE source = %s AND source_order_id IN (' . implode( ',', array_fill( 0, count( $orders ), '%s' ) ) . ')', array_merge( array( \WPAffiliateCaptain\Integrations\WooCommerce::SOURCE ), $orders ) ) );
		}
		$messages = array();
		if ( $attributed ) {
			$messages[] = sprintf(
				/* translators: %d: number of orders */
				_n(
					'%d order of this customer is attributed to a partner (order number, partner, method, campaign). The attribution is retained as a booking record of the partner commission for the statutory retention period and deleted afterwards; partners never see the customer\'s name or contact details.',
					'%d orders of this customer are attributed to a partner (order number, partner, method, campaign). The attributions are retained as booking records of the partner commissions for the statutory retention period and deleted afterwards; partners never see the customer\'s name or contact details.',
					$attributed,
					'affiliate-captain'
				),
				$attributed
			);
		}
		if ( $kept ) {
			/* translators: %d: number of orders */
			$messages[] = sprintf( _n( 'The referral data of %d order is retained until its partner commission has been booked (automatic retry).', 'The referral data of %d orders is retained until their partner commission has been booked (automatic retry).', $kept, 'affiliate-captain' ), $kept );
		}
		if ( $unpaid ) {
			/* translators: %d: number of orders */
			$messages[] = sprintf( _n( 'The referral data of %d order that is not paid yet (referral, partner code entered, manual assignment) is retained: it is needed to credit the partner once the order is paid. Run the erasure again after payment or cancellation to remove it.', 'The referral data of %d orders that are not paid yet (referral, partner code entered, manual assignment) is retained: it is needed to credit the partner once the orders are paid. Run the erasure again after payment or cancellation to remove it.', $unpaid, 'affiliate-captain' ), $unpaid );
		}
		return array( $removed, $messages, $attributed > 0 || $kept > 0 || $unpaid > 0 );
	}

	/**
	 * Whether an order can still be paid and then attributed (not paid yet, not cancelled, failed
	 * or refunded). Orders without these methods count as settled.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	private static function order_awaits_payment( $order ) {
		if ( ! method_exists( $order, 'is_paid' ) || $order->is_paid() ) {
			return false;
		}
		return ! ( method_exists( $order, 'has_status' ) && $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) );
	}

	/**
	 * Anonymise a partner: personal fields, codes, visits, links, pending changes, VIES result,
	 * reminders and the change log details; booking records stay (restricted) and the payout
	 * details are deleted unless something is still open.
	 *
	 * The link visits are deleted in short transactions within $visit_budget; a rest is deleted
	 * in the background (delete_partner_visits(), R14-D3).
	 *
	 * @param object $aff          Partner.
	 * @param float  $visit_budget Seconds for deleting the link visits in this request.
	 * @return string[] Messages.
	 */
	public static function erase_partner( $aff, $visit_budget = 10.0 ) {
		global $wpdb;
		$messages = array();
		$open     = self::has_open_items( (int) $aff->id );

		// referred_by (recruiting partner) stays: a pseudonymous link between two partner IDs that
		// sub-affiliate bookings of add-ons may depend on; nothing else about the recruiter is stored.
		$wpdb->update(
			Db::table( 'affiliates' ),
			array(
				'name'             => __( 'Deleted partner', 'affiliate-captain' ),
				'email'            => 'deleted-' . (int) $aff->id . '@invalid.invalid',
				'website'          => '',
				'channel'          => '',
				'application_note' => '',
				'application_data' => null,
				'landing_message'  => null,
				'landing_page_id'  => 0,
				'slug'             => 'deleted-' . (int) $aff->id,
				'user_id'          => 0,
				'status'           => in_array( $aff->status, array( 'active', 'paused' ), true ) ? 'terminated' : $aff->status,
				'status_reason'    => 'privacy_erasure',
				'updated_at'       => Db::now(),
			),
			array( 'id' => (int) $aff->id )
		);
		// Requested codes (CodeRequests) are deleted with their history; the other codes may be
		// derived from the name (vanity codes) → replace them by neutral values.
		\WPAffiliateCaptain\Codes\CodeRequests::forget( (int) $aff->id );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'blocked', code = CONCAT('DEL-', id) WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::delete_partner_visits( (int) $aff->id, (float) $visit_budget );
		$wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payout_requests' ) . " SET note = '' WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Booking records (commission events, attribution notes, payout notes, write-off reasons and
		// the payout change log) must stay unchanged during the retention period: processing is
		// restricted instead (Art. 17(3)(b), Art. 18 GDPR) and the free texts are deleted when the
		// period ends (Retention::purge_erased()).
		if ( ! Restriction::add( (int) $aff->id, Restriction::ERASURE ) ) {
			$messages[] = __( 'The restriction of processing could not be saved. Please restrict the partner manually on the partner page.', 'affiliate-captain' );
		}
		// Pending e-mail change, previous address and account-link token contain addresses.
		delete_transient( 'wpac_email_change_' . (int) $aff->id );
		\WPAffiliateCaptain\Core\Pending::delete( 'email_change_' . (int) $aff->id );
		delete_option( 'wpac_prev_email_' . (int) $aff->id );
		delete_option( 'wpac_user_link_' . (int) $aff->id );
		// Requested public name/website change, VIES result (VAT ID, company name) and payment
		// request history (one option per currency).
		delete_option( 'wpac_public_change_' . (int) $aff->id );
		delete_option( 'wpac_vies_' . (int) $aff->id );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpac_dunning_' . (int) $aff->id . '_' ) . '%' ) ) as $option ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_option( (string) $option );
		}
		// Free-text details in the audit log (reasons may contain personal data); acceptance records
		// stay as proof. The change log of booking-relevant data (payout details: tax data and VAT
		// ID checks, payout requests, …) is kept unchanged as long as Cron::purge_audit() keeps it
		// for the statements (GoBD): its details are emptied once the entry is past the retention
		// period (here, or by Retention::scrub_ledger_texts() when the partner's records expire).
		$audit     = Db::table( 'audit' );
		$cutoff    = Retention::cutoff();
		$financial = \WPAffiliateCaptain\Core\Audit::financial_sql();
		$expired   = null === $cutoff ? '' : $wpdb->prepare( ' OR created_at < %s', $cutoff );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- Table name and constant conditions; values are placeholders.
		$wpdb->query( $wpdb->prepare( "UPDATE {$audit} SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d AND action NOT LIKE 'agreement.%%' AND ( NOT {$financial}{$expired} )", $aff->id ) );
		// Entries under the booking-relevant prefixes that are no booking records (refused or failed
		// changes, other partners with the same IBAN, free-text rejection reasons) are not retained.
		$not_records = self::AUDIT_NOT_RECORDS;
		$wpdb->query( $wpdb->prepare( "UPDATE {$audit} SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d AND action IN (" . implode( ',', array_fill( 0, count( $not_records ), '%s' ) ) . ')', array_merge( array( (int) $aff->id ), $not_records ) ) );
		// The retained ones keep what the booking needs, pseudonymised (R13-N2): tax IDs masked,
		// names, addresses and free texts removed.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, details FROM {$audit} WHERE object_type = 'affiliate' AND object_id = %d AND details IS NOT NULL AND {$financial}", $aff->id ) );
		// phpcs:enable
		foreach ( $rows as $row ) {
			$details = json_decode( (string) $row->details, true );
			$clean   = is_array( $details ) ? self::pseudonymise_details( $details ) : null;
			$wpdb->update( $audit, array( 'details' => $clean ? wp_json_encode( $clean ) : null ), array( 'id' => (int) $row->id ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE object_type = 'affiliate' AND object_id = %d AND details IS NOT NULL AND {$financial}", $aff->id ) );
		if ( $kept ) {
			$messages[] = null === $cutoff
				? sprintf(
					/* translators: %d: number of log entries */
					_n(
						'%d change log entry about payout details or payout requests (e.g. changes of the tax status, VAT ID checks, payout requests) is retained as a booking-relevant record, pseudonymised (tax IDs masked, names, addresses and free texts removed); its processing is restricted. It is not deleted automatically while the retention period is set to "keep forever" or a tax audit / legal hold is active.',
						'%d change log entries about payout details or payout requests (e.g. changes of the tax status, VAT ID checks, payout requests) are retained as booking-relevant records, pseudonymised (tax IDs masked, names, addresses and free texts removed); their processing is restricted. They are not deleted automatically while the retention period is set to "keep forever" or a tax audit / legal hold is active.',
						$kept,
						'affiliate-captain'
					),
					$kept
				)
				: sprintf(
					/* translators: %d: number of log entries */
					_n(
						'%d change log entry about payout details or payout requests (e.g. changes of the tax status, VAT ID checks, payout requests) is retained as a booking-relevant record for the statutory retention period, pseudonymised (tax IDs masked, names, addresses and free texts removed); its processing is restricted and its details are deleted afterwards.',
						'%d change log entries about payout details or payout requests (e.g. changes of the tax status, VAT ID checks, payout requests) are retained as booking-relevant records for the statutory retention period, pseudonymised (tax IDs masked, names, addresses and free texts removed); their processing is restricted and their details are deleted afterwards.',
						$kept,
						'affiliate-captain'
					),
					$kept
				);
		}
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache();

		if ( $open ) {
			// Deleted automatically once everything is settled (daily cron).
			update_option( 'wpac_erasure_pending', array_values( array_unique( array_merge( (array) get_option( 'wpac_erasure_pending', array() ), array( (int) $aff->id ) ) ) ), false );
			$messages[] = __( 'Payout details and the history of the tax data were retained because the partner still has open commissions or payouts (the VAT of these payouts depends on the tax data valid at the time of the service). They are deleted automatically once everything is settled.', 'affiliate-captain' );
		} else {
			ProfileRepository::delete( (int) $aff->id ); // With the history of the tax data.
		}
		/**
		 * A partner's personal data were erased (GDPR Art. 17): add-ons delete their own data of
		 * this partner (e.g. webhook logs, payment provider references). Fires after the core
		 * erasure; the partner row remains (anonymised) as long as bookings reference it.
		 *
		 * @param int  $affiliate_id Partner.
		 * @param bool $open         Whether open commissions or payouts delayed part of the erasure.
		 */
		$erased = (int) $aff->id;
		$open   = (bool) $open;
		\WPAffiliateCaptain\Core\Db::after_commit( // Post-commit like all events (AFF-WF-02).
			static function () use ( $erased, $open ) {
				do_action( 'wpac_affiliate_erased', $erased, $open );
			}
		);
		return $messages;
	}

	/** Change log actions under the booking-relevant prefixes that are no booking records. */
	const AUDIT_NOT_RECORDS = array( 'payout_profile.save_failed', 'payout_profile.password_refused', 'payout_profile.iban_shared', 'payout_request.rejected' );

	/**
	 * Pseudonymise the details of a retained change log entry (R13-N2): tax IDs are masked (kept
	 * recognisable for the booking: first two and last three characters), names, addresses,
	 * e-mail addresses, free texts, error texts and other partners' IDs are removed.
	 *
	 * @param array $details Details.
	 * @return array
	 */
	public static function pseudonymise_details( array $details ) {
		$drop = array( 'legal_name', 'address', 'account_holder', 'name', 'email', 'reason', 'note', 'error', 'partners', 'text' );
		$out  = array();
		foreach ( $details as $key => $value ) {
			if ( in_array( (string) $key, $drop, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = self::pseudonymise_details( $value );
			} elseif ( in_array( (string) $key, array( 'vat_id', 'tax_number', 'consultation' ), true ) && '' !== (string) $value ) {
				$value = self::mask_tax_id( (string) $value );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Mask a tax ID: "DE123456789" → "DE•••789".
	 *
	 * @param string $id Tax ID.
	 * @return string
	 */
	public static function mask_tax_id( $id ) {
		$id = (string) preg_replace( '/\s+/', '', $id );
		return strlen( $id ) > 6 ? substr( $id, 0, 2 ) . '•••' . substr( $id, -3 ) : '•••';
	}

	/**
	 * A partner's data protection request from the portal. Export and erasure use the WordPress
	 * request flow (confirmation e-mail, then the admin handles it under Tools); restriction and
	 * objection go to the operator.
	 *
	 * @param object $aff  Partner.
	 * @param string $type export|erase|restrict|object.
	 * @param string $note Details.
	 * @return string Portal message key.
	 */
	public static function partner_request( $aff, $type, $note ) {
		if ( in_array( $type, array( 'export', 'erase' ), true ) ) {
			if ( ! function_exists( 'wp_create_user_request' ) ) {
				return 'privacy_failed';
			}
			$id = wp_create_user_request( (string) $aff->email, 'export' === $type ? 'export_personal_data' : 'remove_personal_data' );
			if ( is_wp_error( $id ) ) {
				// An open request of the same kind already exists: the confirmation mail is sent again.
				return 'duplicate_request' === $id->get_error_code() ? 'privacy_confirm' : 'privacy_failed';
			}
			wp_send_user_request( $id );
			Audit::log( 'privacy.request', 'affiliate', (int) $aff->id, array( 'type' => $type ) );
			return 'privacy_confirm';
		}
		if ( ! in_array( $type, array( 'restrict', 'object' ), true ) || '' === trim( $note ) ) {
			return 'privacy_failed';
		}
		$switched = \WPAffiliateCaptain\Affiliates\Notifications::switch_for_admin();
		try {
			$sent     = \WPAffiliateCaptain\Affiliates\Notifications::send(
				'privacy_request',
				\WPAffiliateCaptain\Affiliates\Notifications::admin_email(),
				'restrict' === $type
					/* translators: %s: partner */
					? sprintf( __( 'Data protection: %s asks to restrict processing', 'affiliate-captain' ), $aff->name )
					/* translators: %s: partner */
					: sprintf( __( 'Data protection: %s objects to processing', 'affiliate-captain' ), $aff->name ),
				/* translators: 1: details, 2: URL */
				sprintf( __( "Details from the partner:\n\n%1\$s\n\nPlease answer within one month (Art. 12(3) GDPR). Restrict processing on the partner page if justified:\n%2\$s", 'affiliate-captain' ), $note, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $aff->id ) ),
				array(
					'admin'        => true,
					'reply_to'     => is_email( $aff->email ) ? (string) $aff->email : '',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $switched );
		}
		Audit::log( 'privacy.request', 'affiliate', (int) $aff->id, array( 'type' => $type, 'mail' => $sent ? 'sent' : 'failed' ) );
		// Only claim "sent" when the operator really got the request (the mail is its only record).
		return $sent ? 'privacy_sent' : 'privacy_failed';
	}

	/**
	 * Language of the record of processing activities template (R14-L5): German for German
	 * locales, else the English template (docs/RECORD-OF-PROCESSING.md).
	 *
	 * @return string de|en
	 */
	public static function ropa_language() {
		if ( function_exists( 'get_user_locale' ) ) {
			$locale = (string) get_user_locale();
		} elseif ( function_exists( 'get_locale' ) ) {
			$locale = (string) get_locale();
		} else {
			$locale = 'de_DE';
		}
		$lang = 0 === strpos( strtolower( $locale ), 'de' ) ? 'de' : 'en';
		/**
		 * Filter the language of the record of processing activities template.
		 *
		 * @param string $lang   de|en.
		 * @param string $locale Locale of the current user.
		 */
		$lang = (string) apply_filters( 'wpac_ropa_language', $lang, $locale );
		return 'de' === $lang ? 'de' : 'en';
	}

	/**
	 * Record of processing activities (template in docs/, filled with the current settings).
	 *
	 * @param string $lang de|en, '' = ropa_language().
	 * @return string Markdown.
	 */
	public static function ropa( $lang = '' ) {
		$de   = 'de' === ( '' !== $lang ? $lang : self::ropa_language() );
		$file = WPAC_DIR . 'docs/' . ( $de ? 'VERARBEITUNGSVERZEICHNIS.md' : 'RECORD-OF-PROCESSING.md' );
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$name = (string) Settings::get( 'debtor_name' );
		$days = static function ( $key ) {
			$v = (int) Settings::get( $key );
			return $v > 0 ? (string) $v : '∞';
		};
		$life = (int) Settings::get( 'lifetime_days' );
		// The German template keeps German words; the English one is translatable for other languages.
		if ( ! Settings::get( 'lifetime_enabled' ) ) {
			$lifetime = $de ? 'nicht aktiv' : __( 'not active', 'affiliate-captain' );
		} elseif ( $life > 0 ) {
			/* translators: %d: days */
			$lifetime = $de ? $life . ' Tage' : sprintf( _n( '%d day', '%d days', $life, 'affiliate-captain' ), $life );
		} else {
			$lifetime = $de ? 'bis Vertragsende' : __( 'until the partnership ends', 'affiliate-captain' );
		}
		$providers = '';
		if ( ProfileRepository::manual_enabled() ) {
			// Other payment method on: the payment provider receives the payout (e.g. PayPal; outside the EU possible).
			$providers = $de ? 'Zahlungsdienstleister des vom Partner gewählten anderen Zahlungswegs (z. B. PayPal, Wise; ggf. Drittland), ' : __( 'payment provider of the other payment method chosen by the partner (e.g. PayPal, Wise; possibly a third country), ', 'affiliate-captain' );
		}
		if ( ProfileRepository::method_enabled( ProfileRepository::METHOD_BANK ) ) {
			// Bank transfer outside SEPA: correspondent banks and the partner's bank, possibly in a third country.
			$providers .= $de ? 'Korrespondenzbanken und Bank des Partners bei Überweisungen außerhalb des SEPA-Raums (ggf. Drittland), ' : __( 'correspondent banks and the partner\'s bank for transfers outside the SEPA area (possibly a third country), ', 'affiliate-captain' );
		}
		$storage = '';
		if ( ! \WPAffiliateCaptain\Payouts\Statement::local_store() ) {
			// R15-P4: credit note PDFs with an object storage provider (processor, possibly a third country).
			$storage = $de ? 'Auftragsverarbeiter für die Ablage der Gutschrift-PDFs: Objektspeicher-Anbieter ([Anbieter, Standort, bei Drittland die Garantien nach Art. 44 ff. DSGVO – vom Betreiber zu ergänzen]), ' : __( 'processor storing the credit note PDFs: object storage provider ([provider, location, for a third country the safeguards under Art. 44 ff. GDPR – to be completed by the operator]), ', 'affiliate-captain' );
		}
		$cookie_range = self::program_cookie_days();
		$map          = array(
			'{operator_name}'             => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{operator_address}'           => str_replace( array( "\r\n", "\n" ), ', ', (string) Settings::get( 'operator_address' ) ),
			'{date}'                       => wp_date( 'Y-m-d' ),
			'{visit_retention_days}'       => $days( 'visit_retention_days' ),
			'{cookie_days}'                => $cookie_range ? implode( '–', $cookie_range ) : $days( 'cookie_days' ), // Range per partner program (wpac_setting).
			'{lifetime_days}'              => $lifetime,
			'{retention_years}'            => Retention::years() > 0 ? (string) Retention::years() : '∞',
			'{application_retention_days}' => $days( 'application_retention_days' ),
			'{pending_application_days}'   => $days( 'pending_application_days' ),
			'{audit_retention_days}'       => $days( 'audit_retention_days' ),
			'{payment_providers}'          => $providers,
			'{document_storage}'           => $storage,
		);
		// AFF-SU-03: the entry on the review quality only while a review plugin listens.
		$text = \WPAffiliateCaptain\Reports\ReviewQuality::available()
			? str_replace( array( "<!-- review-quality -->\n", "<!-- /review-quality -->\n" ), '', $text )
			: (string) preg_replace( '/<!-- review-quality -->\n.*?<!-- \/review-quality -->\n/s', '', $text );
		return strtr( $text, $map );
	}

	/**
	 * Note for the shop operator when the affiliate cookie is set without consent (cookie mode
	 * "always", R12-L4): shown in the privacy policy guide and in Site Health. Not legal advice.
	 *
	 * @return string
	 */
	public static function always_mode_note() {
		return __( 'Note for the shop operator: the affiliate cookie is set without consent (cookie mode "Always, without consent"). Storing and reading information on the visitor\'s device without consent is only allowed if it is strictly necessary for a service the visitor explicitly requested (§ 25(2) no. 2 TDDDG, Art. 5(3) ePrivacy Directive). Affiliate cookies are usually not considered strictly necessary, so in the EU they usually require consent. Have this assessed by your data protection advisor and complete the legal basis, or switch the affiliate cookie to "Only with marketing consent".', 'affiliate-captain' );
	}

	/**
	 * Cookie lifetimes of the partner programs when an add-on changes them (filter wpac_setting,
	 * contract 1.5.0): the default program, the program of every partner group and the programs of
	 * the report selector (wpac_report_programs).
	 *
	 * @return int[]|null [ shortest, longest ] days, or null when every program uses the setting
	 *                    (always without a wpac_setting callback).
	 */
	public static function program_cookie_days() {
		if ( function_exists( 'has_filter' ) && false === has_filter( 'wpac_setting' ) ) {
			return null;
		}
		$base     = (int) Settings::get( 'cookie_days' );
		$programs = array( '' => 0 );
		foreach ( (array) \WPAffiliateCaptain\Affiliates\Groups::all() as $group ) {
			$programs[ \WPAffiliateCaptain\Affiliates\Programs::for_group( (int) $group->id ) ] = (int) $group->id;
		}
		foreach ( array_keys( \WPAffiliateCaptain\Affiliates\Programs::report_choices() ) as $key ) {
			if ( ! isset( $programs[ (string) $key ] ) ) {
				$programs[ (string) $key ] = preg_match( '/^group:([1-9]\d{0,9})$/', (string) $key, $m ) ? (int) $m[1] : 0;
			}
		}
		$days = array();
		foreach ( $programs as $program => $group_id ) {
			$days[] = max( 1, (int) Settings::get(
				'cookie_days',
				array(
					'program'  => (string) $program,
					'group_id' => (int) $group_id,
				)
			) );
		}
		$days = array_unique( $days );
		if ( array( $base ) === array_values( $days ) ) {
			return null;
		}
		$days[] = $base;
		return array( min( $days ), max( $days ) );
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$p   = array();
		$p[] = __( 'When you visit our website through a partner link or enter a partner code, we store which partner referred you so we can pay the partner a commission. We do not store your IP address for this purpose. Partners do not receive your name or contact details.', 'affiliate-captain' );
		$mode = Settings::get( 'cookie_mode' );
		$cookie_days = (int) Settings::get( 'cookie_days' );
		// Several partner programs with own cookie lifetimes (add-on, wpac_setting): name the range.
		$range = 'off' !== $mode ? self::program_cookie_days() : null;
		if ( 'consent' === $mode ) {
			/* translators: %d: days */
			$p[] = sprintf( _n( 'An affiliate cookie (wpac_ref, %d day) is only set if you consented to marketing cookies; withdrawing consent removes it.', 'An affiliate cookie (wpac_ref, %d days) is only set if you consented to marketing cookies; withdrawing consent removes it.', $cookie_days, 'affiliate-captain' ), $cookie_days );
		} elseif ( 'always' === $mode ) {
			/* translators: %d: days */
			$p[] = sprintf( _n( 'An affiliate cookie (wpac_ref, %d day) is set when you follow a partner link, without asking for your consent.', 'An affiliate cookie (wpac_ref, %d days) is set when you follow a partner link, without asking for your consent.', $cookie_days, 'affiliate-captain' ), $cookie_days );
		}
		if ( $range ) {
			/* translators: 1: shortest lifetime in days, 2: longest lifetime in days */
			$p[] = sprintf( __( 'The lifetime of the affiliate cookie depends on the partner program of the partner whose link you followed: between %1$d and %2$d days.', 'affiliate-captain' ), $range[0], $range[1] );
		}
		$session = (bool) Settings::get( 'use_wc_session' );
		if ( $session ) {
			// R12-L4: Tracker::store_touch() keeps the touch in an existing shop session whatever the
			// cookie mode is (server-side; no cookie of its own) – say so instead of implying consent.
			$p[] = 'always' === $mode
				? __( 'If you have a shopping cart (shop session), the partner code of the link you followed is also kept with your shop session on our server until checkout. No additional cookie is set for this; the session cookie of your shopping cart is used.', 'affiliate-captain' )
				: __( 'If you have a shopping cart (shop session), the partner code of the link you followed is kept with your shop session on our server until checkout. No additional cookie is set for this; the session cookie of your shopping cart is used. This does not depend on your consent to marketing cookies.', 'affiliate-captain' );
		}
		if ( Settings::get( 'lifetime_enabled' ) ) {
			$days = (int) Settings::get( 'lifetime_days' );
			$p[]  = $days > 0
				/* translators: %d: days */
				? sprintf( _n( 'After a purchase via a partner, later purchases within %d day are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', 'After a purchase via a partner, later purchases within %d days are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', $days, 'affiliate-captain' ), $days )
				: __( 'After a purchase via a partner, later purchases are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', 'affiliate-captain' );
			$p[]  = __( 'This link is only created for purchases with a customer account that earned the partner a commission (never for guest orders), and it is deleted at the latest when the partnership ends or the shop stops crediting later purchases.', 'affiliate-captain' );
		}
		if ( Settings::get( 'js_tracking' ) ) {
			$p[] = 'always' === $mode
				? __( 'Pages served from a cache report the partner code in the page address to our server with a small script (the same information the page request itself contains: partner code, campaign, page). The address of the previous website is sent only as its host name, without asking for your consent.', 'affiliate-captain' )
				: ( 'consent' === $mode
					? __( 'Pages served from a cache report the partner code in the page address to our server with a small script (the same information the page request itself contains: partner code, campaign, page). The address of the previous website is only sent as its host name and only with your marketing consent.', 'affiliate-captain' )
					: __( 'Pages served from a cache report the partner code in the page address to our server with a small script (the same information the page request itself contains: partner code, campaign, page). The address of the previous website is not stored.', 'affiliate-captain' ) );
		}
		if ( 'off' !== $mode || $session ) {
			// R15-P5: the referral of the visit also decides who recruited a new partner (referred_by).
			$p[] = __( 'If you apply to become a partner yourself, the referral described above is also used to record which partner recruited you.', 'affiliate-captain' );
		}
		$p[] = __( 'For partners we store application details, accepted terms (version, time and the accepted text), partner codes, commission records and payout details. Bank details are stored encrypted.', 'affiliate-captain' );
		$p[] = __( 'If you applied through the link of another partner, we store which partner recruited you (partner ID) with your partner account, so that partner can be credited for the recruitment (contract with the partners, Art. 6(1)(b) GDPR). It is kept as long as your partner account and the related booking records.', 'affiliate-captain' );

		// Storage periods.
		$years    = Retention::years();
		$periods  = array();
		$visits   = (int) Settings::get( 'visit_retention_days' );
		$apps     = (int) Settings::get( 'application_retention_days' );
		$audit    = (int) Settings::get( 'audit_retention_days' );
		/* translators: %d: days */
		$periods[] = $visits > 0 ? sprintf( _n( 'link visits (without IP address): %d day', 'link visits (without IP address): %d days', $visits, 'affiliate-captain' ), $visits ) : __( 'link visits (without IP address): until deleted manually', 'affiliate-captain' );
		if ( Settings::get( 'cookie_mode' ) !== 'off' ) {
			$periods[] = $range
				/* translators: 1: shortest lifetime in days, 2: longest lifetime in days */
				? sprintf( __( 'affiliate cookie: %1$d to %2$d days depending on the partner program', 'affiliate-captain' ), $range[0], $range[1] )
				/* translators: %d: days */
				: sprintf( _n( 'affiliate cookie: %d day', 'affiliate cookie: %d days', $cookie_days, 'affiliate-captain' ), $cookie_days );
		}
		if ( Settings::get( 'lifetime_enabled' ) ) {
			$days      = (int) Settings::get( 'lifetime_days' );
			/* translators: %d: days */
			$periods[] = $days > 0 ? sprintf( _n( 'customer link to the referring partner: %d day', 'customer link to the referring partner: %d days', $days, 'affiliate-captain' ), $days ) : __( 'customer link to the referring partner: until the partner relationship ends', 'affiliate-captain' );
		}
		/* translators: %d: days */
		$periods[] = $apps > 0 ? sprintf( _n( 'rejected partner applications: %d day', 'rejected partner applications: %d days', $apps, 'affiliate-captain' ), $apps ) : __( 'rejected partner applications: until deleted manually', 'affiliate-captain' );
		$undecided = (int) Settings::get( 'pending_application_days' );
		/* translators: %d: days */
		$periods[] = $undecided > 0 ? sprintf( _n( 'partner applications that were never decided: %d day', 'partner applications that were never decided: %d days', $undecided, 'affiliate-captain' ), $undecided ) : __( 'partner applications that were never decided: until deleted manually', 'affiliate-captain' );
		/* translators: %s: validity of the confirmation link (e.g. "2 days") */
		$periods[] = sprintf( __( 'applications whose e-mail address was not confirmed: %s', 'affiliate-captain' ), \WPAffiliateCaptain\Affiliates\Registration::confirm_ttl_text() );
		if ( $years > 0 ) {
			/* translators: %d: years */
			$periods[] = sprintf( _n( 'attribution of an order to a partner (order number, campaign, sub ID): %d year after the end of the calendar year of the last booking of the order; afterwards the commission records of the order keep only partner, amounts and dates (order number pseudonymised, product and booking texts deleted)', 'attribution of an order to a partner (order number, campaign, sub ID): %d years after the end of the calendar year of the last booking of the order; afterwards the commission records of the order keep only partner, amounts and dates (order number pseudonymised, product and booking texts deleted)', $years, 'affiliate-captain' ), $years );
		}
		/* translators: %d: years */
		$periods[] = $years > 0 ? sprintf( _n( 'commission records, credit notes, payout details and the accepted terms: %d year after the end of the calendar year (statutory retention; longer during a tax audit)', 'commission records, credit notes, payout details and the accepted terms: %d years after the end of the calendar year (statutory retention; longer during a tax audit)', $years, 'affiliate-captain' ), $years ) : __( 'commission records, credit notes, payout details and the accepted terms: statutory retention period, currently not deleted automatically', 'affiliate-captain' );
		if ( $years > 0 || $apps > 0 ) {
			// Retention::purge_ended(): ended partnerships are anonymised without an erasure request.
			$periods[] = __( 'partner account and payout details after the partnership ended: deleted once the retention period of the last booking has passed (without bookings: after the period for rejected applications)', 'affiliate-captain' );
		}
		/* translators: %d: days */
		$periods[] = $audit > 0 ? sprintf( _n( 'change log of other settings and partner data: %d day', 'change log of other settings and partner data: %d days', $audit, 'affiliate-captain' ), $audit ) : __( 'change log: until deleted manually', 'affiliate-captain' );
		$p[] = __( 'Storage periods:', 'affiliate-captain' ) . ' ' . implode( '; ', $periods ) . '.';

		// Legal bases.
		// R12-L4: the basis named for the cookie follows the cookie mode; "always" claims none.
		$bases   = array();
		$bases[] = $session
			? __( 'attribution of an order to a partner without cookie and keeping the partner code in the shop session: legitimate interest in paying the partner correctly (Art. 6(1)(f) GDPR)', 'affiliate-captain' )
			: __( 'attribution of an order to a partner without cookie: legitimate interest in paying the partner correctly (Art. 6(1)(f) GDPR)', 'affiliate-captain' );
		if ( 'consent' === $mode ) {
			$bases[] = __( 'storing and reading the affiliate cookie on your device and reading the previous page: your consent (Art. 6(1)(a) GDPR, § 25(1) TDDDG)', 'affiliate-captain' );
		} elseif ( 'always' === $mode ) {
			$bases[] = __( 'storing and reading the affiliate cookie on your device and reading the previous page without consent: [legal basis to be assessed and completed by the shop operator]', 'affiliate-captain' );
		}
		$bases[] = __( 'partner program, commissions and payouts: contract with the partner (Art. 6(1)(b) GDPR)', 'affiliate-captain' );
		$bases[] = __( 'bookkeeping and retention of credit notes: legal obligation (Art. 6(1)(c) GDPR, § 147 AO / § 132 BAO / Art. 958f OR)', 'affiliate-captain' );
		$p[]     = __( 'Legal bases:', 'affiliate-captain' ) . ' ' . implode( '; ', $bases ) . '.';

		// Recipients.
		if ( \WPAffiliateCaptain\Payouts\Statement::local_store() ) {
			$p[] = __( 'Recipients: partners see their own orders only without customer names or contact details; payout details go to our bank for the transfer and, as part of our bookkeeping, to our tax advisor and the tax authorities; our hosting provider processes the data on our behalf (Art. 28 GDPR). Data is not transferred to third countries by this plugin.', 'affiliate-captain' );
		} else {
			// R15-P4: credit notes (name, address, VAT ID of the partner) are kept with an object
			// storage provider (WPAC_S3_* constants or a custom store) – possibly in a third country.
			$p[] = __( 'Recipients: partners see their own orders only without customer names or contact details; payout details go to our bank for the transfer and, as part of our bookkeeping, to our tax advisor and the tax authorities; our hosting provider processes the data on our behalf (Art. 28 GDPR).', 'affiliate-captain' );
			$p[] = __( 'Credit notes, cancellations and correction credit notes (with the partner\'s name, address and VAT ID) are stored with an object storage provider on our behalf (Art. 28 GDPR): [provider, location of the storage and, for a location outside the EU/EEA, the safeguards for the transfer (Art. 44 ff. GDPR) – to be completed by the shop operator].', 'affiliate-captain' );
		}
		if ( ProfileRepository::method_enabled( ProfileRepository::METHOD_BANK ) ) {
			$p[] = __( 'Partners with a bank account outside the SEPA area: the account holder, account number, bank code, bank name and country, BIC/SWIFT, the amount and the payment reference go to our bank and, for the international transfer, to correspondent banks and the partner\'s bank, which may be located in third countries (Art. 49(1)(b) GDPR: necessary for the performance of the contract with the partner).', 'affiliate-captain' );
		}
		if ( ProfileRepository::manual_enabled() ) {
			$p[] = __( 'Partners who choose another payment method (e.g. PayPal or Wise) instead of a bank transfer: the amount, the payment reference and the payment details they entered (e.g. their PayPal address) go to that payment provider for the payout. The provider processes them under its own responsibility and may transfer them to third countries (e.g. the USA).', 'affiliate-captain' );
		}
		// VIES (Vies::check()): on unless switched off under Settings → Payouts ("vies_check").
		$vies = Settings::get( 'vies_check' );
		if ( null === $vies || $vies ) {
			$p[] = __( 'VAT ID check: if a partner enters an EU VAT ID, it is checked with the VIES service of the European Commission (ec.europa.eu) when the payout details are saved and again periodically. The VAT ID of the partner and our own VAT ID are sent; the European Commission receives these as an independent controller (legal basis: our obligation and legitimate interest to verify the VAT ID before issuing reverse-charge credit notes, Art. 6(1)(c) and (f) GDPR). The result and the consultation number are stored with the payout details.', 'affiliate-captain' );
		}
		// R14-L4: customers are data subjects too (attribution without cookie and the customer link
		// rely on legitimate interest): Art. 13(2)(b) and Art. 21(4) GDPR require an explicit note.
		$p[] = Settings::get( 'lifetime_enabled' )
			? __( 'Customers can object at any time, on grounds relating to their particular situation, to the attribution of their orders to a partner and to the link to a partner for later purchases (Art. 21 GDPR); we then remove the link and do not attribute later orders. Customers also have the right of access, correction, erasure, restriction of processing and to lodge a complaint with a data protection supervisory authority. A message to the contact details given in this privacy policy is enough.', 'affiliate-captain' )
			: __( 'Customers can object at any time, on grounds relating to their particular situation, to the attribution of their orders to a partner (Art. 21 GDPR). Customers also have the right of access, correction, erasure, restriction of processing and to lodge a complaint with a data protection supervisory authority. A message to the contact details given in this privacy policy is enough.', 'affiliate-captain' );
		$p[] = __( 'Partners can ask for a copy of their data, its correction, erasure or restriction of processing, and can object to processing based on legitimate interest. Records we must keep by law are restricted instead of deleted until the retention period ends.', 'affiliate-captain' );
		$html = '';
		foreach ( $p as $para ) {
			$html .= '<p>' . esc_html( $para ) . '</p>';
		}
		if ( 'always' === $mode ) {
			// Note for the operator in the policy guide (WordPress marks it as instructions, not policy text).
			$html .= '<p class="privacy-policy-tutorial"><strong>' . esc_html( self::always_mode_note() ) . '</strong></p>';
		}
		$html .= '<p><em>' . esc_html__( 'This text is a suggestion based on your current settings and must be reviewed by your data protection advisor.', 'affiliate-captain' ) . '</em></p>';
		wp_add_privacy_policy_content( 'Affiliate Captain', wp_kses_post( $html ) );
	}

	/**
	 * Whether the partner still has open commissions (any non-rejected, unpaid row,
	 * also negative ones) or a payout that is not yet paid: bank details are needed.
	 *
	 * @param int $affiliate_id Partner.
	 * @return bool
	 */
	public static function has_open_items( $affiliate_id ) {
		global $wpdb;
		$c = Db::table( 'commissions' );
		$p = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE affiliate_id = %d AND amount_minor <> 0 AND status IN ('pending','review')", $affiliate_id ) );
		// Approved, unassigned rows count by their net sum per currency: a write-off offsets the
		// negative balance it closes (both rows stay unassigned), so nothing is open afterwards.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT currency FROM {$c} WHERE affiliate_id = %d AND status = 'approved' AND payout_id = 0 GROUP BY currency HAVING SUM(amount_minor) <> 0 ) t", $affiliate_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$payouts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE affiliate_id = %d AND status IN ('pending','exported')", $affiliate_id ) );
		return $rows > 0 || $payouts > 0;
	}

	/**
	 * Daily: delete expired per-partner options that contain e-mail addresses or tokens (previous
	 * address kept 30 days after a change, account-link tokens valid 7 days). They used to be
	 * removed only when read again, i.e. possibly never.
	 *
	 * @return int Options deleted.
	 */
	public static function purge_expired_options() {
		global $wpdb;
		$fields = array(
			'wpac_prev_email_' => 'until',
			'wpac_user_link_'  => 'expires',
		);
		$n      = 0;
		foreach ( $fields as $prefix => $field ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 2000", $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( $names as $name ) {
				if ( ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '\d+$/', (string) $name ) ) {
					continue;
				}
				$value = get_option( (string) $name );
				if ( ! is_array( $value ) || ! isset( $value[ $field ] ) || (int) $value[ $field ] < time() ) {
					delete_option( (string) $name );
					++$n;
				}
			}
		}
		return $n;
	}

	/**
	 * Delete retained payout details of erased partners once everything is settled (daily cron).
	 */
	public static function finish_pending_erasures() {
		if ( get_option( self::VISITS_ERASE_OPTION ) ) {
			self::continue_visit_erasure(); // Backup of the single event (R14-D3).
		}
		$pending = array_map( 'intval', (array) get_option( 'wpac_erasure_pending', array() ) );
		if ( ! $pending ) {
			return;
		}
		$left = array();
		foreach ( $pending as $id ) {
			if ( self::has_open_items( $id ) ) {
				$left[] = $id;
				continue;
			}
			ProfileRepository::delete( $id );
			Audit::log( 'privacy.profile_deleted', 'affiliate', $id );
		}
		update_option( 'wpac_erasure_pending', $left, false );
	}

	/**
	 * Anonymise rejected applicants without any commission after the retention period
	 * (daily cron; data minimisation, Art. 5(1)(e) GDPR).
	 *
	 * @return int Number anonymised.
	 */
	public static function purge_rejected_applications() {
		return self::purge_applications( 'rejected', (int) Settings::get( 'application_retention_days' ) );
	}

	/**
	 * Delete applications that were confirmed but never decided (status pending) after the period
	 * set under Settings → Data (pending_application_days, default 365 days; daily cron). The data
	 * is anonymised like a rejected application and the application counts as rejected. Unconfirmed
	 * applications (double opt-in) never become a partner record: they expire after 2 days
	 * (Core\Pending::purge()).
	 *
	 * @return int Number anonymised.
	 */
	public static function purge_pending_applications() {
		return self::purge_applications( 'pending', (int) Settings::get( 'pending_application_days' ) );
	}

	/**
	 * Anonymise the applications of a status older than $days, in batches until none is left or
	 * the daily run's time budget is used up (the rest follows in the next run of the rest, R13-N4:
	 * a backlog of thousands no longer takes weeks at 50 a day). Applicants whose processing is
	 * restricted (Art. 18 GDPR, e.g. kept for legal claims) are skipped.
	 *
	 * @param string $status rejected|pending.
	 * @param int    $days   Period in days (0 = never).
	 * @return int Number anonymised.
	 */
	private static function purge_applications( $status, $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$a      = Db::table( 'affiliates' );
		$c      = Db::table( 'commissions' );
		$before = Db::now( time() - $days * DAY_IN_SECONDS );
		$last   = 0;
		$n      = 0;
		for ( $round = 0; $round < 200; $round++ ) {
			if ( $round > 0 && \WPAffiliateCaptain\Core\Cron::over_budget() ) {
				\WPAffiliateCaptain\Core\Cron::mark_unfinished();
				break;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT a.id FROM {$a} a WHERE a.id > %d AND a.status = %s AND a.status_reason <> 'privacy_erasure' AND a.updated_at < %s AND NOT EXISTS ( SELECT 1 FROM {$c} c WHERE c.affiliate_id = a.id ) ORDER BY a.id ASC LIMIT 50", $last, $status, $before ) );
			foreach ( $ids as $id ) {
				$last = max( $last, (int) $id );
				if ( self::erase_applicant( (int) $id ) ) {
					++$n;
				}
			}
			if ( count( $ids ) < 50 ) {
				break;
			}
		}
		return $n;
	}

	/**
	 * Remove the application data of a rejected or never decided applicant (never a partner with
	 * commissions).
	 * Unlike erase(), this does not touch what the person has as a customer (lifetime links to
	 * other partners stay) and nothing has to be retained: no bookings exist.
	 *
	 * @param int $affiliate_id Applicant.
	 * @return bool
	 */
	public static function erase_applicant( $affiliate_id ) {
		global $wpdb;
		$aff = AffiliateRepository::find( (int) $affiliate_id );
		if ( ! $aff || ! in_array( $aff->status, array( 'rejected', 'pending' ), true ) ) {
			return false;
		}
		if ( Restriction::is_restricted( (int) $aff->id ) ) {
			return false; // Art. 18 GDPR: the data must be kept (e.g. for legal claims), not deleted.
		}
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE affiliate_id = %d", $aff->id ) ) ) {
			return false; // Has bookings: handled by erase() with retention.
		}
		$wpdb->update(
			Db::table( 'affiliates' ),
			array(
				'name'             => __( 'Deleted applicant', 'affiliate-captain' ),
				'email'            => 'deleted-' . (int) $aff->id . '@invalid.invalid',
				'website'          => '',
				'channel'          => '',
				'application_note' => '',
				'application_data' => null,
				'landing_message'  => null,
				'landing_page_id'  => 0,
				'slug'             => 'deleted-' . (int) $aff->id,
				'user_id'          => 0,
				'status'           => 'rejected', // A never decided application ends as rejected.
				'status_reason'    => 'privacy_erasure',
				'updated_at'       => Db::now(),
			),
			array( 'id' => (int) $aff->id )
		);
		$wpdb->delete( Db::table( 'partner_codes' ), array( 'affiliate_id' => (int) $aff->id ) );
		self::delete_partner_visits( (int) $aff->id ); // Rest in the background (R14-D3).
		$wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $aff->id ) );
		ProfileRepository::delete( (int) $aff->id );
		// Without commissions there are no credit notes, so the acceptance records are not needed either.
		Retention::delete_agreements( (int) $aff->id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'audit' ) . " SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d", $aff->id ) );
		self::forget_applicant_options( (int) $aff->id );
		AffiliateRepository::clear_cache( (int) $aff->id );
		Audit::log( 'privacy.applicant_erased', 'affiliate', (int) $aff->id, array( 'was' => (string) $aff->status ) );
		return true;
	}

	/**
	 * Leftovers of an applicant or rejected ex-partner outside the partner row (R13-N4): VIES
	 * result, previous e-mail address, requested public change, account-link token, pending e-mail
	 * change, payment reminders and the mail opt-out.
	 *
	 * @param int $id Partner ID.
	 */
	private static function forget_applicant_options( $id ) {
		global $wpdb;
		foreach ( array( 'wpac_vies_', 'wpac_prev_email_', 'wpac_public_change_', 'wpac_user_link_', \WPAffiliateCaptain\Codes\CodeRequests::HISTORY_PREFIX ) as $prefix ) {
			delete_option( $prefix . $id );
		}
		delete_transient( 'wpac_email_change_' . $id );
		\WPAffiliateCaptain\Core\Pending::delete( 'email_change_' . $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpac_dunning_' . $id . '_' ) . '%' ) ) as $option ) {
			delete_option( (string) $option );
		}
		\WPAffiliateCaptain\Core\Locale::forget_partner( $id );
		$optout = get_option( \WPAffiliateCaptain\Affiliates\Notifications::OPTOUT_OPTION, array() );
		if ( is_array( $optout ) && isset( $optout[ $id ] ) ) {
			unset( $optout[ $id ] );
			update_option( \WPAffiliateCaptain\Affiliates\Notifications::OPTOUT_OPTION, $optout, false );
		}
	}
}
