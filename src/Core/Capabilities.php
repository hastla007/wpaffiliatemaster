<?php
/**
 * Custom capabilities.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/** Program configuration, rules, integrations, settings. */
	const MANAGE_PROGRAM = 'wpac_manage_program';
	/** Applications, affiliates, codes, commissions. */
	const MANAGE_AFFILIATES = 'wpac_manage_affiliates';
	/** Payout runs, SEPA export. */
	const MANAGE_PAYOUTS = 'wpac_manage_payouts';
	/** Read tax and bank details of partners. */
	const VIEW_TAX_DATA = 'wpac_view_tax_data';

	/**
	 * All caps.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::MANAGE_PROGRAM, self::MANAGE_AFFILIATES, self::MANAGE_PAYOUTS, self::VIEW_TAX_DATA );
	}

	/**
	 * Grant all caps to administrators.
	 */
	public static function install() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}
}
