<?php
/**
 * Commission base of WooCommerce orders (SET-AC-02): net (default) or gross, optionally with
 * shipping and fees as own positions. The choice is frozen per order when its sale is first
 * reported, so later refunds and item changes use the same base as the booked commission;
 * orders booked before the setting existed keep the former base (net, no shipping, no fees).
 *
 * Other sales sources (contract wpac_record_sale) report their own amounts and are not affected.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class CommissionBasis {

	/** Order meta with the frozen base of the order ("net:0:0" = basis:shipping:fees). */
	const META = '_wpac_commission_basis';

	/** Item meta key of the sales contract marking a shipping or fee position. */
	const POSITION = 'wpac_position';

	/**
	 * The former fixed base (and the default): net, without shipping and fees.
	 *
	 * @return array{basis:string,shipping:bool,fees:bool}
	 */
	public static function legacy() {
		return array(
			'basis'    => 'net',
			'shipping' => false,
			'fees'     => false,
		);
	}

	/**
	 * Base from the settings.
	 *
	 * @return array{basis:string,shipping:bool,fees:bool}
	 */
	public static function current() {
		return array(
			'basis'    => 'gross' === (string) Settings::get( 'commission_basis' ) ? 'gross' : 'net',
			'shipping' => (bool) Settings::get( 'commission_include_shipping' ),
			'fees'     => (bool) Settings::get( 'commission_include_fees' ),
		);
	}

	/**
	 * Whether a base is the former fixed one.
	 *
	 * @param array $basis Base.
	 * @return bool
	 */
	public static function is_legacy( array $basis ) {
		return 'net' === $basis['basis'] && ! $basis['shipping'] && ! $basis['fees'];
	}

	/**
	 * Stored form of a base.
	 *
	 * @param array $basis Base.
	 * @return string
	 */
	public static function encode( array $basis ) {
		return ( 'gross' === $basis['basis'] ? 'gross' : 'net' ) . ':' . ( $basis['shipping'] ? 1 : 0 ) . ':' . ( $basis['fees'] ? 1 : 0 );
	}

	/**
	 * Base from its stored form (null when empty or unknown).
	 *
	 * @param mixed $value Stored value.
	 * @return array|null
	 */
	public static function decode( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(net|gross):([01]):([01])$/', $value, $m ) ) {
			return null;
		}
		return array(
			'basis'    => $m[1],
			'shipping' => '1' === $m[2],
			'fees'     => '1' === $m[3],
		);
	}

	/**
	 * Label of the rate type "percent" for the current base (settings and rule forms).
	 *
	 * @param array|null $basis Base (null = settings).
	 * @return string
	 */
	public static function percent_label( $basis = null ) {
		$basis = null === $basis ? self::current() : $basis;
		return 'gross' === $basis['basis'] ? __( 'Percent of gross', 'affiliate-captain' ) : __( 'Percent of net', 'affiliate-captain' );
	}

	/**
	 * What shipping and fees add to the base ('' when neither).
	 *
	 * @param array $basis Base.
	 * @return string
	 */
	private static function extras( array $basis ) {
		if ( $basis['shipping'] && $basis['fees'] ) {
			return __( 'including shipping and fees', 'affiliate-captain' );
		}
		if ( $basis['shipping'] ) {
			return __( 'including shipping, without fees', 'affiliate-captain' );
		}
		if ( $basis['fees'] ) {
			return __( 'including fees, without shipping', 'affiliate-captain' );
		}
		return '';
	}

	/**
	 * One sentence describing the base (rules page, rule preview).
	 *
	 * @param array|null $basis Base (null = settings).
	 * @return string
	 */
	public static function description( $basis = null ) {
		$basis = null === $basis ? self::current() : $basis;
		if ( self::is_legacy( $basis ) ) {
			return __( 'Basis is the net line amount after discount, excluding tax and shipping.', 'affiliate-captain' );
		}
		$main = 'gross' === $basis['basis']
			? __( 'Basis is the gross line amount after discount, including VAT', 'affiliate-captain' )
			: __( 'Basis is the net line amount after discount, excluding VAT', 'affiliate-captain' );
		$extra = self::extras( $basis );
		return $main . ( '' !== $extra ? ', ' . $extra : ', ' . __( 'without shipping and fees', 'affiliate-captain' ) ) . '.';
	}

	/**
	 * Text after a percentage rate in the partner area, e.g. "of the net order value".
	 *
	 * @param array|null $basis Base (null = settings).
	 * @return string
	 */
	public static function portal_suffix( $basis = null ) {
		$basis = null === $basis ? self::current() : $basis;
		$main  = 'gross' === $basis['basis'] ? __( 'of the gross order value (incl. VAT)', 'affiliate-captain' ) : __( 'of the net order value', 'affiliate-captain' );
		$extra = self::extras( $basis );
		return $main . ( '' !== $extra ? ', ' . $extra : '' );
	}
}
