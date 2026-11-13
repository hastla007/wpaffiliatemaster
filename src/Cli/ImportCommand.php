<?php
/**
 * WP-CLI: `wp wpac import partners <file.csv> [--dry-run] [--notify]`.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Cli;

use WPAffiliateCaptain\Migration\PartnerImport;

defined( 'ABSPATH' ) || exit;

/**
 * Import data into Affiliate Captain.
 */
final class ImportCommand {

	/**
	 * Import partners from a CSV file (format: docs/IMPORT-EXPORT.md).
	 *
	 * Every row is checked first; rows with errors are skipped and listed.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file (UTF-8, separator ";" "," or tab, column names in the first line).
	 *
	 * [--dry-run]
	 * : Only check the file and show what would be imported.
	 *
	 * [--notify]
	 * : Send the usual e-mails to imported partners (approval, new account, account link). Off by default.
	 *
	 * [--format=<format>]
	 * : Output format of the row list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac import partners partners.csv --dry-run
	 *     wp wpac import partners partners.csv
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function partners( $args, $assoc ) {
		$file = (string) $args[0];
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( 'File not readable: ' . $file );
		}
		$dry    = ! empty( $assoc['dry-run'] );
		$report = PartnerImport::run(
			(string) file_get_contents( $file ),
			array(
				'dry_run'       => $dry,
				'notify'        => ! empty( $assoc['notify'] ),
				'allow_program' => true,
			)
		);
		if ( is_wp_error( $report ) ) {
			\WP_CLI::error( $report->get_error_message() );
		}
		$items = array();
		foreach ( $report['rows'] as $line => $r ) {
			if ( $r['errors'] ) {
				$result = 'error: ' . implode( '; ', $r['errors'] );
			} elseif ( isset( $r['id'] ) ) {
				$result = 'created #' . $r['id'];
			} else {
				$result = 'ok' . ( $r['notes'] ? ' (' . implode( '; ', $r['notes'] ) . ')' : '' );
			}
			$items[] = array(
				'line'   => $line,
				'email'  => $r['data']['email'],
				'status' => $r['data']['status'],
				'codes'  => implode( ' ', $r['data']['codes'] ),
				'result' => $result,
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $items, array( 'line', 'email', 'status', 'codes', 'result' ) );
		if ( $report['unknown'] ) {
			\WP_CLI::warning( 'Ignored columns: ' . implode( ', ', $report['unknown'] ) );
		}
		$ok = count( $report['rows'] ) - $report['failed'];
		if ( $dry ) {
			\WP_CLI::success( sprintf( 'Dry run: %d partners can be imported, %d rows have errors. Nothing was saved.', $ok, $report['failed'] ) );
			return;
		}
		if ( $report['failed'] ) {
			\WP_CLI::warning( sprintf( '%d partners created, %d rows skipped.', $report['created'], $report['failed'] ) );
			return;
		}
		\WP_CLI::success( sprintf( '%d partners created.', $report['created'] ) );
	}
}
