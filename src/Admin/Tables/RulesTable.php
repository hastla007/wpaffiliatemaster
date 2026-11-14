<?php
/**
 * Commission rule list (WP_List_Table, R5-O47): search, sorting, paging; edit/delete per row.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Commissions\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class RulesTable extends ListTable {

	/** @var array */
	private $groups;

	/** @var array */
	private $aff_names;

	/** @var string */
	private $currency;

	/** @var array */
	private $targets;

	/**
	 * Constructor.
	 *
	 * @param array  $groups    Group names by ID.
	 * @param array  $aff_names Partner names by ID.
	 * @param string $currency  Program currency.
	 */
	public function __construct( array $groups, array $aff_names, $currency ) {
		parent::__construct(
			array(
				'singular' => 'rule',
				'plural'   => 'rules',
				'ajax'     => false,
			)
		);
		$this->groups    = $groups;
		$this->aff_names = $aff_names;
		$this->currency  = (string) $currency;
		$this->targets   = self::targets();
	}

	/**
	 * Product targets of a rule (list and rule form).
	 *
	 * @return array key => label
	 */
	public static function targets() {
		return array(
			'all'      => __( 'All products', 'affiliate-captain' ),
			'product'  => __( 'Product IDs', 'affiliate-captain' ),
			'category' => __( 'Category IDs', 'affiliate-captain' ),
			'tag'      => __( 'Product tag IDs', 'affiliate-captain' ),
			'sku'      => __( 'SKU / course code', 'affiliate-captain' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'       => '#',
			'scope'    => esc_html__( 'Applies to', 'affiliate-captain' ),
			'target'   => esc_html__( 'Products', 'affiliate-captain' ),
			'rate'     => esc_html__( 'Rate', 'affiliate-captain' ),
			'priority' => esc_html__( 'Priority', 'affiliate-captain' ),
			'note'     => esc_html__( 'Note', 'affiliate-captain' ),
			'actions'  => '<span class="screen-reader-text">' . esc_html__( 'Actions', 'affiliate-captain' ) . '</span>',
		);
	}

	/**
	 * Sortable columns (without a choice: the resolution order, scope > products > priority).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'       => array( 'id', true ),
			'scope'    => array( 'scope', false ),
			'target'   => array( 'target', false ),
			'rate'     => array( 'rate_type', false ),
			'priority' => array( 'priority', true ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = max( 1, (int) $this->get_items_per_page( 'wpac_rules_per_page', 50 ) );
		list( $rows, $total ) = RuleRepository::query(
			array(
				'search' => self::search_term(),
				'limit'  => $per,
				'offset' => ( $this->get_pagenum() - 1 ) * $per,
			) + $this->sort_args()
		);
		$this->items = $rows;
		$this->finish( $total, $per, 'scope' );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		if ( '' !== self::search_term() ) {
			esc_html_e( 'No rules match this search.', 'affiliate-captain' );
			echo ' <a href="' . esc_url( Admin::url( 'wpac-rules' ) ) . '">' . esc_html__( 'Reset search', 'affiliate-captain' ) . '</a>';
			return;
		}
		esc_html_e( 'No rules yet – the program default applies to all sales.', 'affiliate-captain' );
	}

	/**
	 * Row class: inactive rules are greyed out.
	 *
	 * @param object $item Row.
	 */
	public function single_row( $item ) {
		echo '<tr class="' . ( $item->active ? '' : 'wpac-inactive' ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Columns.
	 *
	 * @param object $r      Row.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $r, $column ) {
		switch ( $column ) {
			case 'id':
				return (int) $r->id . ' <small>v' . (int) $r->version . '</small>';
			case 'scope':
				if ( 'group' === $r->scope ) {
					return esc_html( __( 'Group', 'affiliate-captain' ) . ': ' . ( isset( $this->groups[ (int) $r->scope_id ] ) ? $this->groups[ (int) $r->scope_id ] : '#' . $r->scope_id ) );
				}
				if ( 'affiliate' === $r->scope ) {
					return esc_html( __( 'Partner', 'affiliate-captain' ) . ': ' . ( isset( $this->aff_names[ (int) $r->scope_id ] ) ? $this->aff_names[ (int) $r->scope_id ] : '#' . $r->scope_id ) );
				}
				return esc_html__( 'Global', 'affiliate-captain' );
			case 'target':
				return esc_html( isset( $this->targets[ $r->target ] ) ? $this->targets[ $r->target ] : $r->target ) . ( '' !== (string) $r->target_value ? ': <code>' . esc_html( $r->target_value ) . '</code>' : '' );
			case 'rate':
				return '<strong>' . esc_html( RuleRepository::format_rate( $r->rate_type, $r->rate, $this->currency ) ) . '</strong>';
			case 'priority':
				return (string) (int) $r->priority;
			case 'note':
				return esc_html( $r->note );
			case 'actions':
				return self::capture(
					static function () use ( $r ) {
						echo '<a href="' . esc_url( Admin::url( 'wpac-rules', array( 'edit' => $r->id ) ) ) . '">' . esc_html__( 'Edit', 'affiliate-captain' ) . '</a> ';
						Admin::form_open( 'rule_delete', 'wpac-inline' );
						echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><input type="text" name="reason" aria-label="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '" size="10"> <button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Delete this rule? Existing commissions are not changed.', 'affiliate-captain' ) . '">' . esc_html__( 'Delete', 'affiliate-captain' ) . '</button></form>';
					}
				);
		}
		return '';
	}
}
