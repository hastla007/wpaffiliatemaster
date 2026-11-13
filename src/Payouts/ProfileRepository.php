<?php
/**
 * Payout profiles: tax status, address, VAT ID and encrypted bank details.
 * Reading full details requires the wpac_view_tax_data capability.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Crypto;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Iban;

defined( 'ABSPATH' ) || exit;

final class ProfileRepository {

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function tax_status_labels() {
		return array(
			'private'        => __( 'Private individual', 'wp-affiliate-captain' ),
			'small_business' => __( 'Small business (no VAT, e.g. §19 UStG)', 'wp-affiliate-captain' ),
			'business'       => __( 'Business (VAT registered)', 'wp-affiliate-captain' ),
		);
	}

	/**
	 * Profile row (IBAN stays encrypted).
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return object|null
	 */
	public static function get( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'payout_profiles' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d", $affiliate_id ) );
	}

	/**
	 * Decrypted IBAN (for SEPA export only).
	 *
	 * @param object $profile Profile.
	 * @return string|null
	 */
	public static function iban( $profile ) {
		return $profile ? Crypto::decrypt( (string) $profile->iban_encrypted ) : null;
	}

	/**
	 * Whether a profile is complete for payouts.
	 *
	 * @param object|null $profile Profile.
	 * @return bool
	 */
	public static function is_complete( $profile ) {
		return true === self::missing( $profile );
	}

	/**
	 * First missing mandatory field of a profile (credit notes need name and address of
	 * both parties; businesses and small businesses a VAT ID or tax number), or true.
	 *
	 * @param object|null $profile Profile.
	 * @return true|string Field key.
	 */
	public static function missing( $profile ) {
		if ( ! $profile ) {
			return 'tax_status';
		}
		foreach ( array( 'tax_status', 'legal_name', 'address', 'country', 'account_holder' ) as $f ) {
			if ( '' === trim( (string) $profile->$f ) ) {
				return $f;
			}
		}
		if ( '' === (string) $profile->iban_encrypted ) {
			return 'iban';
		}
		if ( 'business' === $profile->tax_status && '' === (string) $profile->vat_id ) {
			return 'vat_id';
		}
		if ( 'small_business' === $profile->tax_status && '' === (string) $profile->vat_id && '' === trim( (string) ( isset( $profile->tax_number ) ? $profile->tax_number : '' ) ) ) {
			return 'tax_number';
		}
		return true;
	}

	/**
	 * Save (validate) profile input.
	 *
	 * @param int   $affiliate_id Affiliate.
	 * @param array $in           Raw (unslashed) input. Empty IBAN keeps the stored one.
	 * @return true|\WP_Error
	 */
	public static function save( $affiliate_id, array $in ) {
		global $wpdb;
		$labels = self::tax_status_labels();
		$tax    = isset( $in['tax_status'] ) && isset( $labels[ $in['tax_status'] ] ) ? $in['tax_status'] : '';
		$data   = array(
			'affiliate_id'   => (int) $affiliate_id,
			'tax_status'     => $tax,
			'legal_name'     => sanitize_text_field( isset( $in['legal_name'] ) ? $in['legal_name'] : '' ),
			'address'        => sanitize_textarea_field( isset( $in['address'] ) ? $in['address'] : '' ),
			'country'        => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', isset( $in['country'] ) ? (string) $in['country'] : '' ), 0, 2 ) ),
			'vat_id'         => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', isset( $in['vat_id'] ) ? (string) $in['vat_id'] : '' ) ),
			'tax_number'     => substr( preg_replace( '/[^A-Za-z0-9\/ .\-]/', '', isset( $in['tax_number'] ) ? (string) $in['tax_number'] : '' ), 0, 40 ),
			'account_holder' => sanitize_text_field( isset( $in['account_holder'] ) ? $in['account_holder'] : '' ),
			'bic'            => strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', isset( $in['bic'] ) ? (string) $in['bic'] : '' ), 0, 11 ) ),
			'updated_at'     => Db::now(),
		);
		if ( 'business' === $tax && '' === $data['vat_id'] ) {
			return new \WP_Error( 'wpac_vat', __( 'Please enter your VAT ID.', 'wp-affiliate-captain' ) );
		}
		if ( 'small_business' === $tax && '' === $data['vat_id'] && '' === trim( $data['tax_number'] ) ) {
			return new \WP_Error( 'wpac_tax_number', __( 'Please enter your tax number or VAT ID (required on credit notes).', 'wp-affiliate-captain' ) );
		}
		$iban = Iban::normalize( isset( $in['iban'] ) ? (string) $in['iban'] : '' );
		if ( '' !== $iban ) {
			if ( ! Iban::is_valid( $iban ) ) {
				return new \WP_Error( 'wpac_iban', __( 'The IBAN is not valid.', 'wp-affiliate-captain' ) );
			}
			$data['iban_encrypted'] = Crypto::encrypt( $iban );
			$data['iban_country']   = substr( $iban, 0, 2 );
			$data['iban_last4']     = substr( $iban, -4 );
		}
		$existing = self::get( $affiliate_id );
		if ( $existing ) {
			$wpdb->update( Db::table( 'payout_profiles' ), $data, array( 'affiliate_id' => (int) $affiliate_id ) );
		} else {
			$wpdb->insert( Db::table( 'payout_profiles' ), $data + array( 'iban_encrypted' => '' ) );
		}
		if ( '' !== $data['vat_id'] && ( ! $existing || (string) $existing->vat_id !== $data['vat_id'] ) ) {
			Vies::schedule( (int) $affiliate_id ); // Check the new VAT ID against VIES in the background.
		}
		if ( '' !== $iban && $existing && '' !== (string) $existing->iban_encrypted && Crypto::decrypt( (string) $existing->iban_encrypted ) !== $iban ) {
			\WPAffiliateCaptain\Affiliates\Notifications::bank_details_changed( (int) $affiliate_id );
		}
		Audit::log(
			'payout_profile.updated',
			'affiliate',
			(int) $affiliate_id,
			array(
				'tax_status'   => $tax,
				'iban_changed' => '' !== $iban,
			)
		);
		return true;
	}

	/**
	 * Snapshot stored with a payout (no IBAN, only masked).
	 *
	 * @param object|null $profile Profile.
	 * @return array
	 */
	public static function snapshot( $profile ) {
		if ( ! $profile ) {
			return array();
		}
		return array(
			'tax_status'     => $profile->tax_status,
			'legal_name'     => $profile->legal_name,
			'address'        => $profile->address,
			'country'        => $profile->country,
			'vat_id'         => $profile->vat_id,
			'tax_number'     => isset( $profile->tax_number ) ? $profile->tax_number : '',
			'account_holder' => $profile->account_holder,
			'iban_masked'    => Iban::mask( $profile->iban_last4, $profile->iban_country ),
			'bic'            => $profile->bic,
		);
	}

	/**
	 * Delete bank details (privacy eraser).
	 *
	 * @param int $affiliate_id Affiliate.
	 */
	public static function delete( $affiliate_id ) {
		global $wpdb;
		$wpdb->delete( Db::table( 'payout_profiles' ), array( 'affiliate_id' => (int) $affiliate_id ) );
	}
}
