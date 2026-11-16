<?php
/**
 * Partner programs, variant A of docs/MULTI-PROGRAM.md: a program is a partner group, every
 * partner belongs to exactly one program. The core only offers neutral extension points (contract
 * 1.5.0); an add-on decides what a program is called and which settings differ per program
 * (filter wpac_setting, see Core\Settings::get()).
 *
 * Program key: "group:<group ID>" for a partner group, '' for the default program (partners
 * without a group). An add-on may map partners differently with the filter
 * wpac_affiliate_program; list filters and reports then need the matching groups from the filter
 * wpac_program_groups.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

defined( 'ABSPATH' ) || exit;

final class Programs {

	/** Prefix of the program key of a partner group. */
	const PREFIX = 'group:';

	/** Longest program key. */
	const MAX_LENGTH = 40;

	/**
	 * Program key of a partner group.
	 *
	 * @param int $group_id Group (0 = default program).
	 * @return string
	 */
	public static function for_group( $group_id ) {
		return (int) $group_id > 0 ? self::PREFIX . (int) $group_id : '';
	}

	/**
	 * A valid program key ('' = default program), or null when the value cannot be one: at most
	 * 40 characters of a–z, A–Z, 0–9, ":", "_", "." and "-".
	 *
	 * @param mixed $program Value.
	 * @return string|null
	 */
	public static function clean( $program ) {
		if ( ! is_scalar( $program ) ) {
			return null;
		}
		$program = trim( (string) $program );
		if ( '' === $program ) {
			return '';
		}
		return strlen( $program ) <= self::MAX_LENGTH && preg_match( '/^[A-Za-z0-9:_.\-]+$/', $program ) ? $program : null;
	}

	/**
	 * Program of a partner (wpac_affiliate_program()).
	 *
	 * @param int $affiliate_id Partner.
	 * @return string Program key ('' = default program, also for unknown partners).
	 */
	public static function of_affiliate( $affiliate_id ) {
		$aff     = (int) $affiliate_id > 0 ? AffiliateRepository::find( (int) $affiliate_id ) : null;
		$group   = $aff ? (int) $aff->group_id : 0;
		$program = self::for_group( $group );
		/**
		 * Program of a partner. Default: "group:<group ID>" of the partner group, '' without a
		 * group. Since contract 1.5.0.
		 *
		 * @param string $program      Program key (at most 40 characters of a–z, 0–9, ":", "_", ".", "-").
		 * @param int    $affiliate_id Partner.
		 * @param int    $group_id     Partner group (0 = none).
		 */
		$filtered = self::clean( apply_filters( 'wpac_affiliate_program', $program, (int) $affiliate_id, $group ) );
		return null === $filtered ? $program : $filtered;
	}

	/**
	 * Partner group of a partner (0 = none or unknown).
	 *
	 * @param int $affiliate_id Partner.
	 * @return int
	 */
	public static function group_of( $affiliate_id ) {
		$aff = (int) $affiliate_id > 0 ? AffiliateRepository::find( (int) $affiliate_id ) : null;
		return $aff ? (int) $aff->group_id : 0;
	}

	/**
	 * Partner groups whose partners belong to a program (for list filters and reports).
	 *
	 * @param string $program Program key.
	 * @return int[] Group IDs (0 = partners without a group); empty = no partner.
	 */
	public static function groups_of( $program ) {
		$program = (string) $program;
		$groups  = array();
		if ( '' === $program ) {
			$groups = array( 0 );
		} elseif ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '([1-9]\d{0,9})$/', $program, $m ) ) {
			$groups = array( (int) $m[1] );
		}
		/**
		 * Partner groups of a program (list filter "program" of wpac_get_affiliates() and
		 * wpac_get_commissions(), program reports). Needed when wpac_affiliate_program maps
		 * partners to other program keys than "group:<ID>". Since contract 1.5.0.
		 *
		 * @param int[]  $groups  Group IDs (0 = partners without a group).
		 * @param string $program Program key.
		 */
		$groups = apply_filters( 'wpac_program_groups', $groups, $program );
		$out    = array();
		foreach ( is_array( $groups ) ? $groups : array() as $g ) {
			if ( is_numeric( $g ) && (int) $g >= 0 ) {
				$out[ (int) $g ] = (int) $g;
			}
		}
		return array_values( $out );
	}

	/**
	 * SQL condition "partner belongs to the program" on a column with the partner ID.
	 *
	 * @param string $program Program key.
	 * @param string $column  Column with the partner ID (constant, e.g. "affiliate_id" or "a.affiliate_id").
	 * @return string SQL starting with " AND " (values escaped).
	 */
	public static function affiliate_sql( $program, $column = 'affiliate_id' ) {
		$groups = self::groups_of( $program );
		if ( ! $groups ) {
			return ' AND 1=0';
		}
		$t = \WPAffiliateCaptain\Core\Db::table( 'affiliates' );
		return ' AND ' . $column . ' IN (SELECT id FROM ' . $t . ' WHERE group_id IN (' . implode( ',', array_map( 'intval', $groups ) ) . '))';
	}

	/**
	 * Programs offered in the program selector of the admin reports (empty = no selector).
	 *
	 * @return array<string,string> Program key => label.
	 */
	public static function report_choices() {
		/**
		 * Programs for the selector of the admin reports (dashboard and CSV export). Empty by
		 * default: the selector only appears when an add-on returns programs. Since contract 1.5.0.
		 *
		 * @param array $programs Program key => label (e.g. array( '' => 'Standard', 'group:3' => 'Schools' )).
		 */
		$list = apply_filters( 'wpac_report_programs', array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $key => $label ) {
			$key   = self::clean( $key );
			$label = is_scalar( $label ) ? trim( sanitize_text_field( (string) $label ) ) : '';
			if ( null !== $key && '' !== $label ) {
				$out[ $key ] = $label;
			}
		}
		return $out;
	}

	/**
	 * Name of the partner's program in the partner area ('' = none shown).
	 *
	 * @param object $aff Partner row.
	 * @return string
	 */
	public static function label( $aff ) {
		$program = self::of_affiliate( (int) $aff->id );
		/**
		 * Name of the partner's program in the partner area (overview of the conditions). Empty by
		 * default: no line is shown. Since contract 1.5.0.
		 *
		 * @param string $label   Label.
		 * @param string $program Program key.
		 * @param object $aff     Partner.
		 */
		$label = apply_filters( 'wpac_portal_program_label', '', $program, $aff );
		return is_scalar( $label ) ? trim( sanitize_text_field( (string) $label ) ) : '';
	}

	/**
	 * Fire wpac_affiliate_program_changed when a partner's program differs.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $from         Program before.
	 * @param string $to           Program now.
	 */
	public static function changed( $affiliate_id, $from, $to ) {
		if ( (string) $from === (string) $to ) {
			return;
		}
		/**
		 * The program of a partner changed (variant A: the partner group changed, in the admin, by
		 * wpac_update_affiliate() or because the group was deleted). Not fired when a partner is
		 * created. Since contract 1.5.0.
		 *
		 * @param int    $affiliate_id Partner.
		 * @param string $from         Program key before.
		 * @param string $to           Program key now.
		 */
		do_action( 'wpac_affiliate_program_changed', (int) $affiliate_id, (string) $from, (string) $to );
	}
}
