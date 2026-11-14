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

	/** @var array Per partner: code, balance, payout, terms, login (loaded for the page in one go). */
	private $extra = array();

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
			'code'       => __( 'Code', 'affiliate-captain' ),
			'balance'    => __( 'Balance', 'affiliate-captain' ),
			'payout'     => __( 'Payout details', 'affiliate-captain' ),
			'terms'      => __( 'Terms', 'affiliate-captain' ),
			'login'      => __( 'Login', 'affiliate-captain' ),
			'created_at' => __( 'Since', 'affiliate-captain' ),
		);
	}

	/**
	 * Columns hidden until switched on in the screen options.
	 *
	 * @return string[]
	 */
	public static function default_hidden() {
		return array( 'website', 'group' );
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
		// Only applications can be approved or rejected in bulk: offered in the view of pending
		// applications, not where they would do nothing (R5-O52).
		if ( 'pending' !== Admin::get( 'status' ) ) {
			return array();
		}
		return array(
			'approve' => __( 'Approve applications', 'affiliate-captain' ),
			'reject'  => __( 'Reject applications', 'affiliate-captain' ),
		);
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
		$this->extra           = self::extra_data( wp_list_pluck( $rows, 'id' ) );
		$hidden                = function_exists( 'get_hidden_columns' ) && $this->screen ? get_hidden_columns( $this->screen ) : self::default_hidden();
		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns(), 'name' );
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
		if ( '' !== Admin::get( 's' ) || '' !== Admin::get( 'status' ) ) {
			esc_html_e( 'No partners match this search or view.', 'affiliate-captain' );
			echo ' <a href="' . esc_url( Admin::url( 'wpac-affiliates' ) ) . '">' . esc_html__( 'Show all partners', 'affiliate-captain' ) . '</a>';
			return;
		}
		esc_html_e( 'No partners yet. Add the first partner yourself, or publish the application page so partners can apply.', 'affiliate-captain' );
		echo ' <a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add partner', 'affiliate-captain' ) . '</a> · <a href="' . esc_url( Admin::url( 'wpac' ) ) . '">' . esc_html__( 'Open the setup checklist', 'affiliate-captain' ) . '</a>';
	}

	/**
	 * Code, balance, payout details, terms and login of the listed partners (five queries per page).
	 *
	 * @param int[] $ids Partner IDs.
	 * @return array id => [ code, codes, balances, payout, terms, login ]
	 */
	public static function extra_data( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'code'     => '',
				'codes'    => 0,
				'balances' => array(),
				'payout'   => false,
				'terms'    => false,
				'login'    => false,
			);
		}
		if ( ! $ids ) {
			return $out;
		}
		$in    = implode( ',', $ids ); // Integers only.
		$codes = \WPAffiliateCaptain\Core\Db::table( 'partner_codes' );
		$bal   = \WPAffiliateCaptain\Core\Db::table( 'balances' );
		$prof  = \WPAffiliateCaptain\Core\Db::table( 'payout_profiles' );
		$agr   = \WPAffiliateCaptain\Core\Db::table( 'agreements' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own tables, integer list.
		foreach ( (array) $wpdb->get_results( "SELECT affiliate_id, MIN(code) AS code, COUNT(*) AS n FROM {$codes} WHERE status = 'active' AND affiliate_id IN ({$in}) GROUP BY affiliate_id" ) as $r ) {
			$out[ (int) $r->affiliate_id ]['code']  = (string) $r->code;
			$out[ (int) $r->affiliate_id ]['codes'] = (int) $r->n;
		}
		foreach ( (array) $wpdb->get_results( "SELECT affiliate_id, currency, balance_minor FROM {$bal} WHERE balance_minor <> 0 AND affiliate_id IN ({$in})" ) as $r ) {
			$out[ (int) $r->affiliate_id ]['balances'][ (string) $r->currency ] = (int) $r->balance_minor;
		}
		foreach ( (array) $wpdb->get_col( "SELECT affiliate_id FROM {$prof} WHERE iban_encrypted IS NOT NULL AND iban_encrypted <> '' AND affiliate_id IN ({$in})" ) as $id ) {
			$out[ (int) $id ]['payout'] = true;
		}
		$latest = (array) $wpdb->get_results( "SELECT g.affiliate_id, g.version, g.doc_hash FROM {$agr} g JOIN (SELECT affiliate_id, MAX(id) AS id FROM {$agr} WHERE affiliate_id IN ({$in}) GROUP BY affiliate_id) m ON m.id = g.id WHERE g.result = 'accepted'" );
		// phpcs:enable
		$affs  = AffiliateRepository::find_many( $ids );
		$terms = array();
		foreach ( $latest as $r ) {
			$aff   = isset( $affs[ (int) $r->affiliate_id ] ) ? $affs[ (int) $r->affiliate_id ] : null;
			$group = $aff ? (int) $aff->group_id : 0;
			if ( ! isset( $terms[ $group ] ) ) {
				$terms[ $group ] = \WPAffiliateCaptain\Affiliates\Agreements::current( $group );
			}
			$out[ (int) $r->affiliate_id ]['terms'] = \WPAffiliateCaptain\Affiliates\Agreements::matches( (string) $r->version, (string) $r->doc_hash, $terms[ $group ] );
		}
		foreach ( $affs as $id => $aff ) {
			$out[ (int) $id ]['login'] = $aff && (int) $aff->user_id > 0;
		}
		return $out;
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
		$x = isset( $this->extra[ (int) $item->id ] ) ? $this->extra[ (int) $item->id ] : null;
		if ( ! $x ) {
			return '';
		}
		$yes = '<span class="wpac-state wpac-state--ok">' . esc_html__( 'yes', 'affiliate-captain' ) . '</span>';
		$no  = '<span class="wpac-state wpac-state--open">' . esc_html__( 'missing', 'affiliate-captain' ) . '</span>';
		switch ( $column ) {
			case 'code':
				return '' !== $x['code'] ? '<code dir="ltr">' . esc_html( $x['code'] ) . '</code>' . ( $x['codes'] > 1 ? ' <small>+' . (int) ( $x['codes'] - 1 ) . '</small>' : '' ) : '—';
			case 'balance':
				$parts = array();
				foreach ( $x['balances'] as $cur => $minor ) {
					$parts[] = esc_html( \WPAffiliateCaptain\Core\Money::format( $minor, $cur ) );
				}
				return $parts ? implode( '<br>', $parts ) : '—';
			case 'payout':
				return $x['payout'] ? $yes : $no;
			case 'terms':
				return $x['terms'] ? '<span class="wpac-state wpac-state--ok">' . esc_html__( 'accepted', 'affiliate-captain' ) . '</span>' : '<span class="wpac-state wpac-state--open">' . esc_html__( 'not yet', 'affiliate-captain' ) . '</span>';
			case 'login':
				return $x['login'] ? $yes : '<span class="wpac-state wpac-state--open">' . esc_html__( 'no account', 'affiliate-captain' ) . '</span>';
		}
		return '';
	}
}
