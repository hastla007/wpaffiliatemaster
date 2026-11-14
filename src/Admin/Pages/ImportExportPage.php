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
use WPAffiliateCaptain\Core\Crypto;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\PrivateFile;
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
	 * Per-user transient of the import: only the path and hash of the encrypted state file,
	 * mail option and file name (the file and the report can be several MB – too large for a
	 * transient in Memcached or a single option row).
	 *
	 * @return string
	 */
	private static function key() {
		return 'wpac_import_' . get_current_user_id();
	}

	/** Seconds of import work per request; the progress page continues with the next batch. */
	const BATCH_SECONDS = 15;

	/** Prefix of the encrypted state files in the protected folder. */
	const FILE_PREFIX = 'wpac-import';

	/**
	 * Save the import state encrypted in a private file (protected folder, 0600).
	 *
	 * @param array $state State of PartnerImport::start()/step().
	 * @param array $meta  Transient data (path is set here).
	 * @return bool
	 */
	public static function save_state( array $state, array $meta ) {
		$cipher = Crypto::encrypt( (string) wp_json_encode( $state ) );
		$path   = isset( $meta['path'] ) ? (string) $meta['path'] : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			$path = PrivateFile::create( self::FILE_PREFIX, false, true );
		}
		if ( '' === $path || '' === $cipher || strlen( $cipher ) !== (int) file_put_contents( $path, $cipher ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		$meta['path'] = $path;
		$meta['hash'] = hash( 'sha256', $cipher );
		if ( ! set_transient( self::key(), $meta, 2 * HOUR_IN_SECONDS ) && get_transient( self::key() ) !== $meta ) {
			return false;
		}
		return true;
	}

	/**
	 * Load the import state of the current user.
	 *
	 * @return array|null [ state, meta ]
	 */
	public static function load_state() {
		$meta = get_transient( self::key() );
		if ( ! is_array( $meta ) || empty( $meta['path'] ) || ! is_readable( (string) $meta['path'] ) ) {
			return null;
		}
		$cipher = (string) file_get_contents( (string) $meta['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! hash_equals( (string) $meta['hash'], hash( 'sha256', $cipher ) ) ) {
			return null; // Replaced or damaged.
		}
		$state = json_decode( (string) Crypto::decrypt( $cipher ), true );
		return is_array( $state ) && isset( $state['rows'] ) ? array( $state, $meta ) : null;
	}

	/**
	 * Delete the import state of the current user (file and transient), and state files of
	 * imports that were abandoned more than a day ago.
	 */
	public static function delete_state() {
		$meta = get_transient( self::key() );
		if ( is_array( $meta ) && ! empty( $meta['path'] ) ) {
			PrivateFile::release( (string) $meta['path'] );
		}
		delete_transient( self::key() );
		foreach ( (array) glob( trailingslashit( PrivateFile::dir( true ) ) . self::FILE_PREFIX . '-*' ) as $old ) {
			if ( is_file( (string) $old ) && filemtime( (string) $old ) < time() - DAY_IN_SECONDS ) {
				PrivateFile::release( (string) $old );
			}
		}
	}

	/**
	 * Check the uploaded file, or import the checked file in batches.
	 */
	public static function handle_import() {
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
		}
		check_admin_referer( 'wpac_import', '_wpac_nonce' );
		$page = Admin::url( self::SLUG );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above (the progress page continues by GET).
		$step = isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : '';
		$opts = array(
			'max_rows'      => PartnerImport::ADMIN_MAX_ROWS,
			'allow_program' => current_user_can( Capabilities::MANAGE_PROGRAM ),
		);
		if ( 'run' === $step ) {
			self::run_batch( $page );
		}

		// Step 1: upload and check (nothing is written).
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
		$loaded = self::load_state();
		if ( $loaded && '' !== (string) $loaded[0]['started_at'] && empty( $loaded[0]['done'] ) ) {
			Admin::back( __( 'Your previous import has not finished yet. Continue it first.', 'affiliate-captain' ), 'warning', add_query_arg( 'step', 'progress', $page ) );
		}
		$csv = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			// Excel on Windows saves "CSV" in Windows-1252 unless "CSV UTF-8" is chosen.
			$csv = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $csv, 'UTF-8', 'Windows-1252' ) : $csv;
		}
		$notify = ! empty( $_POST['notify'] );
		$state  = PartnerImport::start( $csv, $opts + array( 'dry_run' => false, 'notify' => $notify ) );
		if ( is_wp_error( $state ) ) {
			Admin::back( $state->get_error_message(), 'error', $page );
		}
		self::delete_state();
		$meta = array(
			'notify' => $notify,
			'file'   => $name,
			'phase'  => 'preview',
		);
		if ( ! self::save_state( $state, $meta ) ) {
			self::delete_state();
			Admin::back( __( 'The checked file could not be stored for the import (file system or cache error). Please try again, or use WP-CLI: wp wpac import partners <file>', 'affiliate-captain' ), 'error', $page );
		}
		wp_safe_redirect( add_query_arg( 'step', 'preview', $page ) );
		exit;
	}

	/**
	 * Import the next batch of the checked file (one import at a time per site; a second
	 * submit continues the same import instead of starting it again).
	 *
	 * @param string $page Import page URL.
	 */
	private static function run_batch( $page ) {
		$loaded = self::load_state();
		if ( ! $loaded ) {
			Admin::back( __( 'The checked file has expired. Please upload it again.', 'affiliate-captain' ), 'error', $page );
		}
		list( $state, $meta ) = $loaded;
		if ( empty( $state['done'] ) ) {
			$owner = Db::lock( PartnerImport::LOCK, 120 );
			if ( '' === $owner ) {
				Admin::back( PartnerImport::running_error()->get_error_message(), 'warning', add_query_arg( 'step', 'progress', $page ) );
			}
			try {
				// Read again under the lock: a parallel request may have advanced it.
				$loaded = self::load_state();
				$state  = $loaded ? $loaded[0] : $state;
				$meta   = $loaded ? $loaded[1] : $meta;
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				PartnerImport::step( $state, self::BATCH_SECONDS );
				$meta['phase'] = empty( $state['done'] ) ? 'running' : 'result';
				$saved         = self::save_state( $state, $meta );
			} finally {
				Db::unlock( PartnerImport::LOCK, $owner );
			}
			if ( ! $saved ) {
				Admin::back( __( 'The import progress could not be saved. Already imported partners are kept; continue the import to complete the rest.', 'affiliate-captain' ), 'error', add_query_arg( 'step', 'progress', $page ) );
			}
			if ( empty( $state['done'] ) ) {
				wp_safe_redirect( add_query_arg( 'step', 'progress', $page ) );
				exit;
			}
		}
		$report = PartnerImport::report( $state );
		/* translators: %d: number of partners */
		$message = sprintf( _n( 'Import finished: %d partner created.', 'Import finished: %d partners created.', $report['created'], 'affiliate-captain' ), $report['created'] );
		if ( $report['skipped'] ) {
			/* translators: %d: number of rows */
			$message .= ' ' . sprintf( _n( '%d partner already existed and was skipped.', '%d partners already existed and were skipped.', $report['skipped'], 'affiliate-captain' ), $report['skipped'] );
		}
		if ( $report['warnings'] ) {
			/* translators: %d: number of partners */
			$message .= ' ' . sprintf( _n( '%d partner was created with a warning (see the list).', '%d partners were created with a warning (see the list).', $report['warnings'], 'affiliate-captain' ), $report['warnings'] );
		}
		if ( $report['failed'] ) {
			/* translators: %d: number of rows */
			$message .= ' ' . sprintf( _n( '%d row with errors was skipped.', '%d rows with errors were skipped.', $report['failed'], 'affiliate-captain' ), $report['failed'] );
		}
		$type = 'success';
		if ( $report['failed'] || $report['warnings'] ) {
			$type = $report['created'] ? 'warning' : 'error';
		}
		Admin::back( $message, $type, add_query_arg( 'step', 'result', $page ) );
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
		// The ZIP may contain decrypted IBANs: private file (0600, outside the web root where
		// possible), finished and deleted even when the browser disconnects or PHP stops early.
		ignore_user_abort( true );
		$file = \WPAffiliateCaptain\Core\PrivateFile::create( 'wpac-program-export' );
		$res  = $file ? ProgramExport::write( $file, $tax ) : new \WP_Error( 'wpac_export_zip', __( 'The export file could not be created.', 'affiliate-captain' ) );
		if ( is_wp_error( $res ) ) {
			\WPAffiliateCaptain\Core\PrivateFile::release( $file );
			Admin::back( $res->get_error_message(), 'error', add_query_arg( 'tab', 'export', Admin::url( self::SLUG ) ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'affiliate-program-' . gmdate( 'Y-m-d' ) . ( $tax ? '-with-bank-data' : '' ) . '.zip' ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $file ) );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		\WPAffiliateCaptain\Core\PrivateFile::release( $file );
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
	 * Import: upload form, check result, progress or import result.
	 */
	private static function render_import() {
		$step   = Admin::get( 'step' );
		$loaded = in_array( $step, array( 'preview', 'progress', 'result' ), true ) ? self::load_state() : null;
		if ( $loaded ) {
			list( $state, $meta ) = $loaded;
			if ( empty( $state['done'] ) && '' !== (string) $state['started_at'] ) {
				self::render_progress( $state );
				return;
			}
			self::render_report( $state, $meta, ! empty( $state['done'] ) );
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
		echo '<tr><th scope="row">' . esc_html__( 'E-mails', 'affiliate-captain' ) . '</th><td><label><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'Send a welcome e-mail to imported active partners (partner area, password or account link)', 'affiliate-captain' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Approval, rejection, pause and termination e-mails are never sent for imported partners. Off: no e-mails at all; partners then set their password with "Lost your password?" on the login page, and partners whose e-mail address already belongs to a customer account get the account link from the partner page ("Resend account link").', 'affiliate-captain' ) . '</p></td></tr></table>';
		submit_button( __( 'Check file', 'affiliate-captain' ) );
		echo '</form>';
	}

	/**
	 * Progress of a running import: continues with the next batch by itself (meta refresh, no
	 * script) and offers a button in case the browser does not follow it.
	 *
	 * @param array $state State.
	 */
	private static function render_progress( array $state ) {
		$report = PartnerImport::report( $state );
		$next   = wp_nonce_url( admin_url( 'admin-post.php?action=wpac_import&step=run' ), 'wpac_import', '_wpac_nonce' );
		echo '<meta http-equiv="refresh" content="1;url=' . esc_url( $next ) . '">';
		/* translators: 1: processed rows, 2: all rows */
		echo '<p role="status"><strong>' . esc_html( sprintf( __( 'Import running: %1$d of %2$d rows processed …', 'affiliate-captain' ), $report['done_rows'], $report['total'] ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'The import continues in batches. Keep this page open; if it stops, continue it with the button – nothing is imported twice.', 'affiliate-captain' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $next ) . '">' . esc_html__( 'Continue import', 'affiliate-captain' ) . '</a></p>';
	}

	/**
	 * Table of the check or import result.
	 *
	 * @param array $state State.
	 * @param array $meta  Transient data (file, notify).
	 * @param bool  $done  Import finished (else: check result).
	 */
	private static function render_report( array $state, array $meta, $done ) {
		$report = PartnerImport::report( $state );
		$ok     = 0;
		foreach ( $report['rows'] as $r ) {
			$ok += 'ok' === $r['result'] ? 1 : 0;
		}
		if ( ! $done ) {
			/* translators: %s: file name */
			echo '<p><strong>' . esc_html( sprintf( __( 'Check of %s (nothing has been saved yet):', 'affiliate-captain' ), isset( $meta['file'] ) ? $meta['file'] : '' ) ) . '</strong> ';
			/* translators: %d: number of partners */
			echo esc_html( sprintf( _n( '%d partner can be imported.', '%d partners can be imported.', $ok, 'affiliate-captain' ), $ok ) );
			if ( $report['skipped'] ) {
				/* translators: %d: number of rows */
				echo ' ' . esc_html( sprintf( _n( '%d partner already exists and will be skipped.', '%d partners already exist and will be skipped.', $report['skipped'], 'affiliate-captain' ), $report['skipped'] ) );
			}
			if ( $report['failed'] ) {
				/* translators: %d: number of rows */
				echo ' ' . esc_html( sprintf( _n( '%d row has errors and will be skipped.', '%d rows have errors and will be skipped.', $report['failed'], 'affiliate-captain' ), $report['failed'] ) );
			}
			echo '</p>';
		} elseif ( empty( $meta['notify'] ) && $report['created'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No e-mails were sent. Tell the imported partners that they can set their password with "Lost your password?" on the login page, using their e-mail address:', 'affiliate-captain' ) . ' <code>' . esc_html( wp_lostpassword_url() ) . '</code></p>';
			echo '<p>' . esc_html__( 'Partners whose e-mail address already belongs to a customer account are not linked to it yet: open the partner and click "Resend account link".', 'affiliate-captain' ) . '</p></div>';
		}
		if ( ! empty( $report['unknown'] ) ) {
			echo '<p>' . esc_html__( 'Ignored columns:', 'affiliate-captain' ) . ' <code>' . esc_html( implode( ', ', $report['unknown'] ) ) . '</code></p>';
		}
		$statuses = \WPAffiliateCaptain\Affiliates\AffiliateRepository::status_labels();
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Line', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Name', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'E-mail', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Codes', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Rate', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Result', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $report['rows'] as $line => $r ) {
			$d     = $r['data'];
			$rate  = $d['rate'] ? ( 'fixed' === $d['rate'][0] ? $d['rate'][1] . ' ' . \WPAffiliateCaptain\Core\Settings::get( 'currency' ) : $d['rate'][1] . ' %' ) : '—';
			$notes = array_merge( $r['notes'], isset( $r['warnings'] ) ? (array) $r['warnings'] : array() );
			$extra = $notes ? ' – ' . esc_html( implode( '; ', $notes ) ) : '';
			if ( 'error' === $r['result'] ) {
				$result = '<span class="wpac-badge wpac-badge--rejected">' . esc_html__( 'Error', 'affiliate-captain' ) . '</span> ' . esc_html( implode( '; ', $r['errors'] ) );
			} elseif ( 'skipped' === $r['result'] ) {
				$result = '<span class="wpac-badge">' . esc_html__( 'Skipped', 'affiliate-captain' ) . '</span> ' . esc_html( (string) $r['skip'] );
			} elseif ( ! empty( $r['id'] ) ) {
				$result = '<a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $r['id'] ) ) ) . '">' . esc_html__( 'Created', 'affiliate-captain' ) . '</a>' . $extra;
			} else {
				$result = esc_html__( 'OK', 'affiliate-captain' ) . $extra;
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
		echo '<p class="description">' . ( empty( $meta['notify'] ) ? esc_html__( 'No e-mails are sent to the imported partners.', 'affiliate-captain' ) : esc_html__( 'Imported active partners receive a welcome e-mail.', 'affiliate-captain' ) ) . '</p>';
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
