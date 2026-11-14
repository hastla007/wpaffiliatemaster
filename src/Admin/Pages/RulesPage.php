<?php
/**
 * Admin: commission rules, partner groups and rule preview.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Commissions\Calculator;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Commissions\Tiers;
use WPAffiliateCaptain\Commissions\TierService;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class RulesPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		switch ( $do ) {
			case 'rule_save':
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				$id     = absint( Admin::post( 'id', 0 ) );
				if ( $id && '' === $reason ) {
					Admin::back( __( 'Please enter a reason for the change.', 'affiliate-captain' ), 'error' );
				}
				$data = RuleRepository::sanitize(
					array(
						'scope'        => sanitize_key( Admin::post( 'scope' ) ),
						'scope_id'     => absint( Admin::post( 'scope' ) === 'group' ? Admin::post( 'group_id' ) : Admin::post( 'affiliate_id' ) ),
						'target'       => sanitize_key( Admin::post( 'target' ) ),
						'target_value' => self::posted_target_value( sanitize_key( Admin::post( 'target' ) ) ),
						'rate_type'    => sanitize_key( Admin::post( 'rate_type' ) ),
						'rate'         => Admin::post( 'rate' ),
						'priority'     => (int) Admin::post( 'priority', 0 ),
						'active'       => Admin::post( 'active' ),
						'note'         => Admin::post( 'note' ),
					)
				);
				if ( is_wp_error( $data ) ) {
					Admin::back( $data->get_error_message(), 'error' );
				}
				RuleRepository::save( $data, $id, $reason );
				Admin::back( __( 'Rule saved. Existing commissions are not changed.', 'affiliate-captain' ), 'success', Admin::url( 'wpac-rules' ) );
				break;

			case 'rule_delete':
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				if ( '' === $reason ) {
					Admin::back( __( 'Please enter a reason.', 'affiliate-captain' ), 'error' );
				}
				RuleRepository::delete( absint( Admin::post( 'id' ) ), $reason );
				Admin::back( __( 'Rule deleted.', 'affiliate-captain' ) );
				break;

			case 'group_create':
				$name = sanitize_text_field( Admin::post( 'name' ) );
				if ( '' === $name ) {
					Admin::back( __( 'Please enter a name.', 'affiliate-captain' ), 'error' );
				}
				Groups::create( $name, Admin::post( 'description' ) );
				Admin::back( __( 'Group created.', 'affiliate-captain' ) );
				break;

			case 'tier_create':
				$basis = 'orders' === Admin::post( 'basis' ) ? 'orders' : 'revenue';
				$steps = Tiers::parse_steps( (string) Admin::post( 'steps' ), $basis, (string) Settings::get( 'currency' ) );
				if ( null === $steps ) {
					Admin::back( __( 'Please enter steps as "threshold:bonus percent", one per line.', 'affiliate-captain' ), 'error' );
				}
				$scope = sanitize_key( Admin::post( 'scope' ) );
				TierService::create(
					array(
						'scope'    => $scope,
						'scope_id' => absint( 'group' === $scope ? Admin::post( 'group_id' ) : Admin::post( 'affiliate_id' ) ),
						'basis'    => $basis,
						'mode'     => sanitize_key( Admin::post( 'mode' ) ),
						'steps'    => $steps,
						'note'     => Admin::post( 'note' ),
					),
					sanitize_text_field( Admin::post( 'reason' ) )
				);
				Admin::back( __( 'Tier plan created.', 'affiliate-captain' ) );
				break;

			case 'tier_deactivate':
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				if ( '' === $reason ) {
					Admin::back( __( 'Please enter a reason.', 'affiliate-captain' ), 'error' );
				}
				TierService::deactivate( absint( Admin::post( 'id' ) ), $reason );
				Admin::back( __( 'Tier plan deactivated.', 'affiliate-captain' ) );
				break;

			case 'tier_close_month':
				$month = sanitize_text_field( Admin::post( 'month' ) );
				if ( ! TierService::is_due( $month ) ) {
					Admin::back( __( 'This month cannot be closed yet: it must have ended and its hold period must have passed.', 'affiliate-captain' ), 'error' );
				}
				$n = TierService::close_month( $month );
				/* translators: 1: count, 2: month */
				Admin::back( sprintf( __( '%1$d monthly bonuses booked for %2$s.', 'affiliate-captain' ), $n, $month ) );
				break;

			case 'group_update':
				$r = Groups::update(
					absint( Admin::post( 'id' ) ),
					array(
						'name'              => Admin::post( 'name' ),
						'description'       => Admin::post( 'description' ),
						'terms_page_id'     => Admin::post( 'terms_page_id' ),
						'agreement_version' => Admin::post( 'agreement_version' ),
						'public'            => Admin::post( 'public' ),
						'fields'            => Admin::post( 'fields' ),
					)
				);
				if ( is_wp_error( $r ) ) {
					Admin::back( $r->get_error_message(), 'error' );
				}
				Admin::back( __( 'Group saved.', 'affiliate-captain' ) );
				break;

			case 'group_delete':
				$impact = Groups::delete( absint( Admin::post( 'id' ) ) );
				$msg    = __( 'Group deleted.', 'affiliate-captain' );
				if ( $impact['rules'] ) {
					/* translators: %d: number of rules */
					$msg .= ' ' . sprintf( _n( '%d commission rule of the group was deactivated.', '%d commission rules of the group were deactivated.', $impact['rules'], 'affiliate-captain' ), $impact['rules'] );
				}
				if ( $impact['own_terms'] && $impact['partners'] ) {
					/* translators: %d: number of partners */
					$msg .= ' ' . sprintf( _n( '%d partner now falls under the program terms and must accept them before the next credit note.', '%d partners now fall under the program terms and must accept them before the next credit note.', $impact['partners'], 'affiliate-captain' ), $impact['partners'] );
				}
				Admin::back( $msg, $impact['own_terms'] && $impact['partners'] ? 'warning' : 'success' );
				break;
		}
	}

	/**
	 * Render.
	 */
	public static function render() {
		$groups   = Groups::options();
		$currency = (string) Settings::get( 'currency' );
		list( $affs ) = AffiliateRepository::query( array( 'limit' => 1000 ) );
		$aff_names    = array();
		foreach ( $affs as $a ) {
			$aff_names[ (int) $a->id ] = $a->name;
		}
		$edit = absint( Admin::get( 'edit' ) ) ? RuleRepository::find( absint( Admin::get( 'edit' ) ) ) : null;

		echo '<div class="wrap"><h1>' . esc_html__( 'Rules & groups', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		$tabs = array(
			'rules'   => __( 'Rules', 'affiliate-captain' ),
			'preview' => __( 'Rule preview', 'affiliate-captain' ),
			'tiers'   => __( 'Monthly tiers', 'affiliate-captain' ),
			'groups'  => __( 'Partner groups', 'affiliate-captain' ),
		);
		$tab  = Admin::get( 'tab' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : ( Admin::get( 'preview' ) ? 'preview' : 'rules' );
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Sections', 'affiliate-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s"%s>%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( Admin::url( 'wpac-rules', array( 'tab' => $key ) ) ), $key === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav><div class="wpac-tabs-content">';
		if ( 'preview' === $tab ) {
			self::render_preview( $aff_names, $currency );
		} elseif ( 'tiers' === $tab ) {
			self::render_tiers( $groups, $aff_names, $currency );
		} elseif ( 'groups' === $tab ) {
			self::render_groups();
		} else {
			self::render_rules( $groups, $aff_names, $currency, $edit );
		}
		echo '</div></div>';
	}

	/**
	 * Rules tab.
	 *
	 * @param array       $groups    Groups.
	 * @param array       $aff_names Affiliates.
	 * @param string      $currency  Currency.
	 * @param object|null $edit      Rule being edited.
	 */
	private static function render_rules( array $groups, array $aff_names, $currency, $edit ) {
		/* translators: %s: default rate */
		echo '<p>' . esc_html( sprintf( __( 'Program default: %s. Basis is the net line amount after discount, excluding tax and shipping.', 'affiliate-captain' ), RuleRepository::format_rate( Settings::get( 'default_rate_type' ), Settings::get( 'default_rate' ), $currency ) ) ) . ' ';
		echo esc_html__( 'Resolution: an exclusion always wins; otherwise the most specific rule applies (partner > group > global, product/SKU > category > all, then priority).', 'affiliate-captain' ) . '</p>';

		$scope_label = static function ( $r ) use ( $groups, $aff_names ) {
			if ( 'group' === $r->scope ) {
				return __( 'Group', 'affiliate-captain' ) . ': ' . ( isset( $groups[ (int) $r->scope_id ] ) ? $groups[ (int) $r->scope_id ] : '#' . $r->scope_id );
			}
			if ( 'affiliate' === $r->scope ) {
				return __( 'Partner', 'affiliate-captain' ) . ': ' . ( isset( $aff_names[ (int) $r->scope_id ] ) ? $aff_names[ (int) $r->scope_id ] : '#' . $r->scope_id );
			}
			return __( 'Global', 'affiliate-captain' );
		};
		$targets = array(
			'all'      => __( 'All products', 'affiliate-captain' ),
			'product'  => __( 'Product IDs', 'affiliate-captain' ),
			'category' => __( 'Category IDs', 'affiliate-captain' ),
			'tag'      => __( 'Product tag IDs', 'affiliate-captain' ),
			'sku'      => __( 'SKU / course code', 'affiliate-captain' ),
		);

		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Applies to', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Products', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Rate', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Priority', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Note', 'affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		$rules = RuleRepository::all();
		if ( ! $rules ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No rules yet – the program default applies to all sales.', 'affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $rules as $r ) {
			printf(
				'<tr class="%s"><td>%d <small>v%d</small></td><td>%s</td><td>%s%s</td><td><strong>%s</strong></td><td>%d</td><td>%s</td><td><a href="%s">%s</a> ',
				$r->active ? '' : 'wpac-inactive',
				(int) $r->id,
				(int) $r->version,
				esc_html( $scope_label( $r ) ),
				esc_html( $targets[ $r->target ] ),
				'' !== $r->target_value ? ': <code>' . esc_html( $r->target_value ) . '</code>' : '',
				esc_html( RuleRepository::format_rate( $r->rate_type, $r->rate, $currency ) ),
				(int) $r->priority,
				esc_html( $r->note ),
				esc_url( Admin::url( 'wpac-rules', array( 'edit' => $r->id ) ) ),
				esc_html__( 'Edit', 'affiliate-captain' )
			);
			Admin::form_open( 'rule_delete', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><input type="text" name="reason" aria-label="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" size="10"> <button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Delete this rule? Existing commissions are not changed.', 'affiliate-captain' ) . '">' . esc_html__( 'Delete', 'affiliate-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';

		// Rule form.
		$v = static function ( $k, $d = '' ) use ( $edit ) {
			return $edit ? (string) $edit->$k : $d;
		};
		$rate_display = $edit && 'fixed' === $edit->rate_type ? Money::to_decimal( (int) $edit->rate, $currency ) : $v( 'rate' );
		echo '<h2>' . esc_html( $edit ? __( 'Edit rule', 'affiliate-captain' ) . ' #' . $edit->id : __( 'Add rule', 'affiliate-captain' ) ) . '</h2>';
		Admin::form_open( 'rule_save' );
		echo '<input type="hidden" name="id" value="' . (int) ( $edit ? $edit->id : 0 ) . '"><table class="form-table">';
		echo '<tr><th><label for="wpac-rule-scope">' . esc_html__( 'Applies to', 'affiliate-captain' ) . '</label></th><td><select name="scope" id="wpac-rule-scope">';
		foreach ( array( 'global' => __( 'All partners', 'affiliate-captain' ), 'group' => __( 'Partner group', 'affiliate-captain' ), 'affiliate' => __( 'Single partner', 'affiliate-captain' ) ) as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'scope', 'global' ), $k, false ), esc_html( $l ) );
		}
		echo '</select> <select name="group_id" aria-label="' . esc_attr__( 'Partner group', 'affiliate-captain' ) . '" data-wpac-depends="scope=group"><option value="">' . esc_html__( '– group –', 'affiliate-captain' ) . '</option>';
		foreach ( $groups as $gid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $gid, selected( 'group' === $v( 'scope' ) ? (int) $v( 'scope_id' ) : 0, $gid, false ), esc_html( $name ) );
		}
		echo '</select> <select name="affiliate_id" aria-label="' . esc_attr__( 'Partner', 'affiliate-captain' ) . '" data-wpac-depends="scope=affiliate"><option value="">' . esc_html__( '– partner –', 'affiliate-captain' ) . '</option>';
		foreach ( $aff_names as $aid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $aid, selected( 'affiliate' === $v( 'scope' ) ? (int) $v( 'scope_id' ) : 0, $aid, false ), esc_html( $name ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="wpac-rule-target">' . esc_html__( 'Products', 'affiliate-captain' ) . '</label></th><td><select name="target" id="wpac-rule-target">';
		foreach ( $targets as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'target', 'all' ), $k, false ), esc_html( $l ) );
		}
		echo '</select>';
		// With WooCommerce: products by search and categories from a list instead of typed IDs (R5-O51).
		$pickers = self::target_pickers( $v( 'target' ), $v( 'target_value' ) );
		echo $pickers; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in target_pickers().
		echo ' <span data-wpac-depends="target=' . ( '' !== $pickers ? 'tag|sku' : 'product|category|tag|sku' ) . '"><input type="text" name="target_value" value="' . esc_attr( $v( 'target_value' ) ) . '" class="regular-text" aria-label="' . esc_attr__( 'IDs or SKUs', 'affiliate-captain' ) . '" aria-describedby="wpac-target-hint"><span class="wpac-field-hint" id="wpac-target-hint">' . esc_html__( 'Comma separated; SKUs support trailing * (e.g. GROUP-*)', 'affiliate-captain' ) . '</span></span></td></tr>';
		echo '<tr><th><label for="wpac-rule-type">' . esc_html__( 'Commission', 'affiliate-captain' ) . '</label></th><td><select name="rate_type" id="wpac-rule-type">';
		foreach ( array( 'percent' => __( 'Percent of net', 'affiliate-captain' ), 'fixed' => __( 'Fixed amount per unit', 'affiliate-captain' ), 'exclude' => __( 'Exclude (no commission)', 'affiliate-captain' ) ) as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'rate_type', 'percent' ), $k, false ), esc_html( $l ) );
		}
		echo '</select> <span data-wpac-depends="rate_type=percent|fixed"><input type="text" name="rate" value="' . esc_attr( $rate_display ) . '" size="8" aria-label="' . esc_attr__( 'Rate', 'affiliate-captain' ) . '"> <span data-wpac-depends="rate_type=percent">%</span><span data-wpac-depends="rate_type=fixed">' . esc_html( $currency . ' ' . __( 'per unit', 'affiliate-captain' ) ) . '</span></span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Priority', 'affiliate-captain' ) . '</th><td><input type="number" name="priority" value="' . esc_attr( $v( 'priority', '0' ) ) . '" class="small-text" aria-label="' . esc_attr__( 'Priority', 'affiliate-captain' ) . '"> <label><input type="checkbox" name="active" value="1" ' . checked( $v( 'active', '1' ), '1', false ) . '> ' . esc_html__( 'Active', 'affiliate-captain' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Note', 'affiliate-captain' ) . '</th><td><input type="text" name="note" value="' . esc_attr( $v( 'note' ) ) . '" class="regular-text" aria-label="' . esc_attr__( 'Note', 'affiliate-captain' ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Reason for change', 'affiliate-captain' ) . '</th><td><input type="text" name="reason" class="regular-text" aria-label="' . esc_attr__( 'Reason for change', 'affiliate-captain' ) . '" ' . ( $edit ? 'required' : '' ) . '></td></tr>';
		echo '</table>';
		submit_button( $edit ? __( 'Save rule', 'affiliate-captain' ) : __( 'Add rule', 'affiliate-captain' ) );
		echo '</form>';

	}

	/**
	 * Product search and category list for a rule (WooCommerce): '' without WooCommerce.
	 *
	 * @param string $target Current target.
	 * @param string $value  Current IDs (comma separated).
	 * @return string HTML.
	 */
	private static function target_pickers( $target, $value ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
		$out = ' <span data-wpac-depends="target=product"><label class="screen-reader-text" for="wpac-rule-products">' . esc_html__( 'Products', 'affiliate-captain' ) . '</label><select class="wc-product-search" multiple="multiple" style="min-width:320px" id="wpac-rule-products" name="target_products[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'affiliate-captain' ) . '" data-action="woocommerce_json_search_products_and_variations">';
		if ( 'product' === $target ) {
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				$out    .= '<option value="' . (int) $id . '" selected="selected">' . esc_html( $product ? wp_strip_all_tags( $product->get_formatted_name() ) : '#' . $id ) . '</option>';
			}
		}
		$out .= '</select></span>';
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		$out .= ' <span data-wpac-depends="target=category"><label class="screen-reader-text" for="wpac-rule-categories">' . esc_html__( 'Categories', 'affiliate-captain' ) . '</label><select class="wc-enhanced-select" multiple="multiple" style="min-width:320px" id="wpac-rule-categories" name="target_categories[]" data-placeholder="' . esc_attr__( 'Choose categories…', 'affiliate-captain' ) . '">';
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out .= '<option value="' . (int) $term->term_id . '"' . ( 'category' === $target && in_array( (int) $term->term_id, $ids, true ) ? ' selected="selected"' : '' ) . '>' . esc_html( $term->name ) . '</option>';
		}
		return $out . '</select></span>';
	}

	/**
	 * Target value of the rule form: chosen products/categories, else the typed value.
	 *
	 * @param string $target Target.
	 * @return string
	 */
	private static function posted_target_value( $target ) {
		$lists = array(
			'product'  => 'target_products',
			'category' => 'target_categories',
		);
		if ( isset( $lists[ $target ] ) ) {
			$ids = array_filter( array_map( 'absint', (array) Admin::post( $lists[ $target ], array() ) ) );
			if ( $ids ) {
				return implode( ',', $ids );
			}
		}
		return (string) Admin::post( 'target_value' );
	}

	/**
	 * Groups tab.
	 */
	private static function render_groups() {
		// Groups.
		echo '<h2>' . esc_html__( 'Partner groups', 'affiliate-captain' ) . '</h2><p class="description">' . esc_html__( 'e.g. "Training partner" and "Content partner" – each group can get its own rules.', 'affiliate-captain' ) . '</p><ul>';
		foreach ( Groups::all() as $g ) {
			echo '<li class="wpac-group"><details><summary><strong>' . esc_html( $g->name ) . '</strong> ' . esc_html( $g->description ) . ( (int) $g->public ? ' · ' . esc_html__( 'selectable in application form', 'affiliate-captain' ) : '' ) . ( (int) $g->terms_page_id ? ' · ' . esc_html__( 'own terms', 'affiliate-captain' ) . ' v' . esc_html( $g->agreement_version ) : '' ) . '</summary>';
			Admin::form_open( 'group_update' );
			echo '<input type="hidden" name="id" value="' . (int) $g->id . '"><table class="form-table">';
			echo '<tr><th>' . esc_html__( 'Name', 'affiliate-captain' ) . '</th><td><input type="text" name="name" value="' . esc_attr( $g->name ) . '" class="regular-text" required></td></tr>';
			echo '<tr><th>' . esc_html__( 'Description', 'affiliate-captain' ) . '</th><td><input type="text" name="description" value="' . esc_attr( (string) $g->description ) . '" class="large-text"></td></tr>';
			echo '<tr><th>' . esc_html__( 'Own terms page', 'affiliate-captain' ) . '</th><td>';
			wp_dropdown_pages(
				array(
					'name'              => 'terms_page_id',
					'selected'          => (int) $g->terms_page_id,
					'show_option_none'  => esc_html__( '— program terms —', 'affiliate-captain' ),
					'option_none_value' => '0',
				)
			);
			echo ' ' . esc_html__( 'Version', 'affiliate-captain' ) . ' <input type="text" name="agreement_version" value="' . esc_attr( $g->agreement_version ) . '" size="6"></td></tr>';
			echo '<tr><th>' . esc_html__( 'Application', 'affiliate-captain' ) . '</th><td><label><input type="checkbox" name="public" value="1" ' . checked( (int) $g->public, 1, false ) . '> ' . esc_html__( 'Applicants can choose this group', 'affiliate-captain' ) . '</label><br><code>[wpac_affiliate_registration group="' . (int) $g->id . '"]</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Additional application fields', 'affiliate-captain' ) . '</th><td><textarea name="fields" rows="4" class="large-text code" placeholder="school_name|Name der Bootsschule|text|required&#10;students|Schüler pro Jahr|number">' . esc_textarea( Groups::fields_text( $g ) ) . '</textarea><p class="description">' . esc_html__( 'One field per line: key|Label|type|required – types: text, textarea, url, number, checkbox.', 'affiliate-captain' ) . '</p></td></tr>';
			echo '</table><p><button class="button button-primary">' . esc_html__( 'Save group', 'affiliate-captain' ) . '</button></p></form>';
			Admin::form_open( 'group_delete', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $g->id . '"><button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Delete this group? Its partners fall back to the program rules and terms (with own terms they must accept the program terms before the next credit note) and the commission rules of the group are deactivated.', 'affiliate-captain' ) . '">' . esc_html__( 'Delete group', 'affiliate-captain' ) . '</button></form></details></li>';
		}
		echo '</ul>';
		Admin::form_open( 'group_create' );
		echo '<input type="text" name="name" aria-label="' . esc_attr__( 'Name', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Name', 'affiliate-captain' ) . '" required> <input type="text" name="description" aria-label="' . esc_attr__( 'Description', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Description', 'affiliate-captain' ) . '" class="regular-text"> <button class="button">' . esc_html__( 'Add group', 'affiliate-captain' ) . '</button></form>';
	}

	/**
	 * Monthly tier plans.
	 *
	 * @param array  $groups    Groups.
	 * @param array  $aff_names Affiliates.
	 * @param string $currency  Currency.
	 */
	private static function render_tiers( array $groups, array $aff_names, $currency ) {
		echo '<h2>' . esc_html__( 'Monthly tiers', 'affiliate-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Additional bonus in percent of the monthly net revenue (after refunds). The most specific active plan applies (partner > group > global). Months are closed automatically after the hold period; the plan is frozen in the booked bonus.', 'affiliate-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Applies to', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Basis', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Mode', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Steps', 'affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		$plans = TierService::all();
		if ( ! $plans ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No tier plans.', 'affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $plans as $p ) {
			$scope = 'global' === $p->scope ? __( 'Global', 'affiliate-captain' ) : ( 'group' === $p->scope ? __( 'Group', 'affiliate-captain' ) . ': ' . ( isset( $groups[ (int) $p->scope_id ] ) ? $groups[ (int) $p->scope_id ] : '#' . $p->scope_id ) : __( 'Partner', 'affiliate-captain' ) . ': ' . ( isset( $aff_names[ (int) $p->scope_id ] ) ? $aff_names[ (int) $p->scope_id ] : '#' . $p->scope_id ) );
			printf(
				'<tr class="%s"><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
				$p->active ? '' : 'wpac-inactive',
				(int) $p->id,
				esc_html( $scope ),
				esc_html( 'orders' === $p->basis ? __( 'Number of orders', 'affiliate-captain' ) : __( 'Net revenue', 'affiliate-captain' ) ),
				esc_html( 'retroactive' === $p->mode ? __( 'Retroactive', 'affiliate-captain' ) : __( 'Marginal', 'affiliate-captain' ) ),
				esc_html( TierService::steps_text( $p, $currency ) )
			);
			if ( $p->active ) {
				Admin::form_open( 'tier_deactivate', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $p->id . '"><input type="text" name="reason" size="10" aria-label="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '"> <button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Deactivate this tier plan?', 'affiliate-captain' ) . '">' . esc_html__( 'Deactivate', 'affiliate-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Add tier plan', 'affiliate-captain' ) . '</h3>';
		Admin::form_open( 'tier_create' );
		echo '<p><select name="scope"><option value="global">' . esc_html__( 'All partners', 'affiliate-captain' ) . '</option><option value="group">' . esc_html__( 'Partner group', 'affiliate-captain' ) . '</option><option value="affiliate">' . esc_html__( 'Single partner', 'affiliate-captain' ) . '</option></select> ';
		echo '<select name="group_id"><option value="">' . esc_html__( '– group –', 'affiliate-captain' ) . '</option>';
		foreach ( $groups as $gid => $name ) {
			printf( '<option value="%d">%s</option>', (int) $gid, esc_html( $name ) );
		}
		echo '</select> <select name="affiliate_id"><option value="">' . esc_html__( '– partner –', 'affiliate-captain' ) . '</option>';
		foreach ( $aff_names as $aid => $name ) {
			printf( '<option value="%d">%s</option>', (int) $aid, esc_html( $name ) );
		}
		echo '</select></p><p><select name="basis"><option value="revenue">' . esc_html__( 'Basis: net revenue per month', 'affiliate-captain' ) . '</option><option value="orders">' . esc_html__( 'Basis: number of orders per month', 'affiliate-captain' ) . '</option></select> ';
		echo '<select name="mode"><option value="marginal">' . esc_html__( 'Marginal (only above the threshold)', 'affiliate-captain' ) . '</option><option value="retroactive">' . esc_html__( 'Retroactive (whole month)', 'affiliate-captain' ) . '</option></select></p>';
		/* translators: %s: currency */
		echo '<p><textarea name="steps" rows="3" cols="40" placeholder="' . esc_attr( sprintf( __( "threshold:bonus %%\n1000:2\n5000:3   (revenue in %s, or number of orders)", 'affiliate-captain' ), $currency ) ) . '"></textarea></p>';
		echo '<p><input type="text" name="note" aria-label="' . esc_attr__( 'Note', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Note', 'affiliate-captain' ) . '" class="regular-text"> <input type="text" name="reason" aria-label="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" class="regular-text"> <button class="button">' . esc_html__( 'Add tier plan', 'affiliate-captain' ) . '</button></p></form>';

		Admin::form_open( 'tier_close_month' );
		/* translators: %s: last closed month */
		echo '<p>' . esc_html( sprintf( __( 'Last closed month: %s.', 'affiliate-captain' ), Settings::get( 'tier_month_closed' ) ? Settings::get( 'tier_month_closed' ) : '—' ) ) . ' <input type="month" name="month" value="' . esc_attr( gmdate( 'Y-m', strtotime( 'first day of previous month' ) ) ) . '"> <button class="button" data-wpac-confirm="' . esc_attr__( 'Book monthly bonuses now? Refunds after this point will not change the bonus.', 'affiliate-captain' ) . '">' . esc_html__( 'Close month now', 'affiliate-captain' ) . '</button></p></form>';
	}

	/**
	 * Rule preview for an example partner/product.
	 *
	 * @param array  $aff_names Affiliates.
	 * @param string $currency  Currency.
	 */
	private static function render_preview( array $aff_names, $currency ) {
		echo '<h2>' . esc_html__( 'Rule preview', 'affiliate-captain' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="wpac-rules"><input type="hidden" name="tab" value="preview"><input type="hidden" name="preview" value="1">';
		echo '<select name="p_affiliate">';
		foreach ( $aff_names as $aid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $aid, selected( absint( Admin::get( 'p_affiliate' ) ), $aid, false ), esc_html( $name ) );
		}
		echo '</select> ';
		echo '<input type="number" name="p_product" aria-label="' . esc_attr__( 'Product ID', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Product ID', 'affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_product' ) ) . '" class="small-text"> ';
		echo '<input type="text" name="p_sku" aria-label="' . esc_attr__( 'SKU / course code', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'SKU / course code', 'affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_sku' ) ) . '" size="10"> ';
		echo '<input type="text" name="p_cats" aria-label="' . esc_attr__( 'Category IDs', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Category IDs', 'affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_cats' ) ) . '" size="8"> ';
		echo '<input type="text" name="p_tags" aria-label="' . esc_attr__( 'Tag IDs', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Tag IDs', 'affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_tags' ) ) . '" size="8"> ';
		echo '<input type="text" name="p_net" aria-label="' . esc_attr__( 'Net amount', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Net amount', 'affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_net' ) ? Admin::get( 'p_net' ) : '100' ) . '" size="7"> ';
		echo '<button class="button">' . esc_html__( 'Calculate', 'affiliate-captain' ) . '</button></form>';

		if ( ! Admin::get( 'preview' ) ) {
			return;
		}
		$aff = AffiliateRepository::find( absint( Admin::get( 'p_affiliate' ) ) );
		if ( ! $aff ) {
			return;
		}
		$product_id = absint( Admin::get( 'p_product' ) );
		$cats       = array_map( 'intval', array_filter( explode( ',', Admin::get( 'p_cats' ) ) ) );
		$sku        = Admin::get( 'p_sku' );
		$tags       = array_map( 'intval', array_filter( explode( ',', Admin::get( 'p_tags' ) ) ) );
		if ( $product_id && function_exists( 'wc_get_product' ) && ( $product = wc_get_product( $product_id ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$sku  = $sku ? $sku : $product->get_sku();
			$cats = $cats ? $cats : wc_get_product_term_ids( $product->get_parent_id() ? $product->get_parent_id() : $product_id, 'product_cat' );
			$tags = $tags ? $tags : wc_get_product_term_ids( $product->get_parent_id() ? $product->get_parent_id() : $product_id, 'product_tag' );
		}
		// Parent categories: rules on a parent category apply to its subcategories as well.
		$ancestors = array();
		if ( function_exists( 'get_ancestors' ) ) {
			foreach ( $cats as $cat ) {
				foreach ( (array) get_ancestors( (int) $cat, 'product_cat', 'taxonomy' ) as $a ) {
					$ancestors[ (int) $a ] = (int) $a;
				}
			}
		}
		$ancestors = array_values( array_diff( $ancestors, $cats ) );
		$net       = Money::to_minor( (string) Money::normalize_decimal( Admin::get( 'p_net' ) ), $currency );
		$calc = Calculator::calculate(
			array(
				'product_id'   => $product_id,
				'parent_id'    => 0,
				'sku'          => $sku,
				'category_ids'          => $cats,
				'category_ancestor_ids' => $ancestors,
				'tag_ids'               => $tags,
				'quantity'              => 1,
				'net_minor'             => $net,
			),
			RuleRepository::active_for( (int) $aff->id, (int) $aff->group_id ),
			(int) $aff->id,
			(int) $aff->group_id,
			RuleRepository::default_rate(),
			true,
			RuleRepository::precedence()
		);
		echo '<div class="notice notice-info inline"><p>';
		if ( $calc['excluded'] ) {
			/* translators: %d: rule ID */
			echo esc_html( sprintf( __( 'Excluded by rule #%d – no commission.', 'affiliate-captain' ), $calc['rule_id'] ) );
		} else {
			printf(
				/* translators: 1: commission, 2: rate, 3: rule */
				esc_html__( 'Commission: %1$s (%2$s, %3$s)', 'affiliate-captain' ),
				'<strong>' . esc_html( Money::format( $calc['amount_minor'], $currency ) ) . '</strong>',
				esc_html( RuleRepository::format_rate( $calc['rate_type'], $calc['rate'], $currency ) ),
				esc_html( $calc['rule_id'] ? sprintf( /* translators: %d: rule */ __( 'rule #%d', 'affiliate-captain' ), $calc['rule_id'] ) : __( 'program default', 'affiliate-captain' ) )
			);
		}
		echo '</p></div>';
	}
}
