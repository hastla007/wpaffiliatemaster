<?php
/**
 * Partner list (WP_List_Table): status views, search, sorting, paging, bulk approval.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Groups;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class AffiliatesTable extends \WP_List_Table {

	/** @var array */
	private $labels;

	/** @var array */
	private $groups;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'partner',
				'plural'   => 'partners',
				'ajax'     => false,
			)
		);
		$this->labels = AffiliateRepository::status_labels();
		$this->groups = Groups::options();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox">',
			'name'       => __( 'Partner', 'affiliate-captain' ),
			'website'    => __( 'Website / channel', 'affiliate-captain' ),
			'group'      => __( 'Group', 'affiliate-captain' ),
			'status'     => __( 'Status', 'affiliate-captain' ),
			'created_at' => __( 'Since', 'affiliate-captain' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'       => array( 'name', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'approve' => __( 'Approve applications', 'affiliate-captain' ) );
	}

	/**
	 * Status views with counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = AffiliateRepository::count_by_status();
		$current = Admin::get( 'status' );
		$views   = array(
			'all' => sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( Admin::url( 'wpac-affiliates' ) ), '' === $current ? ' class="current" aria-current="page"' : '', esc_html__( 'All', 'affiliate-captain' ), (int) array_sum( $counts ) ),
		);
		foreach ( $this->labels as $key => $label ) {
			$views[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( Admin::url( 'wpac-affiliates', array( 'status' => $key ) ) ), $current === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ), (int) $counts[ $key ] );
		}
		return $views;
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = $this->get_items_per_page( 'wpac_partners_per_page', 30 );
		list( $rows, $total ) = AffiliateRepository::query(
			array(
				'status'  => sanitize_key( Admin::get( 'status' ) ),
				'search'  => Admin::get( 's' ),
				'limit'   => $per,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per,
				'orderby' => sanitize_key( Admin::get( 'orderby' ) ),
				'order'   => sanitize_key( Admin::get( 'order' ) ),
			)
		);
		$this->items           = $rows;
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
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
		esc_html_e( 'No partners found.', 'affiliate-captain' );
	}

	/**
	 * Checkbox.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %s: partner name */
		return sprintf( '<label class="screen-reader-text" for="wpac-cb-%1$d">%2$s</label><input type="checkbox" id="wpac-cb-%1$d" name="ids[]" value="%1$d">', (int) $item->id, esc_html( sprintf( __( 'Select %s', 'affiliate-captain' ), $item->name ) ) );
	}

	/**
	 * Name with row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		$edit    = Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $item->id ) );
		$actions = array(
			'edit'        => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'affiliate-captain' ) . '</a>',
			'commissions' => '<a href="' . esc_url( Admin::url( 'wpac-commissions', array( 'affiliate_id' => $item->id ) ) ) . '">' . esc_html__( 'Commissions', 'affiliate-captain' ) . '</a>',
		);
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $item->name ? $item->name : '#' . $item->id ) . '</a></strong><br><span class="description">' . esc_html( $item->email ) . '</span>' . $this->row_actions( $actions );
	}

	/**
	 * Other columns.
	 *
	 * @param object $item   Row.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $item, $column ) {
		switch ( $column ) {
			case 'website':
				return ( $item->website ? '<a href="' . esc_url( $item->website ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item->website ) . '</a>' : '—' ) . ( $item->channel ? '<br>' . esc_html( $item->channel ) : '' );
			case 'group':
				return esc_html( isset( $this->groups[ (int) $item->group_id ] ) ? $this->groups[ (int) $item->group_id ] : '—' );
			case 'status':
				return Admin::badge( $item->status, isset( $this->labels[ $item->status ] ) ? $this->labels[ $item->status ] : $item->status );
			case 'created_at':
				return esc_html( Admin::date( $item->created_at ) );
		}
		return '';
	}
}
