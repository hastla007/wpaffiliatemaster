<?php
/**
 * Admin: payout runs, statements, SEPA export.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\Statement;
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
			case 'payout_rebuild':
				$new = PayoutService::rebuild( absint( Admin::post( 'payout_id' ) ) );
				if ( is_wp_error( $new ) ) {
					Admin::back( $new->get_error_message(), 'error' );
				}
				Admin::back( $new ? __( 'Payout cancelled and created again with the refund deducted.', 'wp-affiliate-captain' ) : __( 'Payout cancelled; nothing is payable any more after the refund.', 'wp-affiliate-captain' ) );
				break;

			case 'objection_resolve':
				$note = sanitize_textarea_field( Admin::post( 'resolution' ) );
				if ( '' === trim( $note ) ) {
					Admin::back( __( 'Please describe what was done (the partner is informed).', 'wp-affiliate-captain' ), 'error' );
				}
				\WPAffiliateCaptain\Payouts\Objections::resolve( absint( Admin::post( 'payout_id' ) ), $note );
				Admin::back( __( 'Objection marked as handled; the partner was informed.', 'wp-affiliate-captain' ) );
				break;

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

			case 'payout_bulk':
				$ids    = self::selected();
				$action = sanitize_key( Admin::post( 'bulk_action' ) );
				if ( ! $ids ) {
					Admin::back( __( 'Please select at least one payout.', 'wp-affiliate-captain' ), 'error' );
				}
				if ( ! in_array( $action, array( 'sepa', 'csv', 'paid', 'cancel' ), true ) ) {
					Admin::back( __( 'Please choose an action.', 'wp-affiliate-captain' ), 'error' );
				}
				if ( in_array( $action, array( 'paid', 'cancel' ), true ) && ! Admin::post( 'confirm' ) ) {
					$sum = 0;
					foreach ( $ids as $id ) {
						$p    = PayoutService::find( $id );
						$sum += $p ? (int) $p->amount_minor : 0;
					}
					Admin::back(
						/* translators: 1: number of payouts, 2: amount */
						sprintf( __( 'Please tick the confirmation box: this affects %1$d payout(s) totalling %2$s.', 'wp-affiliate-captain' ), count( $ids ), html_entity_decode( Money::format( $sum, (string) Settings::get( 'currency' ) ), ENT_QUOTES, 'UTF-8' ) ),
						'error'
					);
				}
				if ( 'csv' === $action ) {
					// Bank transfer list for any currency (foreign banks, payment providers); afterwards
					// "Mark as paid" when the money went out.
					if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
						Admin::back( __( 'The SEPA file contains bank details. You need the permission to view tax and bank data.', 'wp-affiliate-captain' ), 'error' );
					}
					$result = PayoutService::transfer_csv( $ids, (bool) Admin::post( 'reexport' ) );
					if ( is_wp_error( $result ) ) {
						Admin::back( $result->get_error_message(), 'error' );
					}
					\WPAffiliateCaptain\Core\Csv::download( sanitize_file_name( $result[0] ) . '.csv', array( 'number', 'account_holder', 'iban', 'bic', 'amount', 'currency', 'reference' ), $result[1] );
				}
				if ( 'sepa' === $action ) {
					if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
						Admin::back( __( 'The SEPA file contains bank details. You need the permission to view tax and bank data.', 'wp-affiliate-captain' ), 'error' );
					}
					$result = PayoutService::sepa_export( $ids, (bool) Admin::post( 'reexport' ) );
					if ( is_wp_error( $result ) ) {
						Admin::back( $result->get_error_message(), 'error' );
					}
					nocache_headers();
					header( 'Content-Type: application/xml; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $result[0] ) . '"' );
					echo $result[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					exit;
				}
				if ( 'paid' === $action ) {
					$errors = 0;
					foreach ( $ids as $id ) {
						if ( is_wp_error( PayoutService::mark_paid( $id ) ) ) {
							++$errors;
						}
					}
					Admin::back( $errors ? __( 'Some payouts could not be marked as paid.', 'wp-affiliate-captain' ) : __( 'Payouts marked as paid.', 'wp-affiliate-captain' ), $errors ? 'error' : 'success' );
				}
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				foreach ( $ids as $id ) {
					$r = PayoutService::cancel( $id, $reason, (bool) Admin::post( 'confirm_not_sent' ) );
					if ( is_wp_error( $r ) ) {
						Admin::back( $r->get_error_message(), 'error' );
					}
				}
				Admin::back( __( 'Payouts cancelled. The commissions are available for the next run.', 'wp-affiliate-captain' ) );
				break;

			case 'payout_request_process':
				$r = PayoutRequests::process( absint( Admin::post( 'id' ) ) );
				if ( is_wp_error( $r ) ) {
					Admin::back( $r->get_error_message(), 'error' );
				}
				Admin::back( __( 'Payout created from the request.', 'wp-affiliate-captain' ) );
				break;

			case 'payout_request_reject':
				$r = PayoutRequests::reject( absint( Admin::post( 'id' ) ), sanitize_text_field( Admin::post( 'reason' ) ) );
				if ( is_wp_error( $r ) ) {
					Admin::back( $r->get_error_message(), 'error' );
				}
				Admin::back( __( 'Request rejected.', 'wp-affiliate-captain' ) );
				break;

			case 'statement_download':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in dispatch().
				$p = PayoutService::find( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
				if ( ! $p || ! current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PAYOUTS ) ) {
					wp_die( esc_html__( 'Not allowed.', 'wp-affiliate-captain' ), 403 );
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$storno = ! empty( $_GET['storno'] );
				\WPAffiliateCaptain\Core\Audit::log( $storno ? 'statement.storno_downloaded' : 'statement.downloaded', 'payout', (int) $p->id );
				if ( $storno ) {
					Statement::send_storno( $p );
				}
				Statement::send( $p );
				break;

			case 'payout_export':
				list( $rows ) = PayoutService::query( array( 'limit' => 100000 ) );
				$out          = array();
				foreach ( $rows as $p ) {
					$aff   = AffiliateRepository::find( (int) $p->affiliate_id );
					$snap  = json_decode( (string) $p->profile_snapshot, true );
					$tax   = current_user_can( Capabilities::VIEW_TAX_DATA );
					$out[] = array( $p->number, $p->affiliate_id, $aff ? $aff->name : '', $p->period_end, $p->currency, Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->status, $tax && isset( $snap['tax_status'] ) ? $snap['tax_status'] : '', $tax && isset( $snap['vat_id'] ) ? $snap['vat_id'] : '', $p->created_at, $p->paid_at );
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
		$claims = \WPAffiliateCaptain\Commissions\Dunning::candidates();
		if ( $claims ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Negative balances of ended partnerships (to claim back):', 'wp-affiliate-captain' ) . '</strong></p><ul>';
			foreach ( array_slice( $claims, 0, 20 ) as $cl ) {
				if ( ! $cl['affiliate'] ) {
					continue;
				}
				/* translators: 1: partner, 2: amount, 3: reminders sent */
				echo '<li><a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $cl['affiliate']->id, 'tab' => 'balance' ) ) ) . '">' . esc_html( $cl['affiliate']->name ) . '</a>: ' . esc_html( sprintf( __( '%1$s (%2$d of 3 reminders sent)', 'wp-affiliate-captain' ), Money::format( $cl['amount'], $cl['currency'] ), count( $cl['history'] ) ) ) . '</li>';
			}
			echo '</ul></div>';
		}
		foreach ( PayoutService::clawbacks() as $pid => $orders ) {
			$cp = PayoutService::find( (int) $pid );
			/* translators: 1: payout number, 2: orders */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Payout %1$s (not exported yet) contains commissions that were refunded afterwards (%2$s). It would pay too much now; the difference is only deducted in the next payout.', 'wp-affiliate-captain' ), $cp ? $cp->number : '#' . (int) $pid, implode( ', ', array_slice( (array) $orders, 0, 10 ) ) ) ) . '</p>';
			Admin::form_open( 'payout_rebuild' );
			echo '<input type="hidden" name="payout_id" value="' . (int) $pid . '"><p><button class="button button-primary" data-wpac-confirm="' . esc_attr__( 'Cancel this payout (cancellation document) and create it again with the refund deducted?', 'wp-affiliate-captain' ) . '">' . esc_html__( 'Cancel and create again', 'wp-affiliate-captain' ) . '</button></p></form></div>';
		}
		foreach ( \WPAffiliateCaptain\Payouts\Objections::open() as $pid => $o ) {
			$op  = PayoutService::find( (int) $pid );
			$oaf = \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( (int) $o['affiliate_id'] );
			/* translators: 1: partner, 2: number, 3: date */
			echo '<div class="notice notice-warning"><p><strong>' . esc_html( sprintf( __( '%1$s objected to credit note %2$s on %3$s:', 'wp-affiliate-captain' ), $oaf ? $oaf->name : '#' . (int) $o['affiliate_id'], $op ? $op->number : '#' . (int) $pid, Admin::date( $o['at'], true ) ) ) . '</strong><br>' . nl2br( esc_html( $o['reason'] ) ) . '</p><p class="description">' . esc_html__( 'The credit note no longer counts as an invoice. Cancel the payout (cancellation document) and create a corrected one, or explain why it is correct.', 'wp-affiliate-captain' ) . '</p>';
			Admin::form_open( 'objection_resolve' );
			echo '<input type="hidden" name="payout_id" value="' . (int) $pid . '"><p><textarea name="resolution" rows="2" class="large-text" placeholder="' . esc_attr__( 'What was done (sent to the partner)', 'wp-affiliate-captain' ) . '"></textarea></p><p><button class="button">' . esc_html__( 'Mark as handled', 'wp-affiliate-captain' ) . '</button></p></form></div>';
		}

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
				submit_button( sprintf( _n( 'Create %d payout', 'Create %d payouts', $eligible, 'wp-affiliate-captain' ), $eligible ), 'primary', 'submit', true, array( 'data-wpac-confirm' => __( 'Create the payouts now? Each one gets a numbered credit note that cannot be changed afterwards (only cancelled).', 'wp-affiliate-captain' ) ) );
				echo '</form>';
			}
		}

		// Requests.
		$requests = PayoutRequests::query( 'requested' );
		if ( $requests ) {
			echo '<h2>' . esc_html__( 'Open payout requests', 'wp-affiliate-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Requested', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Available now', 'wp-affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $requests as $r ) {
				$aff = AffiliateRepository::find( (int) $r->affiliate_id );
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>', esc_html( Admin::date( $r->created_at, true ) ), esc_html( $aff ? $aff->name : '#' . $r->affiliate_id ), esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ), esc_html( Money::format( PayoutRequests::available( (int) $r->affiliate_id ), $r->currency ) ) );
				Admin::form_open( 'payout_request_process', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><button class="button button-primary button-small">' . esc_html__( 'Create payout', 'wp-affiliate-captain' ) . '</button></form> ';
				Admin::form_open( 'payout_request_reject', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><input type="text" name="reason" size="14" required aria-label="' . esc_attr__( 'Reason for the partner', 'wp-affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Reason', 'wp-affiliate-captain' ) . '"> <button class="button button-small" data-wpac-confirm="' . esc_attr__( 'Reject this payout request? The partner is notified with the reason.', 'wp-affiliate-captain' ) . '">' . esc_html__( 'Reject', 'wp-affiliate-captain' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
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

		Admin::form_open( 'payout_bulk' );
		echo '<table class="wp-list-table widefat striped"><thead><tr><td class="check-column"><input type="checkbox" class="wpac-cb-all" aria-label="' . esc_attr__( 'Select all', 'wp-affiliate-captain' ) . '"></td><th>' . esc_html_x( 'Number', 'document number', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Period end', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Bank details', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No payouts yet.', 'wp-affiliate-captain' ) . '</td></tr>';
		}
		foreach ( $rows as $p ) {
			$aff  = AffiliateRepository::find( (int) $p->affiliate_id );
			$snap = json_decode( (string) $p->profile_snapshot, true );
			printf(
				'<tr><th class="check-column">%s</th><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s%s</td></tr>',
				in_array( $p->status, array( 'pending', 'exported' ), true ) ? '<input type="checkbox" class="wpac-cb" name="ids[]" value="' . (int) $p->id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: document number */ __( 'Select %s', 'wp-affiliate-captain' ), $p->number ) ) . '">' : '',
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
		echo '<fieldset class="wpac-bulk"><legend>' . esc_html__( 'Bulk action for selected payouts', 'wp-affiliate-captain' ) . '</legend>';
		echo '<p><label for="wpac-bulk-action" class="screen-reader-text">' . esc_html__( 'Action', 'wp-affiliate-captain' ) . '</label><select name="bulk_action" id="wpac-bulk-action" required>';
		echo '<option value="">' . esc_html__( '— Choose action —', 'wp-affiliate-captain' ) . '</option>';
		echo '<option value="sepa">' . esc_html__( 'Download SEPA XML', 'wp-affiliate-captain' ) . '</option>';
		echo '<option value="csv">' . esc_html__( 'Download transfer list (CSV, any currency)', 'wp-affiliate-captain' ) . '</option>';
		echo '<option value="paid">' . esc_html__( 'Mark as paid', 'wp-affiliate-captain' ) . '</option>';
		echo '<option value="cancel">' . esc_html__( 'Cancel', 'wp-affiliate-captain' ) . '</option>';
		echo '</select> <button class="button" type="submit" data-wpac-confirm="' . esc_attr__( 'Run this action for the selected payouts?', 'wp-affiliate-captain' ) . '">' . esc_html__( 'Apply', 'wp-affiliate-captain' ) . '</button></p>';
		echo '<p><label for="wpac-cancel-reason">' . esc_html__( 'Cancellation reason (required for "Cancel")', 'wp-affiliate-captain' ) . '</label><br><input type="text" name="reason" id="wpac-cancel-reason" class="regular-text"></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'I confirm this action for the selected payouts (required for "Mark as paid" and "Cancel").', 'wp-affiliate-captain' ) . '</label>';
		echo '<br><label><input type="checkbox" name="reexport" value="1"> ' . esc_html__( 'Export again (only if the bank did not process the previous file)', 'wp-affiliate-captain' ) . '</label>';
		echo '<br><label><input type="checkbox" name="confirm_not_sent" value="1"> ' . esc_html__( 'Confirm: the bank did NOT execute the exported transfer (required to cancel exported payouts)', 'wp-affiliate-captain' ) . '</label></p></fieldset></form>';
		Admin::pager( $total, $per );
		echo '</div>';
	}

	/**
	 * Admin download URL of a statement.
	 *
	 * @param int $id Payout.
	 * @return string
	 */
	public static function statement_url( $id, $storno = false ) {
		return add_query_arg(
			array(
				'action'      => 'wpac',
				'do'          => 'statement_download',
				'id'          => (int) $id,
				'storno'      => $storno ? 1 : 0,
				'_wpac_nonce' => wp_create_nonce( 'wpac_statement_download' ),
			),
			admin_url( 'admin-post.php' )
		);
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
		if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			// Address, VAT ID and tax status are restricted to wpac_view_tax_data.
			$snap = array_intersect_key( $snap, array( 'iban_masked' => 1 ) );
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Tax and address details are hidden (missing permission wpac_view_tax_data).', 'wp-affiliate-captain' ) . '</p></div>';
		}

		echo '<div class="wrap wpac-receipt">';
		echo '<p class="wpac-noprint"><a href="' . esc_url( Admin::url( 'wpac-payouts' ) ) . '">&larr; ' . esc_html__( 'All payouts', 'wp-affiliate-captain' ) . '</a>';
		if ( $p->statement_file && current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			echo ' · <a class="button button-primary" href="' . esc_url( self::statement_url( (int) $p->id ) ) . '">' . esc_html__( 'Download PDF statement', 'wp-affiliate-captain' ) . '</a> <small>SHA-256 ' . esc_html( substr( $p->statement_hash, 0, 16 ) ) . '…</small>';
			if ( $p->storno_file ) {
				/* translators: %s: cancellation number */
				echo ' · <a class="button" href="' . esc_url( self::statement_url( (int) $p->id, true ) ) . '">' . esc_html( sprintf( __( 'Download cancellation %s', 'wp-affiliate-captain' ), $p->storno_number ) ) . '</a>';
			}
		} elseif ( $p->statement_deleted_at ) {
			echo ' · ' . esc_html__( 'Statement deleted after the retention period.', 'wp-affiliate-captain' );
		}
		echo '</p>';
		echo '<h1>' . esc_html__( 'Commission statement', 'wp-affiliate-captain' ) . ' ' . esc_html( $p->number ) . '</h1>';
		echo '<div class="wpac-columns"><div class="wpac-col"><h3>' . esc_html__( 'Issued by', 'wp-affiliate-captain' ) . '</h3><p>' . esc_html( Settings::get( 'debtor_name' ) ? Settings::get( 'debtor_name' ) : get_bloginfo( 'name' ) ) . '</p></div>';
		echo '<div class="wpac-col"><h3>' . esc_html__( 'Partner', 'wp-affiliate-captain' ) . '</h3><p>' . esc_html( ! empty( $snap['legal_name'] ) ? $snap['legal_name'] : ( $aff ? $aff->name : '' ) ) . '<br>' . nl2br( esc_html( isset( $snap['address'] ) ? $snap['address'] : '' ) ) . '<br>' . esc_html( isset( $snap['country'] ) ? $snap['country'] : '' ) . '</p>';
		echo '<p>' . esc_html__( 'Tax status', 'wp-affiliate-captain' ) . ': ' . esc_html( isset( $snap['tax_status'], $labels[ $snap['tax_status'] ] ) ? $labels[ $snap['tax_status'] ] : '—' ) . '<br>';
		echo esc_html__( 'VAT ID', 'wp-affiliate-captain' ) . ': ' . esc_html( ! empty( $snap['vat_id'] ) ? $snap['vat_id'] : '—' ) . '<br>';
		if ( ! empty( $snap['tax_number'] ) ) {
			echo esc_html__( 'Tax number', 'wp-affiliate-captain' ) . ': ' . esc_html( $snap['tax_number'] ) . '<br>';
		}
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
		$net = (int) $p->net_minor ? (int) $p->net_minor : (int) $p->amount_minor - (int) $p->vat_minor;
		echo '</tbody><tfoot>';
		echo '<tr><th colspan="3">' . esc_html__( 'Net amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html( Money::format( $net, $p->currency ) ) . '</th></tr>';
		/* translators: %s: VAT rate */
		echo '<tr><th colspan="3">' . esc_html( (int) $p->vat_minor ? sprintf( __( 'plus VAT %s %%', 'wp-affiliate-captain' ), $p->vat_rate ) : __( 'VAT', 'wp-affiliate-captain' ) ) . '</th><th>' . esc_html( Money::format( (int) $p->vat_minor, $p->currency ) ) . '</th></tr>';
		echo '<tr><th colspan="3">' . esc_html__( 'Total payout', 'wp-affiliate-captain' ) . '</th><th>' . esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ) . '</th></tr>';
		echo '</tfoot></table>';
		if ( $p->vat_mode ) {
			echo '<p class="description">' . esc_html( Statement::vat_note( (string) $p->vat_mode ) ) . '</p>';
		} else {
			// Payouts from before VAT handling: no treatment was stored.
			echo '<p class="description">' . esc_html__( 'VAT treatment depends on the partner\'s tax status and must be confirmed by your tax advisor. This statement does not calculate VAT.', 'wp-affiliate-captain' ) . '</p>';
		}
		echo '</div>';
	}
}
