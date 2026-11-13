<?php
/**
 * Payout profiles: tax status, address, VAT ID and encrypted bank details (IBAN for SEPA
 * transfers, or free-text payment details for other methods that are paid manually).
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
			'private'        => __( 'Private individual', 'affiliate-captain' ),
			'small_business' => __( 'Small business (no VAT, e.g. §19 UStG)', 'affiliate-captain' ),
			'business'       => __( 'Business (VAT registered)', 'affiliate-captain' ),
		);
	}

	/** Payout by SEPA transfer (IBAN). */
	const METHOD_SEPA = 'sepa';

	/** Payout by another method (PayPal, Wise, foreign bank …): free-text payment details, paid manually. */
	const METHOD_MANUAL = 'manual';

	/** Prefix of the encrypted payment details of the manual method (distinguishes them from an IBAN). */
	const MANUAL_PREFIX = 'manual:';

	/**
	 * Payout method labels.
	 *
	 * @return array
	 */
	public static function method_labels() {
		return array(
			self::METHOD_SEPA   => __( 'Bank transfer (SEPA, IBAN)', 'affiliate-captain' ),
			self::METHOD_MANUAL => __( 'Other payment method (e.g. PayPal), paid manually', 'affiliate-captain' ),
		);
	}

	/**
	 * Whether partners may choose another payment method (setting "payout_method_manual").
	 *
	 * @return bool
	 */
	public static function manual_enabled() {
		$on = \WPAffiliateCaptain\Core\Settings::get( 'payout_method_manual' );
		return null === $on || (bool) $on;
	}

	/**
	 * Methods a partner can choose: all, or only SEPA when the other method is switched off
	 * (a partner who already uses it keeps it until changing).
	 *
	 * @param object|null $profile Profile.
	 * @return array
	 */
	public static function method_options( $profile ) {
		$labels = self::method_labels();
		if ( ! self::manual_enabled() && self::METHOD_MANUAL !== self::method( $profile ) ) {
			unset( $labels[ self::METHOD_MANUAL ] );
		}
		return $labels;
	}

	/**
	 * Whether an input changes where the money goes (stored IBAN, payment details or method):
	 * such a change needs a confirmation by the partner (password).
	 *
	 * @param object|null $profile Stored profile.
	 * @param array       $in      Input as passed to save().
	 * @return bool
	 */
	public static function changes_destination( $profile, array $in ) {
		if ( ! $profile || '' === (string) $profile->iban_encrypted ) {
			return false; // First details: nothing is redirected.
		}
		$old    = self::method( $profile );
		$method = isset( $in['payout_method'] ) && isset( self::method_labels()[ $in['payout_method'] ] ) ? (string) $in['payout_method'] : $old;
		if ( $method !== $old ) {
			return true;
		}
		if ( self::METHOD_MANUAL === $method ) {
			$details = trim( substr( sanitize_textarea_field( isset( $in['payment_details'] ) ? (string) $in['payment_details'] : '' ), 0, 500 ) );
			return '' !== $details && self::payment_details( $profile ) !== $details;
		}
		$iban = Iban::normalize( isset( $in['iban'] ) ? (string) $in['iban'] : '' );
		return '' !== $iban && self::iban( $profile ) !== $iban;
	}

	/**
	 * Payment details with most characters hidden, to show the partner what is stored
	 * ("PayPal: an•••@example.com", long numbers only with their last 4 digits).
	 *
	 * @param string $details Details.
	 * @return string
	 */
	public static function mask_details( $details ) {
		$out = array();
		foreach ( preg_split( '/(\s+)/u', (string) $details, -1, PREG_SPLIT_DELIM_CAPTURE ) as $token ) {
			if ( preg_match( '/^([^@\s]{1,2})[^@\s]*(@\S+)$/u', $token, $m ) ) {
				$token = $m[1] . '•••' . $m[2];
			} elseif ( preg_match_all( '/\d/', $token ) >= 6 ) {
				$token = '•••' . substr( $token, -4 );
			}
			$out[] = $token;
		}
		return implode( '', $out );
	}

	/**
	 * PayPal address in free-text payment details ("PayPal: name@example.com"): the first e-mail
	 * address; '' when there is none.
	 *
	 * @param string|null $details Payment details.
	 * @return string
	 */
	public static function paypal_email( $details ) {
		if ( null === $details || ! preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string) $details, $m ) ) {
			return '';
		}
		return strtolower( $m[0] );
	}

	/**
	 * Input of a rejected admin form to show again: never the IBAN, the payment details only
	 * encrypted (the form store is a plain transient), the chosen method kept.
	 *
	 * @param array $in Input as passed to save().
	 * @return array
	 */
	public static function form_keep( array $in ) {
		$details = trim( isset( $in['payment_details'] ) ? (string) $in['payment_details'] : '' );
		unset( $in['iban'], $in['payment_details'] );
		$kept = array_map( 'sanitize_textarea_field', $in );
		if ( '' !== $details ) {
			$kept['payment_details_encrypted'] = Crypto::encrypt( $details );
		}
		return $kept;
	}

	/**
	 * Payment details typed into a rejected form (see form_keep()), '' when none.
	 *
	 * @param array $kept Kept form.
	 * @return string
	 */
	public static function form_details( array $kept ) {
		return isset( $kept['payment_details_encrypted'] ) ? (string) Crypto::decrypt( (string) $kept['payment_details_encrypted'] ) : '';
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Label of a profile field (messages).
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private static function field_label( $field ) {
		$labels = array(
			'legal_name'     => __( 'Name / company', 'affiliate-captain' ),
			'account_holder' => __( 'Account holder', 'affiliate-captain' ),
			'vat_id'         => __( 'VAT ID', 'affiliate-captain' ),
		);
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Payout method of a profile. The manual method stores its encrypted payment details in the
	 * same column as the IBAN, without IBAN country/last digits (an IBAN always has both).
	 *
	 * @param object|null $profile Profile.
	 * @return string sepa|manual
	 */
	public static function method( $profile ) {
		if ( $profile && '' !== (string) $profile->iban_encrypted && '' === (string) $profile->iban_country ) {
			return self::METHOD_MANUAL;
		}
		return self::METHOD_SEPA;
	}

	/**
	 * Decrypted payment details of the manual method (null for SEPA or when not decryptable).
	 *
	 * @param object|null $profile Profile.
	 * @return string|null
	 */
	public static function payment_details( $profile ) {
		if ( self::METHOD_MANUAL !== self::method( $profile ) ) {
			return null;
		}
		$plain = Crypto::decrypt( (string) $profile->iban_encrypted );
		if ( null === $plain || 0 !== strpos( $plain, self::MANUAL_PREFIX ) ) {
			return null;
		}
		return (string) substr( $plain, strlen( self::MANUAL_PREFIX ) );
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
		if ( ! $profile || self::METHOD_MANUAL === self::method( $profile ) ) {
			return null;
		}
		return Crypto::decrypt( (string) $profile->iban_encrypted );
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
		$manual = self::METHOD_MANUAL === self::method( $profile );
		// The account holder belongs to the bank transfer; other methods carry it in their details.
		foreach ( $manual ? array( 'tax_status', 'legal_name', 'address', 'country' ) : array( 'tax_status', 'legal_name', 'address', 'country', 'account_holder' ) as $f ) {
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
		// The columns are limited: a longer value would not be stored (and the old one kept unnoticed).
		foreach ( array(
			'legal_name'     => 190,
			'account_holder' => 190,
			'vat_id'         => 40,
		) as $field => $max ) {
			if ( self::length( $data[ $field ] ) > $max ) {
				return new \WP_Error(
					'wpac_too_long_' . $field,
					/* translators: 1: field, 2: number of characters */
					sprintf( __( '%1$s is too long (at most %2$d characters).', 'affiliate-captain' ), self::field_label( $field ), $max )
				);
			}
		}
		if ( 'business' === $tax && '' === $data['vat_id'] ) {
			return new \WP_Error( 'wpac_vat', __( 'Please enter your VAT ID.', 'affiliate-captain' ) );
		}
		if ( 'small_business' === $tax && '' === $data['vat_id'] && '' === trim( $data['tax_number'] ) ) {
			return new \WP_Error( 'wpac_tax_number', __( 'Please enter your tax number or VAT ID (required on credit notes).', 'affiliate-captain' ) );
		}
		$existing = self::get( $affiliate_id );
		$methods  = self::method_labels();
		// Without a choice in the input the stored method stays (older forms, integrations).
		$method      = isset( $in['payout_method'] ) && isset( $methods[ $in['payout_method'] ] ) ? (string) $in['payout_method'] : self::method( $existing );
		$old_method  = self::method( $existing );
		$details_new = false;
		$iban        = Iban::normalize( isset( $in['iban'] ) ? (string) $in['iban'] : '' );
		if ( self::METHOD_MANUAL === $method && self::METHOD_MANUAL !== $old_method && ! self::manual_enabled() ) {
			return new \WP_Error( 'wpac_method_disabled', __( 'Payouts by another payment method are not offered. Please enter your IBAN for a bank transfer.', 'affiliate-captain' ) );
		}
		if ( self::METHOD_MANUAL === $method ) {
			// Free-text payment details (e.g. "PayPal: name@example.com"), stored encrypted like the IBAN.
			$details = trim( substr( sanitize_textarea_field( isset( $in['payment_details'] ) ? (string) $in['payment_details'] : '' ), 0, 500 ) );
			$iban    = '';
			if ( '' === $details && self::METHOD_MANUAL !== $old_method ) {
				return new \WP_Error( 'wpac_payment_details', __( 'Please enter your payment details (e.g. your PayPal address).', 'affiliate-captain' ) );
			}
			if ( '' !== $details ) {
				$details_new            = self::payment_details( $existing ) !== $details;
				$data['iban_encrypted'] = Crypto::encrypt( self::MANUAL_PREFIX . $details );
				$data['iban_country']   = '';
				$data['iban_last4']     = '';
			}
			$data['bic'] = '';
		} else {
			if ( '' === $iban && self::METHOD_MANUAL === $old_method ) {
				return new \WP_Error( 'wpac_iban_required', __( 'Please enter your IBAN for payouts by bank transfer.', 'affiliate-captain' ) );
			}
			if ( '' !== $iban ) {
				if ( ! Iban::is_valid( $iban ) ) {
					return new \WP_Error( 'wpac_iban', __( 'The IBAN is not valid.', 'affiliate-captain' ) );
				}
				$data['iban_encrypted'] = Crypto::encrypt( $iban );
				$data['iban_country']   = substr( $iban, 0, 2 );
				$data['iban_last4']     = substr( $iban, -4 );
			}
		}
		if ( ! isset( $data['iban_encrypted'] ) && $existing && Crypto::needs_reencrypt( (string) $existing->iban_encrypted ) ) {
			// Stored with the key derived from the salts before WPAC_ENCRYPTION_KEY was set: re-encrypt
			// (IBAN or manual payment details alike).
			$data['iban_encrypted'] = Crypto::encrypt( (string) Crypto::decrypt( (string) $existing->iban_encrypted ) );
		}
		if ( $existing ) {
			$stored = $wpdb->update( Db::table( 'payout_profiles' ), $data, array( 'affiliate_id' => (int) $affiliate_id ) );
		} else {
			$stored = $wpdb->insert( Db::table( 'payout_profiles' ), $data + array( 'iban_encrypted' => '' ) );
		}
		if ( false === $stored || ( ! $existing && ! $stored ) ) {
			// Nothing was saved: no success message and no "bank details changed" mail.
			Audit::log( 'payout_profile.save_failed', 'affiliate', (int) $affiliate_id, array( 'error' => substr( (string) $wpdb->last_error, 0, 190 ) ) );
			return new \WP_Error( 'wpac_profile_db', __( 'Your payout details could not be saved. Please try again; your previous details are unchanged.', 'affiliate-captain' ) );
		}
		if ( '' !== $data['vat_id'] && ( ! $existing || (string) $existing->vat_id !== $data['vat_id'] ) ) {
			Vies::schedule( (int) $affiliate_id ); // Check the new VAT ID against VIES in the background.
		}
		$had_details = $existing && '' !== (string) $existing->iban_encrypted;
		$changed     = $had_details && ( ( '' !== $iban && Crypto::decrypt( (string) $existing->iban_encrypted ) !== $iban ) || $details_new || $method !== $old_method );
		if ( $changed ) {
			// Security notice to the partner: where the money goes was changed.
			\WPAffiliateCaptain\Affiliates\Notifications::bank_details_changed( (int) $affiliate_id );
		}
		Audit::log(
			'payout_profile.updated',
			'affiliate',
			(int) $affiliate_id,
			array(
				'tax_status'      => $tax,
				'payout_method'   => $method,
				'iban_changed'    => '' !== $iban,
				'details_changed' => $details_new,
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
			'iban_masked'    => self::METHOD_MANUAL === self::method( $profile ) ? '' : Iban::mask( $profile->iban_last4, $profile->iban_country ),
			'bic'            => $profile->bic,
			// Never the payment details themselves (they stay encrypted in the profile).
			'payout_method'  => self::method( $profile ),
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
