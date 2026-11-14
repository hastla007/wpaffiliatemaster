<?php
/**
 * Retry queue (WP_List_Table, R5-O47): status filter, search, sorting, paging; retry/dismiss per row.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Tables;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\IntegrationsPage;
use WPAffiliateCaptain\Core\Jobs;

defined( 'ABSPATH' ) || exit;

final class JobsTable extends ListTable {

	/** @var string */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param string $status Status filter ('' = all open and failed).
	 */
	public function __construct( $status = '' ) {
		parent::__construct(
			array(
				'singular' => 'job',
				'plural'   => 'jobs',
				'ajax'     => false,
			)
		);
		$this->status = in_array( (string) $status, self::statuses(), true ) ? (string) $status : '';
	}

	/**
	 * Statuses of the filter (done jobs are not listed).
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'queued', 'parked', 'running', 'failed' );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'              => '#',
			'type'            => esc_html__( 'Type', 'affiliate-captain' ),
			'order'           => esc_html__( 'Order', 'affiliate-captain' ),
			'created_at'      => esc_html__( 'Created', 'affiliate-captain' ),
			'status'          => esc_html__( 'Status', 'affiliate-captain' ),
			'attempts'        => esc_html__( 'Attempts', 'affiliate-captain' ),
			'error'           => esc_html__( 'Error', 'affiliate-captain' ),
			'next_attempt_at' => esc_html__( 'Next attempt', 'affiliate-captain' ),
			'actions'         => '<span class="screen-reader-text">' . esc_html__( 'Actions', 'affiliate-captain' ) . '</span>',
		);
	}

	/**
	 * Sortable columns (status and next attempt use the status_next index).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'              => array( 'id', true ),
			'type'            => array( 'type', false ),
			'created_at'      => array( 'created_at', true ),
			'status'          => array( 'status', false ),
			'attempts'        => array( 'attempts', true ),
			'next_attempt_at' => array( 'next_attempt_at', false ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per                  = 50;
		list( $rows, $total ) = Jobs::query_paged(
			array(
				'status' => $this->status,
				'search' => self::search_term(),
				'limit'  => $per,
				'offset' => ( $this->get_pagenum() - 1 ) * $per,
			) + $this->sort_args()
		);
		$this->items = $rows;
		$this->finish( $total, $per, 'type' );
	}

	/**
	 * Status filter.
	 *
	 * @return array
	 */
	protected function get_views() {
		$views = array();
		foreach ( array_merge( array( '' ), self::statuses() ) as $s ) {
			$url   = Admin::url( 'wpac-integrations', array_filter( array( 'tab' => 'queue', 'job_status' => $s ) ) );
			$label = '' === $s ? __( 'All', 'affiliate-captain' ) : IntegrationsPage::job_status_label( $s );
			$key   = '' === $s ? 'all' : $s;
			$views[ $key ] = $s === $this->status ? '<strong aria-current="page">' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return $views;
	}

	/**
	 * Status filter links (also in the minimal test list table).
	 */
	public function views() {
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $this->get_views() ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_views().
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		if ( '' !== self::search_term() || '' !== $this->status ) {
			esc_html_e( 'No jobs match this search or filter.', 'affiliate-captain' );
			echo ' <a href="' . esc_url( Admin::url( 'wpac-integrations', array( 'tab' => 'queue' ) ) ) . '">' . esc_html__( 'Reset search', 'affiliate-captain' ) . '</a>';
			return;
		}
		esc_html_e( 'No open or failed jobs. Sales and refunds that cannot be booked right away (e.g. during a database problem) wait here and are retried automatically.', 'affiliate-captain' );
	}

	/**
	 * Columns.
	 *
	 * @param object $j      Row.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $j, $column ) {
		switch ( $column ) {
			case 'id':
				return (string) (int) $j->id;
			case 'type':
				return esc_html( IntegrationsPage::job_type_label( (string) $j->type ) );
			case 'order':
				$order = Jobs::order_of( $j );
				$cell  = '<code>' . esc_html( $j->idempotency_key ) . '</code>';
				if ( ! $order ) {
					return $cell;
				}
				$edit = '';
				if ( \WPAffiliateCaptain\Integrations\WooCommerce::SOURCE === $order[0] && ctype_digit( $order[1] ) && function_exists( 'wc_get_order' ) ) {
					$wc   = wc_get_order( (int) $order[1] );
					$edit = ( $wc && is_callable( array( $wc, 'get_edit_order_url' ) ) ) ? (string) $wc->get_edit_order_url() : '';
				}
				return ( '' !== $edit ? '<a href="' . esc_url( $edit ) . '">#' . esc_html( $order[1] ) . '</a>' : '#' . esc_html( $order[1] ) )
					. ' <small>(<a href="' . esc_url( Admin::url( 'wpac-audit', array( 'order_ref' => $order[1] ) ) ) . '">' . esc_html__( 'log', 'affiliate-captain' ) . '</a>)</small><br>' . $cell;
			case 'created_at':
				return esc_html( Admin::date( $j->created_at, true ) );
			case 'status':
				return esc_html( IntegrationsPage::job_status_label( (string) $j->status ) );
			case 'attempts':
				return (string) (int) $j->attempts;
			case 'error':
				$explain = IntegrationsPage::job_error_label( (string) $j->last_error_code );
				return esc_html( '' !== $explain ? $explain : ( '' !== (string) $j->last_error_code ? __( 'Error while booking.', 'affiliate-captain' ) : '—' ) )
					. '<br><small><code>' . esc_html( $j->last_error_code ) . '</code> ' . esc_html( $j->last_error ) . '</small>';
			case 'next_attempt_at':
				return esc_html( 'queued' === $j->status ? Admin::date( $j->next_attempt_at, true ) : '—' );
			case 'actions':
				return self::capture(
					static function () use ( $j ) {
						if ( 'running' !== $j->status ) {
							Admin::form_open( 'job_retry', 'wpac-inline' );
							echo '<input type="hidden" name="id" value="' . (int) $j->id . '"><button class="button button-small">' . esc_html__( 'Retry now', 'affiliate-captain' ) . '</button></form>';
						}
						if ( in_array( $j->status, array( 'failed', 'parked' ), true ) ) {
							Admin::form_open( 'job_dismiss', 'wpac-inline' );
							echo '<input type="hidden" name="id" value="' . (int) $j->id . '"><button class="button button-small button-link-delete" data-wpac-confirm="' . esc_attr__( 'Dismiss this job? It will not be retried; a sale in it stays without commission.', 'affiliate-captain' ) . '">' . esc_html__( 'Dismiss', 'affiliate-captain' ) . '</button></form>';
						}
					}
				);
		}
		return '';
	}
}
