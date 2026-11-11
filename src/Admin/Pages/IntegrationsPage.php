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
		if ( 'job_retry' === $do ) {
			$ok = Jobs::retry( absint( Admin::post( 'id' ) ) );
			Admin::back( $ok ? __( 'Job processed successfully.', 'wp-affiliate-captain' ) : __( 'Job failed again – see error.', 'wp-affiliate-captain' ), $ok ? 'success' : 'error' );
		}
	}

	/**
	 * Render.
	 */
	public static function render() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Integrations', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		/* translators: %s: version */
		echo '<p>' . esc_html( sprintf( __( 'Public contract version: %s. Integrations with a different major version are not called.', 'wp-affiliate-captain' ), WPAC_CONTRACT_VERSION ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Integration', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Version', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Contract', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
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
				$status = Admin::badge( 'paused', __( 'Not installed / not registered', 'wp-affiliate-captain' ) );
			} elseif ( ! $compat ) {
				$status = Admin::badge( 'rejected', __( 'Incompatible contract', 'wp-affiliate-captain' ) );
			} else {
				$status = Admin::badge( 'active', __( 'Connected', 'wp-affiliate-captain' ) );
			}
			printf( '<tr><td><strong>%s</strong> <code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $info['name'] ), esc_html( $slug ), esc_html( $info['version'] ? $info['version'] : '—' ), esc_html( $info['contract'] ? $info['contract'] : '—' ), $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Environment', 'wp-affiliate-captain' ) . '</h2><ul class="wpac-checks">';
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$checks = array(
			array( class_exists( 'WooCommerce' ), __( 'WooCommerce active', 'wp-affiliate-captain' ) . ( class_exists( 'WooCommerce' ) ? ' (' . ( $hpos ? 'HPOS' : __( 'legacy order storage', 'wp-affiliate-captain' ) ) . ')' : '' ) ),
			array( function_exists( 'woocommerce_register_additional_checkout_field' ), __( 'Partner code field available in block checkout (WooCommerce 8.9+)', 'wp-affiliate-captain' ) ),
			array( function_exists( 'wp_has_consent' ), __( 'WP Consent API detected (marketing consent for the affiliate cookie)', 'wp-affiliate-captain' ) ),
			array( Crypto::has_dedicated_key(), __( 'Dedicated encryption key WPAC_ENCRYPTION_KEY defined (otherwise derived from WordPress salts)', 'wp-affiliate-captain' ) ),
			array( '' !== (string) Settings::get( 'debtor_iban' ), __( 'Company account for SEPA export configured', 'wp-affiliate-captain' ) ),
			array( true !== \WPAffiliateCaptain\Payouts\Statement::publicly_accessible(), defined( 'WPAC_STATEMENT_DIR' ) ? __( 'Statements stored outside the web root (WPAC_STATEMENT_DIR)', 'wp-affiliate-captain' ) : ( true === \WPAffiliateCaptain\Payouts\Statement::publicly_accessible() ? __( 'WARNING: the statements folder is publicly reachable (e.g. nginx ignores .htaccess). Define WPAC_STATEMENT_DIR outside the web root or block /wp-content/uploads/wpac-statements/.', 'wp-affiliate-captain' ) : __( 'Statements folder is not publicly reachable', 'wp-affiliate-captain' ) ) ),
			array( '' !== (string) Settings::get( 'operator_address' ) && ( '' !== (string) Settings::get( 'operator_vat_id' ) || '' !== (string) Settings::get( 'operator_tax_number' ) ), __( 'Address and VAT ID / tax number for credit notes configured', 'wp-affiliate-captain' ) ),
			array( ! \WPAffiliateCaptain\Commissions\Balance::verify_all( 200 ), __( 'Partner accounts: booking chains intact and balances match the commission ledger', 'wp-affiliate-captain' ) ),
			array( (bool) wp_next_scheduled( 'wpac_hourly' ), __( 'Scheduled tasks active (hold period release, retries)', 'wp-affiliate-captain' ) ),
		);
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'ok' : 'warn' ) . '">' . ( $c[0] ? '✔' : '✖' ) . ' ' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Retry queue', 'wp-affiliate-captain' ) . '</h2>';
		$jobs = Jobs::query();
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No open or failed jobs.', 'wp-affiliate-captain' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Type', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Key', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Attempts', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Error', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Next attempt', 'wp-affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $jobs as $j ) {
			printf( '<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%d</td><td><code>%s</code> %s</td><td>%s</td><td>', (int) $j->id, esc_html( $j->type ), esc_html( $j->idempotency_key ), esc_html( $j->status ), (int) $j->attempts, esc_html( $j->last_error_code ), esc_html( $j->last_error ), esc_html( Admin::date( $j->next_attempt_at, true ) ) );
			Admin::form_open( 'job_retry', 'wpac-inline' );
			echo '<input type="hidden" name="id" value="' . (int) $j->id . '"><button class="button button-small">' . esc_html__( 'Retry now', 'wp-affiliate-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
