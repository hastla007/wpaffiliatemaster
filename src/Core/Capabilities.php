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
	 * in-memory names, the stored role keeps the untranslated name. Both the list names and
	 * the role data are set (role dropdowns and get_editable_roles() read the latter), again
	 * after switch_to_blog() reloads the roles (wp_roles_init; R13-N10).
	 *
	 * @param \WP_Roles|null $roles Roles object (wp_roles_init), null = wp_roles().
	 */
	public static function translate_role_name( $roles = null ) {
		if ( ! is_object( $roles ) ) {
			if ( ! function_exists( 'wp_roles' ) ) {
				return;
			}
			$roles = wp_roles();
		}
		if ( function_exists( 'did_action' ) && ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
			return; // Too early for translations (WordPress 6.7+ notice); init follows.
		}
		$name = __( 'Tax advisor (Affiliate Captain, read only)', 'affiliate-captain' );
		if ( isset( $roles->role_names[ self::TAX_ADVISOR_ROLE ] ) ) {
			$roles->role_names[ self::TAX_ADVISOR_ROLE ] = $name;
		}
		if ( isset( $roles->roles[ self::TAX_ADVISOR_ROLE ] ) && is_array( $roles->roles[ self::TAX_ADVISOR_ROLE ] ) ) {
			$roles->roles[ self::TAX_ADVISOR_ROLE ]['name'] = $name;
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
	 * Roles and which of the plugin's capabilities each has (SET-AC-21). Administrators are not
	 * listed: they always keep every capability.
	 *
	 * @return array role => [ name, caps => [ cap => bool ] ]
	 */
	public static function role_matrix() {
		$out = array();
		if ( ! function_exists( 'wp_roles' ) ) {
			return $out;
		}
		foreach ( wp_roles()->roles as $role => $data ) {
			if ( 'administrator' === $role ) {
				continue;
			}
			$caps = array();
			foreach ( self::all() as $cap ) {
				$caps[ $cap ] = ! empty( $data['capabilities'][ $cap ] );
			}
			$out[ $role ] = array(
				'name' => function_exists( 'translate_user_role' ) ? translate_user_role( (string) $data['name'] ) : (string) $data['name'],
				'caps' => $caps,
			);
		}
		return $out;
	}

	/**
	 * Give or take the plugin's capabilities per role (SET-AC-21). Administrators always keep all
	 * of them, so nobody can lock the site out of the plugin.
	 *
	 * @param array $wanted role => [ cap => bool ] (roles and caps not given are left unchanged).
	 * @return array Changes: list of [ role, cap, granted ].
	 */
	public static function set_role_caps( array $wanted ) {
		$changes = array();
		$matrix  = self::role_matrix();
		foreach ( $wanted as $role => $caps ) {
			$role = (string) $role;
			if ( 'administrator' === $role || ! isset( $matrix[ $role ] ) || ! is_array( $caps ) ) {
				continue;
			}
			$obj = wp_roles()->get_role( $role );
			if ( ! $obj ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				if ( ! array_key_exists( $cap, $caps ) ) {
					continue;
				}
				$grant = (bool) $caps[ $cap ];
				if ( $grant === $matrix[ $role ]['caps'][ $cap ] ) {
					continue;
				}
				if ( $grant ) {
					$obj->add_cap( $cap );
				} else {
					$obj->remove_cap( $cap );
				}
				$changes[] = array( $role, $cap, $grant );
			}
		}
		return $changes;
	}

	/**
	 * Label of a capability for the role table.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function label( $cap ) {
		$labels = array(
			self::MANAGE_PROGRAM    => __( 'Program and settings', 'affiliate-captain' ),
			self::MANAGE_AFFILIATES => __( 'Partners, codes and commissions', 'affiliate-captain' ),
			self::MANAGE_PAYOUTS    => __( 'Payouts and credit notes', 'affiliate-captain' ),
			self::VIEW_TAX_DATA     => __( 'See tax and bank details', 'affiliate-captain' ),
		);
		return isset( $labels[ $cap ] ) ? $labels[ $cap ] : $cap;
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
