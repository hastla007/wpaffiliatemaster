<?php
/**
 * Optional sections of the settings export (SET-AC-08a): e-mail templates (with the texts per
 * language, SET-AC-17), partner groups, commission rules and monthly tier plans.
 *
 * - Each section is exported only on request and imported only after a preview that names every
 *   item as new, changed, unchanged or rejected (with the reason).
 * - Items are matched by stable keys, never by database IDs: groups by name, rules by scope, group
 *   name, products and note, tier plans by scope and group name, templates by key and language.
 *   Product, category and tag IDs of rules travel as SKU or slug and are looked up on the target site.
 * - Nothing is deleted: items of the site that are not in the file stay as they are. Partner-specific
 *   rules and tier plans, partners and commissions are never exported or changed; existing
 *   commissions keep their snapshot.
 * - Amounts (fixed rules, caps, revenue thresholds) are minor units of the currency named in the
 *   section; they are only imported when the program currency (after the import) is the same.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Commissions\Calculator;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Commissions\TierService;

defined( 'ABSPATH' ) || exit;

final class SettingsSections {

	/** Version of the section format (key "sections_format" of the export). */
	const FORMAT = 1;

	/** Sections in the order they are imported (groups first: rules and tiers refer to them). */
	const SECTIONS = array( 'groups', 'rules', 'tiers', 'mail_templates' );

	/** Most items per section that are read from a file. */
	const MAX_ITEMS = 2000;

	/** @var array Result of the last apply(): section => counts. */
	private static $last = array();

	/**
	 * Section labels.
	 *
	 * @return array section => label
	 */
	public static function labels() {
		return array(
			'groups'         => __( 'Partner groups', 'affiliate-captain' ),
			'rules'          => __( 'Commission rules', 'affiliate-captain' ),
			'tiers'          => __( 'Monthly tier plans', 'affiliate-captain' ),
			'mail_templates' => __( 'E-mail templates', 'affiliate-captain' ),
		);
	}

	/**
	 * Known section names from a list ("all", an array or a comma separated string).
	 *
	 * @param mixed $list Requested sections.
	 * @return string[]
	 */
	public static function clean( $list ) {
		if ( is_string( $list ) ) {
			$list = 'all' === strtolower( trim( $list ) ) ? self::SECTIONS : explode( ',', $list );
		}
		$out = array();
		foreach ( (array) $list as $name ) {
			$name = is_scalar( $name ) ? strtolower( trim( (string) $name ) ) : '';
			$name = 'templates' === $name || 'mails' === $name ? 'mail_templates' : $name;
			if ( in_array( $name, self::SECTIONS, true ) && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		return array_values( array_intersect( self::SECTIONS, $out ) );
	}

	/* ---------------------------------------------------------------- Export */

	/**
	 * Export of the requested sections.
	 *
	 * @param string[] $sections Sections.
	 * @return array section => data
	 */
	public static function export( array $sections ) {
		$out = array();
		foreach ( self::clean( $sections ) as $section ) {
			$out[ $section ] = call_user_func( array( __CLASS__, 'export_' . $section ) );
		}
		return $out;
	}

	/**
	 * Partner groups.
	 *
	 * @return array
	 */
	private static function export_groups() {
		$items = array();
		foreach ( Groups::all() as $g ) {
			$items[] = array(
				'name'              => (string) $g->name,
				'description'       => (string) $g->description,
				'agreement_version' => (string) $g->agreement_version,
				'terms_page'        => self::page_slug( (int) $g->terms_page_id ),
				'public'            => (int) $g->public,
				'fields'            => Groups::fields( $g ),
			);
		}
		return array( 'items' => $items );
	}

	/**
	 * Commission rules for all partners and for groups (partner rules stay on the site).
	 *
	 * @return array
	 */
	private static function export_rules() {
		$names   = Groups::options();
		$rules   = self::local_rules();
		$items   = array();
		$partner = 0;
		foreach ( (array) RuleRepository::all() as $r ) {
			$partner += 'affiliate' === $r->scope ? 1 : 0;
		}
		foreach ( $rules as $r ) {
			$items[] = array(
				'scope'            => (string) $r->scope,
				'group'            => 'group' === $r->scope && isset( $names[ (int) $r->scope_id ] ) ? (string) $names[ (int) $r->scope_id ] : '',
				'target'           => (string) $r->target,
				'target_value'     => (string) $r->target_value,
				'target_refs'      => self::refs( (string) $r->target, (string) $r->target_value ),
				'rate_type'        => (string) $r->rate_type,
				'rate'             => (string) $r->rate,
				'max_amount_minor' => (int) $r->max_amount_minor,
				'min_base_minor'   => (int) $r->min_base_minor,
				'valid_from'       => Calculator::day( $r->valid_from ),
				'valid_until'      => Calculator::day( $r->valid_until ),
				'priority'         => (int) $r->priority,
				'active'           => (int) $r->active,
				'note'             => (string) $r->note,
			);
		}
		return array(
			'currency'      => (string) Settings::get( 'currency' ),
			'partner_rules' => $partner,
			'items'         => $items,
		);
	}

	/**
	 * Tier plan in effect for all partners and per group (partner plans stay on the site).
	 *
	 * @return array
	 */
	private static function export_tiers() {
		$names = Groups::options();
		$items = array();
		foreach ( self::local_tiers() as $plan ) {
			$items[] = array(
				'scope' => (string) $plan->scope,
				'group' => 'group' === $plan->scope && isset( $names[ (int) $plan->scope_id ] ) ? (string) $names[ (int) $plan->scope_id ] : '',
				'basis' => (string) $plan->basis,
				'mode'  => (string) $plan->mode,
				'steps' => self::steps( json_decode( (string) $plan->steps, true ) ),
				'note'  => (string) $plan->note,
			);
		}
		return array(
			'currency' => (string) Settings::get( 'currency' ),
			'items'    => $items,
		);
	}

	/**
	 * E-mail templates changed in the admin, per language (the site language under its locale).
	 *
	 * @return array
	 */
	private static function export_mail_templates() {
		$s     = Notifications::template_settings();
		$site  = Locale::site();
		$items = array();
		foreach ( $s['templates'] as $key => $t ) {
			if ( is_array( $t ) && isset( $t['subject'], $t['body'] ) ) {
				$items[] = array(
					'key'     => (string) $key,
					'locale'  => $site,
					'subject' => (string) $t['subject'],
					'body'    => (string) $t['body'],
				);
			}
		}
		foreach ( $s['i18n'] as $locale => $list ) {
			foreach ( (array) $list as $key => $t ) {
				if ( is_array( $t ) && isset( $t['subject'], $t['body'] ) ) {
					$items[] = array(
						'key'     => (string) $key,
						'locale'  => (string) $locale,
						'subject' => (string) $t['subject'],
						'body'    => (string) $t['body'],
					);
				}
			}
		}
		return array(
			'layout' => $s['layout'],
			'items'  => $items,
		);
	}

	/* ---------------------------------------------------------------- Preview */

	/**
	 * Check the sections of a file without changing anything.
	 *
	 * @param mixed  $raw      "sections" of the file.
	 * @param string $currency Program currency after the import.
	 * @return array { sections: section => { items, counts }, ignored: names }
	 */
	public static function preview( $raw, $currency ) {
		$out = array(
			'sections' => array(),
			'ignored'  => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( array_keys( $raw ) as $name ) {
			if ( ! in_array( (string) $name, self::SECTIONS, true ) ) {
				$out['ignored'][] = 'sections:' . sanitize_key( (string) $name );
			}
		}
		$ctx = array(
			'currency'   => strtoupper( (string) $currency ),
			'new_groups' => array(),
		);
		foreach ( self::SECTIONS as $section ) {
			if ( ! isset( $raw[ $section ] ) || ! is_array( $raw[ $section ] ) ) {
				continue;
			}
			$items = self::items( $section, $raw[ $section ], $ctx );
			if ( 'groups' === $section ) {
				foreach ( $items as $item ) {
					if ( 'new' === $item['status'] ) {
						$ctx['new_groups'][] = $item['key'];
					}
				}
			}
			$out['sections'][ $section ] = array(
				'items'  => $items,
				'counts' => self::counts( $items ),
			);
		}
		return $out;
	}

	/**
	 * Whether a preview has anything to apply.
	 *
	 * @param array $preview Result of preview() (the "sections" part).
	 * @return bool
	 */
	public static function has_changes( array $preview ) {
		foreach ( $preview as $section ) {
			if ( ! empty( $section['counts']['new'] ) || ! empty( $section['counts']['changed'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Items of one section with their status.
	 *
	 * @param string $section Section.
	 * @param array  $data    Section data of the file.
	 * @param array  $ctx     currency, new_groups.
	 * @return array[]
	 */
	private static function items( $section, array $data, array $ctx ) {
		$list = isset( $data['items'] ) && is_array( $data['items'] ) ? array_slice( array_values( $data['items'] ), 0, self::MAX_ITEMS ) : array();
		$list = array_values( array_filter( $list, 'is_array' ) );
		return call_user_func( array( __CLASS__, 'items_' . $section ), $list, $data, $ctx );
	}

	/**
	 * Count the items per status.
	 *
	 * @param array[] $items Items.
	 * @return array
	 */
	private static function counts( array $items ) {
		$out = array(
			'new'       => 0,
			'changed'   => 0,
			'unchanged' => 0,
			'error'     => 0,
		);
		foreach ( $items as $item ) {
			++$out[ $item['status'] ];
		}
		return $out;
	}

	/**
	 * One preview item.
	 *
	 * @param string $key     Matching key.
	 * @param string $label   Shown name.
	 * @param string $status  new, changed, unchanged or error.
	 * @param array  $changes field => [ from, to ] (display values).
	 * @param string $message Reason of an error or a note.
	 * @param array  $op      What apply() does.
	 * @return array
	 */
	private static function item( $key, $label, $status, array $changes = array(), $message = '', array $op = array() ) {
		return array(
			'key'     => (string) $key,
			'label'   => (string) $label,
			'status'  => $status,
			'changes' => $changes,
			'message' => (string) $message,
			'op'      => $op,
		);
	}

	/**
	 * Partner groups, matched by name (case-insensitive).
	 *
	 * @param array[] $list Items of the file.
	 * @param array   $data Section data.
	 * @param array   $ctx  Context.
	 * @return array[]
	 */
	private static function items_groups( array $list, array $data, array $ctx ) {
		unset( $data, $ctx );
		$local = self::group_index();
		$seen  = array();
		$out   = array();
		foreach ( $list as $in ) {
			$name = sanitize_text_field( self::str( $in, 'name' ) );
			$key  = self::lower( $name );
			if ( '' === $name ) {
				$out[] = self::item( '', '—', 'error', array(), __( 'The group has no name.', 'affiliate-captain' ) );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$out[] = self::item( $key, $name, 'error', array(), __( 'This name is in the file twice; only the first entry is used.', 'affiliate-captain' ) );
				continue;
			}
			$seen[ $key ] = true;
			if ( isset( $local[ $key ] ) && count( $local[ $key ] ) > 1 ) {
				$out[] = self::item( $key, $name, 'error', array(), __( 'Several groups with this name exist on this site. Rename them first.', 'affiliate-captain' ) );
				continue;
			}
			$fields_text = self::fields_text( isset( $in['fields'] ) && is_array( $in['fields'] ) ? $in['fields'] : array() );
			$fields      = Groups::parse_fields( $fields_text );
			if ( is_wp_error( $fields ) ) {
				$out[] = self::item( $key, $name, 'error', array(), $fields->get_error_message() );
				continue;
			}
			$group = isset( $local[ $key ] ) ? Groups::find( $local[ $key ][0] ) : null;
			$note  = '';
			$slug  = sanitize_title( self::str( $in, 'terms_page' ) );
			$page  = '' === $slug ? 0 : self::page_id( $slug );
			if ( '' !== $slug && ! $page ) {
				/* translators: %s: page slug */
				$note = sprintf( __( 'Terms page "%s" not found on this site; the terms page of the group is not changed.', 'affiliate-captain' ), $slug );
				$page = $group ? (int) $group->terms_page_id : 0;
			}
			$want = array(
				'name'              => $group ? (string) $group->name : $name,
				'description'       => sanitize_textarea_field( self::str( $in, 'description' ) ),
				'agreement_version' => sanitize_text_field( self::str( $in, 'agreement_version' ) ),
				'terms_page_id'     => $page,
				'public'            => empty( $in['public'] ) ? 0 : 1,
				'fields'            => $fields_text,
			);
			if ( ! $group ) {
				$out[] = self::item( $key, $name, 'new', array(), $note, array( 'data' => $want ) );
				continue;
			}
			$changes = array();
			$have    = array(
				'description'       => (string) $group->description,
				'agreement_version' => (string) $group->agreement_version,
				'terms_page_id'     => (int) $group->terms_page_id,
				'public'            => (int) $group->public,
				'fields'            => self::fields_text( Groups::fields( $group ) ),
			);
			foreach ( $have as $field => $old ) {
				if ( (string) $old !== (string) $want[ $field ] ) {
					$changes[ $field ] = 'terms_page_id' === $field ? array( self::page_label( (int) $old ), self::page_label( (int) $want[ $field ] ) ) : array( (string) $old, (string) $want[ $field ] );
				}
			}
			$out[] = self::item(
				$key,
				$name,
				$changes ? 'changed' : 'unchanged',
				$changes,
				$note,
				array(
					'id'   => (int) $group->id,
					'data' => $want,
				)
			);
		}
		return $out;
	}

	/**
	 * Commission rules, matched by scope, group, products and note; several rules with the same
	 * key are matched in their order (oldest first).
	 *
	 * @param array[] $list Items of the file.
	 * @param array   $data Section data.
	 * @param array   $ctx  Context.
	 * @return array[]
	 */
	private static function items_rules( array $list, array $data, array $ctx ) {
		$from_cur = strtoupper( sanitize_text_field( self::str( $data, 'currency' ) ) );
		$names    = Groups::options();
		$local    = array();
		foreach ( self::local_rules() as $r ) {
			$group                                   = 'group' === $r->scope && isset( $names[ (int) $r->scope_id ] ) ? (string) $names[ (int) $r->scope_id ] : '';
			$local[ self::rule_key( (string) $r->scope, $group, (string) $r->target, self::refs( (string) $r->target, (string) $r->target_value ), (string) $r->note ) ][] = $r;
		}
		$used = array();
		$out  = array();
		foreach ( $list as $in ) {
			$scope  = sanitize_key( self::str( $in, 'scope' ) );
			$group  = sanitize_text_field( self::str( $in, 'group' ) );
			$target = sanitize_key( self::str( $in, 'target' ) );
			$note   = sanitize_text_field( self::str( $in, 'note' ) );
			$refs   = isset( $in['target_refs'] ) && is_array( $in['target_refs'] ) ? array_values( array_map( 'strval', array_filter( $in['target_refs'], 'is_scalar' ) ) ) : array();
			if ( in_array( $target, array( 'product', 'category', 'tag' ), true ) && ! $refs ) {
				// A file without references (written by hand): the IDs as they are.
				$refs = self::id_refs( self::str( $in, 'target_value' ) );
			} elseif ( ! in_array( $target, array( 'product', 'category', 'tag' ), true ) ) {
				$refs = self::refs( $target, sanitize_text_field( self::str( $in, 'target_value' ) ) );
			}
			$key   = self::rule_key( $scope, $group, $target, $refs, $note );
			$label = self::rule_label( $scope, $group, $target, $refs, $note );
			if ( 'affiliate' === $scope ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Rules for single partners are not imported.', 'affiliate-captain' ) );
				continue;
			}
			if ( ! in_array( $scope, array( 'global', 'group' ), true ) ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Unknown scope.', 'affiliate-captain' ) );
				continue;
			}
			$scope_id = self::scope_id( $scope, $group, $ctx, $error );
			if ( null === $scope_id ) {
				$out[] = self::item( $key, $label, 'error', array(), $error );
				continue;
			}
			$value = self::resolve_refs( $target, $refs, $missing );
			if ( $missing ) {
				/* translators: %s: list of SKUs, slugs or IDs */
				$out[] = self::item( $key, $label, 'error', array(), sprintf( __( 'Not found on this site: %s', 'affiliate-captain' ), implode( ', ', $missing ) ) );
				continue;
			}
			$rate_type = sanitize_key( self::str( $in, 'rate_type' ) );
			$has_money = 'fixed' === $rate_type || ! empty( $in['max_amount_minor'] ) || ! empty( $in['min_base_minor'] );
			if ( $has_money && $from_cur !== $ctx['currency'] ) {
				/* translators: 1: currency of the file, 2: program currency */
				$out[] = self::item( $key, $label, 'error', array(), sprintf( __( 'The amounts are in %1$s, the program currency is %2$s. Amounts are not converted.', 'affiliate-captain' ), $from_cur, $ctx['currency'] ) );
				continue;
			}
			$money = static function ( $field ) use ( $in, $from_cur ) {
				return empty( $in[ $field ] ) ? '' : Money::to_decimal( (int) $in[ $field ], $from_cur );
			};
			$data = RuleRepository::sanitize(
				array(
					'scope'        => $scope,
					'scope_id'     => $scope_id ? $scope_id : 1, // A group created by this import gets its ID on apply.
					'target'       => $target,
					'target_value' => $value,
					'rate_type'    => $rate_type,
					'rate'         => 'fixed' === $rate_type ? Money::to_decimal( (int) self::str( $in, 'rate' ), $from_cur ) : self::str( $in, 'rate' ),
					'max_amount'   => $money( 'max_amount_minor' ),
					'min_base'     => $money( 'min_base_minor' ),
					'valid_from'   => self::str( $in, 'valid_from' ),
					'valid_until'  => self::str( $in, 'valid_until' ),
					'priority'     => (int) self::str( $in, 'priority' ),
					'active'       => ! isset( $in['active'] ) || ! empty( $in['active'] ),
					'note'         => $note,
				)
			);
			if ( is_wp_error( $data ) ) {
				$out[] = self::item( $key, $label, 'error', array(), $data->get_error_message() );
				continue;
			}
			$data['scope_id'] = $scope_id;
			$op               = array(
				'data'  => $data,
				'group' => $group,
			);
			$n                = isset( $used[ $key ] ) ? $used[ $key ] : 0;
			$used[ $key ]     = $n + 1;
			$rule             = isset( $local[ $key ][ $n ] ) ? $local[ $key ][ $n ] : null;
			$pending          = $scope_id ? '' : __( 'The group is created by this import (only when partner groups are imported too).', 'affiliate-captain' );
			if ( ! $rule ) {
				$out[] = self::item( $key, $label, 'new', array(), $pending, $op );
				continue;
			}
			$changes = array();
			$cur     = $ctx['currency'];
			$show    = array(
				'rate'             => static function ( $r ) use ( $cur ) {
					return RuleRepository::format_rate( (string) $r['rate_type'], (string) $r['rate'], $cur );
				},
				'max_amount_minor' => static function ( $r ) use ( $cur ) {
					return (int) $r['max_amount_minor'] ? Money::format( (int) $r['max_amount_minor'], $cur ) : '—';
				},
				'min_base_minor'   => static function ( $r ) use ( $cur ) {
					return (int) $r['min_base_minor'] ? Money::format( (int) $r['min_base_minor'], $cur ) : '—';
				},
				'valid_from'       => static function ( $r ) {
					return '' !== Calculator::day( $r['valid_from'] ) ? Calculator::day( $r['valid_from'] ) : '—';
				},
				'valid_until'      => static function ( $r ) {
					return '' !== Calculator::day( $r['valid_until'] ) ? Calculator::day( $r['valid_until'] ) : '—';
				},
				'priority'         => static function ( $r ) {
					return (string) (int) $r['priority'];
				},
				'active'           => static function ( $r ) {
					return (int) $r['active'] ? __( 'active', 'affiliate-captain' ) : __( 'inactive', 'affiliate-captain' );
				},
			);
			$old     = (array) $rule;
			foreach ( $show as $field => $fmt ) {
				$a = $fmt( $old );
				$b = $fmt( $data );
				if ( 'rate' === $field ) {
					$same = (string) $old['rate_type'] === (string) $data['rate_type'] && self::same_number( $old['rate'], $data['rate'] );
				} else {
					$same = $a === $b;
				}
				if ( ! $same ) {
					$changes[ $field ] = array( $a, $b );
				}
			}
			$op['id'] = (int) $rule->id;
			$out[]    = self::item( $key, $label, $changes ? 'changed' : 'unchanged', $changes, $pending, $op );
		}
		return $out;
	}

	/**
	 * Tier plans, matched by scope and group: a changed plan replaces the plan in effect (a new
	 * plan, the old one is deactivated and kept for the history of booked bonuses).
	 *
	 * @param array[] $list Items of the file.
	 * @param array   $data Section data.
	 * @param array   $ctx  Context.
	 * @return array[]
	 */
	private static function items_tiers( array $list, array $data, array $ctx ) {
		$from_cur = strtoupper( sanitize_text_field( self::str( $data, 'currency' ) ) );
		$local    = array();
		foreach ( self::local_tiers() as $plan ) {
			$local[ $plan->scope . ':' . (int) $plan->scope_id ] = $plan;
		}
		$seen = array();
		$out  = array();
		foreach ( $list as $in ) {
			$scope = sanitize_key( self::str( $in, 'scope' ) );
			$group = sanitize_text_field( self::str( $in, 'group' ) );
			$key   = $scope . '|' . self::lower( $group );
			$label = 'group' === $scope ? $group : __( 'All partners', 'affiliate-captain' );
			if ( 'affiliate' === $scope ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Tier plans for single partners are not imported.', 'affiliate-captain' ) );
				continue;
			}
			if ( ! in_array( $scope, array( 'global', 'group' ), true ) ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Unknown scope.', 'affiliate-captain' ) );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'This plan is in the file twice; only the first entry is used.', 'affiliate-captain' ) );
				continue;
			}
			$seen[ $key ] = true;
			$scope_id     = self::scope_id( $scope, $group, $ctx, $error );
			if ( null === $scope_id ) {
				$out[] = self::item( $key, $label, 'error', array(), $error );
				continue;
			}
			$basis = self::str( $in, 'basis' );
			$mode  = self::str( $in, 'mode' );
			$steps = self::steps( isset( $in['steps'] ) ? $in['steps'] : null );
			if ( ! in_array( $basis, array( 'revenue', 'orders' ), true ) || ! in_array( $mode, array( 'marginal', 'retroactive' ), true ) || ! $steps ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Basis, mode or steps of the plan are not valid.', 'affiliate-captain' ) );
				continue;
			}
			if ( 'revenue' === $basis && $from_cur !== $ctx['currency'] ) {
				/* translators: 1: currency of the file, 2: program currency */
				$out[] = self::item( $key, $label, 'error', array(), sprintf( __( 'The amounts are in %1$s, the program currency is %2$s. Amounts are not converted.', 'affiliate-captain' ), $from_cur, $ctx['currency'] ) );
				continue;
			}
			$want    = array(
				'scope'    => $scope,
				'scope_id' => $scope_id,
				'basis'    => $basis,
				'mode'     => $mode,
				'steps'    => $steps,
				'note'     => sanitize_text_field( self::str( $in, 'note' ) ),
			);
			$op      = array(
				'data'  => $want,
				'group' => $group,
			);
			$pending = $scope_id || 'global' === $scope ? '' : __( 'The group is created by this import (only when partner groups are imported too).', 'affiliate-captain' );
			$plan    = 'global' === $scope || $scope_id ? ( isset( $local[ $scope . ':' . $scope_id ] ) ? $local[ $scope . ':' . $scope_id ] : null ) : null;
			if ( ! $plan ) {
				$out[] = self::item( $key, $label, 'new', array(), $pending, $op );
				continue;
			}
			$cur      = $ctx['currency'];
			$text     = static function ( $basis, array $steps ) use ( $cur ) {
				return TierService::steps_text(
					(object) array(
						'basis' => $basis,
						'steps' => wp_json_encode( $steps ),
					),
					$cur
				);
			};
			$changes  = array();
			$old      = array(
				'basis' => (string) $plan->basis,
				'mode'  => (string) $plan->mode,
				'steps' => $text( (string) $plan->basis, self::steps( json_decode( (string) $plan->steps, true ) ) ),
				'note'  => (string) $plan->note,
			);
			$new      = array(
				'basis' => $basis,
				'mode'  => $mode,
				'steps' => $text( $basis, $steps ),
				'note'  => $want['note'],
			);
			$same_set = wp_json_encode( self::steps( json_decode( (string) $plan->steps, true ) ) ) === wp_json_encode( $steps );
			foreach ( $old as $field => $value ) {
				if ( 'steps' === $field ? ! $same_set : $value !== $new[ $field ] ) {
					$changes[ $field ] = array( $value, $new[ $field ] );
				}
			}
			$op['id'] = (int) $plan->id;
			$out[]    = self::item( $key, $label, $changes ? 'changed' : 'unchanged', $changes, $changes ? __( 'Replaces the plan in effect; the old plan is deactivated and kept.', 'affiliate-captain' ) : '', $op );
		}
		return $out;
	}

	/**
	 * E-mail templates, matched by template key and language; plus the layout (text or HTML).
	 *
	 * @param array[] $list Items of the file.
	 * @param array   $data Section data.
	 * @param array   $ctx  Context.
	 * @return array[]
	 */
	private static function items_mail_templates( array $list, array $data, array $ctx ) {
		unset( $ctx );
		$out  = array();
		$defs = Notifications::template_defs();
		$site = Locale::site();
		$inst = Locale::installed();
		if ( isset( $data['layout'] ) && in_array( $data['layout'], array( 'text', 'html' ), true ) ) {
			$now   = Notifications::html_layout() ? 'html' : 'text';
			$label = __( 'Layout of the e-mails', 'affiliate-captain' );
			$out[] = self::item( 'layout', $label, $now === $data['layout'] ? 'unchanged' : 'changed', $now === $data['layout'] ? array() : array( 'layout' => array( $now, (string) $data['layout'] ) ), '', array( 'layout' => (string) $data['layout'] ) );
		}
		$seen = array();
		foreach ( $list as $in ) {
			$tkey   = sanitize_key( self::str( $in, 'key' ) );
			$locale = self::str( $in, 'locale' );
			$locale = preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale ) ? $locale : '';
			$key    = $tkey . '@' . $locale;
			$label  = ( isset( $defs[ $tkey ] ) ? $defs[ $tkey ]['label'] : $tkey ) . ' (' . ( '' !== $locale ? Locale::name( $locale ) : '?' ) . ')';
			if ( ! isset( $defs[ $tkey ] ) ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Unknown e-mail template.', 'affiliate-captain' ) );
				continue;
			}
			if ( '' === $locale || ( $locale !== $site && ! isset( $inst[ $locale ] ) ) ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'This language is not installed on this site.', 'affiliate-captain' ) );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'This template is in the file twice; only the first entry is used.', 'affiliate-captain' ) );
				continue;
			}
			$seen[ $key ] = true;
			// The same clean-up as Notifications::save_template(), so an unchanged text is "unchanged".
			$subject = Db::cut( trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( self::str( $in, 'subject' ) ) ) ), Notifications::TEMPLATE_SUBJECT_MAX );
			$body    = Db::cut( trim( str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( self::str( $in, 'body' ) ) ) ), Notifications::TEMPLATE_BODY_MAX );
			if ( '' === $subject || '' === $body ) {
				$out[] = self::item( $key, $label, 'error', array(), __( 'Please enter a subject and a text.', 'affiliate-captain' ) );
				continue;
			}
			$lang = $locale === $site ? '' : $locale;
			$have = Notifications::stored_template( $tkey, $lang );
			if ( ! $have && '' === $lang ) {
				$default = Notifications::default_template( $tkey );
				$have    = $default && trim( $default[0] ) === $subject && trim( str_replace( array( "\r\n", "\r" ), "\n", $default[1] ) ) === $body ? array( $subject, $body ) : null;
			}
			$op = array(
				'key'     => $tkey,
				'locale'  => $lang,
				'subject' => $subject,
				'body'    => $body,
			);
			if ( ! $have ) {
				$out[] = self::item( $key, $label, 'new', array(), '', $op );
				continue;
			}
			$changes = array();
			if ( $have[0] !== $subject ) {
				$changes['subject'] = array( $have[0], $subject );
			}
			if ( $have[1] !== $body ) {
				$changes['body'] = array( self::short( $have[1] ), self::short( $body ) );
			}
			$out[] = self::item( $key, $label, $changes ? 'changed' : 'unchanged', $changes, '', $op );
		}
		return $out;
	}

	/* ---------------------------------------------------------------- Apply */

	/**
	 * Import the sections of a file: each section is checked again right before it is written
	 * (groups first), then its new and changed items are saved. Rejected items are skipped.
	 *
	 * @param mixed         $raw      "sections" of the file.
	 * @param string        $currency Program currency (after the settings of the file were applied).
	 * @param string[]|null $only     Sections to import (null = all in the file).
	 * @param array         $context  Extra audit details.
	 * @return array section => { created, updated, unchanged, failed }
	 */
	public static function apply( $raw, $currency, $only = null, array $context = array() ) {
		self::$last = array();
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$only    = null === $only ? self::SECTIONS : self::clean( $only );
		$changed = array();
		foreach ( self::SECTIONS as $section ) {
			if ( ! in_array( $section, $only, true ) || ! isset( $raw[ $section ] ) || ! is_array( $raw[ $section ] ) ) {
				continue;
			}
			$ctx    = array(
				'currency'   => strtoupper( (string) $currency ),
				'new_groups' => array(),
			);
			$counts = array(
				'created'   => 0,
				'updated'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			);
			foreach ( self::items( $section, $raw[ $section ], $ctx ) as $item ) {
				if ( 'unchanged' === $item['status'] ) {
					++$counts['unchanged'];
					continue;
				}
				if ( 'error' === $item['status'] || ! self::write( $section, $item ) ) {
					++$counts['failed'];
					continue;
				}
				++$counts[ 'new' === $item['status'] ? 'created' : 'updated' ];
				$changed[ $section ][] = $item['key'];
			}
			self::$last[ $section ] = $counts;
		}
		if ( self::$last ) {
			foreach ( $changed as $section => $keys ) {
				$changed[ $section ] = array_slice( $keys, 0, 50 );
			}
			Audit::log(
				'settings.sections_imported',
				'settings',
				0,
				$context + array(
					'sections' => self::$last,
					'changed'  => $changed,
				)
			);
		}
		return self::$last;
	}

	/**
	 * Result of the last apply().
	 *
	 * @return array section => counts
	 */
	public static function last_report() {
		return self::$last;
	}

	/**
	 * One line per section for a notice ("Commission rules: 2 new, 1 changed.").
	 *
	 * @param array $report Result of apply().
	 * @return string[]
	 */
	public static function summary( array $report ) {
		$labels = self::labels();
		$out    = array();
		foreach ( $report as $section => $c ) {
			/* translators: 1: section name, 2: new items, 3: changed items, 4: unchanged items, 5: skipped items */
			$out[] = sprintf( __( '%1$s: %2$d new, %3$d changed, %4$d unchanged, %5$d skipped.', 'affiliate-captain' ), isset( $labels[ $section ] ) ? $labels[ $section ] : $section, (int) $c['created'], (int) $c['updated'], (int) $c['unchanged'], (int) $c['failed'] );
		}
		return $out;
	}

	/**
	 * Write one item.
	 *
	 * @param string $section Section.
	 * @param array  $item    Preview item (new or changed).
	 * @return bool
	 */
	private static function write( $section, array $item ) {
		$op     = $item['op'];
		$reason = __( 'Import of a settings file', 'affiliate-captain' );
		switch ( $section ) {
			case 'groups':
				$id = isset( $op['id'] ) ? (int) $op['id'] : Groups::create( $op['data']['name'], $op['data']['description'], $op['data']['agreement_version'] );
				return $id > 0 && true === Groups::update( $id, $op['data'] );
			case 'rules':
				if ( ! $op['data']['scope_id'] && 'group' === $op['data']['scope'] ) {
					return false; // The group was not created (section not imported).
				}
				return RuleRepository::save( $op['data'], isset( $op['id'] ) ? (int) $op['id'] : 0, $reason ) > 0;
			case 'tiers':
				if ( ! $op['data']['scope_id'] && 'group' === $op['data']['scope'] ) {
					return false;
				}
				$id = TierService::create( $op['data'], $reason );
				if ( $id <= 0 ) {
					return false;
				}
				// The plan in effect for this scope is now the new one: deactivate the others (kept for history).
				foreach ( TierService::all() as $plan ) {
					if ( (int) $plan->active && (int) $plan->id !== $id && $plan->scope === $op['data']['scope'] && (int) $plan->scope_id === (int) $op['data']['scope_id'] ) {
						TierService::deactivate( (int) $plan->id, $reason );
					}
				}
				return true;
			case 'mail_templates':
				if ( isset( $op['layout'] ) ) {
					return Notifications::set_layout( $op['layout'] ) || Notifications::html_layout() === ( 'html' === $op['layout'] );
				}
				return true === Notifications::save_template( $op['key'], $op['subject'], $op['body'], $op['locale'] );
		}
		return false;
	}

	/* ---------------------------------------------------------------- Helpers */

	/**
	 * Rules of the site that can be exported (all partners and groups), oldest first.
	 *
	 * @return object[]
	 */
	private static function local_rules() {
		$rules = array_values(
			array_filter(
				(array) RuleRepository::all(),
				static function ( $r ) {
					return in_array( $r->scope, array( 'global', 'group' ), true );
				}
			)
		);
		usort(
			$rules,
			static function ( $a, $b ) {
				return (int) $a->id <=> (int) $b->id;
			}
		);
		return $rules;
	}

	/**
	 * Tier plan in effect per scope (all partners, each group): the newest active plan.
	 *
	 * @return object[]
	 */
	private static function local_tiers() {
		$out = array();
		foreach ( (array) TierService::all() as $plan ) {
			if ( ! (int) $plan->active || ! in_array( $plan->scope, array( 'global', 'group' ), true ) ) {
				continue;
			}
			$k = $plan->scope . ':' . (int) $plan->scope_id;
			if ( ! isset( $out[ $k ] ) || (int) $plan->id > (int) $out[ $k ]->id ) {
				$out[ $k ] = $plan;
			}
		}
		return array_values( $out );
	}

	/**
	 * Groups of the site by lower-case name.
	 *
	 * @return array name => int[] IDs
	 */
	private static function group_index() {
		$out = array();
		foreach ( Groups::options() as $id => $name ) {
			$out[ self::lower( (string) $name ) ][] = (int) $id;
		}
		return $out;
	}

	/**
	 * Group ID of a rule or plan: 0 for all partners and for a group this import creates, null
	 * (with $error) when the group cannot be found.
	 *
	 * @param string $scope Scope.
	 * @param string $group Group name.
	 * @param array  $ctx   Context.
	 * @param string $error Set to the reason.
	 * @return int|null
	 */
	private static function scope_id( $scope, $group, array $ctx, &$error ) {
		$error = '';
		if ( 'global' === $scope ) {
			return 0;
		}
		$key   = self::lower( $group );
		$index = self::group_index();
		if ( '' === $key ) {
			$error = __( 'The group is missing.', 'affiliate-captain' );
			return null;
		}
		if ( isset( $index[ $key ] ) ) {
			if ( count( $index[ $key ] ) > 1 ) {
				$error = __( 'Several groups with this name exist on this site. Rename them first.', 'affiliate-captain' );
				return null;
			}
			return $index[ $key ][0];
		}
		if ( in_array( $key, $ctx['new_groups'], true ) ) {
			return 0;
		}
		/* translators: %s: group name */
		$error = sprintf( __( 'Group "%s" not found on this site. Import the partner groups too, or create the group first.', 'affiliate-captain' ), $group );
		return null;
	}

	/**
	 * Matching key of a rule.
	 *
	 * @param string   $scope  Scope.
	 * @param string   $group  Group name.
	 * @param string   $target Target.
	 * @param string[] $refs   References.
	 * @param string   $note   Note.
	 * @return string
	 */
	private static function rule_key( $scope, $group, $target, array $refs, $note ) {
		sort( $refs );
		return implode( '|', array( $scope, self::lower( $group ), $target, implode( ',', $refs ), self::lower( $note ) ) );
	}

	/**
	 * Shown name of a rule.
	 *
	 * @param string   $scope  Scope.
	 * @param string   $group  Group name.
	 * @param string   $target Target.
	 * @param string[] $refs   References.
	 * @param string   $note   Note.
	 * @return string
	 */
	private static function rule_label( $scope, $group, $target, array $refs, $note ) {
		$who = 'group' === $scope ? $group : ( 'global' === $scope ? __( 'All partners', 'affiliate-captain' ) : $scope );
		$out = $who . ' · ' . ( 'all' === $target ? __( 'all products', 'affiliate-captain' ) : $target . ' ' . implode( ', ', $refs ) );
		return '' !== $note ? $out . ' · ' . $note : $out;
	}

	/**
	 * References of a rule target that stay valid on another site: products by SKU (else slug),
	 * categories and tags by slug; SKUs as they are (upper case, sorted).
	 *
	 * @param string $target Target.
	 * @param string $value  Stored target value.
	 * @return string[]
	 */
	public static function refs( $target, $value ) {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( 'sku' === $target ) {
				$out[] = strtoupper( $part );
				continue;
			}
			if ( 'all' === $target ) {
				continue;
			}
			$id  = absint( $part );
			$ref = '';
			if ( 'product' === $target ) {
				$sku = $id && function_exists( 'get_post_meta' ) ? (string) get_post_meta( $id, '_sku', true ) : '';
				$ref = '' !== $sku ? 'sku:' . $sku : self::post_slug( $id );
			} elseif ( $id && function_exists( 'get_term' ) ) {
				$term = get_term( $id, 'category' === $target ? 'product_cat' : 'product_tag' );
				$ref  = is_object( $term ) && ! is_wp_error( $term ) && ! empty( $term->slug ) ? 'slug:' . $term->slug : '';
			}
			$out[] = '' !== $ref ? $ref : 'id:' . $id;
		}
		if ( 'sku' === $target ) {
			sort( $out );
		}
		return $out;
	}

	/**
	 * References of plain IDs (hand-written file without references).
	 *
	 * @param string $value Comma separated IDs.
	 * @return string[]
	 */
	private static function id_refs( $value ) {
		$out = array();
		foreach ( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) as $id ) {
			$out[] = 'id:' . $id;
		}
		return $out;
	}

	/**
	 * Target value of this site for references; $missing gets the references not found.
	 *
	 * @param string   $target  Target.
	 * @param string[] $refs    References.
	 * @param string[] $missing Not found.
	 * @return string
	 */
	private static function resolve_refs( $target, array $refs, &$missing ) {
		$missing = array();
		if ( 'all' === $target ) {
			return '';
		}
		if ( 'sku' === $target ) {
			return implode( ',', $refs );
		}
		$ids = array();
		foreach ( $refs as $ref ) {
			$ref = sanitize_text_field( (string) $ref );
			list( $kind, $val ) = array_pad( explode( ':', $ref, 2 ), 2, '' );
			$id = 0;
			if ( 'id' === $kind ) {
				$id = absint( $val ); // Kept as it is (a file without SKU or slug).
			} elseif ( 'product' === $target ) {
				$id = 'sku' === $kind ? self::product_by_sku( $val ) : ( 'slug' === $kind ? self::post_id( $val, 'product' ) : 0 );
			} elseif ( 'slug' === $kind && function_exists( 'get_term_by' ) ) {
				$term = get_term_by( 'slug', $val, 'category' === $target ? 'product_cat' : 'product_tag' );
				$id   = is_object( $term ) ? (int) $term->term_id : 0;
			}
			if ( $id ) {
				$ids[] = $id;
			} else {
				$missing[] = $ref;
			}
		}
		return implode( ',', $ids );
	}

	/**
	 * Product ID by SKU.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	private static function product_by_sku( $sku ) {
		if ( '' === $sku ) {
			return 0;
		}
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			return (int) wc_get_product_id_by_sku( $sku );
		}
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'meta_key'       => '_sku', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only on import.
				'meta_value'     => $sku, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- only on import.
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * "slug:…" of a post, '' when unknown.
	 *
	 * @param int $id Post.
	 * @return string
	 */
	private static function post_slug( $id ) {
		$post = $id && function_exists( 'get_post' ) ? get_post( $id ) : null;
		return is_object( $post ) && ! empty( $post->post_name ) ? 'slug:' . $post->post_name : '';
	}

	/**
	 * Post ID by slug.
	 *
	 * @param string $slug Slug.
	 * @param string $type Post type.
	 * @return int
	 */
	private static function post_id( $slug, $type ) {
		if ( '' === $slug || ! function_exists( 'get_page_by_path' ) ) {
			return 0;
		}
		$post = get_page_by_path( $slug, OBJECT, $type );
		return is_object( $post ) ? (int) $post->ID : 0;
	}

	/**
	 * Path of a page ('' when none).
	 *
	 * @param int $id Page.
	 * @return string
	 */
	private static function page_slug( $id ) {
		$post = $id && function_exists( 'get_post' ) ? get_post( $id ) : null;
		return is_object( $post ) && ! empty( $post->post_name ) ? (string) $post->post_name : '';
	}

	/**
	 * Page ID by path.
	 *
	 * @param string $slug Path.
	 * @return int
	 */
	private static function page_id( $slug ) {
		return self::post_id( $slug, 'page' );
	}

	/**
	 * Shown name of a page.
	 *
	 * @param int $id Page.
	 * @return string
	 */
	private static function page_label( $id ) {
		if ( ! $id ) {
			return '—';
		}
		$slug = self::page_slug( $id );
		return '' !== $slug ? $slug : '#' . $id;
	}

	/**
	 * Application fields as the editable text of the group form (key|Label|type|required).
	 *
	 * @param array $fields Field definitions.
	 * @return string
	 */
	private static function fields_text( array $fields ) {
		$lines = array();
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['key'], $f['label'] ) ) {
				continue;
			}
			$type    = isset( $f['type'] ) ? (string) $f['type'] : 'text';
			$lines[] = str_replace( '|', ' ', (string) $f['key'] ) . '|' . str_replace( '|', ' ', (string) $f['label'] ) . '|' . $type . ( ! empty( $f['required'] ) ? '|required' : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Tier steps in a canonical form ({threshold:int, rate:string}, sorted); [] when invalid.
	 *
	 * @param mixed $steps Steps.
	 * @return array
	 */
	private static function steps( $steps ) {
		$out = array();
		foreach ( is_array( $steps ) ? $steps : array() as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['threshold'], $s['rate'] ) || ! is_numeric( $s['threshold'] ) ) {
				return array();
			}
			$rate = Money::normalize_decimal( (string) $s['rate'] );
			if ( null === $rate || (float) $rate < 0 || (float) $rate > 100 || (int) $s['threshold'] < 0 ) {
				return array();
			}
			$out[] = array(
				'threshold' => (int) $s['threshold'],
				'rate'      => $rate,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['threshold'] <=> $b['threshold'];
			}
		);
		return $out;
	}

	/**
	 * A value of the file as a string.
	 *
	 * @param array  $in  Item.
	 * @param string $key Key.
	 * @return string
	 */
	private static function str( array $in, $key ) {
		return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
	}

	/**
	 * Lower case (multibyte where available).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Same decimal number ("10" = "10.0").
	 *
	 * @param mixed $a Number.
	 * @param mixed $b Number.
	 * @return bool
	 */
	private static function same_number( $a, $b ) {
		$na = Money::normalize_decimal( (string) $a );
		$nb = Money::normalize_decimal( (string) $b );
		if ( null === $na || null === $nb ) {
			return (string) $a === (string) $b;
		}
		return rtrim( rtrim( strpos( $na, '.' ) !== false ? $na : $na . '.', '0' ), '.' ) === rtrim( rtrim( strpos( $nb, '.' ) !== false ? $nb : $nb . '.', '0' ), '.' );
	}

	/**
	 * Long text shortened for the preview.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 157 ) . '…' : $text;
	}
}
