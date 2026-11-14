<?php
/**
 * Audit log list (WP_List_Table, R5-O47): filters, search, sorting, paging.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Pages\AuditPage;
use WPAffiliateCaptain\Core\Audit;

defined( 'ABSPATH' ) || exit;

final class AuditTable extends ListTable {

	/** @var array */
	private $filters;

	/** @var array */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param array $filters Filters (AuditPage::filters()).
	 */
	public function __construct( array $filters ) {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
		$this->filters = $filters;
		$this->types   = AuditPage::object_types();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'created_at'  => esc_html__( 'Date', 'affiliate-captain' ),
			'actor'       => esc_html__( 'User', 'affiliate-captain' ),
			'action'      => esc_html__( 'Action', 'affiliate-captain' ),
			'object_type' => esc_html__( 'Object', 'affiliate-captain' ),
			'details'     => esc_html__( 'Details', 'affiliate-captain' ),
		);
	}

	/**
	 * Sortable columns (date uses the created_at index, object the object index; the action has
	 * no index of its own: sorting by it reads the filtered rows once).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at'  => array( 'created_at', true ),
			'action'      => array( 'action', false ),
			'object_type' => array( 'object_type', false ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = max( 1, (int) $this->get_items_per_page( 'wpac_audit_per_page', 100 ) );
		$sort                 = $this->sort_args();
		list( $rows, $total ) = Audit::query_paged(
			$this->filters + array(
				'search'     => self::search_term(),
				'limit'      => $per,
				'offset'     => ( $this->get_pagenum() - 1 ) * $per,
				'orderby'    => '' !== $sort['orderby'] ? $sort['orderby'] : 'id',
				'sort_order' => $sort['order'],
			)
		);
		$this->items = $rows;
		$this->finish( $total, $per, 'created_at' );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		echo esc_html( array_filter( $this->filters ) || '' !== self::search_term() ? __( 'No entries match this filter.', 'affiliate-captain' ) : __( 'No entries yet. Changes to partners, commissions, payouts and settings are recorded here.', 'affiliate-captain' ) );
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
			case 'created_at':
				return esc_html( \WPAffiliateCaptain\Admin\Admin::date( $r->created_at, true ) );
			case 'actor':
				$user = $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
				return esc_html( $user ? $user->display_name : __( 'System', 'affiliate-captain' ) );
			case 'action':
				return '<span title="' . esc_attr( $r->action ) . '">' . esc_html( AuditPage::action_label( $r->action ) ) . '</span>';
			case 'object_type':
				return esc_html( ( isset( $this->types[ $r->object_type ] ) ? $this->types[ $r->object_type ] : $r->object_type ) . ( $r->object_id ? ' #' . $r->object_id : '' ) );
			case 'details':
				return '<span title="' . esc_attr( (string) $r->details ) . '">' . esc_html( AuditPage::details_text( (string) $r->details ) ) . '</span>';
		}
		return '';
	}
}
