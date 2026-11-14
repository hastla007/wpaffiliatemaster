<?php
/**
 * Minimal WP_List_Table for the integration render checks: the plugin's tables only need the
 * column/row callbacks, paging arguments and a plain table output.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

class WP_List_Table {
	public $items = array();
	protected $_args = array();
	protected $_pagination_args = array();
	protected $_column_headers;

	public function __construct( $args = array() ) {
		$this->_args = $args;
	}
	public function get_columns() {
		return array();
	}
	protected function get_sortable_columns() {
		return array();
	}
	protected function get_bulk_actions() {
		return array();
	}
	public function get_pagenum() {
		return max( 1, absint( $_REQUEST['paged'] ?? 1 ) );
	}
	protected function get_items_per_page( $option, $default = 20 ) {
		return (int) $default;
	}
	protected function set_pagination_args( $args ) {
		$this->_pagination_args = $args;
	}
	public function current_action() {
		return isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( $_REQUEST['action'] ) : false;
	}
	protected function row_actions( $actions, $always_visible = false ) {
		return '<div class="row-actions">' . implode( ' | ', (array) $actions ) . '</div>';
	}
	public function prepare_items() {}
	public function views() {}
	public function search_box( $text, $input_id ) {
		echo '<input type="search" id="' . esc_attr( $input_id ) . '">';
	}
	protected function extra_tablenav( $which ) {}
	protected function column_default( $item, $column_name ) {
		return '';
	}
	public function no_items() {
		echo 'No items found.';
	}
	public function display() {
		$columns = $this->get_columns();
		$this->get_bulk_actions();
		$this->get_sortable_columns();
		$this->extra_tablenav( 'top' );
		echo '<table class="wp-list-table"><thead><tr>';
		foreach ( $columns as $name => $label ) {
			echo '<th class="column-' . esc_attr( $name ) . '">' . $label . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $this->items ) {
			echo '<tr><td>';
			$this->no_items();
			echo '</td></tr>';
		}
		foreach ( $this->items as $item ) {
			echo '<tr>';
			foreach ( array_keys( $columns ) as $name ) {
				if ( 'cb' === $name ) {
					$cell = method_exists( $this, 'column_cb' ) ? $this->column_cb( $item ) : '';
				} elseif ( method_exists( $this, 'column_' . $name ) ) {
					$cell = call_user_func( array( $this, 'column_' . $name ), $item );
				} else {
					$cell = $this->column_default( $item, $name );
				}
				echo '<td class="column-' . esc_attr( $name ) . '">' . $cell . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->extra_tablenav( 'bottom' );
	}
}
