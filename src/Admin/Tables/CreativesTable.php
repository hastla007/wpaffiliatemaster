<?php
/**
 * Creatives list (WP_List_Table): search, sorting, paging, row actions as small forms (R5-O47).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Promotion\Creatives;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class CreativesTable extends \WP_List_Table {

	/** @var array */
	private $labels;

	/** @var array */
	private $groups;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'creative',
				'plural'   => 'creatives',
				'ajax'     => false,
			)
		);
		$this->labels = Creatives::status_labels();
		$this->groups = Groups::options();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'title'      => esc_html__( 'Creative', 'affiliate-captain' ),
			'version'    => esc_html__( 'Version', 'affiliate-captain' ),
			'group'      => esc_html__( 'Group', 'affiliate-captain' ),
			'disclosure' => esc_html__( 'Disclosure', 'affiliate-captain' ),
			'status'     => esc_html__( 'Status', 'affiliate-captain' ),
			'actions'    => '<span class="screen-reader-text">' . esc_html__( 'Actions', 'affiliate-captain' ) . '</span>',
		);
	}

	/**
	 * Sortable columns (Creatives::query() maps them to fixed SQL).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'   => array( 'title', false ),
			'version' => array( 'version', false ),
			'status'  => array( 'status', false ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = $this->get_items_per_page( 'wpac_creatives_per_page', 50 );
		list( $rows, $total ) = Creatives::query(
			array(
				'search'  => Admin::get( 's' ),
				'limit'   => $per,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per,
				'orderby' => sanitize_key( Admin::get( 'orderby' ) ),
				'order'   => sanitize_key( Admin::get( 'order' ) ),
			)
		);
		$this->items           = $rows;
		// Load the images of this page in two queries (posts and meta), not one by one per row.
		$attachments = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'attachment_id' ) ) ) ) );
		if ( $attachments && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $attachments, false, true );
		}
		$hidden                = function_exists( 'get_hidden_columns' ) && $this->screen ? get_hidden_columns( $this->screen ) : array();
		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns(), 'title' );
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
		echo esc_html( '' !== Admin::get( 's' ) ? __( 'No creatives match this search.', 'affiliate-captain' ) : __( 'No creatives yet.', 'affiliate-captain' ) );
	}

	/**
	 * Row; archived versions dimmed.
	 *
	 * @param object $item Row.
	 */
	public function single_row( $item ) {
		echo '<tr' . ( 'archived' === $item->status ? ' class="wpac-inactive"' : '' ) . '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Columns.
	 *
	 * @param object $c      Row.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $c, $column ) {
		switch ( $column ) {
			case 'title':
				$preview = 'image' === $c->type ? wp_get_attachment_image( (int) $c->attachment_id, array( 80, 80 ) ) : '<small>' . esc_html( wp_trim_words( $c->content, 20 ) ) . '</small>';
				return '<strong>' . esc_html( $c->title ) . '</strong><br>' . $preview;
			case 'version':
				return 'v' . (int) $c->version . ( $c->change_note ? '<br><small>' . esc_html( $c->change_note ) . '</small>' : '' );
			case 'group':
				return esc_html( $c->group_id && isset( $this->groups[ (int) $c->group_id ] ) ? $this->groups[ (int) $c->group_id ] : __( 'All', 'affiliate-captain' ) );
			case 'disclosure':
				return esc_html( $c->disclosure );
			case 'status':
				$user = $c->approved_by ? get_userdata( (int) $c->approved_by ) : null;
				return Admin::badge( 'draft' === $c->status ? 'pending' : ( 'approved' === $c->status ? 'active' : 'paused' ), isset( $this->labels[ $c->status ] ) ? $this->labels[ $c->status ] : $c->status )
					. ( $user ? '<br><small>' . esc_html( $user->display_name . ', ' . Admin::date( $c->approved_at ) ) . '</small>' : '' );
			case 'actions':
				return $this->actions( $c );
		}
		return '';
	}

	/**
	 * New version link, approve and archive forms (each with its own nonce).
	 *
	 * @param object $c Row.
	 * @return string
	 */
	private function actions( $c ) {
		ob_start();
		echo '<a href="' . esc_url( Admin::url( 'wpac-creatives', array( 'edit' => $c->id ) ) ) . '">' . esc_html__( 'New version', 'affiliate-captain' ) . '</a> ';
		if ( 'draft' === $c->status ) {
			Admin::form_open( 'creative_approve', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button button-small button-primary">' . esc_html__( 'Approve', 'affiliate-captain' ) . '</button></form> ';
		}
		if ( 'archived' !== $c->status ) {
			Admin::form_open( 'creative_archive', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Archive this creative? Partners will no longer see it.', 'affiliate-captain' ) . '">' . esc_html__( 'Archive', 'affiliate-captain' ) . '</button></form>';
		}
		return (string) ob_get_clean();
	}
}
