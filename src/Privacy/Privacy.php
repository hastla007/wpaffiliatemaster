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
		$aff = self::affiliate( $email );
		if ( ! $aff ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$items   = array();
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
		$totals   = Ledger::totals( array( 'affiliate_id' => (int) $aff->id ) );
		$open     = 0;
		foreach ( array( 'pending', 'review', 'approved' ) as $s ) {
			if ( isset( $totals[ $s ] ) ) {
				$open += array_sum( $totals[ $s ] );
			}
		}

		$wpdb->update(
			Db::table( 'affiliates' ),
			array(
				'name'             => __( 'Deleted partner', 'wp-affiliate-captain' ),
				'email'            => 'deleted-' . (int) $aff->id . '@invalid.invalid',
				'website'          => '',
				'channel'          => '',
				'application_note' => '',
				'status'           => in_array( $aff->status, array( 'active', 'paused' ), true ) ? 'terminated' : $aff->status,
				'status_reason'    => 'privacy_erasure',
				'updated_at'       => Db::now(),
			),
			array( 'id' => (int) $aff->id )
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'partner_codes' ) . " SET status = 'blocked' WHERE affiliate_id = %d", $aff->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->delete( Db::table( 'visits' ), array( 'affiliate_id' => (int) $aff->id ) );

		if ( $open > 0 ) {
			$messages[] = __( 'Payout details were retained because the partner still has an open commission balance.', 'wp-affiliate-captain' );
		} else {
			ProfileRepository::delete( (int) $aff->id );
		}
		$messages[] = __( 'Commission and payout records are retained for bookkeeping obligations.', 'wp-affiliate-captain' );
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
		wp_add_privacy_policy_content(
			'WP Affiliate Captain',
			wp_kses_post(
				'<p>' . __( 'When you visit our website through a partner link or enter a partner code, we store which partner referred you so we can pay the partner a commission. We do not store your IP address for this purpose. An affiliate cookie is only set if you consented to marketing cookies. Partners do not receive your name or contact details.', 'wp-affiliate-captain' ) . '</p>' .
				'<p>' . __( 'For partners we store application details, accepted terms (version and time), partner codes, commission records and payout details. Bank details are stored encrypted.', 'wp-affiliate-captain' ) . '</p>' .
				'<p><em>' . __( 'This text is a suggestion and must be reviewed by your data protection advisor.', 'wp-affiliate-captain' ) . '</em></p>'
			)
		);
	}
}
