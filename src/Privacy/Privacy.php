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
use WPAffiliateCaptain\Commissions\Ledger;
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
		$exporters['wp-affiliate-captain'] = array(
			'exporter_friendly_name' => __( 'Affiliate Captain partner data', 'wp-affiliate-captain' ),
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
		$erasers['wp-affiliate-captain'] = array(
			'eraser_friendly_name' => __( 'Affiliate Captain partner data', 'wp-affiliate-captain' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$items = array();
		// Customers: lifetime partner link (stored as keyed hash).
		$user = get_user_by( 'email', $email );
		$link = \WPAffiliateCaptain\Tracking\CustomerLinks::find( \WPAffiliateCaptain\Tracking\CustomerLinks::hashes( $user ? (int) $user->ID : 0, $email ) );
		if ( $link ) {
			$items[] = array(
				'group_id'    => 'wpac-customer-link',
				'group_label' => __( 'Partner referral (lifetime link)', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-customer-link-' . $link->id,
				'data'        => array(
					array( 'name' => __( 'Partner ID', 'wp-affiliate-captain' ), 'value' => (string) $link->affiliate_id ),
					array( 'name' => __( 'Since', 'wp-affiliate-captain' ), 'value' => $link->created_at ),
					array( 'name' => __( 'Valid until', 'wp-affiliate-captain' ), 'value' => $link->expires_at ? $link->expires_at : '—' ),
				),
			);
		}
		$aff = self::affiliate( $email );
		if ( ! $aff ) {
			return array(
				'data' => $items,
				'done' => true,
			);
		}
		$items[] = array(
			'group_id'    => 'wpac-affiliate',
			'group_label' => __( 'Partner account', 'wp-affiliate-captain' ),
			'item_id'     => 'wpac-affiliate-' . $aff->id,
			'data'        => array(
				array( 'name' => __( 'Name', 'wp-affiliate-captain' ), 'value' => $aff->name ),
				array( 'name' => __( 'E-mail', 'wp-affiliate-captain' ), 'value' => $aff->email ),
				array( 'name' => __( 'Website', 'wp-affiliate-captain' ), 'value' => $aff->website ),
				array( 'name' => __( 'Channel', 'wp-affiliate-captain' ), 'value' => $aff->channel ),
				array( 'name' => __( 'Application', 'wp-affiliate-captain' ), 'value' => (string) $aff->application_note ),
				array( 'name' => __( 'Status', 'wp-affiliate-captain' ), 'value' => $aff->status ),
				array( 'name' => __( 'Created', 'wp-affiliate-captain' ), 'value' => $aff->created_at ),
			),
		);
		foreach ( CodeRepository::for_affiliate( (int) $aff->id ) as $c ) {
			$items[] = array(
				'group_id'    => 'wpac-codes',
				'group_label' => __( 'Partner codes', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-code-' . $c->id,
				'data'        => array(
					array( 'name' => __( 'Code', 'wp-affiliate-captain' ), 'value' => $c->code ),
					array( 'name' => __( 'Used', 'wp-affiliate-captain' ), 'value' => (string) $c->usage_count ),
				),
			);
		}
		foreach ( Agreements::for_affiliate( (int) $aff->id ) as $a ) {
			$items[] = array(
				'group_id'    => 'wpac-agreements',
				'group_label' => __( 'Terms acceptance', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-agreement-' . $a->id,
				'data'        => array(
					array( 'name' => __( 'Version', 'wp-affiliate-captain' ), 'value' => $a->version ),
					array( 'name' => __( 'Document hash', 'wp-affiliate-captain' ), 'value' => $a->doc_hash ),
					array( 'name' => __( 'Result', 'wp-affiliate-captain' ), 'value' => $a->result ),
					array( 'name' => __( 'Date', 'wp-affiliate-captain' ), 'value' => $a->created_at ),
				),
			);
		}
		$profile = ProfileRepository::get( (int) $aff->id );
		if ( $profile ) {
			$snap    = ProfileRepository::snapshot( $profile );
			$data    = array();
			foreach ( $snap as $k => $v ) {
				$data[] = array(
					'name'  => $k,
					'value' => (string) $v,
				);
			}
			$items[] = array(
				'group_id'    => 'wpac-payout-profile',
				'group_label' => __( 'Payout details', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-profile-' . $aff->id,
				'data'        => $data,
			);
		}
		list( $rows ) = Ledger::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 0,
			)
		);
		foreach ( $rows as $r ) {
			$items[] = array(
				'group_id'    => 'wpac-commissions',
				'group_label' => __( 'Commissions', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-commission-' . $r->id,
				'data'        => array(
					array( 'name' => __( 'Date', 'wp-affiliate-captain' ), 'value' => $r->created_at ),
					array( 'name' => __( 'Order', 'wp-affiliate-captain' ), 'value' => $r->source . ' #' . $r->source_order_id ),
					array( 'name' => __( 'Amount', 'wp-affiliate-captain' ), 'value' => Money::format( (int) $r->amount_minor, $r->currency ) ),
					array( 'name' => __( 'Status', 'wp-affiliate-captain' ), 'value' => $r->status ),
				),
			);
		}
		$app = json_decode( (string) $aff->application_data, true );
		$extra = array(
			array( 'name' => __( 'Vanity slug', 'wp-affiliate-captain' ), 'value' => $aff->slug ),
			array( 'name' => __( 'Last reason', 'wp-affiliate-captain' ), 'value' => (string) $aff->status_reason ),
			array( 'name' => __( 'Welcome message on landing page', 'wp-affiliate-captain' ), 'value' => (string) $aff->landing_message ),
		);
		foreach ( is_array( $app ) ? $app : array() as $field ) {
			$extra[] = array(
				'name'  => isset( $field['label'] ) ? $field['label'] : '',
				'value' => isset( $field['value'] ) ? (string) $field['value'] : '',
			);
		}
		$items[] = array(
			'group_id'    => 'wpac-affiliate-details',
			'group_label' => __( 'Application', 'wp-affiliate-captain' ),
			'item_id'     => 'wpac-affiliate-details-' . $aff->id,
			'data'        => $extra,
		);
		list( $payouts ) = \WPAffiliateCaptain\Payouts\PayoutService::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 1000,
			)
		);
		foreach ( $payouts as $p ) {
			$items[] = array(
				'group_id'    => 'wpac-payouts',
				'group_label' => __( 'Payouts', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-payout-' . $p->id,
				'data'        => array(
					array( 'name' => _x( 'Number', 'document number', 'wp-affiliate-captain' ), 'value' => $p->number ),
					array( 'name' => __( 'Date', 'wp-affiliate-captain' ), 'value' => $p->created_at ),
					array( 'name' => __( 'Amount', 'wp-affiliate-captain' ), 'value' => Money::format( (int) $p->amount_minor, $p->currency ) ),
					array( 'name' => __( 'Status', 'wp-affiliate-captain' ), 'value' => $p->status ),
				),
			);
		}
		foreach ( \WPAffiliateCaptain\Payouts\PayoutRequests::query( '', (int) $aff->id ) as $r ) {
			$items[] = array(
				'group_id'    => 'wpac-payout-requests',
				'group_label' => __( 'Payout requests', 'wp-affiliate-captain' ),
				'item_id'     => 'wpac-request-' . $r->id,
				'data'        => array(
					array( 'name' => __( 'Date', 'wp-affiliate-captain' ), 'value' => $r->created_at ),
					array( 'name' => __( 'Amount', 'wp-affiliate-captain' ), 'value' => Money::format( (int) $r->amount_minor, $r->currency ) ),
					array( 'name' => __( 'Status', 'wp-affiliate-captain' ), 'value' => $r->status ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
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
		// Customers: remove lifetime partner links (stored as hashes).
		$user    = get_user_by( 'email', $email );
		$removed = \WPAffiliateCaptain\Tracking\CustomerLinks::forget( $user ? (int) $user->ID : 0, $email ) > 0;

		$aff = self::affiliate( $email );
		if ( ! $aff ) {
			return array(
				'items_removed'  => $removed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$messages = array();
		$open     = self::has_open_items( (int) $aff->id );

		$wpdb->update(
			Db::table( 'affiliates' ),
			array(
				'name'             => __( 'Deleted partner', 'wp-affiliate-captain' ),
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
		// Codes may be derived from the name (vanity codes) → replace them by neutral values.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'blocked', code = CONCAT('DEL-', id) WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( Db::table( 'visits' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payout_requests' ) . " SET note = '' WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Free-text reasons and notes may contain personal data.
		$c = Db::table( 'commissions' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'commission_events' ) . " SET reason = '' WHERE commission_id IN (SELECT id FROM {$c} WHERE affiliate_id = %d)", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'attributions' ) . " SET note = '' WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Free-text details in the audit log (reasons may contain personal data); acceptance records stay as proof.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'audit' ) . " SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d AND action NOT LIKE 'agreement.%%'", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache();

		if ( $open ) {
			// Deleted automatically once everything is settled (daily cron).
			update_option( 'wpac_erasure_pending', array_values( array_unique( array_merge( (array) get_option( 'wpac_erasure_pending', array() ), array( (int) $aff->id ) ) ) ), false );
			$messages[] = __( 'Payout details were retained because the partner still has open commissions or payouts. They are deleted automatically once everything is settled.', 'wp-affiliate-captain' );
		} else {
			ProfileRepository::delete( (int) $aff->id );
		}
		$messages[] = __( 'Commission and payout records, credit notes and the partner data they contain (name, address, VAT ID) are retained for statutory bookkeeping obligations (e.g. §147 AO) and deleted after the configured retention period.', 'wp-affiliate-captain' );
		Audit::log( 'privacy.erased', 'affiliate', (int) $aff->id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$p   = array();
		$p[] = __( 'When you visit our website through a partner link or enter a partner code, we store which partner referred you so we can pay the partner a commission. We do not store your IP address for this purpose. Partners do not receive your name or contact details.', 'wp-affiliate-captain' );
		$mode = Settings::get( 'cookie_mode' );
		if ( 'consent' === $mode ) {
			/* translators: %d: days */
			$p[] = sprintf( __( 'An affiliate cookie (wpac_ref, %d days) is only set if you consented to marketing cookies; withdrawing consent removes it.', 'wp-affiliate-captain' ), (int) Settings::get( 'cookie_days' ) );
		} elseif ( 'always' === $mode ) {
			/* translators: %d: days */
			$p[] = sprintf( __( 'An affiliate cookie (wpac_ref, %d days) is set when you follow a partner link.', 'wp-affiliate-captain' ), (int) Settings::get( 'cookie_days' ) );
		}
		if ( Settings::get( 'use_wc_session' ) ) {
			$p[] = __( 'If you already have a shopping cart (shop session) or consented to marketing cookies, the partner code is kept in your shop session until checkout.', 'wp-affiliate-captain' );
		}
		if ( Settings::get( 'lifetime_enabled' ) ) {
			$days = (int) Settings::get( 'lifetime_days' );
			$p[]  = $days > 0
				/* translators: %d: days */
				? sprintf( __( 'After a purchase via a partner, later purchases within %d days are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', 'wp-affiliate-captain' ), $days )
				: __( 'After a purchase via a partner, later purchases are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', 'wp-affiliate-captain' );
		}
		$p[]  = __( 'For partners we store application details, accepted terms (version and time), partner codes, commission records and payout details. Bank details are stored encrypted. Credit notes are kept for the statutory retention period.', 'wp-affiliate-captain' );
		$html = '';
		foreach ( $p as $para ) {
			$html .= '<p>' . esc_html( $para ) . '</p>';
		}
		$html .= '<p><em>' . esc_html__( 'This text is a suggestion based on your current settings and must be reviewed by your data protection advisor.', 'wp-affiliate-captain' ) . '</em></p>';
		wp_add_privacy_policy_content( 'WP Affiliate Captain', wp_kses_post( $html ) );
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
	 * Delete retained payout details of erased partners once everything is settled (daily cron).
	 */
	public static function finish_pending_erasures() {
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
		global $wpdb;
		$days = (int) Settings::get( 'application_retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$a = Db::table( 'affiliates' );
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$emails = $wpdb->get_col( $wpdb->prepare( "SELECT a.email FROM {$a} a WHERE a.status = 'rejected' AND a.status_reason <> 'privacy_erasure' AND a.updated_at < %s AND NOT EXISTS ( SELECT 1 FROM {$c} c WHERE c.affiliate_id = a.id ) LIMIT 50", Db::now( time() - $days * DAY_IN_SECONDS ) ) );
		foreach ( $emails as $email ) {
			self::erase( (string) $email );
		}
		return count( $emails );
	}
}
