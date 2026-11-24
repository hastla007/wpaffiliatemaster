<?php
/**
 * WP-CLI: read, change, reset, export and import the settings (SET-AC-10). Uses the register and the
 * same checks as the settings page; secret values are never shown, private values only on request.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Cli;

use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;
use WPAffiliateCaptain\Core\SettingsSections;
use WPAffiliateCaptain\Core\SettingsTransfer;

defined( 'ABSPATH' ) || exit;

/**
 * Read and change the Affiliate Captain settings.
 *
 * On a multisite network the command works on one site (the main site or --url=…).
 */
final class SettingsCommand {

	/** @var bool Whether private values are shown (--with-private). */
	private static $private = false;

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
	 * [--with-private]
	 * : Also show bank details, addresses, tax numbers and e-mail addresses.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function list( $args, $assoc ) {
		self::$private = ! empty( $assoc['with-private'] );
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
	 * [--with-private]
	 * : Show the value also if it is private (bank details, addresses, tax numbers, e-mail addresses).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function get( $args, $assoc ) {
		self::$private = ! empty( $assoc['with-private'] );
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
	 * [--confirm=<word>]
	 * : Only for switching on delete_on_uninstall: DELETE, LÖSCHEN or LOESCHEN.
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
		$wanted = array( $key => (string) $args[1] );
		if ( 'delete_on_uninstall' === $key ) {
			// Switching on needs the typed word, as on the settings page: --confirm=LÖSCHEN.
			$wanted['delete_on_uninstall_confirm'] = isset( $assoc['confirm'] ) ? (string) $assoc['confirm'] : '';
		}
		self::apply( SettingsTransfer::check( $wanted, Settings::all() ), 'settings.cli' );
	}

	/**
	 * Reset settings to their defaults: one key, a tab, or all tabs. The program currency, the
	 * document number prefixes and the chosen pages are only reset when named as a key; private,
	 * secret and local values (maintenance mode, start numbers) never.
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
			$keys = empty( $f['secret'] ) && empty( $f['private'] ) && empty( $f['local'] ) ? array( $key ) : array();
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
	 * Apply a country profile (DE, AT, CH, EU, OTHER or one added by an add-on): shows every change
	 * (old → new) and applies it only with --yes. The values are not legally or tax reviewed.
	 *
	 * ## OPTIONS
	 *
	 * [<code>]
	 * : Profile code. Without it the profiles are listed.
	 *
	 * [--yes]
	 * : Apply the changes shown (without it nothing is changed).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function profile( $args, $assoc ) {
		if ( ! isset( $args[0] ) ) {
			foreach ( CountryProfiles::all() as $code => $p ) {
				\WP_CLI::line( sprintf( '%s: %s', $code, CountryProfiles::name( $code ) ) );
			}
			\WP_CLI::line( 'Proposed for this shop: ' . CountryProfiles::proposed() );
			return;
		}
		$code    = CountryProfiles::clean( $args[0] );
		$preview = CountryProfiles::preview( $code );
		if ( is_wp_error( $preview ) ) {
			\WP_CLI::error( $preview->get_error_message() );
		}
		\WP_CLI::line( 'Country profile ' . CountryProfiles::name( $code ) . ':' );
		self::print_changes( $preview );
		if ( empty( $assoc['yes'] ) ) {
			if ( $preview['changes'] ) {
				\WP_CLI::line( 'Nothing was changed. Run again with --yes to apply these changes.' );
			}
			return;
		}
		$preview['errors'] = array(); // Like on the settings page: only the valid values are applied.
		if ( ! CountryProfiles::apply( $code, $preview, 'cli' ) ) {
			\WP_CLI::error( 'The settings could not be saved (database error).' );
		}
		\WP_CLI::success( sprintf( '%d setting(s) changed.', count( $preview['values'] ) ) );
	}

	/**
	 * Export the settings as JSON (to standard output). Secret values are never included.
	 *
	 * ## OPTIONS
	 *
	 * [--with-private]
	 * : Also bank details, addresses, e-mail addresses and tax numbers.
	 *
	 * [--sections=<list>]
	 * : Also these sections, comma separated: groups, rules, tiers, mail_templates – or "all".
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function export( $args, $assoc ) {
		$sections = isset( $assoc['sections'] ) ? SettingsSections::clean( (string) $assoc['sections'] ) : array();
		if ( isset( $assoc['sections'] ) && ! $sections ) {
			\WP_CLI::error( 'Unknown sections. Use groups, rules, tiers, mail_templates or all.' );
		}
		\WP_CLI::line( (string) wp_json_encode( SettingsTransfer::export( ! empty( $assoc['with-private'] ), $sections ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
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
	 * [--sections=<list>]
	 * : Import only these sections of the file (groups, rules, tiers, mail_templates); default all in the file.
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
		if ( isset( $assoc['sections'] ) ) {
			$preview['sections_selected'] = SettingsSections::clean( (string) $assoc['sections'] );
			$preview['sections']          = array_intersect_key( $preview['sections'], array_flip( $preview['sections_selected'] ) );
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			self::print_changes( $preview );
			self::print_sections( $preview['sections'] );
			return;
		}
		self::print_sections( $preview['sections'] );
		self::apply( $preview, 'settings.imported', array( 'source' => 'cli' ) );
	}

	/**
	 * Print the preview of the sections of an import (SET-AC-08a): new, changed and rejected items.
	 *
	 * @param array $sections section => { items, counts }.
	 */
	private static function print_sections( array $sections ) {
		$labels = SettingsSections::labels();
		foreach ( $sections as $section => $data ) {
			$c = $data['counts'];
			\WP_CLI::line( sprintf( '%s: %d new, %d changed, %d unchanged, %d not imported', isset( $labels[ $section ] ) ? $labels[ $section ] : $section, $c['new'], $c['changed'], $c['unchanged'], $c['error'] ) );
			foreach ( $data['items'] as $item ) {
				if ( 'unchanged' === $item['status'] ) {
					continue;
				}
				$parts = array();
				foreach ( $item['changes'] as $field => $pair ) {
					$parts[] = $field . ': ' . $pair[0] . ' → ' . $pair[1];
				}
				$line = sprintf( '  %s  %s%s', $item['status'], $item['label'], $parts ? ' (' . implode( '; ', $parts ) . ')' : '' );
				if ( 'error' === $item['status'] ) {
					\WP_CLI::warning( trim( $line ) . ': ' . $item['message'] );
				} else {
					\WP_CLI::line( $line . ( '' !== $item['message'] ? ' – ' . $item['message'] : '' ) );
				}
			}
		}
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
	 * Value for the output: secret values are never shown, private values only with --with-private.
	 *
	 * @param string $key      Key.
	 * @param mixed  $value    Value.
	 * @param array|null $settings Optional: settings the value belongs to (formats amounts).
	 * @return string
	 */
	public static function shown( $key, $value, $settings = null ) {
		$f = SettingsSchema::field( $key );
		// Secret values never, private values only with --with-private (P4-04).
		if ( ! empty( $f['secret'] ) || ( ! empty( $f['private'] ) && ! self::$private ) ) {
			return '' === (string) $value ? '' : '(set)';
		}
		if ( is_array( $settings ) ) {
			// In a preview of changes amounts are shown formatted in their currency (P1-14).
			return SettingsTransfer::shown( (string) $key, $value, $settings );
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Print accepted but adjusted values (P3-12), e.g. amounts that keep their value after a
	 * currency change without exchange rate, or a retention hold that a file cannot lift.
	 *
	 * @param array $checked Result of a check.
	 */
	private static function print_notices( array $checked ) {
		foreach ( isset( $checked['notices'] ) ? (array) $checked['notices'] : array() as $key => $message ) {
			\WP_CLI::warning( $key . ': ' . $message );
		}
	}

	/**
	 * Print planned changes and rejected values.
	 *
	 * @param array $checked Result of a check.
	 */
	private static function print_changes( array $checked ) {
		list( $old, $new ) = SettingsTransfer::sides( $checked );
		foreach ( $checked['changes'] as $key => $pair ) {
			\WP_CLI::line( sprintf( '%s: %s → %s', $key, self::shown( $key, $pair[0], $old ), self::shown( $key, $pair[1], $new ) ) );
		}
		foreach ( $checked['errors'] as $key => $message ) {
			\WP_CLI::warning( $key . ': ' . $message );
		}
		self::print_notices( $checked );
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
		self::print_notices( $checked );
		if ( ! SettingsTransfer::apply( $checked, $action, $context ) ) {
			\WP_CLI::error( 'The settings could not be saved (database error).' );
		}
		foreach ( SettingsSections::summary( SettingsSections::last_report() ) as $line ) {
			\WP_CLI::line( $line );
		}
		if ( count( $checked['values'] ) ) {
			\WP_CLI::success( sprintf( '%d setting(s) changed.', count( $checked['values'] ) ) );
			return;
		}
		\WP_CLI::success( SettingsSections::last_report() ? 'No setting changed.' : 'Nothing to change.' );
	}
}
