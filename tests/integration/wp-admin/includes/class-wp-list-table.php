<?php
/**
 * Minimal WP_List_Table for the integration render checks: the plugin's tables only need the
 * column/row callbacks, paging arguments and a plain table output (with sort links on the
 * sortable columns, the search field and the page count, like WordPress, for R5-O47).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable

class WP_List_Table {
	public $items = array();
	public $screen = null;
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
	public function has_items() {
		return ! empty( $this->items );
	}
	public function get_pagination_arg( $key ) {
		if ( 'total_pages' === $key && ! isset( $this->_pagination_args['total_pages'] ) && ! empty( $this->_pagination_args['per_page'] ) ) {
			return (int) ceil( ( $this->_pagination_args['total_items'] ?? 0 ) / $this->_pagination_args['per_page'] );
		}
		return $this->_pagination_args[ $key ] ?? 0;
	}
	public function search_box( $text, $input_id ) {
		$value = isset( $_REQUEST['s'] ) ? (string) $_REQUEST['s'] : '';
		echo '<p class="search-box"><label class="screen-reader-text" for="' . esc_attr( $input_id ) . '-search-input">' . esc_html( $text ) . '</label><input type="search" id="' . esc_attr( $input_id ) . '-search-input" name="s" value="' . esc_attr( $value ) . '"><button type="submit" class="button">' . esc_html( $text ) . '</button></p>';
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
		$sortable = $this->get_sortable_columns();
		$this->extra_tablenav( 'top' );
		echo '<table class="wp-list-table"><thead><tr>';
		$current = isset( $_GET['orderby'] ) ? (string) $_GET['orderby'] : '';
		$dir     = isset( $_GET['order'] ) && 'desc' === strtolower( (string) $_GET['order'] ) ? 'desc' : 'asc';
		foreach ( $columns as $name => $label ) {
			if ( isset( $sortable[ $name ] ) ) {
				$s     = (array) $sortable[ $name ];
				$by    = (string) $s[0];
				$order = $current === $by ? ( 'asc' === $dir ? 'desc' : 'asc' ) : ( ! empty( $s[1] ) ? 'desc' : 'asc' );
				$label = '<a href="' . esc_url( add_query_arg( array( 'orderby' => $by, 'order' => $order ) ) ) . '"><span>' . $label . '</span></a>';
				echo '<th class="column-' . esc_attr( $name ) . ' sortable' . ( $current === $by ? ' sorted ' . $dir : '' ) . '">' . $label . '</th>';
				continue;
			}
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
		$pages = (int) $this->get_pagination_arg( 'total_pages' );
		echo '<div class="tablenav-pages"><span class="displaying-num">' . (int) $this->get_pagination_arg( 'total_items' ) . ' items</span>';
		if ( $pages > 1 ) {
			echo '<span class="total-pages">' . $pages . '</span>';
			for ( $i = 1; $i <= $pages; $i++ ) {
				echo ' <a class="page-numbers" href="' . esc_url( add_query_arg( 'paged', $i ) ) . '">' . $i . '</a>';
			}
		}
		echo '</div>';
		$this->extra_tablenav( 'bottom' );
	}
}
