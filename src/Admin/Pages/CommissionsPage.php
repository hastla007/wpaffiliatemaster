<?php
/**
 * Admin: commission ledger.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Money;

defined( 'ABSPATH' ) || exit;

final class CommissionsPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'commission_status' === $do ) {
			$ids    = array_filter( array_map( 'absint', (array) Admin::post( 'ids', array() ) ) );
			$single = absint( Admin::post( 'id', 0 ) );
			if ( $single ) {
				$ids = array( $single );
			}
			$to     = sanitize_key( Admin::post( 'to' ) );
			$reason = sanitize_text_field( Admin::post( 'reason' ) );
			$errors = 0;
			$last   = '';
			foreach ( $ids as $id ) {
				$result = 'approved' === $to ? Ledger::approve( $id, $reason ? $reason : __( 'Approved manually', 'wp-affiliate-captain' ) ) : Ledger::reject( $id, $reason );
				if ( is_wp_error( $result ) ) {
					++$errors;
					$last = $result->get_error_message();
				}
			}
			if ( $errors ) {
				/* translators: 1: number of errors, 2: message */
				Admin::back( sprintf( __( '%1$d commission(s) could not be changed: %2$s', 'wp-affiliate-captain' ), $errors, $last ), 'error' );
			}
			Admin::back( __( 'Commissions updated.', 'wp-affiliate-captain' ) );
		}

		if ( 'commission_export' === $do ) {
			$args = self::filters_from( 'post' );
			$args['limit'] = 0;
			list( $rows ) = Ledger::query( $args );
			$names = array();
			$out   = array();
			foreach ( $rows as $r ) {
				if ( ! isset( $names[ $r->affiliate_id ] ) ) {
					$a                         = AffiliateRepository::find( (int) $r->affiliate_id );
					$names[ $r->affiliate_id ] = $a ? $a->name : '';
				}
				$out[] = array(
					$r->id,
					$r->created_at,
					$r->affiliate_id,
					$names[ $r->affiliate_id ],
					$r->source,
					$r->source_order_id,
					$r->source_item_id,
					$r->kind,
					$r->parent_id,
					$r->description,
					$r->currency,
					Money::to_decimal( (int) $r->base_minor, $r->currency ),
					$r->rate_type,
					$r->rate,
					Money::to_decimal( (int) $r->amount_minor, $r->currency ),
					$r->status,
					$r->hold_until,
					$r->payout_id,
				);
			}
			Audit::log( 'commission.exported', 'commission', 0, array( 'count' => count( $out ) ) );
			Csv::download(
				'commissions-' . gmdate( 'Y-m-d' ) . '.csv',
				array( 'id', 'created_at_utc', 'affiliate_id', 'affiliate', 'source', 'order_id', 'item_id', 'kind', 'parent_id', 'description', 'currency', 'base', 'rate_type', 'rate', 'amount', 'status', 'hold_until_utc', 'payout_id' ),
				$out
			);
		}
	}

	/**
	 * Bulk approve/reject of the list table (load-{page} hook, before output).
	 */
	public static function process_bulk() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ( isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '' );
		if ( ! in_array( $action, array( 'approve', 'reject' ), true ) || empty( $_REQUEST['ids'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-commissions' );
		$ids    = array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) );
		$reason = isset( $_REQUEST['reason'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['reason'] ) ) : '';
		// phpcs:enable
		$back = remove_query_arg( array( 'action', 'action2', 'ids', '_wpnonce', '_wp_http_referer', 'reason' ) );
		if ( 'reject' === $action && '' === $reason ) {
			Admin::back( __( 'Please enter a reason for the rejection.', 'wp-affiliate-captain' ), 'error', $back );
		}
		$errors = 0;
		$last   = '';
		foreach ( $ids as $id ) {
			$result = 'approve' === $action ? Ledger::approve( $id, '' !== $reason ? $reason : __( 'Approved manually', 'wp-affiliate-captain' ) ) : Ledger::reject( $id, $reason );
			if ( is_wp_error( $result ) ) {
				++$errors;
				$last = $result->get_error_message();
			}
		}
		if ( $errors ) {
			/* translators: 1: number of errors, 2: message */
			Admin::back( sprintf( __( '%1$d commission(s) could not be changed: %2$s', 'wp-affiliate-captain' ), $errors, $last ), 'error', $back );
		}
		Admin::back( __( 'Commissions updated.', 'wp-affiliate-captain' ), 'success', $back );
	}

	/**
	 * Read filters from GET or POST.
	 *
	 * @param string $from get|post.
	 * @return array
	 */
	private static function filters_from( $from = 'get' ) {
		$read = static function ( $k ) use ( $from ) {
			return 'post' === $from ? sanitize_text_field( (string) Admin::post( $k ) ) : Admin::get( $k );
		};
		return array(
			'affiliate_id' => absint( $read( 'affiliate_id' ) ),
			'status'       => sanitize_key( $read( 'status' ) ),
			'source'       => sanitize_key( $read( 'source' ) ),
			'order_id'     => $read( 'order_id' ),
			'from'         => $read( 'from' ),
			'to'           => $read( 'to' ),
		);
	}

	/**
	 * Render.
	 */
	public static function render() {
		if ( 'view' === Admin::get( 'action' ) ) {
			self::render_view( absint( Admin::get( 'id' ) ) );
			return;
		}
		$filters = self::filters_from( 'get' );
		$labels  = Ledger::status_labels();
		$totals  = Ledger::totals( $filters );

		echo '<div class="wrap"><h1>' . esc_html__( 'Commissions', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();

		// Filters.
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac-commissions">';
		echo '<select name="status" aria-label="' . esc_attr__( 'Status', 'wp-affiliate-captain' ) . '"><option value="">' . esc_html__( 'All statuses', 'wp-affiliate-captain' ) . '</option>';
		foreach ( $labels as $k => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $filters['status'], $k, false ), esc_html( $l ) );
		}
		echo '</select> ';
		echo '<input type="number" name="affiliate_id" placeholder="' . esc_attr__( 'Partner ID', 'wp-affiliate-captain' ) . '" value="' . esc_attr( $filters['affiliate_id'] ? $filters['affiliate_id'] : '' ) . '" class="small-text"> ';
		echo '<input type="text" name="source" placeholder="' . esc_attr__( 'Source', 'wp-affiliate-captain' ) . '" value="' . esc_attr( $filters['source'] ) . '" size="12"> ';
		echo '<input type="text" name="order_id" placeholder="' . esc_attr__( 'Order', 'wp-affiliate-captain' ) . '" value="' . esc_attr( $filters['order_id'] ) . '" size="8"> ';
		echo '<label>' . esc_html__( 'from', 'wp-affiliate-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'to', 'wp-affiliate-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '"></label> ';
		echo '<button class="button">' . esc_html__( 'Filter', 'wp-affiliate-captain' ) . '</button></form>';

		Admin::form_open( 'commission_export', 'wpac-inline' );
		foreach ( $filters as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<button class="button">' . esc_html__( 'Export CSV', 'wp-affiliate-captain' ) . '</button></form>';

		// Totals.
		echo '<p class="wpac-totals">';
		foreach ( $totals as $status => $by_currency ) {
			foreach ( $by_currency as $cur => $minor ) {
				echo '<span>' . esc_html( ( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . ': ' . Money::format( $minor, $cur ) ) . '</span> ';
			}
		}
		echo '</p>';

		$table = new \WPAffiliateCaptain\Admin\Tables\CommissionsTable( $filters );
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="wpac-commissions">';
		foreach ( $filters as $k => $v ) {
			if ( '' !== (string) $v && 0 !== $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
		}
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Detail with event log.
	 *
	 * @param int $id Commission.
	 */
	private static function render_view( $id ) {
		$c = Ledger::find( $id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Commission', 'wp-affiliate-captain' ) . ' #' . (int) $id . '</h1>';
		echo '<p><a href="' . esc_url( Admin::url( 'wpac-commissions' ) ) . '">&larr; ' . esc_html__( 'All commissions', 'wp-affiliate-captain' ) . '</a></p>';
		Admin::notice();
		if ( ! $c ) {
			echo '<p>' . esc_html__( 'Not found.', 'wp-affiliate-captain' ) . '</p></div>';
			return;
		}
		$labels = Ledger::status_labels();
		$aff    = AffiliateRepository::find( (int) $c->affiliate_id );
		$attr   = Ledger::attribution_for( $c->source, $c->source_order_id );
		echo '<table class="widefat striped wpac-dl"><tbody>';
		$rows = array(
			__( 'Partner', 'wp-affiliate-captain' )      => $aff ? $aff->name : '#' . $c->affiliate_id,
			__( 'Source / order', 'wp-affiliate-captain' ) => Ledger::source_label( $c->source ) . ' #' . $c->source_order_id . ( $c->source_item_id ? ' / ' . __( 'item', 'wp-affiliate-captain' ) . ' ' . $c->source_item_id : '' ),
			__( 'Type', 'wp-affiliate-captain' )         => ( isset( Ledger::kind_labels()[ $c->kind ] ) ? Ledger::kind_labels()[ $c->kind ] : $c->kind ) . ( $c->parent_id ? ' → #' . $c->parent_id : '' ),
			__( 'Item', 'wp-affiliate-captain' )         => $c->description . ( $c->product_ref ? ' (' . $c->product_ref . ')' : '' ),
			__( 'Base', 'wp-affiliate-captain' )         => Money::format( (int) $c->base_minor, $c->currency ),
			__( 'Rate', 'wp-affiliate-captain' )         => RuleRepository::format_rate( $c->rate_type, $c->rate, $c->currency ) . ( $c->rule_id ? ' (' . __( 'rule', 'wp-affiliate-captain' ) . ' #' . $c->rule_id . ')' : ' (' . __( 'default', 'wp-affiliate-captain' ) . ')' ),
			__( 'Commission', 'wp-affiliate-captain' )   => Money::format( (int) $c->amount_minor, $c->currency ),
			__( 'Status', 'wp-affiliate-captain' )       => isset( $labels[ $c->status ] ) ? $labels[ $c->status ] : $c->status,
			__( 'Hold until', 'wp-affiliate-captain' )   => Admin::date( $c->hold_until, true ),
			__( 'Attribution', 'wp-affiliate-captain' )  => $attr ? self::method_label( $attr->method ) . ( $attr->campaign ? ' · ' . $attr->campaign : '' ) . ( $attr->content_id ? ' · ' . __( 'content', 'wp-affiliate-captain' ) . ' #' . $attr->content_id : '' ) : '—',
			__( 'Review reasons', 'wp-affiliate-captain' ) => $attr && $attr->note ? implode(
				', ',
				array_map(
					static function ( $flag ) {
						$labels = \WPAffiliateCaptain\Tracking\FraudCheck::labels();
						return isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag;
					},
					explode( ',', $attr->note )
				)
			) : '—',
			__( 'Payout', 'wp-affiliate-captain' )       => $c->payout_id ? '#' . $c->payout_id : '—',
			__( 'Idempotency key', 'wp-affiliate-captain' ) => $c->idempotency_key,
		);
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( in_array( $c->status, array( 'pending', 'review', 'approved' ), true ) && ! (int) $c->payout_id ) {
			echo '<h2>' . esc_html__( 'Decision', 'wp-affiliate-captain' ) . '</h2>';
			Admin::form_open( 'commission_status' );
			echo '<input type="hidden" name="id" value="' . (int) $c->id . '"><label class="screen-reader-text" for="wpac-decision-reason">' . esc_html__( 'Reason', 'wp-affiliate-captain' ) . '</label><input type="text" id="wpac-decision-reason" name="reason" class="regular-text" placeholder="' . esc_attr__( 'Reason (shown to the partner when rejecting)', 'wp-affiliate-captain' ) . '"> ';
			if ( 'approved' !== $c->status ) {
				echo '<button class="button button-primary" name="to" value="approved">' . esc_html__( 'Approve', 'wp-affiliate-captain' ) . '</button> ';
			}
			/* translators: %s: amount */
			echo '<button class="button" name="to" value="rejected" data-wpac-confirm="' . esc_attr( sprintf( __( 'Reject this commission (%s)? The partner sees the reason.', 'wp-affiliate-captain' ), html_entity_decode( Money::format( (int) $c->amount_minor, $c->currency ), ENT_QUOTES, 'UTF-8' ) ) ) . '">' . esc_html__( 'Reject', 'wp-affiliate-captain' ) . '</button></form>';
		}

		echo '<h2>' . esc_html__( 'Events', 'wp-affiliate-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Event', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Reason', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'By', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( Ledger::events( $id ) as $e ) {
			$user = $e->actor_id ? get_userdata( (int) $e->actor_id ) : null;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s → %s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( Admin::date( $e->created_at, true ) ),
				esc_html( $e->event ),
				esc_html( $e->from_status ? ( isset( $labels[ $e->from_status ] ) ? $labels[ $e->from_status ] : $e->from_status ) : '·' ),
				esc_html( isset( $labels[ $e->to_status ] ) ? $labels[ $e->to_status ] : $e->to_status ),
				esc_html( Money::format( (int) $e->amount_minor, $c->currency ) ),
				esc_html( $e->reason ),
				esc_html( $user ? $user->display_name : __( 'System', 'wp-affiliate-captain' ) )
			);
		}
		echo '</tbody></table>';

		$children = Ledger::children( $id );
		if ( $children ) {
			$kinds = Ledger::kind_labels();
			echo '<h2>' . esc_html__( 'Corrections', 'wp-affiliate-captain' ) . '</h2><ul>';
			foreach ( $children as $ch ) {
				echo '<li><a href="' . esc_url( Admin::url( 'wpac-commissions', array( 'action' => 'view', 'id' => $ch->id ) ) ) . '">#' . (int) $ch->id . '</a> ' . esc_html( ( isset( $kinds[ $ch->kind ] ) ? $kinds[ $ch->kind ] : $ch->kind ) . ' · ' . Money::format( (int) $ch->amount_minor, $ch->currency ) . ' · ' . ( isset( $labels[ $ch->status ] ) ? $labels[ $ch->status ] : $ch->status ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $c->rule_snapshot ) {
			echo '<h2>' . esc_html__( 'Rule snapshot', 'wp-affiliate-captain' ) . '</h2><pre class="wpac-pre">' . esc_html( wp_json_encode( json_decode( $c->rule_snapshot ), JSON_PRETTY_PRINT ) ) . '</pre>';
		}
		echo '</div>';
	}

	/**
	 * Readable attribution method.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	private static function method_label( $method ) {
		$labels = array(
			'link'     => __( 'Partner link', 'wp-affiliate-captain' ),
			'cookie'   => __( 'Cookie', 'wp-affiliate-captain' ),
			'code'     => __( 'Partner code', 'wp-affiliate-captain' ),
			'coupon'   => __( 'Coupon', 'wp-affiliate-captain' ),
			'lifetime' => __( 'Lifetime customer', 'wp-affiliate-captain' ),
			'manual'   => __( 'Manual assignment', 'wp-affiliate-captain' ),
			'app'      => __( 'App', 'wp-affiliate-captain' ),
			'renewal'  => __( 'Subscription renewal', 'wp-affiliate-captain' ),
		);
		return isset( $labels[ $method ] ) ? $labels[ $method ] : (string) $method;
	}
}
