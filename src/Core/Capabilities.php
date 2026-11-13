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
			add_role(
				self::TAX_ADVISOR_ROLE,
				__( 'Tax advisor (Affiliate Captain, read only)', 'affiliate-captain' ),
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

	/**
	 * Remove caps from all roles.
	 */
	public static function uninstall() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		if ( function_exists( 'remove_role' ) ) {
			remove_role( self::TAX_ADVISOR_ROLE );
		}
		foreach ( array_keys( $wp_roles->roles ) as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				foreach ( self::all() as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
