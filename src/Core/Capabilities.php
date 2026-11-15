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

	/** Read-only role for the tax advisor: payouts, credit notes and tax data, nothing else. */
	const TAX_ADVISOR_ROLE = 'wpac_tax_advisor';

	/** Stored (untranslated) name of the tax advisor role. */
	const TAX_ADVISOR_NAME = 'Tax advisor (Affiliate Captain, read only)';

	/**
	 * Show the role name translated (at init, after the text domain is loaded): only the
	 * in-memory names, the stored role keeps the untranslated name.
	 */
	public static function translate_role_name() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}
		$roles = wp_roles();
		if ( isset( $roles->role_names[ self::TAX_ADVISOR_ROLE ] ) ) {
			$roles->role_names[ self::TAX_ADVISOR_ROLE ] = __( 'Tax advisor (Affiliate Captain, read only)', 'affiliate-captain' );
		}
	}

	/**
	 * Grant all caps to administrators; create the read-only tax advisor role.
	 */
	public static function install() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
		if ( function_exists( 'add_role' ) && ! get_role( self::TAX_ADVISOR_ROLE ) ) {
			// Stored untranslated: install() also runs from cron/CLI before "init", where loading
			// translations is too early (WordPress 6.7+ notice). translate_role_name() shows the
			// name in the language of the viewer (R12-N13).
			add_role(
				self::TAX_ADVISOR_ROLE,
				self::TAX_ADVISOR_NAME,
				array(
					'read'              => true,
					self::VIEW_TAX_DATA => true,
				)
			);
		}
	}

	/**
	 * Capability of the payouts page: whoever manages payouts, or – read only – whoever may see
	 * tax data (list, credit notes, cancellations; no runs, exports or changes).
	 *
	 * @return string
	 */
	public static function payouts_page_cap() {
		return ! current_user_can( self::MANAGE_PAYOUTS ) && current_user_can( self::VIEW_TAX_DATA ) ? self::VIEW_TAX_DATA : self::MANAGE_PAYOUTS;
	}
}
