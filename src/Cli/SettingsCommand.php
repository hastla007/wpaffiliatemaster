<?php
/**
 * WP-CLI: read, change, reset, export and import the settings (SET-AC-10). Uses the register and the
 * same checks as the settings page; secret values are never shown, private values only on request.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Cli;

use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsTransfer;

defined( 'ABSPATH' ) || exit;

/**
 * Read and change the Affiliate Captain settings.
 *
 * On a multisite network the command works on one site (the main site or --url=…).
 */
final class SettingsCommand {

	/**
	 * List the settings: key, tab, value and default.
	 *
	 * ## OPTIONS
	 *
	 * [--tab=<tab>]
	 * : Only one tab (program, emails, tracking, security, commissions, promotion, payouts, data).
	 *
	 * [--format=<format>]
	 * : table (default), json or csv.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function list( $args, $assoc ) {
		$all  = Settings::all();
		$tab  = isset( $assoc['tab'] ) ? (string) $assoc['tab'] : '';
		$rows = array();
		foreach ( SettingsSchema::core() as $key => $f ) {
			if ( ! empty( $f['hidden'] ) || ( '' !== $tab && $f['tab'] !== $tab ) ) {
				continue;
			}
			$rows[] = array(
				'key'     => $key,
				'tab'     => $f['tab'],
				'value'   => self::shown( $key, isset( $all[ $key ] ) ? $all[ $key ] : '' ),
				'default' => self::shown( $key, $f['default'] ),
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'key', 'tab', 'value', 'default' ) );
	}

	/**
	 * Show one setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key (see "wp wpac setting list").
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function get( $args, $assoc ) {
		$key = self::key( $args );
		\WP_CLI::line( self::shown( $key, Settings::get( $key ) ) );
	}

	/**
	 * Change one setting (checked like on the settings page; amounts in minor units, e.g. 5000 = 50.00).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key.
	 *
	 * <value>
	 * : New value.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function set( $args, $assoc ) {
		$key = self::key( $args );
		if ( ! isset( $args[1] ) ) {
			\WP_CLI::error( 'Please give a value.' );
		}
		if ( ! empty( SettingsSchema::field( $key )['secret'] ) ) {
			\WP_CLI::error( 'This value cannot be set here; use the settings page.' );
		}
		self::apply( SettingsTransfer::check( array( $key => (string) $args[1] ), Settings::all() ), 'settings.cli' );
	}

	/**
	 * Reset settings to their defaults: one key, a tab, or all tabs. The program currency and the
	 * document number prefixes are only reset when named as a key; private and secret values never.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : One setting key.
	 *
	 * [--tab=<tab>]
	 * : All settings of a tab.
	 *
	 * [--all]
	 * : All tabs.
	 *
	 * [--dry-run]
	 * : Only show what would change.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function reset( $args, $assoc ) {
		$keys = array();
		if ( isset( $args[0] ) ) {
			$key  = self::key( $args );
			$f    = SettingsSchema::field( $key );
			$keys = empty( $f['secret'] ) && empty( $f['private'] ) ? array( $key ) : array();
		} elseif ( ! empty( $assoc['tab'] ) || ! empty( $assoc['all'] ) ) {
			$keys = SettingsTransfer::reset_keys( ! empty( $assoc['all'] ) ? '' : (string) $assoc['tab'] );
		} else {
			\WP_CLI::error( 'Name a key, --tab=<tab> or --all.' );
			return;
		}
		$preview = SettingsTransfer::reset_preview( $keys );
		if ( ! empty( $assoc['dry-run'] ) ) {
			self::print_changes( $preview );
			return;
		}
		self::apply( $preview, 'settings.reset' );
	}

	/**
	 * Export the settings as JSON (to standard output). Secret values are never included.
	 *
	 * ## OPTIONS
	 *
	 * [--with-private]
	 * : Also bank details, addresses, e-mail addresses and tax numbers.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function export( $args, $assoc ) {
		\WP_CLI::line( (string) wp_json_encode( SettingsTransfer::export( ! empty( $assoc['with-private'] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Import settings from a JSON export (checked like on the settings page).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the JSON file.
	 *
	 * [--dry-run]
	 * : Only show what would change.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function import( $args, $assoc ) {
		$file = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'File not found or not readable.' );
		}
		$preview = SettingsTransfer::preview( json_decode( (string) file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file given on the command line.
		if ( is_wp_error( $preview ) ) {
			\WP_CLI::error( $preview->get_error_message() );
		}
		if ( $preview['ignored'] ) {
			\WP_CLI::warning( 'Ignored (unknown or not importable): ' . implode( ', ', $preview['ignored'] ) );
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			self::print_changes( $preview );
			return;
		}
		self::apply( $preview, 'settings.imported', array( 'source' => 'cli' ) );
	}

	/**
	 * Known key from the arguments.
	 *
	 * @param array $args Positional.
	 * @return string
	 */
	private static function key( array $args ) {
		$key = isset( $args[0] ) ? (string) $args[0] : '';
		$f   = SettingsSchema::field( $key );
		if ( ! $f || isset( $f['option'] ) || ! empty( $f['hidden'] ) ) {
			\WP_CLI::error( 'Unknown setting: ' . $key );
		}
		return $key;
	}

	/**
	 * Value for the output: secret values are never shown.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function shown( $key, $value ) {
		$f = SettingsSchema::field( $key );
		if ( ! empty( $f['secret'] ) ) {
			return '' === (string) $value ? '' : '(set)';
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Print planned changes and rejected values.
	 *
	 * @param array $checked Result of a check.
	 */
	private static function print_changes( array $checked ) {
		foreach ( $checked['changes'] as $key => $pair ) {
			\WP_CLI::line( sprintf( '%s: %s → %s', $key, self::shown( $key, $pair[0] ), self::shown( $key, $pair[1] ) ) );
		}
		foreach ( $checked['errors'] as $key => $message ) {
			\WP_CLI::warning( $key . ': ' . $message );
		}
		if ( ! $checked['changes'] && ! $checked['errors'] ) {
			\WP_CLI::line( 'Nothing to change.' );
		}
	}

	/**
	 * Apply checked values, or stop with the rejected ones.
	 *
	 * @param array  $checked Result of a check.
	 * @param string $action  Audit action.
	 * @param array  $context Audit details.
	 */
	private static function apply( array $checked, $action, array $context = array() ) {
		if ( $checked['errors'] ) {
			foreach ( $checked['errors'] as $key => $message ) {
				\WP_CLI::warning( $key . ': ' . $message );
			}
			\WP_CLI::error( 'Nothing was changed: some values are not valid.' );
		}
		if ( ! SettingsTransfer::apply( $checked, $action, $context ) ) {
			\WP_CLI::error( 'The settings could not be saved (database error).' );
		}
		\WP_CLI::success( count( $checked['values'] ) ? sprintf( '%d setting(s) changed.', count( $checked['values'] ) ) : 'Nothing to change.' );
	}
}
