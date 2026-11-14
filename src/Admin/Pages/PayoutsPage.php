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
				Admin::back( $new ? __( 'Payout cancelled and created again with the refund deducted.', 'affiliate-captain' ) : __( 'Payout cancelled; nothing is payable any more after the refund.', 'affiliate-captain' ) );
				break;

			case 'objection_resolve':
				$note = sanitize_textarea_field( Admin::post( 'resolution' ) );
				if ( '' === trim( $note ) ) {
					Admin::back( __( 'Please describe what was done (the partner is informed).', 'affiliate-captain' ), 'error' );
				}
				if ( ! \WPAffiliateCaptain\Payouts\Objections::resolve( absint( Admin::post( 'payout_id' ) ), $note ) ) {
					Admin::back( __( 'This objection was already handled, no longer exists or could not be saved; nothing was sent.', 'affiliate-captain' ), 'error' );
				}
				Admin::back( __( 'Objection marked as handled; the partner was informed.', 'affiliate-captain' ) );
				break;

			case 'payout_run':
				$end      = sanitize_text_field( Admin::post( 'period_end' ) );
				$currency = strtoupper( sanitize_key( Admin::post( 'currency' ) ) );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || 3 !== strlen( $currency ) ) {
					Admin::back( __( 'Please choose a period end and currency.', 'affiliate-captain' ), 'error' );
				}
				$run     = PayoutService::create_run( $end, $currency );
				$created = $run[0];
				$details = isset( $run[2] ) ? $run[2] : array();
				$counts  = array(
					'carried' => 0,
					'blocked' => 0,
					'failed'  => 0,
				);
				$lines   = array();
				foreach ( $details as $d ) {
					++$counts[ $d[2] ];
					if ( 'carried' !== $d[2] ) {
						$lines[] = $d[1] . ': ' . $d[3];
					}
				}
				/* translators: %d: number of payouts */
				$message = sprintf( _n( '%d payout created.', '%d payouts created.', count( $created ), 'affiliate-captain' ), count( $created ) );
				if ( $created ) {
					$message .= ' ' . __( 'The credit notes are written in the background within a few minutes (missing ones at the latest when you export or mark the payouts as paid).', 'affiliate-captain' );
				}
				if ( $counts['carried'] ) {
					/* translators: %d: number of partners */
					$message .= ' ' . sprintf( _n( '%d partner carried forward (below the minimum or negative balance).', '%d partners carried forward (below the minimum or negative balance).', $counts['carried'], 'affiliate-captain' ), $counts['carried'] );
				}
				if ( $counts['blocked'] ) {
					/* translators: %d: number of partners */
					$message .= ' ' . sprintf( _n( '%d partner could not be paid out (blocked):', '%d partners could not be paid out (blocked):', $counts['blocked'], 'affiliate-captain' ), $counts['blocked'] );
				}
				if ( $counts['failed'] ) {
					/* translators: %d: number of partners */
					$message .= ' ' . sprintf( _n( '%d payout failed:', '%d payouts failed:', $counts['failed'], 'affiliate-captain' ), $counts['failed'] );
				}
				Admin::back( $message, $counts['failed'] ? 'error' : ( $counts['blocked'] ? 'warning' : 'success' ), Admin::url( 'wpac-payouts' ), $lines );
				break;

			case 'payout_bulk':
				$ids    = self::selected();
				$action = sanitize_key( Admin::post( 'bulk_action' ) );
				$reason = sanitize_text_field( Admin::post( 'reason' ) );
				// Keep the selection, action and reason when something is missing (no retyping).
				$keep = static function () use ( $ids, $action, $reason ) {
					Admin::keep_form(
						'payout_bulk',
						array(
							'ids'    => $ids,
							'action' => $action,
							'reason' => $reason,
						)
					);
				};
				if ( ! $ids ) {
					Admin::back( __( 'Please select at least one payout.', 'affiliate-captain' ), 'error' );
				}
				if ( ! in_array( $action, array( 'sepa', 'csv', 'paypal', 'paid', 'cancel' ), true ) ) {
					$keep();
					Admin::back( __( 'Please choose an action.', 'affiliate-captain' ), 'error' );
				}
				if ( 'cancel' === $action && '' === trim( $reason ) ) {
					$keep();
					Admin::back( __( 'Please enter a cancellation reason (it is printed on the cancellation document).', 'affiliate-captain' ), 'error' );
				}
				if ( in_array( $action, array( 'paid', 'cancel' ), true ) && ! Admin::post( 'confirm' ) ) {
					$keep();
					Admin::back(
						sprintf(
							/* translators: 1: number of payouts, 2: amount(s) */
							_n( 'Please tick the confirmation box: this affects %1$d payout totalling %2$s. Your selection was kept.', 'Please tick the confirmation box: this affects %1$d payouts totalling %2$s. Your selection was kept.', count( $ids ), 'affiliate-captain' ),
							count( $ids ),
							html_entity_decode( self::totals_text( $ids ), ENT_QUOTES, 'UTF-8' )
						),
						'error'
					);
				}
				if ( 'csv' === $action ) {
					// Bank transfer list for any currency (foreign banks, payment providers); afterwards
					// "Mark as paid" when the money went out.
					if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
						Admin::back( __( 'The transfer list contains bank details. You need the permission to view tax and bank data.', 'affiliate-captain' ), 'error' );
					}
					$result = PayoutService::transfer_csv( $ids, (bool) Admin::post( 'reexport' ) );
					if ( is_wp_error( $result ) ) {
						$keep();
						Admin::back( $result->get_error_message(), 'error' );
					}
					/**
					 * Header of the transfer list; add-ons that append columns via wpac_transfer_rows
					 * append their column names here.
					 *
					 * @since contract 1.2.0
					 * @param string[] $header Column names.
					 */
					$header = (array) apply_filters( 'wpac_transfer_header', self::transfer_header() );
					/* translators: 1: number of payouts, 2: file name */
					self::download_notice( sprintf( _n( 'Transfer list with %1$d payout downloaded (%2$s). The payout is now marked as exported; once the money went out, select it and choose "Mark as paid".', 'Transfer list with %1$d payouts downloaded (%2$s). They are now marked as exported; once the money went out, select them and choose "Mark as paid".', count( $result[1] ), 'affiliate-captain' ), count( $result[1] ), $result[0] . '.csv' ) );
					\WPAffiliateCaptain\Core\Csv::download( sanitize_file_name( $result[0] ) . '.csv', $header, self::transfer_rows_for_excel( $result[1] ) );
				}
				if ( 'paypal' === $action ) {
					// PayPal Payouts file (upload in the PayPal business account); afterwards "Mark as paid".
					if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
						Admin::back( __( 'The PayPal file contains payment details. You need the permission to view tax and bank data.', 'affiliate-captain' ), 'error' );
					}
					$result = PayoutService::paypal_csv( $ids, (bool) Admin::post( 'reexport' ) );
					if ( is_wp_error( $result ) ) {
						$keep();
						Admin::back( $result->get_error_message(), 'error' );
					}
					/* translators: 1: number of payouts, 2: file name */
					$message = sprintf( _n( 'PayPal payouts file with %1$d payout downloaded (%2$s). Upload it in your PayPal business account (Pay & get paid → Payouts); once PayPal executed it, mark the payout as paid.', 'PayPal payouts file with %1$d payouts downloaded (%2$s). Upload it in your PayPal business account (Pay & get paid → Payouts); once PayPal executed them, mark the payouts as paid.', count( $result[1] ), 'affiliate-captain' ), count( $result[1] ), $result[0] . '.csv' );
					if ( $result[2] ) {
						/* translators: %d: number of payouts */
						$message .= ' ' . sprintf( _n( '%d selected payout has no PayPal address and was left out (it stays as it is):', '%d selected payouts have no PayPal address and were left out (they stay as they are):', count( $result[2] ), 'affiliate-captain' ), count( $result[2] ) );
					}
					self::download_notice( $message, $result[2] ? 'warning' : 'success', $result[2] );
					nocache_headers();
					header( 'Content-Type: text/csv; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $result[0] ) . '.csv"' );
					echo PayoutService::paypal_csv_text( $result[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download.
					exit;
				}
				if ( 'sepa' === $action ) {
					if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
						Admin::back( __( 'The SEPA file contains bank details. You need the permission to view tax and bank data.', 'affiliate-captain' ), 'error' );
					}
					$result = PayoutService::sepa_export( $ids, (bool) Admin::post( 'reexport' ) );
					if ( is_wp_error( $result ) ) {
						$keep();
						Admin::back( $result->get_error_message(), 'error' );
					}
					/* translators: 1: number of payouts, 2: file name */
					$message = sprintf( _n( 'SEPA file with %1$d payout downloaded (%2$s). Import it in your online banking; once the bank executed the transfer, mark the payout as paid.', 'SEPA file with %1$d payouts downloaded (%2$s). Import it in your online banking; once the bank executed the transfers, mark the payouts as paid.', count( $result[3] ), 'affiliate-captain' ), count( $result[3] ), $result[0] );
					if ( $result[2] ) {
						/* translators: %d: number of payouts */
						$message .= ' ' . sprintf( _n( '%d selected payout uses another payment method (e.g. PayPal) and is not in the file: pay it by the transfer list or PayPal file and then mark it as paid.', '%d selected payouts use another payment method (e.g. PayPal) and are not in the file: pay them by the transfer list or PayPal file and then mark them as paid.', $result[2], 'affiliate-captain' ), $result[2] );
					}
					self::download_notice( $message, $result[2] ? 'warning' : 'success' );
					nocache_headers();
					header( 'Content-Type: application/xml; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $result[0] ) . '"' );
					echo $result[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					exit;
				}
				// Mark as paid / cancel: every payout on its own; report what worked and what did not.
				$done   = 0;
				$failed = array();
				foreach ( $ids as $id ) {
					$p = PayoutService::find( $id );
					$r = 'paid' === $action ? PayoutService::mark_paid( $id ) : PayoutService::cancel( $id, $reason, (bool) Admin::post( 'confirm_not_sent' ) );
					if ( is_wp_error( $r ) ) {
						$failed[] = ( $p ? $p->number : '#' . (int) $id ) . ': ' . $r->get_error_message();
					} else {
						++$done;
					}
				}
				if ( 'paid' === $action ) {
					/* translators: %d: number of payouts */
					$message = sprintf( _n( '%d payout marked as paid.', '%d payouts marked as paid.', $done, 'affiliate-captain' ), $done );
					if ( $failed ) {
						/* translators: %d: number of payouts */
						$message .= ' ' . sprintf( _n( '%d payout could not be marked as paid:', '%d payouts could not be marked as paid:', count( $failed ), 'affiliate-captain' ), count( $failed ) );
					}
				} else {
					/* translators: %d: number of payouts */
					$message = sprintf( _n( '%d payout cancelled; its commissions are available for the next run.', '%d payouts cancelled; their commissions are available for the next run.', $done, 'affiliate-captain' ), $done );
					if ( $failed ) {
						/* translators: %d: number of payouts */
						$message .= ' ' . sprintf( _n( '%d payout could not be cancelled:', '%d payouts could not be cancelled:', count( $failed ), 'affiliate-captain' ), count( $failed ) );
					}
				}
				Admin::back( $message, $failed ? ( $done ? 'warning' : 'error' ) : 'success', '', $failed );
				break;

			case 'payout_request_process':
				$r = PayoutRequests::process( absint( Admin::post( 'id' ) ) );
				if ( is_wp_error( $r ) ) {
					Admin::back( $r->get_error_message(), 'error' );
				}
				Admin::back( __( 'Payout created from the request.', 'affiliate-captain' ) );
				break;

			case 'payout_request_reject':
				$r = PayoutRequests::reject( absint( Admin::post( 'id' ) ), sanitize_text_field( Admin::post( 'reason' ) ) );
				if ( is_wp_error( $r ) ) {
					Admin::back( $r->get_error_message(), 'error' );
				}
				Admin::back( __( 'Request rejected.', 'affiliate-captain' ) );
				break;

			case 'statement_download':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in dispatch().
				$p = PayoutService::find( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
				// Credit notes are tax documents: reading them needs the tax-data permission only (e.g. a
				// read-only tax advisor account), not the right to run payouts.
				if ( ! $p || ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
					wp_die( esc_html__( 'Not allowed.', 'affiliate-captain' ), 403 );
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
				AffiliateRepository::find_many( wp_list_pluck( $rows, 'affiliate_id' ) );
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
	 * Sum of payouts per currency ("1.200,00 EUR + 80,00 USD"): amounts of different currencies
	 * are never added up.
	 *
	 * @param int[] $ids Payout IDs.
	 * @return string
	 */
	public static function totals_text( array $ids ) {
		$sums = array();
		foreach ( $ids as $id ) {
			$p = PayoutService::find( (int) $id );
			if ( $p ) {
				$cur          = strtoupper( (string) $p->currency );
				$sums[ $cur ] = ( isset( $sums[ $cur ] ) ? $sums[ $cur ] : 0 ) + (int) $p->amount_minor;
			}
		}
		if ( ! $sums ) {
			return Money::format( 0, (string) Settings::get( 'currency' ) );
		}
		$parts = array();
		foreach ( $sums as $cur => $sum ) {
			$parts[] = Money::format( $sum, $cur );
		}
		return implode( ' + ', $parts );
	}

	/**
	 * Column names of the transfer list (translated: it is opened in a spreadsheet).
	 *
	 * @return string[]
	 */
	public static function transfer_header() {
		return array(
			_x( 'Number', 'document number', 'affiliate-captain' ),
			__( 'Account holder', 'affiliate-captain' ),
			__( 'IBAN', 'affiliate-captain' ),
			__( 'BIC', 'affiliate-captain' ),
			__( 'Amount', 'affiliate-captain' ),
			__( 'Currency', 'affiliate-captain' ),
			__( 'Reference', 'affiliate-captain' ),
			__( 'Payout method', 'affiliate-captain' ),
			__( 'Payment details', 'affiliate-captain' ),
		);
	}

	/**
	 * Transfer list rows as a spreadsheet with ";" separator expects them: decimal comma and the
	 * readable payout method instead of the internal code. Extra columns of add-ons stay as they are.
	 *
	 * @param array $rows Rows from PayoutService::transfer_csv() (after wpac_transfer_rows).
	 * @return array
	 */
	public static function transfer_rows_for_excel( array $rows ) {
		$methods = ProfileRepository::method_labels();
		$short   = array(
			ProfileRepository::METHOD_SEPA   => __( 'Bank transfer', 'affiliate-captain' ),
			ProfileRepository::METHOD_MANUAL => __( 'Other payment method', 'affiliate-captain' ),
		);
		foreach ( $rows as $i => $row ) {
			$row = array_values( (array) $row );
			if ( isset( $row[4] ) && preg_match( '/^-?\d+(\.\d+)?$/', (string) $row[4] ) ) {
				$row[4] = str_replace( '.', ',', (string) $row[4] );
			}
			if ( isset( $row[7] ) && isset( $methods[ $row[7] ] ) ) {
				$row[7] = $short[ $row[7] ];
			}
			$rows[ $i ] = $row;
		}
		return $rows;
	}

	/**
	 * A file download ends the request (no redirect): the message is shown when the payouts page
	 * is loaded next – the page reloads itself after the download (see render()).
	 *
	 * @param string   $message Message.
	 * @param string   $type    success|warning.
	 * @param string[] $details Details.
	 */
	private static function download_notice( $message, $type = 'success', array $details = array() ) {
		set_transient(
			'wpac_notice_' . get_current_user_id(),
			array(
				'message' => (string) $message,
				'type'    => $type,
				'details' => array_values( array_map( 'strval', $details ) ),
			),
			5 * MINUTE_IN_SECONDS
		);
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

		echo '<div class="wrap"><h1>' . esc_html__( 'Payouts', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		// Users who may only see tax data (e.g. the tax advisor role) get the list read-only.
		$manage = current_user_can( Capabilities::MANAGE_PAYOUTS );
		if ( $manage ) {
			self::render_tasks( $currency, $end );
		}
		self::render_list( $manage );
	}

	/**
	 * Open tasks, new payout run and payout requests (payout managers only).
	 *
	 * @param string $currency Currency of the run.
	 * @param string $end      Period end of the run.
	 */
	private static function render_tasks( $currency, $end ) {
		$claims = \WPAffiliateCaptain\Commissions\Dunning::candidates();
		if ( $claims ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Negative balances of ended partnerships (to claim back):', 'affiliate-captain' ) . '</strong></p><ul>';
			foreach ( array_slice( $claims, 0, 20 ) as $cl ) {
				if ( ! $cl['affiliate'] ) {
					continue;
				}
				/* translators: 1: partner, 2: amount, 3: reminders sent */
				echo '<li><a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $cl['affiliate']->id, 'tab' => 'balance' ) ) ) . '">' . esc_html( $cl['affiliate']->name ) . '</a>: ' . esc_html( sprintf( __( '%1$s (%2$d of 3 reminders sent)', 'affiliate-captain' ), Money::format( $cl['amount'], $cl['currency'] ), count( $cl['history'] ) ) ) . '</li>';
			}
			echo '</ul></div>';
		}
		$small = PayoutService::small_business_warnings();
		if ( $small ) {
			// Our credit notes alone exceed the small business limit: the partner is very likely
			// liable for VAT – ask for the tax status before the next credit note.
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Small business partners above the small business limit (§19 UStG: 25,000 € in the previous year or 100,000 € in the current year):', 'affiliate-captain' ) . '</strong></p><ul>';
			AffiliateRepository::find_many( wp_list_pluck( $small, 0 ) );
			foreach ( array_slice( $small, 0, 20 ) as $sb ) {
				$sba = AffiliateRepository::find( (int) $sb[0] );
				/* translators: 1: partner, 2: amount previous year, 3: amount current year */
				echo '<li><a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $sb[0], 'tab' => 'payout' ) ) ) . '">' . esc_html( $sba ? $sba->name : '#' . (int) $sb[0] ) . '</a>: ' . esc_html( sprintf( __( 'previous year %1$s, this year %2$s', 'affiliate-captain' ), Money::format( $sb[2], $sb[1] ), Money::format( $sb[3], $sb[1] ) ) ) . '</li>';
			}
			echo '</ul><p class="description">' . esc_html__( 'The limit counts all turnover of the partner. Ask the partner to check the tax status; a VAT-registered partner gets credit notes with VAT from the change on.', 'affiliate-captain' ) . '</p></div>';
		}
		foreach ( Statement::storno_failures() as $spid => $sfail ) {
			$sp = PayoutService::find( (int) $spid );
			if ( $sp && '' === (string) $sp->storno_file ) {
				/* translators: %s: cancellation number */
				echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'The cancellation document %s could not be stored yet. It is retried automatically; check that the statement folder is writable.', 'affiliate-captain' ), (string) $sp->storno_number ) ) . '</p></div>';
			}
		}
		foreach ( PayoutService::clawbacks() as $pid => $orders ) {
			$cp = PayoutService::find( (int) $pid );
			/* translators: 1: payout number, 2: orders */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Payout %1$s (not exported yet) contains commissions that were refunded afterwards (%2$s). It would pay too much now; the difference is only deducted in the next payout.', 'affiliate-captain' ), $cp ? $cp->number : '#' . (int) $pid, implode( ', ', array_slice( (array) $orders, 0, 10 ) ) ) ) . '</p>';
			Admin::form_open( 'payout_rebuild' );
			echo '<input type="hidden" name="payout_id" value="' . (int) $pid . '"><p><button class="button button-primary" data-wpac-confirm="' . esc_attr__( 'Cancel this payout (cancellation document) and create it again with the refund deducted?', 'affiliate-captain' ) . '">' . esc_html__( 'Cancel and create again', 'affiliate-captain' ) . '</button></p></form></div>';
		}
		$objections = \WPAffiliateCaptain\Payouts\Objections::open();
		AffiliateRepository::find_many( wp_list_pluck( $objections, 'affiliate_id' ) );
		foreach ( $objections as $pid => $o ) {
			$op  = PayoutService::find( (int) $pid );
			$oaf = AffiliateRepository::find( (int) $o['affiliate_id'] );
			/* translators: 1: partner, 2: number, 3: date */
			echo '<div class="notice notice-warning"><p><strong>' . esc_html( sprintf( __( '%1$s objected to credit note %2$s on %3$s:', 'affiliate-captain' ), $oaf ? $oaf->name : '#' . (int) $o['affiliate_id'], $op ? $op->number : '#' . (int) $pid, Admin::date( $o['at'], true ) ) ) . '</strong><br>' . nl2br( esc_html( $o['reason'] ) ) . '</p><p class="description">' . esc_html( \WPAffiliateCaptain\Payouts\Objections::guidance( $op ) ) . '</p>';
			if ( $op && in_array( $op->status, array( 'pending', 'exported' ), true ) ) {
				// Direct cancellation of the objected payout (same checks as the bulk action).
				Admin::form_open( 'payout_bulk' );
				echo '<input type="hidden" name="ids[]" value="' . (int) $pid . '"><input type="hidden" name="bulk_action" value="cancel"><input type="hidden" name="confirm" value="1">';
				echo '<p><label for="wpac-obj-cancel-' . (int) $pid . '">' . esc_html__( 'Cancellation reason', 'affiliate-captain' ) . '</label><br><input type="text" required class="regular-text" id="wpac-obj-cancel-' . (int) $pid . '" name="reason" value="' . esc_attr__( 'Objection of the partner', 'affiliate-captain' ) . '"> ';
				if ( 'exported' === $op->status ) {
					echo '<label><input type="checkbox" name="confirm_not_sent" value="1" required> ' . esc_html__( 'The bank did NOT execute the exported transfer', 'affiliate-captain' ) . '</label> ';
				}
				/* translators: %s: payout number */
				echo '<button class="button" data-wpac-confirm="' . esc_attr( sprintf( __( 'Cancel payout %s? A cancellation document is issued and the commissions are paid with the next run.', 'affiliate-captain' ), $op->number ) ) . '">' . esc_html__( 'Cancel this payout', 'affiliate-captain' ) . '</button></p></form>';
			}
			Admin::form_open( 'objection_resolve' );
			echo '<input type="hidden" name="payout_id" value="' . (int) $pid . '"><p><label for="wpac-obj-resolution-' . (int) $pid . '">' . esc_html__( 'What was done (sent to the partner)', 'affiliate-captain' ) . '</label><br><textarea id="wpac-obj-resolution-' . (int) $pid . '" name="resolution" rows="2" class="large-text" required></textarea></p><p><button class="button">' . esc_html__( 'Mark as handled', 'affiliate-captain' ) . '</button></p></form></div>';
		}

		echo '<h2>' . esc_html__( 'New payout run', 'affiliate-captain' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="wpac-payouts">';
		echo '<label>' . esc_html__( 'Period end', 'affiliate-captain' ) . ' <input type="date" name="period_end" value="' . esc_attr( $end ) . '"></label> ';
		echo '<label>' . esc_html__( 'Currency', 'affiliate-captain' ) . ' <input type="text" name="currency" value="' . esc_attr( $currency ) . '" size="4" maxlength="3"></label> ';
		echo '<button class="button" name="preview" value="1">' . esc_html__( 'Preview', 'affiliate-captain' ) . '</button></form>';
		/* translators: %s: amount */
		echo '<p class="description">' . esc_html( sprintf( __( 'Includes approved commissions and corrections up to the period end. Minimum payout: %s. Negative balances and amounts below the minimum are carried forward.', 'affiliate-captain' ), self::min_text( $currency ) ) ) . '</p>';

		if ( Admin::get( 'preview' ) ) {
			$preview  = PayoutService::preview( $end, $currency );
			$eligible = 0;
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Partner', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Entries', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Balance', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Result', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
			if ( ! $preview ) {
				echo '<tr><td colspan="4">' . esc_html__( 'No approved commissions in this period.', 'affiliate-captain' ) . '</td></tr>';
			}
			foreach ( $preview as $row ) {
				$eligible += $row['eligible'] ? 1 : 0;
				printf(
					'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
					esc_html( $row['affiliate'] ? $row['affiliate']->name : '?' ),
					(int) $row['count'],
					esc_html( Money::format( $row['total_minor'], $currency ) ),
					$row['eligible'] ? '<strong>' . esc_html__( 'Will be paid', 'affiliate-captain' ) . '</strong>' : esc_html( $row['reason'] ) . self::fix_link( $row ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fix_link() escapes.
				);
			}
			echo '</tbody></table>';
			if ( $eligible ) {
				Admin::form_open( 'payout_run' );
				echo '<input type="hidden" name="period_end" value="' . esc_attr( $end ) . '"><input type="hidden" name="currency" value="' . esc_attr( $currency ) . '">';
				/* translators: %d: number of payouts */
				submit_button( sprintf( _n( 'Create %d payout', 'Create %d payouts', $eligible, 'affiliate-captain' ), $eligible ), 'primary', 'submit', true, array( 'data-wpac-confirm' => __( 'Create the payouts now? Each one gets a numbered credit note that cannot be changed afterwards (only cancelled).', 'affiliate-captain' ) ) );
				echo '</form>';
			}
		}

		// Requests.
		$requests = PayoutRequests::query( 'requested' );
		AffiliateRepository::find_many( wp_list_pluck( $requests, 'affiliate_id' ) );
		if ( $requests ) {
			echo '<h2>' . esc_html__( 'Open payout requests', 'affiliate-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Partner', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Requested', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Available now', 'affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $requests as $r ) {
				$aff = AffiliateRepository::find( (int) $r->affiliate_id );
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>', esc_html( Admin::date( $r->created_at, true ) ), esc_html( $aff ? $aff->name : '#' . $r->affiliate_id ), esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ), esc_html( Money::format( PayoutRequests::available( (int) $r->affiliate_id, (string) $r->currency ), $r->currency ) ) );
				Admin::form_open( 'payout_request_process', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><button class="button button-primary button-small">' . esc_html__( 'Create payout', 'affiliate-captain' ) . '</button></form> ';
				Admin::form_open( 'payout_request_reject', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $r->id . '"><input type="text" name="reason" size="14" required aria-label="' . esc_attr__( 'Reason for the partner', 'affiliate-captain' ) . '" placeholder="' . esc_attr__( 'Reason', 'affiliate-captain' ) . '"> <button class="button button-small" data-wpac-confirm="' . esc_attr__( 'Reject this payout request? The partner is notified with the reason.', 'affiliate-captain' ) . '">' . esc_html__( 'Reject', 'affiliate-captain' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Minimum payout in a currency as a run applies it (converted from the program currency).
	 *
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function min_text( $currency ) {
		$min = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
		if ( null === $min ) {
			/* translators: %s: currency */
			return sprintf( __( 'cannot be checked (no exchange rate for %s)', 'affiliate-captain' ), $currency );
		}
		return Money::format( (int) $min, $currency );
	}

	/**
	 * List of payouts with filter and (for payout managers) the bulk actions.
	 *
	 * @param bool $manage Whether the user manages payouts (else read only).
	 */
	private static function render_list( $manage ) {
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
		AffiliateRepository::find_many( wp_list_pluck( $rows, 'affiliate_id' ) );
		$kept = wp_parse_args(
			Admin::kept_form( 'payout_bulk' ),
			array(
				'ids'    => array(),
				'action' => '',
				'reason' => '',
			)
		);
		echo '<h2>' . esc_html__( 'Payouts', 'affiliate-captain' ) . '</h2>';
		// Status filter: e.g. everything exported (at the bank) that still has to be marked as paid.
		$filters = array( '' => __( 'All', 'affiliate-captain' ) ) + $labels;
		$links   = array();
		foreach ( $filters as $key => $label ) {
			$url     = Admin::url( 'wpac-payouts', '' === $key ? array() : array( 'status' => $key ) );
			$links[] = (string) $status === (string) $key ? '<strong aria-current="page">' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<p class="subsubsub wpac-status-filter">' . implode( ' | ', $links ) . '</p><div class="clear"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		if ( $manage ) {
			Admin::form_open( 'payout_export', 'wpac-inline' );
			echo '<button class="button">' . esc_html__( 'Export CSV', 'affiliate-captain' ) . '</button></form>';
			Admin::form_open( 'payout_bulk', 'wpac-bulk-form' );
		} else {
			echo '<p class="description">' . esc_html__( 'Read-only view: you can open the payouts and download credit notes and cancellations, but not create, export or change payouts.', 'affiliate-captain' ) . '</p>';
		}
		// A download ends without a page change: admin.js reloads the list afterwards
		// (data-wpac-reload-after), so the new status and the message about the download are shown.
		echo '<table class="wp-list-table widefat striped"><thead><tr><td class="check-column">' . ( $manage ? '<input type="checkbox" class="wpac-cb-all" aria-label="' . esc_attr__( 'Select all', 'affiliate-captain' ) . '">' : '' ) . '</td><th>' . esc_html_x( 'Number', 'document number', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Partner', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Period end', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Bank details', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html( '' !== (string) $status ? __( 'No payouts with this status.', 'affiliate-captain' ) : __( 'No payouts yet. Approved commissions are paid out with a payout run: choose the period end above and click "Preview".', 'affiliate-captain' ) ) . '</td></tr>';
		}
		foreach ( $rows as $p ) {
			$aff  = AffiliateRepository::find( (int) $p->affiliate_id );
			$snap = json_decode( (string) $p->profile_snapshot, true );
			printf(
				'<tr><th class="check-column">%s</th><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s%s</td></tr>',
				$manage && in_array( $p->status, array( 'pending', 'exported' ), true ) ? '<input type="checkbox" class="wpac-cb" name="ids[]" value="' . (int) $p->id . '"' . checked( in_array( (int) $p->id, array_map( 'intval', (array) $kept['ids'] ), true ), true, false ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s: document number */ __( 'Select %s', 'affiliate-captain' ), $p->number ) ) . '">' : '',
				esc_url( Admin::url( 'wpac-payouts', array( 'action' => 'receipt', 'id' => $p->id ) ) ),
				esc_html( $p->number ),
				esc_html( $aff ? $aff->name : '#' . $p->affiliate_id ),
				esc_html( mysql2date( get_option( 'date_format' ), $p->period_end ) ),
				esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ),
				esc_html( isset( $snap['payout_method'] ) && ProfileRepository::METHOD_MANUAL === $snap['payout_method'] ? __( 'Other payment method (paid manually)', 'affiliate-captain' ) : ( isset( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—' ) ),
				Admin::badge( $p->status, $labels[ $p->status ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$p->sepa_batch ? '<br><small>' . esc_html( $p->sepa_batch ) . '</small>' : ''
			);
		}
		echo '</tbody></table>';
		if ( ! $manage ) {
			Admin::pager( $total, $per );
			echo '</div>';
			return;
		}
		echo '<fieldset class="wpac-bulk"><legend>' . esc_html__( 'Bulk action for selected payouts', 'affiliate-captain' ) . '</legend>';
		echo '<p><label for="wpac-bulk-action" class="screen-reader-text">' . esc_html__( 'Action', 'affiliate-captain' ) . '</label><select name="bulk_action" id="wpac-bulk-action" required>';
		echo '<option value="">' . esc_html__( '— Choose action —', 'affiliate-captain' ) . '</option>';
		foreach (
			array(
				'sepa'   => __( 'Download SEPA XML', 'affiliate-captain' ),
				'csv'    => __( 'Download transfer list (CSV, any currency)', 'affiliate-captain' ),
				'paypal' => __( 'Download PayPal payouts file (CSV)', 'affiliate-captain' ),
				'paid'   => __( 'Mark as paid', 'affiliate-captain' ),
				'cancel' => __( 'Cancel', 'affiliate-captain' ),
			) as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $kept['action'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		// One confirmation only: "Mark as paid" and "Cancel" ask with the checkbox below (R5-O49).
		echo '</select> <button class="button" type="submit">' . esc_html__( 'Apply', 'affiliate-captain' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Payouts of partners with another payment method (e.g. PayPal) are left out of the SEPA file. The transfer list contains their payment details; the PayPal payouts file contains those with a PayPal address, for the upload in your PayPal business account. Pay them and then mark them as paid.', 'affiliate-captain' ) . '</p>';
		// Fields appear with the action they belong to (admin.js, data-wpac-depends; all visible without JavaScript).
		echo '<p data-wpac-depends="bulk_action=cancel"><label for="wpac-cancel-reason">' . esc_html__( 'Cancellation reason (required for "Cancel")', 'affiliate-captain' ) . '</label><br><input type="text" name="reason" id="wpac-cancel-reason" class="regular-text" value="' . esc_attr( (string) $kept['reason'] ) . '"></p>';
		echo '<p data-wpac-depends="bulk_action=paid|cancel"><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'I confirm this action for the selected payouts (required for "Mark as paid" and "Cancel").', 'affiliate-captain' ) . '</label></p>';
		echo '<p data-wpac-depends="bulk_action=sepa|csv|paypal"><label><input type="checkbox" name="reexport" value="1"> ' . esc_html__( 'Export again (only if the bank did not process the previous file)', 'affiliate-captain' ) . '</label></p>';
		echo '<p data-wpac-depends="bulk_action=cancel"><label><input type="checkbox" name="confirm_not_sent" value="1"> ' . esc_html__( 'Confirm: the bank did NOT execute the exported transfer (required to cancel exported payouts)', 'affiliate-captain' ) . '</label></p></fieldset></form>';
		Admin::pager( $total, $per );
		echo '</div>';
	}

	/**
	 * Link to where the cause of a blocked payout is fixed (preview rows, R5-O49).
	 *
	 * @param array $row Preview row.
	 * @return string HTML.
	 */
	private static function fix_link( array $row ) {
		if ( ! empty( $row['eligible'] ) || empty( $row['affiliate'] ) || empty( $row['code'] ) ) {
			return '';
		}
		$fix = Admin::blocked_fix( (int) $row['affiliate']->id, (string) $row['code'] );
		return ' – <a href="' . esc_url( $fix[0] ) . '">' . esc_html( $fix[1] ) . '</a>';
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
			echo '<div class="wrap"><p>' . esc_html__( 'Payout not found.', 'affiliate-captain' ) . '</p></div>';
			return;
		}
		$aff    = AffiliateRepository::find( (int) $p->affiliate_id );
		$snap   = json_decode( (string) $p->profile_snapshot, true );
		$snap   = is_array( $snap ) ? $snap : array();
		$labels = ProfileRepository::tax_status_labels();
		if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			// Address, VAT ID and tax status are restricted to wpac_view_tax_data.
			$snap = array_intersect_key( $snap, array( 'iban_masked' => 1, 'payout_method' => 1 ) );
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Tax and address details are hidden (missing permission wpac_view_tax_data).', 'affiliate-captain' ) . '</p></div>';
		}

		echo '<div class="wrap wpac-receipt">';
		echo '<p class="wpac-noprint"><a href="' . esc_url( Admin::url( 'wpac-payouts' ) ) . '">&larr; ' . esc_html__( 'All payouts', 'affiliate-captain' ) . '</a>';
		if ( $p->statement_file && current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			echo ' · <a class="button button-primary" href="' . esc_url( self::statement_url( (int) $p->id ) ) . '">' . esc_html__( 'Download PDF statement', 'affiliate-captain' ) . '</a> <small>SHA-256 ' . esc_html( substr( $p->statement_hash, 0, 16 ) ) . '…</small>';
			if ( $p->storno_file ) {
				/* translators: %s: cancellation number */
				echo ' · <a class="button" href="' . esc_url( self::statement_url( (int) $p->id, true ) ) . '">' . esc_html( sprintf( __( 'Download cancellation %s', 'affiliate-captain' ), $p->storno_number ) ) . '</a>';
			}
		} elseif ( $p->statement_deleted_at ) {
			echo ' · ' . esc_html__( 'Statement deleted after the retention period.', 'affiliate-captain' );
		}
		echo '</p>';
		echo '<h1>' . esc_html__( 'Commission statement', 'affiliate-captain' ) . ' ' . esc_html( $p->number ) . '</h1>';
		echo '<div class="wpac-columns"><div class="wpac-col"><h3>' . esc_html__( 'Issued by', 'affiliate-captain' ) . '</h3><p>' . esc_html( Settings::get( 'debtor_name' ) ? Settings::get( 'debtor_name' ) : get_bloginfo( 'name' ) ) . '</p></div>';
		echo '<div class="wpac-col"><h3>' . esc_html__( 'Partner', 'affiliate-captain' ) . '</h3><p>' . esc_html( ! empty( $snap['legal_name'] ) ? $snap['legal_name'] : ( $aff ? $aff->name : '' ) ) . '<br>' . nl2br( esc_html( isset( $snap['address'] ) ? $snap['address'] : '' ) ) . '<br>' . esc_html( isset( $snap['country'] ) ? $snap['country'] : '' ) . '</p>';
		echo '<p>' . esc_html__( 'Tax status', 'affiliate-captain' ) . ': ' . esc_html( isset( $snap['tax_status'], $labels[ $snap['tax_status'] ] ) ? $labels[ $snap['tax_status'] ] : '—' ) . '<br>';
		echo esc_html__( 'VAT ID', 'affiliate-captain' ) . ': ' . esc_html( ! empty( $snap['vat_id'] ) ? $snap['vat_id'] : '—' ) . '<br>';
		if ( ! empty( $snap['tax_number'] ) ) {
			echo esc_html__( 'Tax number', 'affiliate-captain' ) . ': ' . esc_html( $snap['tax_number'] ) . '<br>';
		}
		if ( isset( $snap['payout_method'] ) && ProfileRepository::METHOD_MANUAL === $snap['payout_method'] ) {
			echo esc_html__( 'Payment method', 'affiliate-captain' ) . ': ' . esc_html__( 'Other payment method (paid manually)', 'affiliate-captain' ) . '</p></div></div>';
		} else {
			echo esc_html__( 'Bank account', 'affiliate-captain' ) . ': ' . esc_html( isset( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—' ) . '</p></div></div>';
		}
		echo '<p>' . esc_html__( 'Period until', 'affiliate-captain' ) . ': ' . esc_html( mysql2date( get_option( 'date_format' ), $p->period_end ) ) . ' · ' . esc_html__( 'Created', 'affiliate-captain' ) . ': ' . esc_html( Admin::date( $p->created_at ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Order', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Description', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( PayoutService::items( $id ) as $item ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( Admin::date( $item->commission_date ) ),
				esc_html( $item->source . ' #' . $item->source_order_id ),
				esc_html( in_array( $item->kind, array( 'sale', 'bonus' ), true ) ? $item->description : __( 'Correction', 'affiliate-captain' ) . ' (' . \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $item->kind ) . ')' ),
				esc_html( Money::format( (int) $item->amount_minor, $p->currency ) )
			);
		}
		$net = (int) $p->net_minor ? (int) $p->net_minor : (int) $p->amount_minor - (int) $p->vat_minor;
		echo '</tbody><tfoot>';
		echo '<tr><th colspan="3">' . esc_html__( 'Net amount', 'affiliate-captain' ) . '</th><th>' . esc_html( Money::format( $net, $p->currency ) ) . '</th></tr>';
		/* translators: %s: VAT rate */
		echo '<tr><th colspan="3">' . esc_html( (int) $p->vat_minor ? sprintf( __( 'plus VAT %s %%', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( $p->vat_rate ) ) : __( 'VAT', 'affiliate-captain' ) ) . '</th><th>' . esc_html( Money::format( (int) $p->vat_minor, $p->currency ) ) . '</th></tr>';
		echo '<tr><th colspan="3">' . esc_html__( 'Total payout', 'affiliate-captain' ) . '</th><th>' . esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ) . '</th></tr>';
		echo '</tfoot></table>';
		if ( $p->vat_mode ) {
			echo '<p class="description">' . esc_html( Statement::vat_note( (string) $p->vat_mode ) ) . '</p>';
		} else {
			// Payouts from before VAT handling: no treatment was stored.
			echo '<p class="description">' . esc_html__( 'VAT treatment depends on the partner\'s tax status and must be confirmed by your tax advisor. This statement does not calculate VAT.', 'affiliate-captain' ) . '</p>';
		}
		echo '</div>';
	}
}
