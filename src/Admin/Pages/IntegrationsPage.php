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
			$ok = Jobs::retry( absint( Admin::post( 'id' ) ) );
			Admin::back( $ok ? __( 'Job processed successfully.', 'affiliate-captain' ) : __( 'Job failed again – see error.', 'affiliate-captain' ), $ok ? 'success' : 'error' );
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
		$status = Crypto::status();
		$legacy = $status['legacy'];
		$broken = $status['broken'];
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
		$misplaced = \WPAffiliateCaptain\Payouts\Statement::misplaced();
		if ( $misplaced ) {
			/* translators: %d: number of files */
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( sprintf( _n( '%d credit note file is still in the previous statement folder.', '%d credit note files are still in the previous statement folder.', count( $misplaced ), 'affiliate-captain' ), count( $misplaced ) ) ) . '</strong> ' . esc_html__( 'The statement folder was changed (e.g. WPAC_STATEMENT_DIR defined later). The files are still found and downloadable, but they stay in the old, possibly public folder until you move them.', 'affiliate-captain' ) . '</p>';
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
			'sale'       => __( 'Sale', 'affiliate-captain' ),
			'refund'     => __( 'Refund', 'affiliate-captain' ),
			'woo_order'  => __( 'WooCommerce order', 'affiliate-captain' ),
			'woo_refund' => __( 'WooCommerce refund', 'affiliate-captain' ),
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
	 * Render.
	 */
	public static function render() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Integrations', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		self::migration_notices();
		/* translators: %s: version */
		echo '<p>' . esc_html( sprintf( __( 'Public contract version: %s. Integrations with a different major version are not called.', 'affiliate-captain' ), WPAC_CONTRACT_VERSION ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Integration', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Version', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Contract', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		$known = array(
			'pass-captain'    => 'Pass Captain',
			'voucher-captain' => 'Voucher Captain',
		);
		$list  = ContractApi::integrations();
		foreach ( $known as $slug => $name ) {
			if ( ! isset( $list[ $slug ] ) ) {
				$list[ $slug ] = array(
					'name'     => $name,
					'version'  => '',
					'contract' => '',
					'active'   => false,
				);
			}
		}
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

		echo '<h2>' . esc_html__( 'Environment', 'affiliate-captain' ) . '</h2><ul class="wpac-checks">';
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$checks = array(
			array( class_exists( 'WooCommerce' ), __( 'WooCommerce active', 'affiliate-captain' ) . ( class_exists( 'WooCommerce' ) ? ' (' . ( $hpos ? 'HPOS' : __( 'legacy order storage', 'affiliate-captain' ) ) . ')' : '' ) ),
			array( function_exists( 'woocommerce_register_additional_checkout_field' ), __( 'Partner code field available in block checkout (WooCommerce 8.9+)', 'affiliate-captain' ) ),
			array( function_exists( 'wp_has_consent' ), __( 'WP Consent API detected (marketing consent for the affiliate cookie)', 'affiliate-captain' ) ),
			array( Crypto::has_dedicated_key(), Crypto::has_dedicated_key() ? __( 'Dedicated encryption key WPAC_ENCRYPTION_KEY defined', 'affiliate-captain' ) : __( 'No dedicated encryption key: add define( \'WPAC_ENCRYPTION_KEY\', \'…64 hex characters…\' ); to wp-config.php (generate it e.g. with "openssl rand -hex 32"). Existing bank details stay readable and can then be re-encrypted here.', 'affiliate-captain' ) ),
			array( '' !== (string) Settings::get( 'debtor_iban' ), __( 'Company account for SEPA export configured', 'affiliate-captain' ) ),
			array( true !== \WPAffiliateCaptain\Payouts\Statement::publicly_accessible(), defined( 'WPAC_STATEMENT_DIR' ) ? __( 'Statements stored outside the web root (WPAC_STATEMENT_DIR)', 'affiliate-captain' ) : ( true === \WPAffiliateCaptain\Payouts\Statement::publicly_accessible() ? __( 'WARNING: the statements folder is publicly reachable (e.g. nginx ignores .htaccess). Define WPAC_STATEMENT_DIR outside the web root or block /wp-content/uploads/wpac-statements/.', 'affiliate-captain' ) : __( 'Statements folder is not publicly reachable', 'affiliate-captain' ) ) ),
			array( '' !== (string) Settings::get( 'operator_address' ) && ( '' !== (string) Settings::get( 'operator_vat_id' ) || '' !== (string) Settings::get( 'operator_tax_number' ) ), __( 'Address and VAT ID / tax number for credit notes configured', 'affiliate-captain' ) ),
			array( ! \WPAffiliateCaptain\Commissions\Balance::verify_all( 200 ), __( 'Partner accounts: booking chains intact and balances match the commission ledger', 'affiliate-captain' ) ),
			array( \WPAffiliateCaptain\Core\Monitor::cron_running(), __( 'Scheduled tasks active (hold period release, retries)', 'affiliate-captain' ) ),
		);
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'ok' : 'warn' ) . '">' . ( $c[0] ? '✔' : '✖' ) . ' ' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Retry queue', 'affiliate-captain' ) . '</h2>';
		$jobs = Jobs::query();
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No open or failed jobs.', 'affiliate-captain' ) . '</p></div>';
			return;
		}
		$failed = count( Jobs::query( 'failed' ) );
		if ( $failed ) {
			Admin::form_open( 'job_retry_all' );
			/* translators: %d: number */
			echo '<p><button class="button button-primary">' . esc_html( sprintf( __( 'Retry all failed jobs (%d)', 'affiliate-captain' ), $failed ) ) . '</button></p></form>';
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Type', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Key', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Attempts', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Error', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Next attempt', 'affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $jobs as $j ) {
			$explain = self::job_error_label( (string) $j->last_error_code );
			printf(
				'<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%d</td><td>%s<br><small><code>%s</code> %s</small></td><td>%s</td><td>',
				(int) $j->id,
				esc_html( self::job_type_label( (string) $j->type ) ),
				esc_html( $j->idempotency_key ),
				esc_html( self::job_status_label( (string) $j->status ) ),
				(int) $j->attempts,
				esc_html( '' !== $explain ? $explain : ( '' !== (string) $j->last_error_code ? __( 'Error while booking.', 'affiliate-captain' ) : '—' ) ),
				esc_html( $j->last_error_code ),
				esc_html( $j->last_error ),
				esc_html( 'queued' === $j->status ? Admin::date( $j->next_attempt_at, true ) : '—' )
			);
			Admin::form_open( 'job_retry', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $j->id . '"><button class="button button-small">' . esc_html__( 'Retry now', 'affiliate-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
