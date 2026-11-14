<?php
/**
 * Creative list (WP_List_Table, R5-O47): search, sorting, paging; approve/archive per row.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Promotion\Creatives;

defined( 'ABSPATH' ) || exit;

final class CreativesTable extends ListTable {

	/** @var array */
	private $groups;

	/** @var array */
	private $labels;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'creative',
				'plural'   => 'creatives',
				'ajax'     => false,
			)
		);
		$this->groups = Groups::options();
		$this->labels = Creatives::status_labels();
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
			'group_id'   => esc_html__( 'Group', 'affiliate-captain' ),
			'disclosure' => esc_html__( 'Disclosure', 'affiliate-captain' ),
			'status'     => esc_html__( 'Status', 'affiliate-captain' ),
			'actions'    => '<span class="screen-reader-text">' . esc_html__( 'Actions', 'affiliate-captain' ) . '</span>',
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'   => array( 'title', false ),
			'version' => array( 'version', true ),
			'status'  => array( 'status', false ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = max( 1, (int) $this->get_items_per_page( 'wpac_creatives_per_page', 50 ) );
		list( $rows, $total ) = Creatives::query(
			array(
				'search' => self::search_term(),
				'limit'  => $per,
				'offset' => ( $this->get_pagenum() - 1 ) * $per,
			) + $this->sort_args()
		);
		$this->items = $rows;
		$this->finish( $total, $per, 'title' );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		if ( '' !== self::search_term() ) {
			esc_html_e( 'No creatives match this search.', 'affiliate-captain' );
			echo ' <a href="' . esc_url( Admin::url( 'wpac-creatives' ) ) . '">' . esc_html__( 'Reset search', 'affiliate-captain' ) . '</a>';
			return;
		}
		esc_html_e( 'No creatives yet.', 'affiliate-captain' );
	}

	/**
	 * Row class: archived versions are greyed out.
	 *
	 * @param object $item Row.
	 */
	public function single_row( $item ) {
		echo '<tr class="' . ( 'archived' === $item->status ? 'wpac-inactive' : '' ) . '">';
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
			case 'group_id':
				return esc_html( $c->group_id && isset( $this->groups[ (int) $c->group_id ] ) ? $this->groups[ (int) $c->group_id ] : __( 'All', 'affiliate-captain' ) );
			case 'disclosure':
				return esc_html( $c->disclosure );
			case 'status':
				$user = $c->approved_by ? get_userdata( (int) $c->approved_by ) : null;
				return Admin::badge( 'draft' === $c->status ? 'pending' : ( 'approved' === $c->status ? 'active' : 'paused' ), isset( $this->labels[ $c->status ] ) ? $this->labels[ $c->status ] : $c->status )
					. ( $user ? '<br><small>' . esc_html( $user->display_name . ', ' . Admin::date( $c->approved_at ) ) . '</small>' : '' );
			case 'actions':
				return self::capture(
					static function () use ( $c ) {
						echo '<a href="' . esc_url( Admin::url( 'wpac-creatives', array( 'edit' => $c->id ) ) ) . '">' . esc_html__( 'New version', 'affiliate-captain' ) . '</a> ';
						if ( 'draft' === $c->status ) {
							Admin::form_open( 'creative_approve', 'wpac-inline' );
							echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button button-small button-primary">' . esc_html__( 'Approve', 'affiliate-captain' ) . '</button></form> ';
						}
						if ( 'archived' !== $c->status ) {
							Admin::form_open( 'creative_archive', 'wpac-inline' );
							echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button-link wpac-danger" data-wpac-confirm="' . esc_attr__( 'Archive this creative? Partners will no longer see it.', 'affiliate-captain' ) . '">' . esc_html__( 'Archive', 'affiliate-captain' ) . '</button></form>';
						}
					}
				);
		}
		return '';
	}
}
