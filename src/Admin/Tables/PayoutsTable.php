<?php
/**
 * Payout list (WP_List_Table): status filter, search, sorting, paging; the bulk actions stay in
 * the payout page's own form around the table (R5-O47).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PayoutsTable extends \WP_List_Table {

	/** @var bool Whether the user manages payouts (checkboxes for the bulk actions). */
	private $manage;

	/** @var string */
	private $status;

	/** @var int[] Payouts checked before (form kept after a failed bulk action). */
	private $kept;

	/** @var array */
	private $labels;

	/**
	 * Constructor.
	 *
	 * @param bool   $manage Whether the user manages payouts.
	 * @param string $status Status filter ('' = all).
	 * @param int[]  $kept   Checked payout IDs.
	 */
	public function __construct( $manage, $status, array $kept = array() ) {
		parent::__construct(
			array(
				'singular' => 'payout',
				'plural'   => 'payouts',
				'ajax'     => false,
			)
		);
		$this->manage = (bool) $manage;
		$this->status = (string) $status;
		$this->kept   = array_map( 'intval', $kept );
		$this->labels = PayoutService::status_labels();
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$cols = array();
		if ( $this->manage ) {
			$cols['cb'] = '<input type="checkbox" class="wpac-cb-all" aria-label="' . esc_attr__( 'Select all', 'affiliate-captain' ) . '">';
		}
		return $cols + array(
			'number'       => esc_html_x( 'Number', 'document number', 'affiliate-captain' ),
			'affiliate_id' => esc_html__( 'Partner', 'affiliate-captain' ),
			'period_end'   => esc_html__( 'Period end', 'affiliate-captain' ),
			'amount_minor' => esc_html__( 'Amount', 'affiliate-captain' ),
			'bank'         => esc_html__( 'Bank details', 'affiliate-captain' ),
			'status'       => esc_html__( 'Status', 'affiliate-captain' ),
		);
	}

	/**
	 * Sortable columns (values must be in the whitelist of PayoutService::query()).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'number'       => array( 'id', true ),
			'period_end'   => array( 'period_end', false ),
			'amount_minor' => array( 'amount_minor', false ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = $this->get_items_per_page( 'wpac_payouts_per_page', 50 );
		list( $rows, $total ) = PayoutService::query(
			array(
				'status'  => $this->status,
				'search'  => Admin::get( 's' ),
				'limit'   => $per,
				'offset'  => ( $this->get_pagenum() - 1 ) * $per,
				'orderby' => sanitize_key( Admin::get( 'orderby' ) ),
				'order'   => sanitize_key( Admin::get( 'order' ) ),
			)
		);
		AffiliateRepository::find_many( wp_list_pluck( $rows, 'affiliate_id' ) );
		$this->items           = $rows;
		$hidden                = function_exists( 'get_hidden_columns' ) && $this->screen ? get_hidden_columns( $this->screen ) : array();
		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns(), 'number' );
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
		echo esc_html( PayoutsPage::empty_text( $this->status, Admin::get( 's' ) ) );
	}

	/**
	 * Checkbox (open and exported payouts only: the bulk actions apply to them).
	 *
	 * @param object $p Row.
	 * @return string
	 */
	protected function column_cb( $p ) {
		if ( ! $this->manage || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
			return '';
		}
		return '<input type="checkbox" class="wpac-cb" name="ids[]" value="' . (int) $p->id . '"' . checked( in_array( (int) $p->id, $this->kept, true ), true, false ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s: document number */ __( 'Select %s', 'affiliate-captain' ), $p->number ) ) . '">';
	}

	/**
	 * Columns.
	 *
	 * @param object $p      Row.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $p, $column ) {
		switch ( $column ) {
			case 'number':
				return '<a href="' . esc_url( Admin::url( 'wpac-payouts', array( 'action' => 'receipt', 'id' => $p->id ) ) ) . '">' . esc_html( $p->number ) . '</a>';
			case 'affiliate_id':
				$aff = AffiliateRepository::find( (int) $p->affiliate_id );
				return esc_html( $aff ? $aff->name : '#' . $p->affiliate_id );
			case 'period_end':
				return esc_html( mysql2date( get_option( 'date_format' ), $p->period_end ) );
			case 'amount_minor':
				return '<strong>' . esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ) . '</strong>';
			case 'bank':
				$snap = json_decode( (string) $p->profile_snapshot, true );
				return esc_html( isset( $snap['payout_method'] ) && ProfileRepository::METHOD_MANUAL === $snap['payout_method'] ? __( 'Other payment method (paid manually)', 'affiliate-captain' ) : ( isset( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—' ) );
			case 'status':
				return Admin::badge( $p->status, isset( $this->labels[ $p->status ] ) ? $this->labels[ $p->status ] : $p->status ) . ( $p->sepa_batch ? '<br><small>' . esc_html( $p->sepa_batch ) . '</small>' : '' );
		}
		return '';
	}
}
