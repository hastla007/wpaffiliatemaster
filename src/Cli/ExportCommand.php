<?php
/**
 * WP-CLI: `wp wpac export <file.zip> [--include-tax-data]`.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Cli;

use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Migration\ProgramExport;

defined( 'ABSPATH' ) || exit;

/**
 * Export the whole partner program.
 */
final class ExportCommand {

	/**
	 * Export partners, groups, codes, rules, tiers, terms acceptances, commissions, payouts, payout
	 * items and payout requests as a ZIP of CSV files (format: docs/IMPORT-EXPORT.md).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Target ZIP file.
	 *
	 * [--include-tax-data]
	 * : Also export payout details with decrypted IBANs and the tax data of payouts. Needs
	 * --user=<login> of a user with the capability wpac_view_tax_data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpac export /secure/backup/affiliate-program.zip
	 *     wp wpac export /secure/backup/with-bank-data.zip --include-tax-data --user=admin
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function __invoke( $args, $assoc ) {
		$file = (string) $args[0];
		$tax  = ! empty( $assoc['include-tax-data'] );
		if ( $tax && ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			\WP_CLI::error( 'Tax and bank data need --user=<login> of a user with the capability wpac_view_tax_data.' );
		}
		$counts = ProgramExport::write( $file, $tax );
		if ( is_wp_error( $counts ) ) {
			\WP_CLI::error( $counts->get_error_message() );
		}
		foreach ( $counts as $name => $n ) {
			\WP_CLI::log( sprintf( '%-22s %d rows', $name, $n ) );
		}
		if ( $tax ) {
			\WP_CLI::warning( 'The file contains decrypted bank details: store it encrypted and delete it when it is no longer needed.' );
		}
		\WP_CLI::success( 'Exported to ' . $file );
	}
}
