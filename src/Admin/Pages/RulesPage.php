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
					Admin::back( __( 'Please enter a reason for the change.', 'wp-affiliate-captain' ), 'error' );
				}
				$data = RuleRepository::sanitize(
					array(
						'scope'        => sanitize_key( Admin::post( 'scope' ) ),
						'scope_id'     => absint( Admin::post( 'scope' ) === 'group' ? Admin::post( 'group_id' ) : Admin::post( 'affiliate_id' ) ),
						'target'       => sanitize_key( Admin::post( 'target' ) ),
						'target_value' => Admin::post( 'target_value' ),
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
				Admin::back( __( 'Rule saved. Existing commissions are not changed.', 'wp-affiliate-captain' ), 'success', Admin::url( 'wpac-rules' ) );
				break;

			case 'rule_delete':
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				if ( '' === $reason ) {
					Admin::back( __( 'Please enter a reason.', 'wp-affiliate-captain' ), 'error' );
				}
				RuleRepository::delete( absint( Admin::post( 'id' ) ), $reason );
				Admin::back( __( 'Rule deleted.', 'wp-affiliate-captain' ) );
				break;

			case 'group_create':
				$name = sanitize_text_field( Admin::post( 'name' ) );
				if ( '' === $name ) {
					Admin::back( __( 'Please enter a name.', 'wp-affiliate-captain' ), 'error' );
				}
				Groups::create( $name, Admin::post( 'description' ) );
				Admin::back( __( 'Group created.', 'wp-affiliate-captain' ) );
				break;

			case 'group_delete':
				Groups::delete( absint( Admin::post( 'id' ) ) );
				Admin::back( __( 'Group deleted.', 'wp-affiliate-captain' ) );
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

		echo '<div class="wrap"><h1>' . esc_html__( 'Rules & groups', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		/* translators: %s: default rate */
		echo '<p>' . esc_html( sprintf( __( 'Program default: %s. Basis is the net line amount after discount, excluding tax and shipping.', 'wp-affiliate-captain' ), RuleRepository::format_rate( Settings::get( 'default_rate_type' ), Settings::get( 'default_rate' ), $currency ) ) ) . ' ';
		echo esc_html__( 'Resolution: an exclusion always wins; otherwise the most specific rule applies (partner > group > global, product/SKU > category > all, then priority).', 'wp-affiliate-captain' ) . '</p>';

		$scope_label = static function ( $r ) use ( $groups, $aff_names ) {
			if ( 'group' === $r->scope ) {
				return __( 'Group', 'wp-affiliate-captain' ) . ': ' . ( isset( $groups[ (int) $r->scope_id ] ) ? $groups[ (int) $r->scope_id ] : '#' . $r->scope_id );
			}
			if ( 'affiliate' === $r->scope ) {
				return __( 'Partner', 'wp-affiliate-captain' ) . ': ' . ( isset( $aff_names[ (int) $r->scope_id ] ) ? $aff_names[ (int) $r->scope_id ] : '#' . $r->scope_id );
			}
			return __( 'Global', 'wp-affiliate-captain' );
		};
		$targets = array(
			'all'      => __( 'All products', 'wp-affiliate-captain' ),
			'product'  => __( 'Product IDs', 'wp-affiliate-captain' ),
			'category' => __( 'Category IDs', 'wp-affiliate-captain' ),
			'sku'      => __( 'SKU / course code', 'wp-affiliate-captain' ),
		);

		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Applies to', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Products', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Rate', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Priority', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Note', 'wp-affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		$rules = RuleRepository::all();
		if ( ! $rules ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No rules yet – the program default applies to all sales.', 'wp-affiliate-captain' ) . '</td></tr>';
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
				esc_html__( 'Edit', 'wp-affiliate-captain' )
			);
			Admin::form_open( 'rule_delete', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><input type="text" name="reason" placeholder="' . esc_attr__( 'Reason', 'wp-affiliate-captain' ) . '" size="10"> <button class="button-link wpac-danger">' . esc_html__( 'Delete', 'wp-affiliate-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';

		// Rule form.
		$v = static function ( $k, $d = '' ) use ( $edit ) {
			return $edit ? (string) $edit->$k : $d;
		};
		$rate_display = $edit && 'fixed' === $edit->rate_type ? Money::to_decimal( (int) $edit->rate, $currency ) : $v( 'rate' );
		echo '<h2>' . esc_html( $edit ? __( 'Edit rule', 'wp-affiliate-captain' ) . ' #' . $edit->id : __( 'Add rule', 'wp-affiliate-captain' ) ) . '</h2>';
		Admin::form_open( 'rule_save' );
		echo '<input type="hidden" name="id" value="' . (int) ( $edit ? $edit->id : 0 ) . '"><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Applies to', 'wp-affiliate-captain' ) . '</th><td><select name="scope">';
		foreach ( array( 'global' => __( 'All partners', 'wp-affiliate-captain' ), 'group' => __( 'Partner group', 'wp-affiliate-captain' ), 'affiliate' => __( 'Single partner', 'wp-affiliate-captain' ) ) as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'scope', 'global' ), $k, false ), esc_html( $l ) );
		}
		echo '</select> <select name="group_id"><option value="">' . esc_html__( '– group –', 'wp-affiliate-captain' ) . '</option>';
		foreach ( $groups as $gid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $gid, selected( 'group' === $v( 'scope' ) ? (int) $v( 'scope_id' ) : 0, $gid, false ), esc_html( $name ) );
		}
		echo '</select> <select name="affiliate_id"><option value="">' . esc_html__( '– partner –', 'wp-affiliate-captain' ) . '</option>';
		foreach ( $aff_names as $aid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $aid, selected( 'affiliate' === $v( 'scope' ) ? (int) $v( 'scope_id' ) : 0, $aid, false ), esc_html( $name ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Products', 'wp-affiliate-captain' ) . '</th><td><select name="target">';
		foreach ( $targets as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'target', 'all' ), $k, false ), esc_html( $l ) );
		}
		echo '</select> <input type="text" name="target_value" value="' . esc_attr( $v( 'target_value' ) ) . '" class="regular-text" placeholder="' . esc_attr__( 'Comma separated; SKUs support trailing * (e.g. GROUP-*)', 'wp-affiliate-captain' ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Commission', 'wp-affiliate-captain' ) . '</th><td><select name="rate_type">';
		foreach ( array( 'percent' => __( 'Percent of net', 'wp-affiliate-captain' ), 'fixed' => __( 'Fixed amount per unit', 'wp-affiliate-captain' ), 'exclude' => __( 'Exclude (no commission)', 'wp-affiliate-captain' ) ) as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'rate_type', 'percent' ), $k, false ), esc_html( $l ) );
		}
		echo '</select> <input type="text" name="rate" value="' . esc_attr( $rate_display ) . '" size="8" placeholder="10"> <span class="description">' . esc_html( '% / ' . $currency ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Priority', 'wp-affiliate-captain' ) . '</th><td><input type="number" name="priority" value="' . esc_attr( $v( 'priority', '0' ) ) . '" class="small-text"> <label><input type="checkbox" name="active" value="1" ' . checked( $v( 'active', '1' ), '1', false ) . '> ' . esc_html__( 'Active', 'wp-affiliate-captain' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Note', 'wp-affiliate-captain' ) . '</th><td><input type="text" name="note" value="' . esc_attr( $v( 'note' ) ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Reason for change', 'wp-affiliate-captain' ) . '</th><td><input type="text" name="reason" class="regular-text" ' . ( $edit ? 'required' : '' ) . '></td></tr>';
		echo '</table>';
		submit_button( $edit ? __( 'Save rule', 'wp-affiliate-captain' ) : __( 'Add rule', 'wp-affiliate-captain' ) );
		echo '</form>';

		self::render_preview( $aff_names, $currency );

		// Groups.
		echo '<h2>' . esc_html__( 'Partner groups', 'wp-affiliate-captain' ) . '</h2><p class="description">' . esc_html__( 'e.g. "Training partner" and "Content partner" – each group can get its own rules.', 'wp-affiliate-captain' ) . '</p><ul>';
		foreach ( Groups::all() as $g ) {
			echo '<li><strong>' . esc_html( $g->name ) . '</strong> ' . esc_html( $g->description ) . ' ';
			Admin::form_open( 'group_delete', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $g->id . '"><button class="button-link wpac-danger">' . esc_html__( 'Delete', 'wp-affiliate-captain' ) . '</button></form></li>';
		}
		echo '</ul>';
		Admin::form_open( 'group_create' );
		echo '<input type="text" name="name" placeholder="' . esc_attr__( 'Name', 'wp-affiliate-captain' ) . '" required> <input type="text" name="description" placeholder="' . esc_attr__( 'Description', 'wp-affiliate-captain' ) . '" class="regular-text"> <button class="button">' . esc_html__( 'Add group', 'wp-affiliate-captain' ) . '</button></form>';
		echo '</div>';
	}

	/**
	 * Rule preview for an example partner/product.
	 *
	 * @param array  $aff_names Affiliates.
	 * @param string $currency  Currency.
	 */
	private static function render_preview( array $aff_names, $currency ) {
		echo '<h2>' . esc_html__( 'Rule preview', 'wp-affiliate-captain' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="wpac-rules"><input type="hidden" name="preview" value="1">';
		echo '<select name="p_affiliate">';
		foreach ( $aff_names as $aid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $aid, selected( absint( Admin::get( 'p_affiliate' ) ), $aid, false ), esc_html( $name ) );
		}
		echo '</select> ';
		echo '<input type="number" name="p_product" placeholder="' . esc_attr__( 'Product ID', 'wp-affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_product' ) ) . '" class="small-text"> ';
		echo '<input type="text" name="p_sku" placeholder="' . esc_attr__( 'SKU / course code', 'wp-affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_sku' ) ) . '" size="10"> ';
		echo '<input type="text" name="p_cats" placeholder="' . esc_attr__( 'Category IDs', 'wp-affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_cats' ) ) . '" size="8"> ';
		echo '<input type="text" name="p_net" placeholder="' . esc_attr__( 'Net amount', 'wp-affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'p_net' ) ? Admin::get( 'p_net' ) : '100' ) . '" size="7"> ';
		echo '<button class="button">' . esc_html__( 'Calculate', 'wp-affiliate-captain' ) . '</button></form>';

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
		if ( $product_id && function_exists( 'wc_get_product' ) && ( $product = wc_get_product( $product_id ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$sku  = $sku ? $sku : $product->get_sku();
			$cats = $cats ? $cats : wc_get_product_term_ids( $product->get_parent_id() ? $product->get_parent_id() : $product_id, 'product_cat' );
		}
		$net  = Money::to_minor( (string) Money::normalize_decimal( Admin::get( 'p_net' ) ), $currency );
		$calc = Calculator::calculate(
			array(
				'product_id'   => $product_id,
				'parent_id'    => 0,
				'sku'          => $sku,
				'category_ids' => $cats,
				'quantity'     => 1,
				'net_minor'    => $net,
			),
			RuleRepository::active(),
			(int) $aff->id,
			(int) $aff->group_id,
			RuleRepository::default_rate()
		);
		echo '<div class="notice notice-info inline"><p>';
		if ( $calc['excluded'] ) {
			/* translators: %d: rule ID */
			echo esc_html( sprintf( __( 'Excluded by rule #%d – no commission.', 'wp-affiliate-captain' ), $calc['rule_id'] ) );
		} else {
			printf(
				/* translators: 1: commission, 2: rate, 3: rule */
				esc_html__( 'Commission: %1$s (%2$s, %3$s)', 'wp-affiliate-captain' ),
				'<strong>' . esc_html( Money::format( $calc['amount_minor'], $currency ) ) . '</strong>',
				esc_html( RuleRepository::format_rate( $calc['rate_type'], $calc['rate'], $currency ) ),
				esc_html( $calc['rule_id'] ? sprintf( /* translators: %d: rule */ __( 'rule #%d', 'wp-affiliate-captain' ), $calc['rule_id'] ) : __( 'program default', 'wp-affiliate-captain' ) )
			);
		}
		echo '</p></div>';
	}
}
