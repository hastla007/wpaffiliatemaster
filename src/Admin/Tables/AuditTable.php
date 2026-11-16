<?php
/**
 * Audit log list (WP_List_Table): filters, search, sorting, paging (R5-O47).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\AuditPage;
use WPAffiliateCaptain\Core\Audit;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class AuditTable extends \WP_List_Table {

	/** @var array */
	private $filters;

	/** @var array */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param array $filters Active filters (AuditPage::filters()).
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
			'created_at'  => __( 'Date', 'affiliate-captain' ),
			'actor'       => __( 'User', 'affiliate-captain' ),
			'action'      => __( 'Action', 'affiliate-captain' ),
			'object_type' => __( 'Object', 'affiliate-captain' ),
			'details'     => __( 'Details', 'affiliate-captain' ),
		);
	}

	/**
	 * Sortable columns (keys must be in Audit::sortable(), the SQL whitelist).
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
		$per                  = $this->get_items_per_page( 'wpac_audit_per_page', 100 );
		list( $rows, $total ) = Audit::page(
			$this->filters + array(
				'search'  => Admin::get( 's' ),
				'limit'   => $per,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per,
				'orderby' => sanitize_key( Admin::get( 'orderby' ) ),
				'sort'    => sanitize_key( Admin::get( 'order' ) ),
			)
		);
		$this->items           = $rows;
		$hidden                = function_exists( 'get_hidden_columns' ) && $this->screen ? get_hidden_columns( $this->screen ) : array();
		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns(), 'created_at' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		echo esc_html( AuditPage::empty_text( array_filter( $this->filters ) || '' !== Admin::get( 's' ) ) );
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
				return esc_html( Admin::date( $r->created_at, true ) );
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
