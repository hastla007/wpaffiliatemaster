<?php
/**
 * Shared parts of the plugin's simpler list tables (R5-O47): sort and search from the request,
 * hidden fields of the GET forms and row forms rendered into a cell.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

abstract class ListTable extends \WP_List_Table {

	/**
	 * Requested sorting, limited to the sortable columns of the table.
	 *
	 * The repositories check the column a second time (Paged::fetch() whitelist); unknown values
	 * fall back to the default order of the list.
	 *
	 * @return array orderby ('' = default order), order (asc|desc).
	 */
	public function sort_args() {
		$allowed = array();
		foreach ( $this->get_sortable_columns() as $col ) {
			$allowed[] = (string) ( is_array( $col ) ? $col[0] : $col );
		}
		$orderby = sanitize_key( Admin::get( 'orderby' ) );
		$order   = strtolower( Admin::get( 'order' ) );
		return array(
			'orderby' => in_array( $orderby, $allowed, true ) ? $orderby : '',
			'order'   => 'asc' === $order ? 'asc' : 'desc',
		);
	}

	/**
	 * Search term of the search box ("s").
	 *
	 * @return string
	 */
	public static function search_term() {
		return trim( Admin::get( 's' ) );
	}

	/**
	 * Hidden fields that keep the page, filters and sorting in a GET form (search, filters).
	 *
	 * @param string $page Admin page slug.
	 * @param array  $keep Extra key => value pairs ('' values are left out).
	 * @param bool   $sort Keep the sorting too (false in front of search_box(), which adds it).
	 */
	public static function hidden_fields( $page, array $keep = array(), $sort = true ) {
		$keep = array( 'page' => $page ) + $keep;
		foreach ( $sort ? array( 'orderby', 'order' ) : array() as $k ) {
			if ( ! isset( $keep[ $k ] ) && '' !== Admin::get( $k ) ) {
				$keep[ $k ] = 'orderby' === $k ? sanitize_key( Admin::get( $k ) ) : ( 'asc' === strtolower( Admin::get( $k ) ) ? 'asc' : 'desc' );
			}
		}
		foreach ( $keep as $k => $v ) {
			if ( '' !== (string) $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
			}
		}
	}

	/**
	 * Output of a callback (row forms that print) as a string for a cell.
	 *
	 * @param callable $fn Callback.
	 * @return string
	 */
	protected static function capture( callable $fn ) {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	/**
	 * Column headers and paging of the loaded rows.
	 *
	 * @param int    $total   Total rows.
	 * @param int    $per     Rows per page.
	 * @param string $primary Primary column.
	 */
	protected function finish( $total, $per, $primary ) {
		$hidden = array();
		if ( $this->screen && function_exists( 'get_hidden_columns' ) ) {
			$hidden = (array) get_hidden_columns( $this->screen );
		}
		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns(), $primary );
		$this->set_pagination_args(
			array(
				'total_items' => (int) $total,
				'per_page'    => (int) $per,
			)
		);
	}
}
