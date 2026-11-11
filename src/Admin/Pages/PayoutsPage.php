<?php
/**
 * Admin: payout runs, statements, SEPA export.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;

defined( 'ABSPATH' ) || exit;

final class PayoutsPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		switch ( $do ) {
			case 'payout_run':
				$end      = sanitize_text_field( Admin::post( 'period_end' ) );
				$currency = strtoupper( sanitize_key( Admin::post( 'currency' ) ) );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || 3 !== strlen( $currency ) ) {
					Admin::back( __( 'Please choose a period end and currency.', 'wp-affiliate-captain' ), 'error' );
				}
				list( $created, $skipped ) = PayoutService::create_run( $end, $currency );
				/* translators: 1: created, 2: skipped */
				Admin::back( sprintf( __( '%1$d payouts created, %2$d partners carried forward.', 'wp-affiliate-captain' ), count( $created ), $skipped ), 'success', Admin::url( 'wpac-payouts' ) );
				break;

			case 'payout_paid':
				$errors = 0;
				foreach ( self::selected() as $id ) {
					if ( is_wp_error( PayoutService::mark_paid( $id ) ) ) {
						++$errors;
					}
				}
				Admin::back( $errors ? __( 'Some payouts could not be marked as paid.', 'wp-affiliate-captain' ) : __( 'Payouts marked as paid.', 'wp-affiliate-captain' ), $errors ? 'error' : 'success' );
				break;

			case 'payout_cancel':
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				foreach ( self::selected() as $id ) {
					$r = PayoutService::cancel( $id, $reason );
					if ( is_wp_error( $r ) ) {
						Admin::back( $r->get_error_message(), 'error' );
					}
				}
				Admin::back( __( 'Payouts cancelled. The commissions are available for the next run.', 'wp-affiliate-captain' ) );
				break;

			case 'payout_sepa':
				$result = PayoutService::sepa_export( self::selected() );
				if ( is_wp_error( $result ) ) {
					Admin::back( $result->get_error_message(), 'error' );
				}
				nocache_headers();
				header( 'Content-Type: application/xml; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $result[0] ) . '"' );
				echo $result[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;

			case 'payout_export':
				list( $rows ) = PayoutService::query( array( 'limit' => 100000 ) );
				$out          = array();
				foreach ( $rows as $p ) {
					$aff   = AffiliateRepository::find( (int) $p->affiliate_id );
					$snap  = json_decode( (string) $p->profile_snapshot, true );
					$out[] = array( $p->number, $p->affiliate_id, $aff ? $aff->name : '', $p->period_end, $p->currency, Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->status, isset( $snap['tax_status'] ) ? $snap['tax_status'] : '', isset( $snap['vat_id'] ) ? $snap['vat_id'] : '', $p->created_at, $p->paid_at );
				}
				Csv::download( 'payouts-' . gmdate( 'Y-m-d' ) . '.csv', array( 'number', 'affiliate_id', 'affiliate', 'period_end', 'currency', 'amount', 'status', 'tax_status', 'vat_id', 'created_at_utc', 'paid_at_utc' ), $out );
				break;
		}
	}

	/**
	 * Selected payout IDs.
	 *
	 * @return int[]
	 */
	private static function selected() {
		return array_filter( array_map( 'absint', (array) Admin::post( 'ids', array() ) ) );
	}

	/**
	 * Render.
	 */
	public static function render() {
		if ( 'receipt' === Admin::get( 'action' ) ) {
			self::render_receipt( absint( Admin::get( 'id' ) ) );
			return;
		}
		$currency = Admin::get( 'currency' ) ? strtoupper( Admin::get( 'currency' ) ) : (string) Settings::get( 'currency' );
		$end      = Admin::get( 'period_end' ) ? Admin::get( 'period_end' ) : gmdate( 'Y-m-d', strtotime( 'last day of previous month' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Payouts', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();

		echo '<h2>' . esc_html__( 'New payout run', 'wp-affiliate-captain' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="wpac-payouts">';
		echo '<label>' . esc_html__( 'Period end', 'wp-affiliate-captain' ) . ' <input type="date" name="period_end" value="' . esc_attr( $end ) . '"></label> ';
		echo '<label>' . esc_html__( 'Currency', 'wp-affiliate-captain' ) . ' <input type="text" name="currency" value="' . esc_attr( $currency ) . '" size="4" maxlength="3"></label> ';
		echo '<button class="button" name="preview" value="1">' . esc_html__( 'Preview', 'wp-affiliate-captain' ) . '</button></form>';
		/* translators: %s: amount */
		echo '<p class="description">' . esc_html( sprintf( __( 'Includes approved commissions and corrections up to the period end. Minimum payout: %s. Negative balances and amounts below the minimum are carried forward.', 'wp-affiliate-captain' ), Money::format( (int) Settings::get( 'min_payout_minor' ), $currency ) ) ) . '</p>';

		if ( Admin::get( 'preview' ) ) {
			$preview  = PayoutService::preview( $end, $currency );
			$eligible = 0;
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Entries', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Balance', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Result', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
			if ( ! $preview ) {
				echo '<tr><td colspan="4">' . esc_html__( 'No approved commissions in this period.', 'wp-affiliate-captain' ) . '</td></tr>';
			}
			foreach ( $preview as $row ) {
				$eligible += $row['eligible'] ? 1 : 0;
				printf(
					'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
					esc_html( $row['affiliate'] ? $row['affiliate']->name : '?' ),
					(int) $row['count'],
					esc_html( Money::format( $row['total_minor'], $currency ) ),
					$row['eligible'] ? '<strong>' . esc_html__( 'Will be paid', 'wp-affiliate-captain' ) . '</strong>' : esc_html( $row['reason'] )
				);
			}
			echo '</tbody></table>';
			if ( $eligible ) {
				Admin::form_open( 'payout_run' );
				echo '<input type="hidden" name="period_end" value="' . esc_attr( $end ) . '"><input type="hidden" name="currency" value="' . esc_attr( $currency ) . '">';
				/* translators: %d: number of payouts */
				submit_button( sprintf( _n( 'Create %d payout', 'Create %d payouts', $eligible, 'wp-affiliate-captain' ), $eligible ) );
				echo '</form>';
			}
		}

		// List.
		$status = Admin::get( 'status' );
		$per    = 50;
		list( $rows, $total ) = PayoutService::query(
			array(
				'status' => $status,
				'limit'  => $per,
				'offset' => ( Admin::paged() - 1 ) * $per,
			)
		);
		$labels = PayoutService::status_labels();
		echo '<h2>' . esc_html__( 'Payouts', 'wp-affiliate-captain' ) . '</h2>';
		Admin::form_open( 'payout_export', 'wpac-inline' );
		echo '<button class="button">' . esc_html__( 'Export CSV', 'wp-affiliate-captain' ) . '</button></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="wpac-payouts-form"><input type="hidden" name="action" value="wpac">';
		echo '<table class="wp-list-table widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.wpac-cb\').forEach(c=>c.checked=this.checked)"></td><th>' . esc_html__( 'Number', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Period end', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Bank details', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No payouts yet.', 'wp-affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $p ) {
			$aff  = AffiliateRepository::find( (int) $p->affiliate_id );
			$snap = json_decode( (string) $p->profile_snapshot, true );
			printf(
				'<tr><th class="check-column">%s</th><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s%s</td></tr>',
				in_array( $p->status, array( 'pending', 'exported' ), true ) ? '<input type="checkbox" class="wpac-cb" name="ids[]" value="' . (int) $p->id . '">' : '',
				esc_url( Admin::url( 'wpac-payouts', array( 'action' => 'receipt', 'id' => $p->id ) ) ),
				esc_html( $p->number ),
				esc_html( $aff ? $aff->name : '#' . $p->affiliate_id ),
				esc_html( mysql2date( get_option( 'date_format' ), $p->period_end ) ),
				esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ),
				esc_html( isset( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—' ),
				Admin::badge( $p->status, $labels[ $p->status ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$p->sepa_batch ? '<br><small>' . esc_html( $p->sepa_batch ) . '</small>' : ''
			);
		}
		echo '</tbody></table>';
		echo '<p class="wpac-bulk">';
		foreach ( array( 'payout_sepa' => __( 'Download SEPA XML', 'wp-affiliate-captain' ), 'payout_paid' => __( 'Mark as paid', 'wp-affiliate-captain' ) ) as $do => $label ) {
			printf(
				'<button class="button" type="submit" name="do" value="%1$s" onclick="this.form._wpac_nonce.value=\'%2$s\'">%3$s</button> ',
				esc_attr( $do ),
				esc_attr( wp_create_nonce( 'wpac_' . $do ) ),
				esc_html( $label )
			);
		}
		printf(
			'<input type="text" name="reason" placeholder="%s"> <button class="button" type="submit" name="do" value="payout_cancel" onclick="this.form._wpac_nonce.value=\'%s\'">%s</button>',
			esc_attr__( 'Cancellation reason', 'wp-affiliate-captain' ),
			esc_attr( wp_create_nonce( 'wpac_payout_cancel' ) ),
			esc_html__( 'Cancel', 'wp-affiliate-captain' )
		);
		echo '<input type="hidden" name="_wpac_nonce" value=""></p></form>';
		Admin::pager( $total, $per );
		echo '</div>';
	}

	/**
	 * Printable statement.
	 *
	 * @param int $id Payout.
	 */
	private static function render_receipt( $id ) {
		$p = PayoutService::find( $id );
		if ( ! $p ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Payout not found.', 'wp-affiliate-captain' ) . '</p></div>';
			return;
		}
		$aff    = AffiliateRepository::find( (int) $p->affiliate_id );
		$snap   = json_decode( (string) $p->profile_snapshot, true );
		$snap   = is_array( $snap ) ? $snap : array();
		$labels = ProfileRepository::tax_status_labels();

		echo '<div class="wrap wpac-receipt">';
		echo '<p class="wpac-noprint"><a href="' . esc_url( Admin::url( 'wpac-payouts' ) ) . '">&larr; ' . esc_html__( 'All payouts', 'wp-affiliate-captain' ) . '</a> · <a href="#" onclick="window.print();return false;">' . esc_html__( 'Print / save as PDF', 'wp-affiliate-captain' ) . '</a></p>';
		echo '<h1>' . esc_html__( 'Commission statement', 'wp-affiliate-captain' ) . ' ' . esc_html( $p->number ) . '</h1>';
		echo '<div class="wpac-columns"><div class="wpac-col"><h3>' . esc_html__( 'Issued by', 'wp-affiliate-captain' ) . '</h3><p>' . esc_html( Settings::get( 'debtor_name' ) ? Settings::get( 'debtor_name' ) : get_bloginfo( 'name' ) ) . '</p></div>';
		echo '<div class="wpac-col"><h3>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</h3><p>' . esc_html( ! empty( $snap['legal_name'] ) ? $snap['legal_name'] : ( $aff ? $aff->name : '' ) ) . '<br>' . nl2br( esc_html( isset( $snap['address'] ) ? $snap['address'] : '' ) ) . '<br>' . esc_html( isset( $snap['country'] ) ? $snap['country'] : '' ) . '</p>';
		echo '<p>' . esc_html__( 'Tax status', 'wp-affiliate-captain' ) . ': ' . esc_html( isset( $snap['tax_status'], $labels[ $snap['tax_status'] ] ) ? $labels[ $snap['tax_status'] ] : '—' ) . '<br>';
		echo esc_html__( 'VAT ID', 'wp-affiliate-captain' ) . ': ' . esc_html( ! empty( $snap['vat_id'] ) ? $snap['vat_id'] : '—' ) . '<br>';
		echo esc_html__( 'Bank account', 'wp-affiliate-captain' ) . ': ' . esc_html( isset( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—' ) . '</p></div></div>';
		echo '<p>' . esc_html__( 'Period until', 'wp-affiliate-captain' ) . ': ' . esc_html( mysql2date( get_option( 'date_format' ), $p->period_end ) ) . ' · ' . esc_html__( 'Created', 'wp-affiliate-captain' ) . ': ' . esc_html( Admin::date( $p->created_at ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Order', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Description', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( PayoutService::items( $id ) as $item ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( Admin::date( $item->commission_date ) ),
				esc_html( $item->source . ' #' . $item->source_order_id ),
				esc_html( 'sale' === $item->kind ? $item->description : __( 'Correction', 'wp-affiliate-captain' ) . ' (' . $item->kind . ')' ),
				esc_html( Money::format( (int) $item->amount_minor, $p->currency ) )
			);
		}
		echo '</tbody><tfoot><tr><th colspan="3">' . esc_html__( 'Total', 'wp-affiliate-captain' ) . '</th><th>' . esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ) . '</th></tr></tfoot></table>';
		echo '<p class="description">' . esc_html__( 'VAT treatment depends on the partner\'s tax status and must be confirmed by your tax advisor. This statement does not calculate VAT.', 'wp-affiliate-captain' ) . '</p>';
		echo '</div>';
	}
}
