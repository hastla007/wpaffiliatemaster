<?php
/**
 * Withholding per partner (SET-AC-20, owner decision E8 (a)): a simple percentage the admin sets
 * on the partner page, with a mandatory note shown on the documents. On payout creation the
 * amount transferred is reduced by the withholding; the credit note (PDF), the e-invoice and the
 * annual overview show it as a line of its own. 0 = off (default): documents stay exactly as
 * before.
 *
 * Storage (no schema change): the partner's setting in the option wpac_withholding_{partner}
 * (not autoloaded, like wpac_tax_history_{partner}); the values used for a payout are frozen in
 * its profile snapshot (key "withholding": percent, note, base_minor, minor). The payout row keeps
 * net_minor and vat_minor of the document; amount_minor is the amount transferred, so
 * net_minor + vat_minor - amount_minor is the withholding of a payout.
 *
 * Basis: the taxable net amount of the credit note ("Net amount" line: without VAT, repayments,
 * write-offs and settled correction credit notes), at most the net total settled – the transfer
 * always stays positive. Whether and how a withholding must be shown is a question for the tax
 * adviser (field marked "legal").
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Withholding {

	/** Option prefix of the partner setting (+ partner ID). */
	const OPTION = 'wpac_withholding_';

	/** Highest percentage (the transfer must stay positive). */
	const MAX = '99.99';

	/** Longest note (characters). */
	const NOTE_MAX = 500;

	/**
	 * Field definition in the style of the settings register (type, limits, flags). The field is
	 * stored per partner, not in wpac_settings; "legal" = check with your tax adviser.
	 *
	 * @return array
	 */
	public static function field() {
		return array(
			'type'    => 'text',
			'default' => '0',
			'min'     => 0,
			'max'     => (float) self::MAX,
			'label'   => __( 'Withholding (%)', 'affiliate-captain' ),
			'help'    => __( 'Share of the net commission withheld from each new payout of this partner, e.g. for a withholding tax you pay to the tax office on the partner\'s behalf. 0 = off. The note is required and printed on the credit note and the e-invoice. Ask your tax adviser whether and how the withholding must be shown.', 'affiliate-captain' ),
			'legal'   => true,
		);
	}

	/**
	 * Parse a percentage as typed ("15", "15 %", "12,5"): canonical decimal with at most two
	 * decimals, or null when invalid or outside 0 … MAX.
	 *
	 * @param mixed $raw Input.
	 * @return string|null
	 */
	public static function parse( $raw ) {
		$value = Settings::parse_percent( is_scalar( $raw ) ? (string) $raw : '' );
		if ( null === $value || '-' === $value[0] || ! preg_match( '/^\d+(\.\d{1,2})?$/', $value ) ) {
			return null;
		}
		if ( Money::scale( $value, 2 ) > Money::scale( self::MAX, 2 ) ) {
			return null;
		}
		return self::canonical( $value );
	}

	/**
	 * "15.50" → "15.5", "007" → "7", "0.00" → "0".
	 *
	 * @param string $value Decimal.
	 * @return string
	 */
	private static function canonical( $value ) {
		$value = ltrim( (string) $value, '0' );
		if ( false !== strpos( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		if ( '' === $value || '.' === $value[0] ) {
			$value = '0' . $value;
		}
		return $value;
	}

	/**
	 * Setting of a partner.
	 *
	 * @param int $affiliate_id Partner.
	 * @return array { percent: string ("0" = off), note: string }
	 */
	public static function get( $affiliate_id ) {
		$v       = get_option( self::OPTION . (int) $affiliate_id, array() );
		$v       = is_array( $v ) ? $v : array();
		$percent = isset( $v['percent'] ) ? self::parse( $v['percent'] ) : null;
		return array(
			'percent' => null === $percent ? '0' : $percent,
			'note'    => isset( $v['note'] ) ? (string) $v['note'] : '',
		);
	}

	/**
	 * Save the setting of a partner (admin). 0 removes it.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param mixed  $percent      Percentage as typed.
	 * @param string $note         Note (required when the percentage is above 0).
	 * @return true|\WP_Error
	 */
	public static function save( $affiliate_id, $percent, $note ) {
		$affiliate_id = (int) $affiliate_id;
		$value        = self::parse( '' === trim( (string) $percent ) ? '0' : $percent );
		if ( null === $value ) {
			/* translators: %s: highest percentage */
			return new \WP_Error( 'wpac_withholding_percent', sprintf( __( 'Please enter a withholding between 0 and %s %% (e.g. 15 or 12,5).', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( self::MAX ) ) );
		}
		$note = trim( sanitize_textarea_field( (string) $note ) );
		if ( '0' !== $value && '' === $note ) {
			return new \WP_Error( 'wpac_withholding_note', __( 'Please enter the note for the withholding: it is printed on the credit note.', 'affiliate-captain' ) );
		}
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $note ) > self::NOTE_MAX : strlen( $note ) > self::NOTE_MAX ) {
			/* translators: %d: number of characters */
			return new \WP_Error( 'wpac_withholding_note', sprintf( __( 'The note for the withholding may have at most %d characters.', 'affiliate-captain' ), self::NOTE_MAX ) );
		}
		$old = self::get( $affiliate_id );
		$new = array(
			'percent' => $value,
			'note'    => '0' === $value ? '' : $note,
		);
		if ( $old === $new ) {
			return true;
		}
		if ( '0' === $value ) {
			delete_option( self::OPTION . $affiliate_id );
		} else {
			update_option( self::OPTION . $affiliate_id, $new, false );
		}
		Audit::log(
			'affiliate.withholding',
			'affiliate',
			$affiliate_id,
			array(
				'old'      => $old['percent'],
				'new'      => $new['percent'],
				'old_note' => $old['note'],
				'new_note' => $new['note'],
			)
		);
		return true;
	}

	/**
	 * Remove the setting of a partner (with the payout details, e.g. on erasure).
	 *
	 * @param int $affiliate_id Partner.
	 */
	public static function delete( $affiliate_id ) {
		delete_option( self::OPTION . (int) $affiliate_id );
	}

	/**
	 * Withholding of a new payout (called inside the payout transaction).
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $payout       Payout being created: currency, period_end, net_minor (net
	 *                            total), taxable_minor (net of the "Net amount" line), vat_minor,
	 *                            gross_minor, vat_mode.
	 * @return array|null { percent, note, base_minor, minor } or null (no withholding).
	 */
	public static function compute( $affiliate_id, array $payout ) {
		$setting = self::get( (int) $affiliate_id );
		/**
		 * Withholding of a new payout in percent (0 = none). Runs for every payout (not for the
		 * correction document of a settled balance) with the percentage set on the partner page
		 * ("0" when off). Return a number or decimal string between 0 and 99.99; anything else
		 * counts as 0.
		 *
		 * @since plugin 0.21.0
		 * @param string $percent      Percentage of the partner setting.
		 * @param int    $affiliate_id Partner.
		 * @param array  $payout       Payout being created (see Withholding::compute()).
		 */
		$filtered = apply_filters( 'wpac_withholding', $setting['percent'], (int) $affiliate_id, $payout );
		$percent  = is_scalar( $filtered ) ? self::parse( is_float( $filtered ) ? number_format( $filtered, 2, '.', '' ) : (string) $filtered ) : null;
		if ( null === $percent || '0' === $percent ) {
			return null;
		}
		$base = max( 0, min( (int) $payout['taxable_minor'], (int) $payout['net_minor'] ) );
		$wh   = Money::percent_of( $base, $percent );
		if ( $wh <= 0 || $wh >= (int) $payout['gross_minor'] ) {
			return null;
		}
		return array(
			'percent'    => $percent,
			'note'       => $setting['note'],
			'base_minor' => $base,
			'minor'      => $wh,
		);
	}

	/**
	 * Withholding of a stored payout (null when none).
	 *
	 * @param object $p Payout row.
	 * @return array|null { percent, note, base_minor, minor } (percent/note/base empty when the
	 *                    snapshot was removed after the retention period).
	 */
	public static function of_payout( $p ) {
		$minor = self::minor( $p );
		if ( $minor <= 0 ) {
			return null;
		}
		$snap = json_decode( (string) ( isset( $p->profile_snapshot ) ? $p->profile_snapshot : '' ), true );
		$w    = is_array( $snap ) && isset( $snap['withholding'] ) && is_array( $snap['withholding'] ) ? $snap['withholding'] : array();
		return array(
			'percent'    => isset( $w['percent'] ) ? (string) $w['percent'] : '',
			'note'       => isset( $w['note'] ) ? (string) $w['note'] : '',
			'base_minor' => isset( $w['base_minor'] ) ? (int) $w['base_minor'] : 0,
			'minor'      => $minor,
		);
	}

	/**
	 * Amount withheld from a payout: net + VAT of the document minus the amount transferred.
	 *
	 * @param object $p Payout row.
	 * @return int
	 */
	public static function minor( $p ) {
		if ( ! isset( $p->vat_mode ) || '' === (string) $p->vat_mode ) {
			return 0; // Payouts from before VAT handling: amount = net.
		}
		return max( 0, (int) $p->net_minor + (int) $p->vat_minor - (int) $p->amount_minor );
	}

	/**
	 * Whether any payout has a withholding (the payout export then has the column).
	 *
	 * @return bool
	 */
	public static function used() {
		global $wpdb;
		$t = \WPAffiliateCaptain\Core\Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$t} WHERE vat_mode <> '' AND net_minor + vat_minor > amount_minor LIMIT 1" );
	}

	/**
	 * Label of the document line, e.g. "Withholding 15 % of 1.000,00 €".
	 *
	 * @param array  $w        Withholding (of_payout()).
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function label( array $w, $currency ) {
		if ( '' === (string) $w['percent'] ) {
			return __( 'Withholding', 'affiliate-captain' );
		}
		/* translators: 1: percentage, 2: basis amount */
		return sprintf( __( 'Withholding %1$s %% of %2$s', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( (string) $w['percent'] ), html_entity_decode( Money::format( (int) $w['base_minor'], (string) $currency ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Note on the documents: the admin's text and that the amount was deducted.
	 *
	 * @param array  $w        Withholding.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function note( array $w, $currency ) {
		/* translators: 1: line label (percentage and basis), 2: amount */
		$text = sprintf( __( '%1$s: %2$s was deducted from the payout amount. It is not VAT.', 'affiliate-captain' ), self::label( $w, $currency ), html_entity_decode( Money::format( (int) $w['minor'], (string) $currency ), ENT_QUOTES, 'UTF-8' ) );
		return '' !== trim( (string) $w['note'] ) ? $text . ' ' . trim( (string) $w['note'] ) : $text;
	}
}
