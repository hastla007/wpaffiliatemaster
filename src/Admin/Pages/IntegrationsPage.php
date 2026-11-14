<?php
/**
 * Admin: integration status, contract compatibility and retry queue.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Core\Crypto;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Scheduler;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Sales\ContractApi;

defined( 'ABSPATH' ) || exit;

final class IntegrationsPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'job_retry_all' === $do ) {
			$ids = array_map( 'intval', wp_list_pluck( Jobs::query( 'failed', 1000 ), 'id' ) );
			$n   = Jobs::retry_all_failed();
			if ( ! $n ) {
				Admin::back( __( 'There are no failed jobs.', 'affiliate-captain' ), 'info' );
			}
			// Process up to 25 right away and report the real outcome; the rest runs in the background.
			$ok     = 0;
			$failed = 0;
			$now    = array_slice( $ids, 0, 25 );
			foreach ( $now as $id ) {
				if ( Jobs::run( $id ) ) {
					++$ok;
				} else {
					++$failed;
				}
			}
			$later = max( 0, $n - count( $now ) );
			if ( $later ) {
				Scheduler::once( Jobs::DRAIN_HOOK, 60 );
			}
			/* translators: %d: number of jobs */
			$message = sprintf( _n( '%d job processed successfully.', '%d jobs processed successfully.', $ok, 'affiliate-captain' ), $ok );
			if ( $failed ) {
				/* translators: %d: number of jobs */
				$message .= ' ' . sprintf( _n( '%d job failed again – see the error in the table.', '%d jobs failed again – see the errors in the table.', $failed, 'affiliate-captain' ), $failed );
			}
			if ( $later ) {
				/* translators: %d: number of jobs */
				$message .= ' ' . sprintf( _n( '%d more job is processed in the background within the next minutes.', '%d more jobs are processed in the background within the next minutes.', $later, 'affiliate-captain' ), $later );
			}
			Admin::back( $message, $failed ? ( $ok ? 'warning' : 'error' ) : 'success' );
		}
		if ( 'job_retry' === $do ) {
			$id  = absint( Admin::post( 'id' ) );
			$job = Jobs::find( $id );
			if ( ! $job || ! in_array( $job->status, array( 'queued', 'failed', 'parked' ), true ) ) {
				Admin::back( __( 'This job is running or already done and was not started again.', 'affiliate-captain' ), 'warning' );
			}
			$ok = Jobs::retry( $id );
			Admin::back( $ok ? __( 'Job processed successfully.', 'affiliate-captain' ) : __( 'Job failed again – see error.', 'affiliate-captain' ), $ok ? 'success' : 'error' );
		}
		if ( 'job_dismiss' === $do ) {
			$ok = Jobs::dismiss( absint( Admin::post( 'id' ) ) );
			Admin::back( $ok ? __( 'Job dismissed. It will not be retried.', 'affiliate-captain' ) : __( 'Only failed or parked jobs can be dismissed.', 'affiliate-captain' ), $ok ? 'success' : 'error' );
		}
		if ( 'reencrypt' === $do ) {
			if ( ! Crypto::has_dedicated_key() ) {
				Admin::back( __( 'Define WPAC_ENCRYPTION_KEY in wp-config.php first.', 'affiliate-captain' ), 'error' );
			}
			delete_option( 'wpac_reencrypt_cursor' );
			$done = Crypto::reencrypt_batch( 500 );
			/* translators: %d: number of partners */
			$message = sprintf( _n( 'Bank details of %d partner were encrypted with the new key.', 'Bank details of %d partners were encrypted with the new key.', $done, 'affiliate-captain' ), $done );
			if ( get_option( 'wpac_reencrypt_cursor' ) ) {
				$message .= ' ' . __( 'The rest is processed in the background.', 'affiliate-captain' );
			}
			\WPAffiliateCaptain\Core\Monitor::check_migrations();
			Admin::back( $message );
		}
		if ( 'statements_move' === $do ) {
			list( $moved, $failed ) = \WPAffiliateCaptain\Payouts\Statement::move_misplaced();
			/* translators: %d: number of files */
			$message = sprintf( _n( '%d credit note file was moved to the new statement folder.', '%d credit note files were moved to the new statement folder.', $moved, 'affiliate-captain' ), $moved );
			if ( $failed ) {
				/* translators: %d: number of files */
				$message .= ' ' . sprintf( _n( '%d file could not be moved (is the new folder writable?).', '%d files could not be moved (is the new folder writable?).', $failed, 'affiliate-captain' ), $failed );
			}
			\WPAffiliateCaptain\Core\Monitor::check_migrations();
			Admin::back( $message, $failed ? 'error' : 'success' );
		}
	}

	/**
	 * Warnings after WPAC_ENCRYPTION_KEY or WPAC_STATEMENT_DIR were defined later: bank details
	 * still encrypted with the old key, credit notes still in the old folder (with the fix).
	 */
	public static function migration_notices() {
		if ( ! current_user_can( \WPAffiliateCaptain\Core\Capabilities::MANAGE_PAYOUTS ) ) {
			return;
		}
		// The daily check's result (Monitor::check_migrations(), refreshed after every action here):
		// decrypting every IBAN and checking every credit note file on each page view is too slow.
		$status = \WPAffiliateCaptain\Core\Monitor::migration_status();
		$legacy = (int) $status['legacy'];
		$broken = (int) $status['broken'];
		if ( $legacy ) {
			/* translators: %d: number of partners */
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( sprintf( _n( 'The bank details of %d partner are still encrypted with the old key (derived from the WordPress salts).', 'The bank details of %d partners are still encrypted with the old key (derived from the WordPress salts).', $legacy, 'affiliate-captain' ), $legacy ) ) . '</strong> ' . esc_html__( 'They stay readable as long as the salts in wp-config.php do not change. Encrypt them with your new WPAC_ENCRYPTION_KEY now – afterwards changing the salts no longer affects them.', 'affiliate-captain' ) . '</p>';
			Admin::form_open( 'reencrypt' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Re-encrypt bank details with the new key', 'affiliate-captain' ) . '</button></p></form></div>';
		}
		if ( $broken ) {
			/* translators: %d: number of partners */
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( _n( 'The bank details of %d partner cannot be decrypted with either key (the WordPress salts were probably changed before WPAC_ENCRYPTION_KEY was set). Ask these partners to enter their IBAN again; SEPA export is not possible for them until then.', 'The bank details of %d partners cannot be decrypted with either key (the WordPress salts were probably changed before WPAC_ENCRYPTION_KEY was set). Ask these partners to enter their IBAN again; SEPA export is not possible for them until then.', $broken, 'affiliate-captain' ), $broken ) ) . '</p><p>' . esc_html__( 'Partner IDs:', 'affiliate-captain' ) . ' ' . esc_html( implode( ', ', array_map( 'intval', $status['broken_ids'] ) ) . ( $broken > count( $status['broken_ids'] ) ? ', …' : '' ) ) . '</p></div>';
		}
		$misplaced = (int) $status['misplaced'];
		if ( $misplaced ) {
			/* translators: %d: number of files */
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( sprintf( _n( '%d credit note file is still in the previous statement folder.', '%d credit note files are still in the previous statement folder.', $misplaced, 'affiliate-captain' ), $misplaced ) ) . '</strong> ' . esc_html__( 'The statement folder was changed (e.g. WPAC_STATEMENT_DIR defined later). The files are still found and downloadable, but they stay in the old, possibly public folder until you move them.', 'affiliate-captain' ) . '</p>';
			Admin::form_open( 'statements_move' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Move credit notes to the new folder', 'affiliate-captain' ) . '</button></p></form></div>';
		}
	}

	/**
	 * Readable job type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private static function job_type_label( $type ) {
		$labels = array(
			'sale'         => __( 'Sale', 'affiliate-captain' ),
			'refund'       => __( 'Refund', 'affiliate-captain' ),
			'woo_order'    => __( 'WooCommerce order', 'affiliate-captain' ),
			'woo_refund'   => __( 'WooCommerce refund', 'affiliate-captain' ),
			'woo_increase' => __( 'WooCommerce order increase', 'affiliate-captain' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}

	/**
	 * Readable job status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function job_status_label( $status ) {
		$labels = array(
			'queued'  => __( 'Waiting for the next attempt', 'affiliate-captain' ),
			'parked'  => __( 'Waiting for its sale', 'affiliate-captain' ),
			'running' => __( 'Running', 'affiliate-captain' ),
			'failed'  => __( 'Failed', 'affiliate-captain' ),
			'done'    => __( 'Done', 'affiliate-captain' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Readable explanation of a job error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private static function job_error_label( $code ) {
		$labels = array(
			'aborted'            => __( 'The previous attempt did not finish (timeout or fatal error).', 'affiliate-captain' ),
			'sale_pending'       => __( 'Waiting for the sale of this order to be booked first.', 'affiliate-captain' ),
			'superseded'         => __( 'Already included in the retried sale.', 'affiliate-captain' ),
			'no_sale'            => __( 'The sale of this order was not reported yet; the refund is booked once it is.', 'affiliate-captain' ),
			'no_handler'         => __( 'The integration of this job is not active right now (e.g. WooCommerce deactivated); it is retried later without using up attempts.', 'affiliate-captain' ),
			'exception'          => __( 'Unexpected error.', 'affiliate-captain' ),
			'db_transaction'     => __( 'Database error (transaction could not be started).', 'affiliate-captain' ),
			'db_commit'          => __( 'Database error (transaction could not be saved).', 'affiliate-captain' ),
			'db_sequence'        => __( 'Database error (number sequence).', 'affiliate-captain' ),
			'integration'        => __( 'The shop or integration reported an error.', 'affiliate-captain' ),
			'commission_changed' => __( 'A commission was changed at the same time.', 'affiliate-captain' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		if ( 0 === strpos( (string) $code, 'db_' ) ) {
			return __( 'Database error.', 'affiliate-captain' );
		}
		return '';
	}

	/**
	 * Check of the statements folder: [ ok, text ]. A probe that could not be made (loopback
	 * requests blocked, firewall) is no all-clear.
	 *
	 * @return array
	 */
	private static function statement_dir_check() {
		if ( \WPAffiliateCaptain\Payouts\Statement::custom_dir() ) {
			return array( true, __( 'Statements stored in their own folder (WPAC_STATEMENT_DIR / wpac_statement_dir) – make sure it is outside the web root', 'affiliate-captain' ) );
		}
		$public = \WPAffiliateCaptain\Payouts\Statement::publicly_accessible();
		if ( true === $public ) {
			return array( false, __( 'WARNING: the statements folder is publicly reachable (e.g. nginx ignores .htaccess). Define WPAC_STATEMENT_DIR outside the web root or block /wp-content/uploads/wpac-statements/.', 'affiliate-captain' ) );
		}
		if ( null === $public ) {
			return array( false, __( 'Could not check whether the statements folder is publicly reachable (the test request from the server to itself was blocked). Check that /wp-content/uploads/wpac-statements/ cannot be opened in a browser, or define WPAC_STATEMENT_DIR outside the web root.', 'affiliate-captain' ) );
		}
		return array( true, __( 'Statements folder is not publicly reachable', 'affiliate-captain' ) );
	}

	/**
	 * Render.
	 */
	/**
	 * Tabs of the page: [ key => label ] (R5-O46: connections, system status, queue).
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'connections' => __( 'Connections', 'affiliate-captain' ),
			'status'      => __( 'System status', 'affiliate-captain' ),
			'queue'       => __( 'Retry queue', 'affiliate-captain' ),
		);
	}

	public static function render() {
		$tabs = self::tabs();
		$tab  = Admin::get( 'tab' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'connections';
		echo '<div class="wrap"><h1>' . esc_html__( 'Integrations', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		$failed = count( Jobs::query( 'failed' ) );
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Integration sections', 'affiliate-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$count = 'queue' === $key && $failed ? ' <span class="count">(' . (int) $failed . ')</span>' : '';
			printf( '<a class="nav-tab %s" href="%s"%s>%s%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( Admin::url( 'wpac-integrations', array( 'tab' => $key ) ) ), $key === $tab ? ' aria-current="page"' : '', esc_html( $label ), $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $count is built from an integer.
		}
		echo '</nav>';
		if ( 'status' === $tab ) {
			self::migration_notices();
			self::render_status();
		} elseif ( 'queue' === $tab ) {
			self::render_queue();
		} else {
			self::render_connections();
		}
		echo '</div>';
	}

	/**
	 * Registered shop integrations (only those that registered; no fixed rows for add-ons that are not installed).
	 */
	private static function render_connections() {
		/* translators: %s: version */
		echo '<p>' . esc_html( sprintf( __( 'Public contract version: %s. Integrations with a different major version are not called.', 'affiliate-captain' ), WPAC_CONTRACT_VERSION ) ) . '</p>';
		$list = ContractApi::integrations();
		if ( ! $list ) {
			echo '<p>' . esc_html( class_exists( 'WooCommerce' ) ? __( 'WooCommerce orders are recorded directly. No further shop or booking system has registered through the integration contract.', 'affiliate-captain' ) : __( 'No sales source is connected yet: activate WooCommerce or an integration that reports sales through the integration contract (see CONTRACT.md).', 'affiliate-captain' ) ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Integration', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Version', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Contract', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $slug => $info ) {
			$info   = wp_parse_args( (array) $info, array( 'name' => $slug, 'version' => '', 'contract' => '', 'active' => false ) );
			$compat = $info['contract'] && ContractApi::is_compatible( $info['contract'] );
			if ( ! $info['active'] ) {
				$status = Admin::badge( 'paused', __( 'Not installed / not registered', 'affiliate-captain' ) );
			} elseif ( ! $compat ) {
				$status = Admin::badge( 'rejected', __( 'Incompatible contract', 'affiliate-captain' ) );
			} else {
				$status = Admin::badge( 'active', __( 'Connected', 'affiliate-captain' ) );
			}
			printf( '<tr><td><strong>%s</strong> <code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $info['name'] ), esc_html( $slug ), esc_html( $info['version'] ? $info['version'] : '—' ), esc_html( $info['contract'] ? $info['contract'] : '—' ), $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';
	}

	/**
	 * Environment checks.
	 */
	private static function render_status() {
		echo '<h2>' . esc_html__( 'Environment', 'affiliate-captain' ) . '</h2><ul class="wpac-checks">';
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$checks = array(
			array( class_exists( 'WooCommerce' ), __( 'WooCommerce active', 'affiliate-captain' ) . ( class_exists( 'WooCommerce' ) ? ' (' . ( $hpos ? 'HPOS' : __( 'legacy order storage', 'affiliate-captain' ) ) . ')' : '' ) ),
			array( function_exists( 'woocommerce_register_additional_checkout_field' ), __( 'Partner code field available in block checkout (WooCommerce 8.9+)', 'affiliate-captain' ) ),
			array( function_exists( 'wp_has_consent' ), __( 'WP Consent API detected (marketing consent for the affiliate cookie)', 'affiliate-captain' ) ),
			array( Crypto::has_dedicated_key(), Crypto::has_dedicated_key() ? __( 'Dedicated encryption key WPAC_ENCRYPTION_KEY defined', 'affiliate-captain' ) : __( 'No dedicated encryption key: add define( \'WPAC_ENCRYPTION_KEY\', \'…64 hex characters…\' ); to wp-config.php (generate it e.g. with "openssl rand -hex 32"). Existing bank details stay readable and can then be re-encrypted here.', 'affiliate-captain' ) ),
			array( '' !== (string) Settings::get( 'debtor_iban' ), __( 'Company account for SEPA export configured', 'affiliate-captain' ) ),
			self::statement_dir_check(),
			array( '' !== (string) Settings::get( 'operator_address' ) && ( '' !== (string) Settings::get( 'operator_vat_id' ) || '' !== (string) Settings::get( 'operator_tax_number' ) ), __( 'Address and VAT ID / tax number for credit notes configured', 'affiliate-captain' ) ),
			array( ! \WPAffiliateCaptain\Commissions\Balance::verify_all( 200 ), __( 'Partner accounts: booking chains intact and balances match the commission ledger', 'affiliate-captain' ) ),
			array( \WPAffiliateCaptain\Core\Monitor::cron_running(), __( 'Scheduled tasks active (hold period release, retries)', 'affiliate-captain' ) ),
		);
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'ok' : 'warn' ) . '"><span aria-hidden="true">' . ( $c[0] ? '✔' : '!' ) . '</span> ' . esc_html( $c[1] ) . '<span class="screen-reader-text"> – ' . esc_html( $c[0] ? __( 'OK', 'affiliate-captain' ) : __( 'check', 'affiliate-captain' ) ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * Retry queue.
	 */
	private static function render_queue() {
		echo '<h2>' . esc_html__( 'Retry queue', 'affiliate-captain' ) . '</h2>';
		$jobs = Jobs::query();
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No open or failed jobs. Sales and refunds that cannot be booked right away (e.g. during a database problem) wait here and are retried automatically.', 'affiliate-captain' ) . '</p>';
			return;
		}
		$failed = Jobs::count( 'failed' ); // All of them, not only the 100 listed.
		if ( $failed ) {
			Admin::form_open( 'job_retry_all' );
			/* translators: %d: number */
			echo '<p><button class="button button-primary">' . esc_html( sprintf( __( 'Retry all failed jobs (%d)', 'affiliate-captain' ), $failed ) ) . '</button></p></form>';
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Type', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Order', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Created', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Attempts', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Error', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Next attempt', 'affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $jobs as $j ) {
			$explain = self::job_error_label( (string) $j->last_error_code );
			$order   = Jobs::order_of( $j );
			$cell    = '<code>' . esc_html( $j->idempotency_key ) . '</code>';
			if ( $order ) {
				$edit = '';
				if ( \WPAffiliateCaptain\Integrations\WooCommerce::SOURCE === $order[0] && ctype_digit( $order[1] ) && function_exists( 'wc_get_order' ) ) {
					$wc   = wc_get_order( (int) $order[1] );
					$edit = $wc ? (string) $wc->get_edit_order_url() : '';
				}
				$cell = ( '' !== $edit ? '<a href="' . esc_url( $edit ) . '">#' . esc_html( $order[1] ) . '</a>' : '#' . esc_html( $order[1] ) )
					. ' <small>(<a href="' . esc_url( Admin::url( 'wpac-audit', array( 'order' => $order[1] ) ) ) . '">' . esc_html__( 'log', 'affiliate-captain' ) . '</a>)</small><br>' . $cell;
			}
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s<br><small><code>%s</code> %s</small></td><td>%s</td><td>',
				(int) $j->id,
				esc_html( self::job_type_label( (string) $j->type ) ),
				$cell, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html( Admin::date( $j->created_at, true ) ),
				esc_html( self::job_status_label( (string) $j->status ) ),
				(int) $j->attempts,
				esc_html( '' !== $explain ? $explain : ( '' !== (string) $j->last_error_code ? __( 'Error while booking.', 'affiliate-captain' ) : '—' ) ),
				esc_html( $j->last_error_code ),
				esc_html( $j->last_error ),
				esc_html( 'queued' === $j->status ? Admin::date( $j->next_attempt_at, true ) : '—' )
			);
			if ( 'running' !== $j->status ) {
				Admin::form_open( 'job_retry', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $j->id . '"><button class="button button-small">' . esc_html__( 'Retry now', 'affiliate-captain' ) . '</button></form>';
			}
			if ( in_array( $j->status, array( 'failed', 'parked' ), true ) ) {
				Admin::form_open( 'job_dismiss', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $j->id . '"><button class="button button-small button-link-delete" data-wpac-confirm="' . esc_attr__( 'Dismiss this job? It will not be retried; a sale in it stays without commission.', 'affiliate-captain' ) . '">' . esc_html__( 'Dismiss', 'affiliate-captain' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
