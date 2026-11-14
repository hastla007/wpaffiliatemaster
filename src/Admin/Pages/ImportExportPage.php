<?php
/**
 * Admin: import partners from CSV (check first, then import) and export the whole program.
 *
 * Self-contained: own submenu entry and own admin-post actions (wpac_import, wpac_import_template,
 * wpac_export), each with its own nonce and capability check.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Migration\PartnerImport;
use WPAffiliateCaptain\Migration\ProgramExport;

defined( 'ABSPATH' ) || exit;

final class ImportExportPage {

	const SLUG = 'wpac-import';

	/** Largest accepted upload (bytes). */
	const MAX_BYTES = 5242880;

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_wpac_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wpac_import_template', array( __CLASS__, 'handle_template' ) );
		add_action( 'admin_post_wpac_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function menu() {
		add_submenu_page( 'wpac', __( 'Import & export', 'affiliate-captain' ), __( 'Import & export', 'affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Per-user store of the uploaded file and the last report (30 minutes).
	 *
	 * @return string
	 */
	private static function key() {
		return 'wpac_import_' . get_current_user_id();
	}

	/**
	 * Check the uploaded file (dry run) or import the checked file.
	 */
	public static function handle_import() {
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
		}
		check_admin_referer( 'wpac_import', '_wpac_nonce' );
		$page = Admin::url( self::SLUG );
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$opts = array(
			'max_rows'      => PartnerImport::ADMIN_MAX_ROWS,
			'allow_program' => current_user_can( Capabilities::MANAGE_PROGRAM ),
		);
		if ( 'run' === $step ) {
			$stored = get_transient( self::key() );
			if ( ! is_array( $stored ) || empty( $stored['csv'] ) ) {
				Admin::back( __( 'The checked file has expired. Please upload it again.', 'affiliate-captain' ), 'error', $page );
			}
			$report = PartnerImport::run( $stored['csv'], $opts + array( 'dry_run' => false, 'notify' => ! empty( $stored['notify'] ) ) );
			if ( is_wp_error( $report ) ) {
				delete_transient( self::key() );
				Admin::back( $report->get_error_message(), 'error', $page );
			}
			// The file itself is not kept after the import (personal data).
			set_transient( self::key(), array( 'report' => $report, 'done' => true ), 30 * MINUTE_IN_SECONDS );
			/* translators: %d: number of partners */
			$message = sprintf( _n( 'Import finished: %d partner created.', 'Import finished: %d partners created.', $report['created'], 'affiliate-captain' ), $report['created'] );
			if ( $report['failed'] ) {
				/* translators: %d: number of rows */
				$message .= ' ' . sprintf( _n( '%d row with errors was skipped.', '%d rows with errors were skipped.', $report['failed'], 'affiliate-captain' ), $report['failed'] );
			}
			Admin::back( $message, $report['failed'] ? 'error' : 'success', add_query_arg( 'step', 'result', $page ) );
		}

		// Step 1: upload and dry run.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path of the uploaded file, checked with is_uploaded_file().
		$tmp   = isset( $_FILES['wpac_csv']['tmp_name'] ) ? (string) wp_unslash( $_FILES['wpac_csv']['tmp_name'] ) : '';
		$size  = isset( $_FILES['wpac_csv']['size'] ) ? (int) $_FILES['wpac_csv']['size'] : 0;
		$name  = isset( $_FILES['wpac_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['wpac_csv']['name'] ) ) : '';
		$error = isset( $_FILES['wpac_csv']['error'] ) ? (int) $_FILES['wpac_csv']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			Admin::back( __( 'Please choose a CSV file.', 'affiliate-captain' ), 'error', $page );
		}
		if ( $size > self::MAX_BYTES ) {
			Admin::back( __( 'The file is larger than 5 MB. Split it or use WP-CLI: wp wpac import partners <file>', 'affiliate-captain' ), 'error', $page );
		}
		if ( ! preg_match( '/\.(csv|txt)$/i', $name ) ) {
			Admin::back( __( 'Please upload a .csv file.', 'affiliate-captain' ), 'error', $page );
		}
		$csv = (string) file_get_contents( $tmp );
		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			// Excel on Windows saves "CSV" in Windows-1252 unless "CSV UTF-8" is chosen.
			$csv = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $csv, 'UTF-8', 'Windows-1252' ) : $csv;
		}
		$report = PartnerImport::run( $csv, $opts + array( 'dry_run' => true ) );
		if ( is_wp_error( $report ) ) {
			Admin::back( $report->get_error_message(), 'error', $page );
		}
		set_transient(
			self::key(),
			array(
				'csv'    => $csv,
				'notify' => ! empty( $_POST['notify'] ),
				'report' => $report,
				'file'   => $name,
			),
			30 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'step', 'preview', $page ) );
		exit;
	}

	/**
	 * Example CSV.
	 */
	public static function handle_template() {
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
		}
		check_admin_referer( 'wpac_import_template' );
		list( $header, $rows ) = PartnerImport::template();
		Csv::download( 'partners-import-example.csv', $header, $rows );
	}

	/**
	 * Program export as ZIP download.
	 */
	public static function handle_export() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
		}
		check_admin_referer( 'wpac_export', '_wpac_nonce' );
		$tax = ! empty( $_POST['include_tax_data'] );
		if ( $tax && ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			wp_die( esc_html__( 'You are not allowed to export tax and bank data.', 'affiliate-captain' ), 403 );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam( 'wpac-program-export.zip' );
		$res  = $file ? ProgramExport::write( $file, $tax ) : new \WP_Error( 'wpac_export_zip', __( 'The export file could not be created.', 'affiliate-captain' ) );
		if ( is_wp_error( $res ) ) {
			if ( $file ) {
				wp_delete_file( $file );
			}
			Admin::back( $res->get_error_message(), 'error', add_query_arg( 'tab', 'export', Admin::url( self::SLUG ) ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'affiliate-program-' . gmdate( 'Y-m-d' ) . ( $tax ? '-with-bank-data' : '' ) . '.zip' ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $file );
		exit;
	}

	/**
	 * Render.
	 */
	public static function render() {
		$tab = 'export' === Admin::get( 'tab' ) && current_user_can( Capabilities::MANAGE_PROGRAM ) ? 'export' : 'import';
		echo '<div class="wrap"><h1>' . esc_html__( 'Import & export', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			$tabs = array(
				'import' => __( 'Import partners', 'affiliate-captain' ),
				'export' => __( 'Export program', 'affiliate-captain' ),
			);
			echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Import and export sections', 'affiliate-captain' ) . '">';
			foreach ( $tabs as $key => $label ) {
				printf( '<a class="nav-tab %s" href="%s"%s>%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( Admin::url( self::SLUG, array( 'tab' => $key ) ) ), $key === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
			}
			echo '</nav>';
		}
		if ( 'export' === $tab ) {
			self::render_export();
		} else {
			self::render_import();
		}
		echo '</div>';
	}

	/**
	 * Import: upload form, check result or import result.
	 */
	private static function render_import() {
		$step   = Admin::get( 'step' );
		$stored = get_transient( self::key() );
		if ( in_array( $step, array( 'preview', 'result' ), true ) && is_array( $stored ) && ! empty( $stored['report'] ) ) {
			self::render_report( $stored, 'result' === $step );
			return;
		}
		$template = wp_nonce_url( admin_url( 'admin-post.php?action=wpac_import_template' ), 'wpac_import_template' );
		echo '<p>' . esc_html__( 'Import partners from a CSV file, for example when you move from another affiliate plugin. The file is checked first; nothing is saved until you confirm the import.', 'affiliate-captain' ) . '</p>';
		echo '<p>' . esc_html__( 'Columns (first line): name, email (required), website, status (active, pending, paused, terminated, rejected; default active), codes (several separated by spaces), rate (percent, or an amount with rate_type "fixed"), rate_type, group (name or ID), user_id (existing WordPress account with the same e-mail address), channel. Column names of AffiliateWP and SliceWP exports are recognised.', 'affiliate-captain' ) . ' <a href="' . esc_url( $template ) . '">' . esc_html__( 'Download an example file', 'affiliate-captain' ) . '</a></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpac_import"><input type="hidden" name="step" value="check">';
		wp_nonce_field( 'wpac_import', '_wpac_nonce' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="wpac_csv">' . esc_html__( 'CSV file', 'affiliate-captain' ) . '</label></th><td><input type="file" id="wpac_csv" name="wpac_csv" accept=".csv,text/csv" required>';
		/* translators: %d: maximum number of rows */
		echo '<p class="description">' . esc_html( sprintf( __( 'UTF-8, separator ";" or ",", up to %d partners and 5 MB per file. Larger files: wp wpac import partners <file>', 'affiliate-captain' ), PartnerImport::ADMIN_MAX_ROWS ) ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'E-mails', 'affiliate-captain' ) . '</th><td><label><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'Send the usual e-mails to imported partners (approval, new account, account link)', 'affiliate-captain' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off: no e-mails are sent. Partners whose e-mail address already belongs to a WordPress account are then only linked to it when you enter its user_id in the file.', 'affiliate-captain' ) . '</p></td></tr></table>';
		submit_button( __( 'Check file', 'affiliate-captain' ) );
		echo '</form>';
	}

	/**
	 * Table of the check or import result.
	 *
	 * @param array $stored Stored data.
	 * @param bool  $done   Import finished (else: check result).
	 */
	private static function render_report( array $stored, $done ) {
		$report = $stored['report'];
		$ok     = 0;
		foreach ( $report['rows'] as $r ) {
			$ok += $r['errors'] ? 0 : 1;
		}
		if ( ! $done ) {
			$bad = count( $report['rows'] ) - $ok;
			/* translators: %s: file name */
			echo '<p><strong>' . esc_html( sprintf( __( 'Check of %s (nothing has been saved yet):', 'affiliate-captain' ), isset( $stored['file'] ) ? $stored['file'] : '' ) ) . '</strong> ';
			/* translators: %d: number of partners */
			echo esc_html( sprintf( _n( '%d partner can be imported.', '%d partners can be imported.', $ok, 'affiliate-captain' ), $ok ) );
			if ( $bad ) {
				/* translators: %d: number of rows */
				echo ' ' . esc_html( sprintf( _n( '%d row has errors and will be skipped.', '%d rows have errors and will be skipped.', $bad, 'affiliate-captain' ), $bad ) );
			}
			echo '</p>';
		}
		if ( ! empty( $report['unknown'] ) ) {
			echo '<p>' . esc_html__( 'Ignored columns:', 'affiliate-captain' ) . ' <code>' . esc_html( implode( ', ', $report['unknown'] ) ) . '</code></p>';
		}
		$statuses = \WPAffiliateCaptain\Affiliates\AffiliateRepository::status_labels();
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Line', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Name', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'E-mail', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Codes', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Rate', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Result', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $report['rows'] as $line => $r ) {
			$d    = $r['data'];
			$rate = $d['rate'] ? ( 'fixed' === $d['rate'][0] ? $d['rate'][1] . ' ' . \WPAffiliateCaptain\Core\Settings::get( 'currency' ) : \WPAffiliateCaptain\Core\Locale::percent( $d['rate'][1] ) ) : '—';
			if ( $r['errors'] ) {
				$result = '<span class="wpac-badge wpac-badge--rejected">' . esc_html__( 'Error', 'affiliate-captain' ) . '</span> ' . esc_html( implode( '; ', $r['errors'] ) );
			} elseif ( $done && ! empty( $r['id'] ) ) {
				$result = '<a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $r['id'] ) ) ) . '">' . esc_html__( 'Created', 'affiliate-captain' ) . '</a>';
			} else {
				$result = esc_html__( 'OK', 'affiliate-captain' ) . ( $r['notes'] ? ' – ' . esc_html( implode( '; ', $r['notes'] ) ) : '' );
			}
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				(int) $line,
				esc_html( $d['name'] ),
				esc_html( $d['email'] ),
				esc_html( isset( $statuses[ $d['status'] ] ) ? $statuses[ $d['status'] ] : '—' ),
				esc_html( $d['codes'] ? implode( ' ', $d['codes'] ) : '—' ),
				esc_html( $rate ),
				$result // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}
		echo '</tbody></table>';
		if ( $done ) {
			echo '<p><a class="button" href="' . esc_url( Admin::url( self::SLUG ) ) . '">' . esc_html__( 'Import another file', 'affiliate-captain' ) . '</a></p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpac_import"><input type="hidden" name="step" value="run">';
		wp_nonce_field( 'wpac_import', '_wpac_nonce' );
		echo '<p>';
		if ( $ok ) {
			/* translators: %d: number of partners */
			echo '<button type="submit" class="button button-primary">' . esc_html( sprintf( _n( 'Import %d partner', 'Import %d partners', $ok, 'affiliate-captain' ), $ok ) ) . '</button> ';
		}
		echo '<a class="button" href="' . esc_url( Admin::url( self::SLUG ) ) . '">' . esc_html__( 'Choose another file', 'affiliate-captain' ) . '</a></p>';
		echo '<p class="description">' . ( empty( $stored['notify'] ) ? esc_html__( 'No e-mails are sent to the imported partners.', 'affiliate-captain' ) : esc_html__( 'Imported partners receive the usual e-mails.', 'affiliate-captain' ) ) . '</p>';
		echo '</form>';
	}

	/**
	 * Export form.
	 */
	private static function render_export() {
		echo '<p>' . esc_html__( 'Download the whole partner program as a ZIP file with one CSV file per list: partners, groups, codes, rules, tiers, terms acceptances, commissions, payouts, payout items and payout requests. Use it as a backup, for your tax advisor or to move to another system.', 'affiliate-captain' ) . '</p>';
		if ( ! ProgramExport::available() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The program export needs the PHP extension "zip". Ask your hosting provider to enable it, or use the CSV exports of the individual lists.', 'affiliate-captain' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpac_export">';
		wp_nonce_field( 'wpac_export', '_wpac_nonce' );
		if ( current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			echo '<p><label><input type="checkbox" name="include_tax_data" value="1"> ' . esc_html__( 'Include tax and bank data (payout details with full IBANs, tax data of payouts)', 'affiliate-captain' ) . '</label></p>';
			echo '<p class="description">' . esc_html__( 'Only tick this if you really need it: the file then contains decrypted bank details. Store it encrypted and delete it when it is no longer needed.', 'affiliate-captain' ) . '</p>';
		}
		submit_button( __( 'Download export', 'affiliate-captain' ), 'primary', 'submit', true );
		echo '</form>';
	}
}
