<?php
/**
 * Commission ledger list (WP_List_Table): filters, sorting, paging, bulk approve/reject with reason.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Money;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class CommissionsTable extends \WP_List_Table {

	/** @var array */
	private $filters;

	/** @var array */
	private $labels;

	/** @var array */
	private $kinds;

	/**
	 * Constructor.
	 *
	 * @param array $filters Active filters.
	 */
	public function __construct( array $filters ) {
		parent::__construct(
			array(
				'singular' => 'commission',
				'plural'   => 'commissions',
				'ajax'     => false,
			)
		);
		$this->filters = $filters;
		$this->labels  = Ledger::status_labels();
		$this->kinds   = Ledger::kind_labels();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox">',
			'created_at'   => __( 'Date', 'affiliate-captain' ),
			'affiliate_id' => __( 'Partner', 'affiliate-captain' ),
			'order'        => __( 'Order', 'affiliate-captain' ),
			'item'         => __( 'Item', 'affiliate-captain' ),
			'base'         => __( 'Base', 'affiliate-captain' ),
			'rate'         => __( 'Rate', 'affiliate-captain' ),
			'amount_minor' => __( 'Commission', 'affiliate-captain' ),
			'status'       => __( 'Status', 'affiliate-captain' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at'   => array( 'created_at', true ),
			'amount_minor' => array( 'amount_minor', false ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'approve' => __( 'Approve', 'affiliate-captain' ),
			'reject'  => __( 'Reject (reason required, shown to the partner)', 'affiliate-captain' ),
		);
	}

	/**
	 * Reason field next to the bulk actions.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' === $which ) {
			echo '<div class="alignleft actions"><label class="screen-reader-text" for="wpac-bulk-reason">' . esc_html__( 'Reason', 'affiliate-captain' ) . '</label><input type="text" name="reason" id="wpac-bulk-reason" placeholder="' . esc_attr__( 'Reason (required for rejection)', 'affiliate-captain' ) . '"></div>';
		}
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = $this->get_items_per_page( 'wpac_commissions_per_page', 50 );
		list( $rows, $total ) = Ledger::query(
			$this->filters + array(
				'limit'   => $per,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per,
				'orderby' => sanitize_key( Admin::get( 'orderby' ) ),
				'order'   => sanitize_key( Admin::get( 'order' ) ),
			)
		);
		AffiliateRepository::find_many( wp_list_pluck( $rows, 'affiliate_id' ) );
		$this->items           = $rows;
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/**
	 * Edit URL of a WooCommerce order without loading the order (HPOS and legacy storage).
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function order_edit_url( $order_id ) {
		if ( $order_id <= 0 || ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && method_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, 'get_order_admin_edit_url' ) ) {
			return (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No commissions found.', 'affiliate-captain' );
	}

	/**
	 * Checkbox.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %d: commission ID */
		return sprintf( '<label class="screen-reader-text" for="wpac-cb-%1$d">%2$s</label><input type="checkbox" id="wpac-cb-%1$d" name="ids[]" value="%1$d">', (int) $item->id, esc_html( sprintf( __( 'Select commission #%d', 'affiliate-captain' ), $item->id ) ) );
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
				$view = Admin::url( 'wpac-commissions', array( 'action' => 'view', 'id' => $r->id ) );
				return '<a href="' . esc_url( $view ) . '">' . esc_html( Admin::date( $r->created_at ) ) . '</a>' . $this->row_actions( array( 'view' => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'Details', 'affiliate-captain' ) . '</a>' ) );
			case 'affiliate_id':
				$a = AffiliateRepository::find( (int) $r->affiliate_id );
				return '<a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $r->affiliate_id ) ) ) . '">' . esc_html( $a ? $a->name : '#' . $r->affiliate_id ) . '</a>';
			case 'order':
				$link = 'woocommerce' === $r->source ? self::order_edit_url( (int) $r->source_order_id ) : '';
				$ref = 'wpac' === $r->source ? '—' : '#' . $r->source_order_id;
				return esc_html( Ledger::source_label( $r->source ) ) . ' ' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $ref ) . '</a>' : esc_html( $ref ) );
			case 'item':
				return esc_html( $r->description ) . ( 'sale' !== $r->kind ? ' <em>(' . esc_html( isset( $this->kinds[ $r->kind ] ) ? $this->kinds[ $r->kind ] : $r->kind ) . ')</em>' : '' );
			case 'base':
				return esc_html( Money::format( (int) $r->base_minor, $r->currency ) );
			case 'rate':
				return esc_html( RuleRepository::format_rate( $r->rate_type, $r->rate, $r->currency ) );
			case 'amount_minor':
				return '<strong class="' . ( (int) $r->amount_minor < 0 ? 'wpac-negative' : '' ) . '">' . esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ) . '</strong>';
			case 'status':
				return Admin::badge( $r->status, isset( $this->labels[ $r->status ] ) ? $this->labels[ $r->status ] : $r->status ) . ( in_array( $r->status, array( 'pending', 'review' ), true ) ? '<br><small>' . esc_html__( 'until', 'affiliate-captain' ) . ' ' . esc_html( Admin::date( $r->hold_until ) ) . '</small>' : '' );
		}
		return '';
	}
}
