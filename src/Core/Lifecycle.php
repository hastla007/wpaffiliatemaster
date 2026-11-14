<?php
/**
 * Lifecycle around installation and copies of the site: taking over the old plugin copy
 * ("wp-affiliate-captain", up to 0.10), an old main file left in the plugin folder, and copies
 * of the site (staging, clones) that must not act like the live shop.
 *
 * Loaded by the main file before the autoloader (take_over() runs before it), so it uses only
 * WordPress functions there.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/** File name of the old main file (folder "wp-affiliate-captain" or wherever it was put). */
	const LEGACY_FILE = 'wp-affiliate-captain.php';

	/** File name of the current main file. */
	const MAIN_FILE = 'affiliate-captain.php';

	/** Option (site and network): the last takeover of an old copy. */
	const TAKEOVER = 'wpac_legacy_takeover';

	/** Option: home URL (without scheme) the program was last running on. */
	const FINGERPRINT = 'wpac_site_fingerprint';

	/** Option: a copy of the site was detected (maintenance mode switched on). */
	const CLONE_OPTION = 'wpac_clone_detected';

	/**
	 * Whether an active plugin entry is the old copy (any folder, but never this copy itself).
	 *
	 * @param string $plugin Plugin basename.
	 * @param string $self   Basename of this copy.
	 * @return bool
	 */
	public static function is_legacy( $plugin, $self ) {
		return (string) $plugin !== (string) $self && self::LEGACY_FILE === basename( (string) $plugin );
	}

	/**
	 * Main file: the old copy is still active – this copy takes its place without running its
	 * deactivation or uninstall routine (both copies share tables, options and hooks).
	 *
	 * - In the site's plugin list the old copy is removed.
	 * - In the network list it is removed when this copy is network-active; otherwise this copy
	 *   takes over the network activation (removing it would switch the program off on every
	 *   other site of the network).
	 * - "Delete all data on uninstall" is switched off: deleting the old copy runs ITS uninstall
	 *   routine, which would otherwise drop the shared data (also when this copy is not loaded).
	 *
	 * @param string $self Basename of this copy (plugin_basename( __FILE__ )).
	 * @return string[] Entries taken over.
	 */
	public static function take_over( $self ) {
		$self   = (string) $self;
		$taken  = array();
		$active = (array) get_option( 'active_plugins', array() );
		$keep   = array();
		foreach ( $active as $plugin ) {
			if ( self::is_legacy( $plugin, $self ) ) {
				$taken[] = (string) $plugin;
			} else {
				$keep[] = $plugin;
			}
		}
		$network_wide = false;
		if ( is_multisite() ) {
			$network      = (array) get_site_option( 'active_sitewide_plugins', array() );
			$network_wide = isset( $network[ $self ] );
			$changed      = false;
			foreach ( array_keys( $network ) as $plugin ) {
				if ( ! self::is_legacy( $plugin, $self ) ) {
					continue;
				}
				$taken[] = (string) $plugin;
				if ( ! isset( $network[ $self ] ) ) {
					$network[ $self ] = $network[ $plugin ];
				}
				unset( $network[ $plugin ] );
				$changed      = true;
				$network_wide = true;
			}
			if ( $changed ) {
				update_site_option( 'active_sitewide_plugins', $network );
				update_site_option(
					self::TAKEOVER,
					array(
						'plugins' => array_values( array_unique( $taken ) ),
						'time'    => time(),
						'swept'   => 0,
					)
				);
			}
			if ( $network_wide ) {
				$keep = array_values( array_diff( $keep, array( $self ) ) ); // Not also per site.
			}
		}
		if ( ! $taken ) {
			return array();
		}
		if ( count( $keep ) !== count( $active ) ) {
			update_option( 'active_plugins', array_values( $keep ) );
		}
		self::disable_data_deletion();
		update_option(
			self::TAKEOVER,
			array(
				'plugins' => array_values( array_unique( $taken ) ),
				'time'    => time(),
				'network' => $network_wide ? 1 : 0,
			),
			false
		);
		return array_values( array_unique( $taken ) );
	}

	/**
	 * Switch "Delete all data on uninstall" off for the current site (old copy's uninstall).
	 *
	 * @return bool Whether it was on.
	 */
	public static function disable_data_deletion() {
		$settings = get_option( 'wpac_settings' );
		if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
			return false;
		}
		$settings['delete_on_uninstall'] = 0;
		update_option( 'wpac_settings', $settings, true );
		update_option( 'wpac_takeover_deletion_off', 1, false );
		if ( class_exists( Settings::class, false ) ) {
			Settings::flush_cache();
		}
		return true;
	}

	/**
	 * The plugin was loaded through an old main file that was left in the plugin folder (an
	 * update copied over an older copy by FTP/rsync): it defines older constants. The active
	 * entry is switched to the new main file in the same folder, which runs from the next
	 * request on (the schema version is a class constant, so nothing is downgraded meanwhile).
	 *
	 * @param string $file Main file that loaded the plugin (WPAC_FILE).
	 * @return bool Whether the entry was switched.
	 */
	public static function switch_main_file( $file ) {
		if ( self::LEGACY_FILE !== basename( (string) $file ) || ! is_readable( dirname( (string) $file ) . '/' . self::MAIN_FILE ) ) {
			return false;
		}
		$old     = plugin_basename( (string) $file );
		$new     = dirname( $old ) . '/' . self::MAIN_FILE;
		$changed = false;
		$active  = (array) get_option( 'active_plugins', array() );
		$pos     = array_search( $old, $active, true );
		if ( false !== $pos ) {
			$active[ $pos ] = $new;
			update_option( 'active_plugins', array_values( array_unique( $active ) ) );
			$changed = true;
		}
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( isset( $network[ $old ] ) ) {
				$network[ $new ] = $network[ $old ];
				unset( $network[ $old ] );
				update_site_option( 'active_sitewide_plugins', $network );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::disable_data_deletion(); // The old main file's folder holds the old uninstall routine too.
			update_option(
				self::TAKEOVER,
				array(
					'plugins' => array( $old ),
					'time'    => time(),
					'network' => 0,
				),
				false
			);
			Log::warning( 'An old main file was active; switched to the new one', array( 'old' => $old, 'new' => $new ) );
		}
		return $changed;
	}

	/** Seconds the network-wide follow-up of a takeover may spend per request. */
	const SWEEP_BUDGET = 10;

	/**
	 * After a takeover (first request of the new copy on a site): what the activation hook would
	 * have done, and on a network the "delete all data" switch of every site.
	 */
	public static function followup() {
		$site   = get_option( self::TAKEOVER );
		$net    = is_multisite() ? get_site_option( self::TAKEOVER ) : false;
		$latest = max( is_array( $site ) ? (int) $site['time'] : 0, is_array( $net ) ? (int) $net['time'] : 0 );
		if ( $latest && (int) get_option( 'wpac_takeover_seen', 0 ) < $latest ) {
			update_option( 'wpac_takeover_seen', $latest, false );
			self::disable_data_deletion();
			Capabilities::install();
			Cron::schedule();
			\WPAffiliateCaptain\Promotion\Landing::reset_rewrite();
			$plugins = is_array( $site ) ? (array) $site['plugins'] : ( is_array( $net ) ? (array) $net['plugins'] : array() );
			update_option(
				'wpac_takeover_notice',
				array(
					'plugins'      => $plugins,
					'deletion_off' => (int) get_option( 'wpac_takeover_deletion_off', 0 ),
				),
				false
			);
			delete_option( 'wpac_takeover_deletion_off' );
			Audit::log( 'setup.legacy_takeover', 'setup', 0, array( 'plugins' => $plugins ) );
		}
		if ( is_array( $net ) && empty( $net['swept'] ) ) {
			self::sweep_network( $net );
		}
	}

	/**
	 * Network takeover: switch "delete all data" off on every site (time budget; continues on
	 * the next request).
	 *
	 * @param array $net Network takeover record.
	 */
	private static function sweep_network( array $net ) {
		$start = microtime( true );
		$after = isset( $net['after'] ) ? (int) $net['after'] : 0;
		$ids   = array_map( 'intval', (array) get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) ) );
		sort( $ids );
		foreach ( $ids as $id ) {
			if ( $id <= $after ) {
				continue;
			}
			if ( microtime( true ) - $start > self::SWEEP_BUDGET ) {
				$net['after'] = $after;
				update_site_option( self::TAKEOVER, $net );
				return;
			}
			switch_to_blog( $id );
			try {
				self::disable_data_deletion();
			} finally {
				restore_current_blog();
			}
			$after = $id;
		}
		$net['swept'] = 1;
		unset( $net['after'] );
		update_site_option( self::TAKEOVER, $net );
	}

	/**
	 * Home URL without scheme (an http → https change is not a copy).
	 *
	 * @return string
	 */
	public static function site_fingerprint() {
		// The stored address, not home_url(): multilingual plugins filter home_url() per language.
		$home = (string) get_option( 'home' );
		$home = '' !== $home ? $home : (string) home_url();
		return untrailingslashit( (string) preg_replace( '#^[a-z]+://#i', '', strtolower( $home ) ) );
	}

	/**
	 * Hook callback for "admin_init" (actions return nothing): see check_clone().
	 */
	public static function run_check_clone() {
		self::check_clone();
	}

	/**
	 * Copy of the site (staging, clone, restored backup on another domain): when the home URL
	 * differs from the one the program last ran on, maintenance mode is switched on – the copy
	 * then sends no report mails, alarms, VIES requests or payouts to real partners until an
	 * admin switches it off (after a real move of the site). Runs on admin and cron requests.
	 *
	 * @return bool Whether a copy was detected now.
	 */
	public static function check_clone() {
		/**
		 * Whether a changed site URL switches the partner program into maintenance mode.
		 *
		 * @param bool $protect Default true.
		 */
		if ( ! apply_filters( 'wpac_clone_protection', true ) ) {
			return false;
		}
		$now = self::site_fingerprint();
		if ( '' === $now ) {
			return false;
		}
		$stored = get_option( self::FINGERPRINT );
		if ( ! is_string( $stored ) || '' === $stored ) {
			update_option( self::FINGERPRINT, $now, true );
			return false;
		}
		if ( $stored === $now ) {
			return false;
		}
		update_option( self::FINGERPRINT, $now, true );
		$was = (bool) Settings::get( 'maintenance_mode' );
		if ( ! $was ) {
			Settings::update( array( 'maintenance_mode' => 1 ) );
		}
		update_option(
			self::CLONE_OPTION,
			array(
				'from' => $stored,
				'to'   => $now,
				'time' => time(),
			),
			false
		);
		Audit::log(
			'setup.copy_detected',
			'settings',
			0,
			array(
				'from' => $stored,
				'to'   => $now,
			)
		);
		Log::warning(
			'Site URL changed: maintenance mode switched on',
			array(
				'from' => $stored,
				'to'   => $now,
			)
		);
		return true;
	}

	/**
	 * Whether outgoing messages to real people (alarms) are held back: a copy of the site was
	 * detected and maintenance mode is still on.
	 *
	 * @return bool
	 */
	public static function copy_paused() {
		if ( ! get_option( self::CLONE_OPTION ) ) {
			return false;
		}
		if ( ! Settings::get( 'maintenance_mode' ) ) {
			delete_option( self::CLONE_OPTION ); // The admin confirmed by switching maintenance off.
			return false;
		}
		return true;
	}

	/**
	 * Admin notices of the lifecycle (takeover, copy of the site, records kept from an earlier
	 * installation).
	 */
	public static function notices() {
		$clone = get_option( self::CLONE_OPTION );
		if ( is_array( $clone ) && self::copy_paused() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Affiliate Captain: this looks like a copy of the site.', 'affiliate-captain' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: 1: previous address, 2: current address */
					__( 'The partner program last ran on %1$s and now runs on %2$s. Maintenance mode was switched on so this copy sends no report mails, alarms, VIES requests or payouts to real partners. If the site was moved for good, switch maintenance mode off in the settings.', 'affiliate-captain' ),
					(string) $clone['from'],
					(string) $clone['to']
				)
			) . '</p></div>';
		}
		$takeover = get_option( 'wpac_takeover_notice' );
		if ( is_array( $takeover ) && current_user_can( 'activate_plugins' ) ) {
			delete_option( 'wpac_takeover_notice' ); // Shown once.
			echo '<div class="notice notice-info"><p>' . esc_html(
				sprintf(
					/* translators: %s: old plugin entries */
					__( 'Affiliate Captain took over from the old copy (%s): data, settings and schedules are kept. You can delete the old copy under Plugins.', 'affiliate-captain' ),
					implode( ', ', array_map( 'strval', (array) $takeover['plugins'] ) )
				)
			) . ( ! empty( $takeover['deletion_off'] ) ? ' ' . esc_html__( '"Delete all data on uninstall" was switched off so deleting the old copy cannot remove the shared data; switch it on again afterwards if you want.', 'affiliate-captain' ) : '' ) . '</p></div>';
		}
		$retained = get_option( 'wpac_retained_notice' );
		if ( is_array( $retained ) && current_user_can( 'activate_plugins' ) ) {
			delete_option( 'wpac_retained_notice' ); // Shown once.
			echo '<div class="notice notice-info"><p>' . esc_html(
				sprintf(
					/* translators: 1: date, 2: number */
					__( 'Affiliate Captain found booking records kept from an earlier installation (uninstalled %1$s, within their retention period). Their %2$d partner numbers stay reserved as "Deleted partner", so new partners never get them. "Delete all data on uninstall" is off.', 'affiliate-captain' ),
					(string) $retained['since'],
					(int) $retained['reserved']
				)
			) . '</p></div>';
		}
	}
}
