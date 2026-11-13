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
				'group_label' => __( 'Partner referral (lifetime link)', 'affiliate-captain' ),
				'item_id'     => 'wpac-customer-link-' . $link->id,
				'data'        => array(
					array( 'name' => __( 'Partner ID', 'affiliate-captain' ), 'value' => (string) $link->affiliate_id ),
					array( 'name' => __( 'Since', 'affiliate-captain' ), 'value' => $link->created_at ),
					array( 'name' => __( 'Valid until', 'affiliate-captain' ), 'value' => $link->expires_at ? $link->expires_at : '—' ),
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
			'group_label' => __( 'Partner account', 'affiliate-captain' ),
			'item_id'     => 'wpac-affiliate-' . $aff->id,
			'data'        => array(
				array( 'name' => __( 'Name', 'affiliate-captain' ), 'value' => $aff->name ),
				array( 'name' => __( 'E-mail', 'affiliate-captain' ), 'value' => $aff->email ),
				array( 'name' => __( 'Website', 'affiliate-captain' ), 'value' => $aff->website ),
				array( 'name' => __( 'Channel', 'affiliate-captain' ), 'value' => $aff->channel ),
				array( 'name' => __( 'Application', 'affiliate-captain' ), 'value' => (string) $aff->application_note ),
				array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => $aff->status ),
				array( 'name' => __( 'Created', 'affiliate-captain' ), 'value' => $aff->created_at ),
			),
		);
		foreach ( CodeRepository::for_affiliate( (int) $aff->id ) as $c ) {
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
		foreach ( Agreements::for_affiliate( (int) $aff->id ) as $a ) {
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
				'group_label' => __( 'Payout details', 'affiliate-captain' ),
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
				'group_label' => __( 'Commissions', 'affiliate-captain' ),
				'item_id'     => 'wpac-commission-' . $r->id,
				'data'        => array(
					array( 'name' => __( 'Date', 'affiliate-captain' ), 'value' => $r->created_at ),
					array( 'name' => __( 'Order', 'affiliate-captain' ), 'value' => $r->source . ' #' . $r->source_order_id ),
					array( 'name' => __( 'Type', 'affiliate-captain' ), 'value' => (string) $r->kind ),
					array( 'name' => __( 'Description', 'affiliate-captain' ), 'value' => (string) $r->description ),
					array( 'name' => __( 'Commission base', 'affiliate-captain' ), 'value' => Money::format( (int) $r->base_minor, $r->currency ) ),
					array( 'name' => __( 'Amount', 'affiliate-captain' ), 'value' => Money::format( (int) $r->amount_minor, $r->currency ) ),
					array( 'name' => __( 'Status', 'affiliate-captain' ), 'value' => $r->status ),
					array( 'name' => __( 'Held until', 'affiliate-captain' ), 'value' => (string) $r->hold_until ),
				),
			);
		}
		global $wpdb;
		$at = Db::table( 'attributions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$at} WHERE affiliate_id = %d ORDER BY id DESC LIMIT 5000", $aff->id ) ) as $a ) {
			$items[] = array(
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
		$vt = Db::table( 'visits' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visits = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, MIN(created_at) first_at, MAX(created_at) last_at FROM {$vt} WHERE affiliate_id = %d", $aff->id ) );
		if ( $visits && (int) $visits->n ) {
			$items[] = array(
				'group_id'    => 'wpac-visits',
				'group_label' => __( 'Link visits (without IP address)', 'affiliate-captain' ),
				'item_id'     => 'wpac-visits-' . $aff->id,
				'data'        => array(
					array( 'name' => __( 'Number of visits', 'affiliate-captain' ), 'value' => (string) (int) $visits->n ),
					array( 'name' => __( 'First visit', 'affiliate-captain' ), 'value' => (string) $visits->first_at ),
					array( 'name' => __( 'Last visit', 'affiliate-captain' ), 'value' => (string) $visits->last_at ),
				),
			);
		}
		$app = json_decode( (string) $aff->application_data, true );
		$extra = array(
			array( 'name' => __( 'Vanity slug', 'affiliate-captain' ), 'value' => $aff->slug ),
			array( 'name' => __( 'Last reason', 'affiliate-captain' ), 'value' => (string) $aff->status_reason ),
			array( 'name' => __( 'Welcome message on landing page', 'affiliate-captain' ), 'value' => (string) $aff->landing_message ),
			array( 'name' => __( 'Partner group', 'affiliate-captain' ), 'value' => (int) $aff->group_id ? (string) ( \WPAffiliateCaptain\Affiliates\Groups::find( (int) $aff->group_id ) ? \WPAffiliateCaptain\Affiliates\Groups::find( (int) $aff->group_id )->name : $aff->group_id ) : '—' ),
			array( 'name' => __( 'Linked user account', 'affiliate-captain' ), 'value' => (int) $aff->user_id ? '#' . (int) $aff->user_id : '—' ),
		);
		$restriction = Restriction::get( (int) $aff->id );
		if ( $restriction ) {
			$extra[] = array( 'name' => __( 'Processing restricted since', 'affiliate-captain' ), 'value' => (string) $restriction['since'] );
		}
		$pending = get_transient( 'wpac_email_change_' . (int) $aff->id );
		if ( is_array( $pending ) ) {
			$extra[] = array( 'name' => __( 'Requested new e-mail address (not confirmed)', 'affiliate-captain' ), 'value' => (string) $pending['email'] );
		}
		$prev = AffiliateRepository::previous_email( (int) $aff->id );
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
		foreach ( \WPAffiliateCaptain\Payouts\PayoutRequests::query( '', (int) $aff->id ) as $r ) {
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
		// Codes may be derived from the name (vanity codes) → replace them by neutral values.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'blocked', code = CONCAT('DEL-', id) WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( Db::table( 'visits' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payout_requests' ) . " SET note = '' WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Booking records (commission events, attribution notes, payout notes, write-off reasons and
		// the payout change log) must stay unchanged during the retention period: processing is
		// restricted instead (Art. 17(3)(b), Art. 18 GDPR) and the free texts are deleted when the
		// period ends (Retention::purge_erased()).
		Restriction::add( (int) $aff->id, Restriction::ERASURE );
		// Pending e-mail change, previous address and account-link token contain addresses.
		delete_transient( 'wpac_email_change_' . (int) $aff->id );
		delete_option( 'wpac_prev_email_' . (int) $aff->id );
		delete_option( 'wpac_user_link_' . (int) $aff->id );
		// Requested public name/website change, VIES result (VAT ID, company name) and payment
		// request history (one option per currency).
		delete_option( 'wpac_public_change_' . (int) $aff->id );
		delete_option( 'wpac_vies_' . (int) $aff->id );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpac_dunning_' . (int) $aff->id . '_' ) . '%' ) ) as $option ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_option( (string) $option );
		}
		// Free-text details in the audit log (reasons may contain personal data); acceptance records stay as proof.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'audit' ) . " SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d AND action NOT LIKE 'agreement.%%'", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache();

		if ( $open ) {
			// Deleted automatically once everything is settled (daily cron).
			update_option( 'wpac_erasure_pending', array_values( array_unique( array_merge( (array) get_option( 'wpac_erasure_pending', array() ), array( (int) $aff->id ) ) ) ), false );
			$messages[] = __( 'Payout details were retained because the partner still has open commissions or payouts. They are deleted automatically once everything is settled.', 'affiliate-captain' );
		} else {
			ProfileRepository::delete( (int) $aff->id );
		}
		$messages[] = __( 'Commission and payout records, credit notes and the partner data they contain (name, address, VAT ID) are retained for statutory bookkeeping obligations (e.g. §147 AO); their processing is restricted and they are deleted after the retention period.', 'affiliate-captain' );
		Audit::log( 'privacy.erased', 'affiliate', (int) $aff->id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => true,
		);
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
		$admin = (string) Settings::get( 'admin_email' );
		wp_mail(
			is_email( $admin ) ? $admin : (string) get_option( 'admin_email' ),
			'restrict' === $type
				/* translators: %s: partner */
				? sprintf( __( 'Data protection: %s asks to restrict processing', 'affiliate-captain' ), $aff->name )
				/* translators: %s: partner */
				: sprintf( __( 'Data protection: %s objects to processing', 'affiliate-captain' ), $aff->name ),
			/* translators: 1: details, 2: URL */
			sprintf( __( "Details from the partner:\n\n%1\$s\n\nPlease answer within one month (Art. 12(3) GDPR). Restrict processing on the partner page if justified:\n%2\$s", 'affiliate-captain' ), $note, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $aff->id ) )
		);
		Audit::log( 'privacy.request', 'affiliate', (int) $aff->id, array( 'type' => $type ) );
		return 'privacy_sent';
	}

	/**
	 * Record of processing activities (template in docs/, filled with the current settings).
	 *
	 * @return string Markdown.
	 */
	public static function ropa() {
		$file = WPAC_DIR . 'docs/VERARBEITUNGSVERZEICHNIS.md';
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$name = (string) Settings::get( 'debtor_name' );
		$days = static function ( $key ) {
			$v = (int) Settings::get( $key );
			return $v > 0 ? (string) $v : '∞';
		};
		$life = (int) Settings::get( 'lifetime_days' );
		$map  = array(
			'{operator_name}'              => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{operator_address}'           => str_replace( array( "\r\n", "\n" ), ', ', (string) Settings::get( 'operator_address' ) ),
			'{date}'                       => wp_date( 'Y-m-d' ),
			'{visit_retention_days}'       => $days( 'visit_retention_days' ),
			'{cookie_days}'                => $days( 'cookie_days' ),
			'{lifetime_days}'              => Settings::get( 'lifetime_enabled' ) ? ( $life > 0 ? $life . ' Tage' : 'bis Vertragsende' ) : 'nicht aktiv',
			'{retention_years}'            => Retention::years() > 0 ? (string) Retention::years() : '∞',
			'{application_retention_days}' => $days( 'application_retention_days' ),
			'{audit_retention_days}'       => $days( 'audit_retention_days' ),
		);
		return strtr( $text, $map );
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
		if ( 'consent' === $mode ) {
			/* translators: %d: days */
			$p[] = sprintf( _n( 'An affiliate cookie (wpac_ref, %d day) is only set if you consented to marketing cookies; withdrawing consent removes it.', 'An affiliate cookie (wpac_ref, %d days) is only set if you consented to marketing cookies; withdrawing consent removes it.', $cookie_days, 'affiliate-captain' ), $cookie_days );
		} elseif ( 'always' === $mode ) {
			/* translators: %d: days */
			$p[] = sprintf( _n( 'An affiliate cookie (wpac_ref, %d day) is set when you follow a partner link.', 'An affiliate cookie (wpac_ref, %d days) is set when you follow a partner link.', $cookie_days, 'affiliate-captain' ), $cookie_days );
		}
		if ( Settings::get( 'use_wc_session' ) ) {
			$p[] = __( 'If you already have a shopping cart (shop session) or consented to marketing cookies, the partner code is kept in your shop session until checkout.', 'affiliate-captain' );
		}
		if ( Settings::get( 'lifetime_enabled' ) ) {
			$days = (int) Settings::get( 'lifetime_days' );
			$p[]  = $days > 0
				/* translators: %d: days */
				? sprintf( _n( 'After a purchase via a partner, later purchases within %d day are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', 'After a purchase via a partner, later purchases within %d days are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', $days, 'affiliate-captain' ), $days )
				: __( 'After a purchase via a partner, later purchases are also credited to this partner. For this we store a keyed hash of your customer account and e-mail address, not the address itself.', 'affiliate-captain' );
		}
		if ( Settings::get( 'js_tracking' ) ) {
			$p[] = __( 'Pages served from a cache report the partner code in the page address to our server with a small script (the same information the page request itself contains: partner code, campaign, page). The address of the previous website is only sent as its host name and only with your marketing consent.', 'affiliate-captain' );
		}
		$p[] = __( 'For partners we store application details, accepted terms (version, time and the accepted text), partner codes, commission records and payout details. Bank details are stored encrypted.', 'affiliate-captain' );

		// Storage periods.
		$years    = Retention::years();
		$periods  = array();
		$visits   = (int) Settings::get( 'visit_retention_days' );
		$apps     = (int) Settings::get( 'application_retention_days' );
		$audit    = (int) Settings::get( 'audit_retention_days' );
		/* translators: %d: days */
		$periods[] = $visits > 0 ? sprintf( _n( 'link visits (without IP address): %d day', 'link visits (without IP address): %d days', $visits, 'affiliate-captain' ), $visits ) : __( 'link visits (without IP address): until deleted manually', 'affiliate-captain' );
		if ( Settings::get( 'cookie_mode' ) !== 'off' ) {
			/* translators: %d: days */
			$periods[] = sprintf( _n( 'affiliate cookie: %d day', 'affiliate cookie: %d days', $cookie_days, 'affiliate-captain' ), $cookie_days );
		}
		if ( Settings::get( 'lifetime_enabled' ) ) {
			$days      = (int) Settings::get( 'lifetime_days' );
			/* translators: %d: days */
			$periods[] = $days > 0 ? sprintf( _n( 'customer link to the referring partner: %d day', 'customer link to the referring partner: %d days', $days, 'affiliate-captain' ), $days ) : __( 'customer link to the referring partner: until the partner relationship ends', 'affiliate-captain' );
		}
		/* translators: %d: days */
		$periods[] = $apps > 0 ? sprintf( _n( 'rejected partner applications: %d day', 'rejected partner applications: %d days', $apps, 'affiliate-captain' ), $apps ) : __( 'rejected partner applications: until deleted manually', 'affiliate-captain' );
		/* translators: %d: years */
		$periods[] = $years > 0 ? sprintf( _n( 'commission records, credit notes, payout details and the accepted terms: %d year after the end of the calendar year (statutory retention; longer during a tax audit)', 'commission records, credit notes, payout details and the accepted terms: %d years after the end of the calendar year (statutory retention; longer during a tax audit)', $years, 'affiliate-captain' ), $years ) : __( 'commission records, credit notes, payout details and the accepted terms: statutory retention period, currently not deleted automatically', 'affiliate-captain' );
		/* translators: %d: days */
		$periods[] = $audit > 0 ? sprintf( _n( 'change log of other settings and partner data: %d day', 'change log of other settings and partner data: %d days', $audit, 'affiliate-captain' ), $audit ) : __( 'change log: until deleted manually', 'affiliate-captain' );
		$p[] = __( 'Storage periods:', 'affiliate-captain' ) . ' ' . implode( '; ', $periods ) . '.';

		// Legal bases.
		$p[] = __( 'Legal bases: attribution of an order to a partner without cookie: legitimate interest in paying the partner correctly (Art. 6(1)(f) GDPR); affiliate cookie and reading the previous page: your consent (Art. 6(1)(a) GDPR, § 25 TDDDG); partner program, commissions and payouts: contract with the partner (Art. 6(1)(b) GDPR); bookkeeping and retention of credit notes: legal obligation (Art. 6(1)(c) GDPR, § 147 AO / § 132 BAO / Art. 958f OR).', 'affiliate-captain' );

		// Recipients.
		$p[] = __( 'Recipients: partners see their own orders only without customer names or contact details; payout details go to our bank for the transfer and, as part of our bookkeeping, to our tax advisor and the tax authorities; our hosting provider processes the data on our behalf (Art. 28 GDPR). Data is not transferred to third countries by this plugin.', 'affiliate-captain' );
		// VIES (Vies::check()): on unless switched off under Settings → Payouts ("vies_check").
		$vies = Settings::get( 'vies_check' );
		if ( null === $vies || $vies ) {
			$p[] = __( 'VAT ID check: if a partner enters an EU VAT ID, it is checked with the VIES service of the European Commission (ec.europa.eu) when the payout details are saved and again periodically. The VAT ID of the partner and our own VAT ID are sent; the European Commission receives these as an independent controller (legal basis: our obligation and legitimate interest to verify the VAT ID before issuing reverse-charge credit notes, Art. 6(1)(c) and (f) GDPR). The result and the consultation number are stored with the payout details.', 'affiliate-captain' );
		}
		$p[] = __( 'Partners can ask for a copy of their data, its correction, erasure or restriction of processing, and can object to processing based on legitimate interest. Records we must keep by law are restricted instead of deleted until the retention period ends.', 'affiliate-captain' );
		$html = '';
		foreach ( $p as $para ) {
			$html .= '<p>' . esc_html( $para ) . '</p>';
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
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT a.id FROM {$a} a WHERE a.status = 'rejected' AND a.status_reason <> 'privacy_erasure' AND a.updated_at < %s AND NOT EXISTS ( SELECT 1 FROM {$c} c WHERE c.affiliate_id = a.id ) LIMIT 50", Db::now( time() - $days * DAY_IN_SECONDS ) ) );
		foreach ( $ids as $id ) {
			self::erase_applicant( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * Remove the application data of a rejected applicant (never a partner with commissions).
	 * Unlike erase(), this does not touch what the person has as a customer (lifetime links to
	 * other partners stay) and nothing has to be retained: no bookings exist.
	 *
	 * @param int $affiliate_id Applicant.
	 * @return bool
	 */
	public static function erase_applicant( $affiliate_id ) {
		global $wpdb;
		$aff = AffiliateRepository::find( (int) $affiliate_id );
		if ( ! $aff || 'rejected' !== $aff->status ) {
			return false;
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
				'slug'             => 'deleted-' . (int) $aff->id,
				'user_id'          => 0,
				'status_reason'    => 'privacy_erasure',
				'updated_at'       => Db::now(),
			),
			array( 'id' => (int) $aff->id )
		);
		$wpdb->delete( Db::table( 'partner_codes' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->delete( Db::table( 'visits' ), array( 'affiliate_id' => (int) $aff->id ) );
		$wpdb->delete( Db::table( 'customer_links' ), array( 'affiliate_id' => (int) $aff->id ) );
		ProfileRepository::delete( (int) $aff->id );
		// Without commissions there are no credit notes, so the acceptance records are not needed either.
		Retention::delete_agreements( (int) $aff->id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'audit' ) . " SET details = NULL WHERE object_type = 'affiliate' AND object_id = %d", $aff->id ) );
		AffiliateRepository::clear_cache( (int) $aff->id );
		Audit::log( 'privacy.applicant_erased', 'affiliate', (int) $aff->id );
		return true;
	}
}
