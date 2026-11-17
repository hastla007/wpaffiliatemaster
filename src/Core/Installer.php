<?php
/**
 * Activation, schema and migrations.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const DB_OPTION = 'wpac_db_version';

	/**
	 * Schema version of this code. It lives in the class (not only in the main file), so an old
	 * main file left in the plugin folder (update copied over an older copy by FTP/rsync, see
	 * Lifecycle::switch_main_file()) can never make the new code run with an older schema version.
	 */
	const DB_VERSION = 16;

	/**
	 * Expected schema version: the class constant, or WPAC_DB_VERSION when the main file defines
	 * a higher one.
	 *
	 * @return int
	 */
	public static function db_version() {
		return max( self::DB_VERSION, defined( 'WPAC_DB_VERSION' ) ? (int) WPAC_DB_VERSION : 0 );
	}

	/**
	 * Activation hook. On a network-wide activation every site of the network is set up.
	 *
	 * @param bool $network_wide Network-wide activation (multisite).
	 */
	public static function activate( $network_wide = false ) {
		if ( ! $network_wide && function_exists( 'get_current_user_id' ) && get_current_user_id() ) {
			// Show the setup checklist once after a manual activation (Admin::activation_redirect()).
			update_option( 'wpac_activation_redirect', (int) get_current_user_id(), false );
		}
		if ( is_multisite() && $network_wide ) {
			// Small networks are set up right away; large ones in batches (cron) so the network
			// activation request never runs into the time limit. Every site also migrates itself
			// on its next admin or cron request (maybe_upgrade()).
			$ids = array_map( 'intval', (array) get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) ) );
			self::network_setup( $ids );
			return;
		}
		self::activate_site();
	}

	/** Seconds a network activation (or one of its cron batches) may spend on setting up sites. */
	const NETWORK_BUDGET = 15;

	/**
	 * Set up sites of a network within the time budget; the rest continues in single cron events.
	 *
	 * @param int[] $ids Site IDs.
	 */
	private static function network_setup( array $ids ) {
		$start = microtime( true );
		$done  = 0;
		foreach ( array_slice( $ids, 0, self::NETWORK_BATCH ) as $id ) {
			if ( $done > 0 && microtime( true ) - $start > self::NETWORK_BUDGET ) {
				break;
			}
			self::run_on_site( $id, array( __CLASS__, 'activate_site' ) );
			++$done;
		}
		$rest = array_slice( $ids, $done );
		if ( $rest ) {
			update_site_option( 'wpac_network_pending', $rest );
			Scheduler::once( self::NETWORK_HOOK, 30 );
		} else {
			delete_site_option( 'wpac_network_pending' );
		}
	}

	/**
	 * Set up the current site: schema, capabilities, cron, default settings.
	 *
	 * The schema is migrated like every other migration: under the migration lock, without time
	 * limit and with errors recorded (admin notice, retry) instead of a fatal "plugin could not be
	 * activated". The schema of a newer plugin version is never touched (downgrade marker stays).
	 */
	public static function activate_site() {
		\WPAffiliateCaptain\Promotion\Landing::reset_rewrite();
		if ( ! self::schema_current() ) {
			self::upgrade();
		}
		Capabilities::install();
		Cron::schedule();
		if ( false === get_option( Settings::OPTION ) ) {
			// New installation: fraud rules start with sensible thresholds instead of switched off.
			// Currency and country follow WooCommerce (R5-O54).
			add_option( Settings::OPTION, Settings::initial(), '', true );
		}
		self::adopt_retained();
		Lifecycle::check_clone();
	}

	/**
	 * Reinstallation after an uninstall that had to keep the booking records (retention period):
	 * partner IDs referenced by the kept records are reserved (pseudonymised placeholder partners),
	 * so a new partner never gets the ID – and with it the credit notes, balance and commissions –
	 * of a former one. The "delete all data" switch of the old installation is switched off.
	 *
	 * @return int Placeholder partners created.
	 */
	public static function adopt_retained() {
		global $wpdb;
		if ( ! get_option( 'wpac_retained_after_uninstall' ) ) {
			return 0;
		}
		$a       = Db::table( 'affiliates' );
		$created = 0;
		$sources = array();
		foreach ( array( 'payouts', 'balances', 'balance_entries', 'commissions', 'agreements' ) as $name ) {
			$table = Db::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$sources[] = "SELECT DISTINCT affiliate_id FROM {$table}";
			}
		}
		if ( $sources ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table names only.
			$ids = array_map( 'intval', (array) $wpdb->get_col( 'SELECT x.affiliate_id FROM (' . implode( ' UNION ', $sources ) . ") x LEFT JOIN {$a} f ON f.id = x.affiliate_id WHERE f.id IS NULL AND x.affiliate_id > 0" ) );
			foreach ( $ids as $id ) {
				$ok = $wpdb->insert(
					$a,
					array(
						'id'            => $id,
						'status'        => 'terminated',
						'slug'          => 'deleted-' . $id,
						'name'          => __( 'Deleted partner', 'affiliate-captain' ),
						'email'         => 'deleted-' . $id . '@invalid.invalid',
						'status_reason' => 'retained_after_uninstall',
						'created_at'    => Db::now(),
						'updated_at'    => Db::now(),
					)
				);
				if ( $ok ) {
					++$created;
				}
			}
		}
		$settings = get_option( Settings::OPTION );
		if ( is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] ) ) {
			$settings['delete_on_uninstall'] = 0;
			update_option( Settings::OPTION, $settings, true );
			Settings::flush_cache();
		}
		update_option( 'wpac_retained_notice', array( 'since' => (string) get_option( 'wpac_retained_after_uninstall' ), 'reserved' => $created ), false );
		delete_option( 'wpac_retained_after_uninstall' );
		Audit::log( 'setup.retained_adopted', 'setup', 0, array( 'reserved_ids' => $created ) );
		return $created;
	}

	/**
	 * Deactivation hook (data is kept).
	 *
	 * @param bool $network_wide Network-wide deactivation (multisite).
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// After this request the plugin code is no longer loaded, so the clean-up cannot continue
			// in later batches. It is kept cheap per site and limited in time; sites not reached keep
			// harmless leftovers (events without a handler, rules rebuilt on the next flush) that a
			// later activation or the uninstall clean up.
			delete_site_option( 'wpac_network_pending' );
			$start = microtime( true );
			$ids   = array_map( 'intval', (array) get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) ) );
			foreach ( $ids as $n => $id ) {
				if ( microtime( true ) - $start > self::DEACTIVATE_BUDGET ) {
					Log::warning( 'Network deactivation: not every site was cleaned up in time', array( 'sites_left' => count( $ids ) - $n ) );
					break;
				}
				self::run_on_site( $id, array( __CLASS__, 'deactivate_site' ) );
			}
			return;
		}
		self::deactivate_site();
	}

	/** Seconds a network-wide deactivation may spend on the per-site clean-up. */
	const DEACTIVATE_BUDGET = 20;

	public static function deactivate_site() {
		Cron::unschedule();
		delete_option( 'wpac_rewrite_flushed' );
		// The rules are rebuilt on the next request without this plugin. Flushing here would save
		// them with the plugin's rule (registered on init of this request) and, inside
		// switch_to_blog() on a network, with the rules of the site that runs the request.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Multisite: set up sites created later and drop the plugin tables when a site is deleted.
	 */
	public static function register_multisite_hooks() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( self::NETWORK_HOOK, array( __CLASS__, 'network_setup_batch' ) );
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 200 );
		// Before WordPress deletes the site's uploads folder (priority 10): keep credit notes that
		// are still within their retention period.
		add_action( 'wp_uninitialize_site', array( __CLASS__, 'archive_statements' ), 5 );
		// Per-request caches hold data of one site; drop them when code switches to another site.
		add_action( 'switch_blog', array( __CLASS__, 'flush_site_caches' ) );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_tables' ), 10, 2 );
	}

	const NETWORK_BATCH = 25;
	const NETWORK_HOOK  = 'wpac_network_setup';

	/**
	 * Set up the next sites of a large network after a network-wide activation (single cron events).
	 */
	public static function network_setup_batch() {
		$pending = array_map( 'intval', (array) get_site_option( 'wpac_network_pending', array() ) );
		if ( ! $pending ) {
			return;
		}
		self::network_setup( $pending );
	}

	/**
	 * Run a callback on one site.
	 *
	 * @param int      $id       Site ID.
	 * @param callable $callback Callback.
	 */
	private static function run_on_site( $id, callable $callback ) {
		switch_to_blog( (int) $id );
		try {
			call_user_func( $callback );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * A new site was created: set it up when the plugin is active for the whole network.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_new_site( $site ) {
		if ( ! self::network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Add the plugin tables to the tables WordPress drops when a site is deleted.
	 *
	 * @param string[] $tables  Table names.
	 * @param int      $blog_id Site ID.
	 * @return string[]
	 */
	public static function drop_tables( $tables, $blog_id ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( (int) $blog_id );
		$keep   = self::site_must_retain( (int) $blog_id );
		foreach ( Db::TABLES as $name ) {
			if ( $keep && in_array( $name, self::FINANCIAL_TABLES, true ) ) {
				continue; // Booking records within their retention period (dropped later by the daily sweep).
			}
			$tables[] = $prefix . 'wpac_' . $name;
		}
		if ( $keep ) {
			self::remember_retained_site( (int) $blog_id );
		}
		return $tables;
	}

	/**
	 * Tables with booking records that must be kept for the statutory retention period (the same
	 * list as in uninstall.php; the partners table is kept too, see there).
	 */
	const FINANCIAL_TABLES = array( 'affiliates', 'commissions', 'commission_events', 'payouts', 'payout_items', 'sequences', 'balances', 'balance_entries', 'audit', 'agreements', 'payout_corrections' );

	/** Site option: deleted sites whose booking records are kept (site ID => retention data). */
	const RETAINED_SITES = 'wpac_retained_sites';

	/**
	 * Whether a site still has credit notes within their retention period (or a legal hold).
	 *
	 * @param int $blog_id Site ID.
	 * @return bool
	 */
	public static function site_must_retain( $blog_id ) {
		global $wpdb;
		$switched = (int) $blog_id !== (int) get_current_blog_id();
		if ( $switched ) {
			switch_to_blog( (int) $blog_id );
		}
		try {
			$t = Db::table( 'payouts' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) {
				return false;
			}
			$cutoff = \WPAffiliateCaptain\Privacy\Retention::cutoff();
			if ( null === $cutoff ) {
				// Keep forever or legal hold: kept as long as there is any payout.
				return (bool) $wpdb->get_var( "SELECT 1 FROM {$t} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
			// Cancellations start their own period (storno_at).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE created_at > %s OR storno_at > %s LIMIT 1", $cutoff, $cutoff ) ) ) {
				return true;
			}
			// So do correction credit notes (issued later than the credit note they correct).
			$k = Db::table( 'payout_corrections' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $k ) ) ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$k} WHERE created_at > %s LIMIT 1", $cutoff ) );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Remember the retention data of a site that is being deleted (its options go with it).
	 *
	 * @param int $blog_id Site ID.
	 */
	private static function remember_retained_site( $blog_id ) {
		$switched = (int) $blog_id !== (int) get_current_blog_id();
		if ( $switched ) {
			switch_to_blog( (int) $blog_id );
		}
		$entry = array(
			'years'  => \WPAffiliateCaptain\Privacy\Retention::years(),
			'hold'   => \WPAffiliateCaptain\Privacy\Retention::on_hold() ? 1 : 0,
			'folder' => '',
			'time'   => time(),
		);
		if ( $switched ) {
			restore_current_blog();
		}
		$all = (array) get_site_option( self::RETAINED_SITES, array() );
		if ( isset( $all[ (int) $blog_id ]['folder'] ) ) {
			$entry['folder'] = (string) $all[ (int) $blog_id ]['folder'];
		}
		$all[ (int) $blog_id ] = $entry;
		update_site_option( self::RETAINED_SITES, $all );
		Log::warning( 'Booking records of a deleted site are kept for the retention period', array( 'site' => (int) $blog_id ) );
	}

	/**
	 * A site is being deleted: move its credit notes out of its uploads folder (which WordPress
	 * deletes) into a protected network folder while they are within the retention period. With
	 * WPAC_STATEMENT_DIR the site's subfolder is outside WordPress and stays where it is.
	 *
	 * @param \WP_Site|int $site Site.
	 */
	public static function archive_statements( $site ) {
		$blog_id = (int) ( is_object( $site ) ? $site->blog_id : $site );
		if ( $blog_id < 1 || ! self::site_must_retain( $blog_id ) ) {
			return;
		}
		switch_to_blog( $blog_id );
		$from = \WPAffiliateCaptain\Payouts\Statement::dir();
		restore_current_blog();
		$folder = $from;
		if ( ! defined( 'WPAC_STATEMENT_DIR' ) || '' === (string) WPAC_STATEMENT_DIR ) {
			switch_to_blog( (int) get_main_site_id() );
			$base = trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'wpac-retained';
			restore_current_blog();
			$folder = $base . '/site-' . $blog_id;
			wp_mkdir_p( $base );
			if ( ! file_exists( $base . '/.htaccess' ) ) {
				file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $base . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_dir( $from ) && ! file_exists( $folder ) && ! @rename( $from, $folder ) ) {
				Log::error( 'Credit notes of a deleted site could not be kept', array( 'site' => $blog_id, 'folder' => $from ) );
				$folder = '';
			}
		}
		$all                       = (array) get_site_option( self::RETAINED_SITES, array() );
		$all[ $blog_id ]           = isset( $all[ $blog_id ] ) ? (array) $all[ $blog_id ] : array( 'years' => 10, 'hold' => 0, 'time' => time() );
		$all[ $blog_id ]['folder'] = $folder;
		// With object storage the documents stay in the bucket under the site's prefix; they are
		// deleted from there after the retention period (delete_retained_objects()).
		switch_to_blog( $blog_id );
		$all[ $blog_id ]['store'] = (string) \WPAffiliateCaptain\Payouts\Statement::store()->id();
		restore_current_blog();
		update_site_option( self::RETAINED_SITES, $all );
	}

	/**
	 * Daily (multisite): drop the tables of sites that were deleted while this plugin was not
	 * loaded in the deleting request (it is only loaded where it is active; the wpmu_drop_tables
	 * filter above then never ran). A site counts as deleted when its WordPress options table is
	 * gone too (wpmu_delete_blog() drops it), so tables of other installations that merely share
	 * the prefix pattern are never touched. Runs at most once a day per network.
	 *
	 * @return int Sites cleaned up.
	 */
	public static function drop_orphaned_tables() {
		global $wpdb;
		if ( ! is_multisite() || get_site_transient( 'wpac_orphan_sweep' ) ) {
			return 0;
		}
		set_site_transient( 'wpac_orphan_sweep', 1, DAY_IN_SECONDS - 300 );
		$base = $wpdb->base_prefix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = array_merge(
			(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( '_wpac_affiliates' ) ) ),
			(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( '_wpac_payouts' ) ) )
		);
		$retained = (array) get_site_option( self::RETAINED_SITES, array() );
		$n        = 0;
		$seen     = array();
		/**
		 * Seconds the daily sweep may spend deleting kept credit notes of deleted sites from the
		 * document store (R15-P3); what is left is retried the next day.
		 *
		 * @since 0.16.0
		 * @param float $budget Seconds (default 20, 0 = no limit).
		 */
		$budget   = (float) apply_filters( 'wpac_retained_purge_budget', 20 );
		$deadline = $budget > 0 ? microtime( true ) + $budget : 0;
		foreach ( $found as $table ) {
			if ( ! preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)_wpac_(affiliates|payouts)$/i', (string) $table, $m ) ) {
				continue;
			}
			$blog_id = (int) $m[1];
			if ( $blog_id < 2 || isset( $seen[ $blog_id ] ) || get_site( $blog_id ) ) {
				continue;
			}
			$seen[ $blog_id ] = true;
			$prefix           = $base . $blog_id . '_';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'options' ) ) ) ) {
				continue; // Not a deleted site (or not one of this network).
			}
			$keep = self::deleted_site_retained( $prefix, isset( $retained[ $blog_id ] ) ? (array) $retained[ $blog_id ] : null );
			foreach ( Db::TABLES as $name ) {
				if ( $keep && in_array( $name, self::FINANCIAL_TABLES, true ) ) {
					continue;
				}
				$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $prefix . 'wpac_' . $name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
			if ( $keep ) {
				continue;
			}
			// Retention over: the kept credit notes go as well. The record stays until every kept
			// document is really gone (R15-P3), so a failure is retried the next day.
			if ( isset( $retained[ $blog_id ] ) ) {
				$retained = self::purge_retained_documents( (int) $blog_id, $retained, $deadline );
				update_site_option( self::RETAINED_SITES, $retained );
			}
			Log::warning( 'Dropped the partner program tables of a deleted site', array( 'site' => $blog_id ) );
			++$n;
		}
		// Kept folders of deleted sites whose tables are already gone.
		foreach ( $retained as $blog_id => $entry ) {
			if ( isset( $seen[ (int) $blog_id ] ) || get_site( (int) $blog_id ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base . (int) $blog_id . '_wpac_payouts' ) ) ) ) {
				$retained = self::purge_retained_documents( (int) $blog_id, $retained, $deadline );
				update_site_option( self::RETAINED_SITES, $retained );
			}
		}
		return $n;
	}

	/**
	 * Whether the kept booking records of a deleted site are still within their retention period.
	 *
	 * @param string     $prefix Table prefix of the deleted site.
	 * @param array|null $entry  Retention data remembered when the site was deleted (null: none,
	 *                           e.g. deleted while the plugin was not loaded: 10 years).
	 * @return bool
	 */
	private static function deleted_site_retained( $prefix, $entry ) {
		global $wpdb;
		$t = $prefix . 'wpac_payouts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) || ! $wpdb->get_var( 'SHOW COLUMNS FROM `' . esc_sql( $t ) . "` LIKE 'storno_at'" ) ) {
			return false;
		}
		$years = null === $entry || ! isset( $entry['years'] ) ? 10 : (int) $entry['years'];
		if ( ! empty( $entry['hold'] ) || $years <= 0 ) {
			return true; // Keep forever / legal hold: the network admin deletes the tables by hand.
		}
		$cutoff = gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( $years + 1 ) . ' years' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . esc_sql( $t ) . '` WHERE created_at > %s OR storno_at > %s LIMIT 1', $cutoff, $cutoff ) ) ) {
			return true;
		}
		// Correction credit notes start their own period (as in site_must_retain()).
		$k = $prefix . 'wpac_payout_corrections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $k ) ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . esc_sql( $k ) . '` WHERE created_at > %s LIMIT 1', $cutoff ) );
	}

	/**
	 * Delete the kept credit notes of a deleted site (folder and document store) after the
	 * retention period. The record is removed only when everything is gone; otherwise it stays
	 * with the number of failed attempts and the last error (Site Health), and the next daily
	 * sweep tries again (R15-P3).
	 *
	 * @param int   $blog_id  Deleted site.
	 * @param array $retained All records (RETAINED_SITES).
	 * @param float $deadline microtime( true ) after which no store request is started (0 = none).
	 * @return array Records after the attempt.
	 */
	public static function purge_retained_documents( $blog_id, array $retained, $deadline = 0 ) {
		$entry  = isset( $retained[ $blog_id ] ) ? (array) $retained[ $blog_id ] : array();
		$folder = self::delete_retained_folder( isset( $entry['folder'] ) ? (string) $entry['folder'] : '' );
		$error  = '';
		$store  = self::delete_retained_objects( (int) $blog_id, $entry, $deadline, $error );
		if ( $folder && $store ) {
			unset( $retained[ $blog_id ] );
			return $retained;
		}
		$entry['failures']    = ( isset( $entry['failures'] ) ? (int) $entry['failures'] : 0 ) + 1;
		$entry['last_error']  = Db::cut( $store ? 'folder could not be deleted' : $error, 300 );
		$entry['last_try']    = time();
		$retained[ $blog_id ] = $entry;
		Log::error( 'Credit notes of a deleted site could not be deleted; tried again tomorrow', array( 'site' => (int) $blog_id, 'failures' => $entry['failures'], 'cause' => $entry['last_error'] ) );
		return $retained;
	}

	/** Option: documents an earlier uninstall could not delete from the document store (R15-P2). */
	const UNINSTALL_LEFT = 'wpac_uninstall_store_left';

	/**
	 * Daily: delete the documents an earlier uninstall could not delete from the document store
	 * within its time budget (the plugin was installed again). Names the payouts or correction
	 * credit notes of this installation refer to are never deleted (numbers can repeat after a
	 * new installation); they are dropped from the record.
	 *
	 * @return int Documents deleted.
	 */
	public static function retry_uninstall_leftovers() {
		global $wpdb;
		$left = get_option( self::UNINSTALL_LEFT );
		if ( ! is_array( $left ) ) {
			return 0;
		}
		$names = isset( $left['names'] ) ? array_values( array_unique( array_map( 'strval', (array) $left['names'] ) ) ) : array();
		$used  = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names only.
		foreach ( (array) $wpdb->get_results( 'SELECT statement_file, storno_file FROM ' . Db::table( 'payouts' ) . " WHERE statement_file <> '' OR storno_file <> ''" ) as $row ) {
			$used[] = basename( (string) $row->statement_file );
			$used[] = basename( (string) $row->storno_file );
		}
		foreach ( (array) $wpdb->get_col( 'SELECT file FROM ' . Db::table( 'payout_corrections' ) . " WHERE file <> ''" ) as $file ) {
			$used[] = basename( (string) $file );
		}
		// phpcs:enable
		$names = array_values( array_diff( $names, $used ) );
		if ( ! $names ) {
			delete_option( self::UNINSTALL_LEFT );
			return 0;
		}
		$store = \WPAffiliateCaptain\Payouts\Statement::store();
		if ( ! isset( $left['store'] ) || (string) $store->id() !== (string) $left['store'] ) {
			return 0; // Another store now: kept for tools/purge-retained.php or the old store's return.
		}
		$res = \WPAffiliateCaptain\Payouts\Storage\StoreCleanup::delete_names( $store, $names, microtime( true ) + 20 );
		if ( ! $res['left'] ) {
			delete_option( self::UNINSTALL_LEFT );
			return count( $names );
		}
		$left['names']    = $res['left'];
		$left['failures'] = ( isset( $left['failures'] ) ? (int) $left['failures'] : 0 ) + 1;
		$left['error']    = Db::cut( (string) $res['error'], 300 );
		update_option( self::UNINSTALL_LEFT, $left, false );
		return count( $names ) - count( $res['left'] );
	}

	/**
	 * Delete the kept credit notes of a deleted site from its document store (after the
	 * retention period): every document under the site's location, through the store abstraction
	 * (object storage of the WPAC_S3_* constants or a store of the filter wpac_statement_store,
	 * which runs while switched to the deleted site). The local folder is handled by
	 * delete_retained_folder().
	 *
	 * @param int    $blog_id  Deleted site.
	 * @param array  $entry    Retention data remembered when the site was deleted.
	 * @param float  $deadline microtime( true ) after which no request is started (0 = none).
	 * @param string $error    Set to the reason of a failure.
	 * @return bool Whether nothing is left in the store.
	 */
	private static function delete_retained_objects( $blog_id, array $entry, $deadline, &$error ) {
		$id = isset( $entry['store'] ) ? (string) $entry['store'] : '';
		if ( '' === $id || 'local' === $id ) {
			return true;
		}
		switch_to_blog( (int) $blog_id );
		$store = \WPAffiliateCaptain\Payouts\Statement::store();
		restore_current_blog();
		if ( (string) $store->id() !== $id ) {
			$error = 'document store "' . $id . '" not configured';
			return false;
		}
		$res   = \WPAffiliateCaptain\Payouts\Storage\StoreCleanup::delete_all( $store, $deadline );
		$error = (string) $res['error'];
		return $res['ok'];
	}

	/**
	 * Delete the kept credit note folder of a deleted site (after the retention period).
	 *
	 * @param string $folder Folder.
	 * @return bool Whether the folder is gone (or there was none).
	 */
	private static function delete_retained_folder( $folder ) {
		if ( '' === $folder || ! is_dir( $folder ) || ! preg_match( '#/site-\d+$#', untrailingslashit( $folder ) ) ) {
			return true;
		}
		foreach ( (array) scandir( $folder ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && is_file( $folder . '/' . $entry ) ) {
				wp_delete_file( $folder . '/' . $entry );
			}
		}
		@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		clearstatcache();
		return ! is_dir( $folder );
	}

	public static function flush_site_caches() {
		Settings::flush_cache();
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::clear_cache();
	}

	public static function network_active() {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( plugin_basename( WPAC_FILE ) );
	}

	/** Lock that lets only one request migrate. */
	const LOCK = 'wpac_upgrade_lock';

	/** @var string Owner value of the migration lock while this request migrates. */
	private static $lock_owner = '';

	/** @var int When the lock was last extended. */
	private static $lock_refreshed = 0;

	/**
	 * Run migrations when the stored schema version is outdated.
	 *
	 * Migrations run in cron and WP-CLI requests and in admin page requests of administrators -
	 * never inline in a checkout, payment webhook, REST or admin-ajax call (admin-ajax.php also
	 * serves anonymous visitors). Background work waits meanwhile (schema_current()).
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::db_version() ) {
			self::clear_stale_problems();
			if ( ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) && ! self::downgraded_from() ) {
				self::maybe_retry_indexes(); // Performance indexes that could not be added online.
			}
			return; // Current, or the schema of a newer version (downgrade, see downgraded_from()).
		}
		// A migration that failed is retried at most every 5 minutes (not on every page load).
		if ( get_option( 'wpac_schema_problems' ) && get_transient( 'wpac_upgrade_retry' ) ) {
			return;
		}
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::upgrade();
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			// The user is only known later: decide on admin_init.
			add_action( 'admin_init', array( __CLASS__, 'upgrade_for_admin' ), 0 );
			return;
		}
		self::ensure_cron();
	}

	/**
	 * Admin request: migrate only for administrators (a cron request does it otherwise).
	 */
	public static function upgrade_for_admin() {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::db_version() ) {
			return;
		}
		if ( current_user_can( 'activate_plugins' ) || current_user_can( 'manage_options' ) ) {
			self::upgrade();
			return;
		}
		self::ensure_cron();
	}

	/**
	 * Make sure a cron request comes to run the migration.
	 */
	private static function ensure_cron() {
		if ( ! wp_next_scheduled( Cron::HOURLY ) ) {
			Cron::schedule();
		}
	}

	/**
	 * Migrate now under the migration lock (also WP-CLI "wp wpac upgrade"). The lock is extended
	 * between the steps, so a long migration is never taken over by a second request.
	 *
	 * @return bool False when another request is migrating right now.
	 */
	public static function upgrade() {
		$owner = Db::lock( self::LOCK, self::LOCK_TTL );
		if ( '' === $owner ) {
			return false; // Another request is migrating.
		}
		self::$lock_owner     = $owner;
		self::$lock_refreshed = time();
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			self::install_schema();
			Capabilities::install();
			Cron::schedule();
		} catch ( \Throwable $e ) {
			if ( $e instanceof IntegrationException && 'upgrade_lock_lost' === $e->error_code ) {
				// Another request took the migration over and finishes it: not a failure of the
				// schema (recording one would leave a notice that never goes away).
				Log::warning( 'Database migration handed over to another request' );
				return true;
			}
			// Recorded like an incomplete schema: admin notice, Site Health, alarm after an hour, retry.
			if ( ! get_option( 'wpac_schema_problems_since' ) ) {
				update_option( 'wpac_schema_problems_since', time(), false );
			}
			update_option( 'wpac_schema_problems', array( 'migration aborted: ' . Db::cut( $e->getMessage(), 300 ) ), false );
			set_transient( 'wpac_upgrade_retry', 1, 5 * MINUTE_IN_SECONDS );
			Log::exception( 'Database migration aborted', $e );
		} finally {
			Db::unlock( self::LOCK, self::$lock_owner );
			self::$lock_owner = '';
		}
		return true;
	}

	/**
	 * A recorded migration problem that no longer applies (e.g. written by a request whose
	 * migration was finished by another one): verify the current schema and clear it.
	 */
	public static function clear_stale_problems() {
		if ( ! get_option( 'wpac_schema_problems' ) || ! self::schema_current() || self::downgraded_from() ) {
			return;
		}
		if ( ! self::verify_schema() ) {
			delete_option( 'wpac_schema_problems' );
			delete_option( 'wpac_schema_problems_since' );
			delete_transient( 'wpac_upgrade_retry' );
		}
	}

	/** Seconds after which the migration lock counts as abandoned (without being extended). */
	const LOCK_TTL = 900;

	/**
	 * Extend the migration lock between steps (at most every 30 seconds). Stops the migration
	 * when the lock was lost meanwhile (another request must not migrate in parallel).
	 *
	 * @throws IntegrationException When the lock was taken over.
	 */
	private static function keep_lock() {
		if ( '' === self::$lock_owner || time() - self::$lock_refreshed < 30 ) {
			return;
		}
		$owner = Db::refresh( self::LOCK, self::$lock_owner );
		if ( '' === $owner ) {
			throw new IntegrationException( 'upgrade_lock_lost', 'The migration lock was taken over by another request.' );
		}
		self::$lock_owner     = $owner;
		self::$lock_refreshed = time();
	}

	/**
	 * Schema version of a newer plugin version when the plugin was downgraded, otherwise 0.
	 *
	 * @return int
	 */
	public static function downgraded_from() {
		$stored = (int) get_option( self::DB_OPTION, 0 );
		return $stored > self::db_version() ? $stored : 0;
	}

	/**
	 * Whether the stored schema matches this plugin version (background work waits otherwise).
	 *
	 * @return bool
	 */
	public static function schema_current() {
		return (int) get_option( self::DB_OPTION, 0 ) >= self::db_version();
	}

	/**
	 * Problems of the schema after a migration: missing tables or columns this version needs,
	 * tables without transactions (not InnoDB). Empty = fine.
	 *
	 * @return string[]
	 */
	public static function verify_schema() {
		global $wpdb;
		$problems = array();
		$required = array(
			'affiliates'      => array( 'id', 'email', 'status', 'status_reason', 'referred_by' ),
			'commissions'     => array( 'id', 'idempotency_key', 'payout_id', 'rule_snapshot' ),
			'payouts'         => array( 'id', 'number', 'storno_number', 'statement_file', 'statement_deleted_at', 'operator_snapshot' ),
			'payout_items'    => array( 'id', 'payout_id', 'commission_id', 'vat_mode', 'vat_rate', 'ref_payout_id' ),
			'payout_profiles' => array( 'affiliate_id', 'tax_number' ),
			'payout_corrections' => array( 'id', 'payout_id', 'number', 'request_key', 'commission_id', 'file', 'hash' ),
			'balances'        => array( 'affiliate_id', 'last_hash', 'verified_id', 'verified_hash' ),
			'balance_entries' => array( 'id', 'hash', 'prev_hash' ),
			'jobs'            => array( 'id', 'idempotency_key', 'attempts' ),
			'attributions'    => array( 'id', 'content_id', 'campaign', 'sub_id', 'visit_id' ),
			'visits'          => array( 'id', 'content_id', 'campaign', 'sub_id' ),
			'visits_daily'    => array( 'id', 'affiliate_id', 'day', 'campaign', 'sub_id', 'visits' ),
			'customer_links'  => array( 'id', 'customer_hash', 'pending' ),
		);
		// Existing tables of this plugin, compared without case: with lower_case_table_names=1
		// (Windows, some macOS/managed MySQL) the server reports names in lower case.
		$own = array();
		foreach ( self::table_names() as $name ) {
			$own[ strtolower( Db::table( $name ) ) ] = $name;
		}
		$in    = implode( ',', array_fill( 0, count( $own ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) IN ({$in})", array_keys( $own ) ) );
		$have  = array();
		foreach ( (array) $found as $row ) {
			$have[ strtolower( (string) $row->t ) ] = $row;
		}
		$warnings = array();
		foreach ( $required as $table => $columns ) {
			$name = Db::table( $table );
			if ( ! isset( $have[ strtolower( $name ) ] ) ) {
				$problems[] = 'missing table ' . $name;
				continue;
			}
			$real = (string) $have[ strtolower( $name ) ]->t;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$cols = array_map( 'strtolower', array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$real}`" ) ) );
			foreach ( array_diff( $columns, $cols ) as $missing ) {
				$problems[] = 'missing column ' . $name . '.' . $missing;
			}
		}
		// v13/v14: indexes the order filters, the audit log sort and the partner statistics rely
		// on (added or replaced online, see add_online_indexes(); dbDelta otherwise).
		foreach ( self::ONLINE_INDEXES as $table => $keys ) {
			$name = Db::table( $table );
			if ( ! isset( $have[ strtolower( $name ) ] ) ) {
				continue;
			}
			$found_keys = self::key_columns( (string) $have[ strtolower( $name ) ]->t );
			foreach ( $keys as $key => $columns ) {
				if ( ! isset( $found_keys[ $key ] ) ) {
					// Performance only: bookings work without it, so it must not stop them (R13-M8).
					$warnings[] = 'missing index ' . $name . '.' . $key . ' (performance only; retried every 5 minutes or by wp wpac upgrade)';
				} elseif ( $found_keys[ $key ] !== $columns ) {
					// Same for a key that still has the columns of an older schema (R14-D1).
					$warnings[] = 'missing index ' . $name . '.' . $key . ' (' . $columns . '; still ' . $found_keys[ $key ] . '; performance only; retried every 5 minutes or by wp wpac upgrade)';
				}
			}
		}
		foreach ( $have as $row ) {
			if ( $row->e && 'innodb' !== strtolower( (string) $row->e ) ) {
				// Not a blocker (the data is there), but without InnoDB there are no transactions.
				$warnings[] = 'table ' . $row->t . ' uses ' . $row->e . ' (InnoDB recommended for transactions)';
			}
		}
		if ( $warnings ) {
			update_option( 'wpac_schema_warnings', $warnings, false );
		} else {
			delete_option( 'wpac_schema_warnings' );
		}
		return $problems;
	}

	/**
	 * Names (without prefix) of all tables of this plugin.
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return Db::TABLES;
	}

	/**
	 * Alarm when a migration has been failing for over an hour (mail at most once a day; the
	 * admin notice and Site Health show it all the time).
	 */
	public static function alert_if_stuck() {
		$problems = get_option( 'wpac_schema_problems' );
		$since    = (int) get_option( 'wpac_schema_problems_since' );
		if ( ! $problems || ! $since || $since > time() - HOUR_IN_SECONDS || get_transient( 'wpac_schema_alerted' ) || Lifecycle::copy_paused() ) {
			return;
		}
		set_transient( 'wpac_schema_alerted', 1, DAY_IN_SECONDS );
		\WPAffiliateCaptain\Affiliates\Notifications::send(
			'schema_stuck',
			\WPAffiliateCaptain\Affiliates\Notifications::admin_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] Partner program: database update is stuck', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: %s: problems */
			sprintf( __( "The database update of Affiliate Captain has not completed for over an hour. Approvals, payouts and mails wait until it is done; tracking and privacy clean-ups keep running.\n\nProblems:\n%s\n\nPlease check the database user's rights (ALTER, CREATE, INDEX) or run: wp wpac upgrade", 'affiliate-captain' ), implode( "\n", (array) $problems ) ),
			array( 'admin' => true )
		);
		Audit::log( 'schema.stuck', 'setup', 0, array( 'problems' => count( (array) $problems ) ) );
	}

	/**
	 * Indexes for performance only (schema 13): table => key => columns. Added online before
	 * dbDelta; missing ones are warnings, not blockers (R13-M8). An existing key with other
	 * columns is replaced online as well (schema 14: visits.affiliate_created also covers
	 * campaign and sub-ID for the partner statistics, R14-D1; a second, wider key would not be
	 * chosen by the optimizer while the narrow one exists).
	 */
	const ONLINE_INDEXES = array(
		'commissions' => array( 'source_order_id' => 'source_order_id' ),
		'audit'       => array( 'action_created' => 'action,created_at' ),
		'visits'      => array( 'affiliate_created' => 'affiliate_id,created_at,campaign,sub_id' ),
	);

	/**
	 * Columns of the keys of a table, in key order: key name (lower case) => "col1,col2".
	 *
	 * @param string $table Real table name.
	 * @return array
	 */
	private static function key_columns( $table ) {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table name only.
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`" ) as $row ) {
			$row = array_change_key_case( (array) $row, CASE_LOWER );
			if ( ! isset( $row['key_name'], $row['column_name'] ) ) {
				continue;
			}
			$out[ strtolower( (string) $row['key_name'] ) ][ (int) $row['seq_in_index'] ] = strtolower( (string) $row['column_name'] );
		}
		foreach ( $out as $key => $columns ) {
			ksort( $columns );
			$out[ $key ] = implode( ',', $columns );
		}
		return $out;
	}

	/**
	 * Limit the time schema changes of this session wait for a metadata lock.
	 *
	 * @return string|null Previous value to restore, null when unchanged.
	 */
	private static function limit_lock_wait() {
		global $wpdb;
		/**
		 * Seconds a schema change of the migration waits for a metadata lock before it gives up
		 * (and is retried after 5 minutes). 0 = server default (up to a day or a year).
		 *
		 * @since 0.13.0
		 * @param int $seconds Default 10.
		 */
		$seconds = (int) apply_filters( 'wpac_migration_lock_wait_timeout', 10 );
		if ( $seconds <= 0 ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$old = $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
		if ( null === $old || '' === (string) $old ) {
			return null;
		}
		$wpdb->query( $wpdb->prepare( 'SET SESSION lock_wait_timeout = %d', $seconds ) );
		// phpcs:enable
		return (string) $old;
	}

	/**
	 * Restore the session's lock wait timeout.
	 *
	 * @param string|null $old Value from limit_lock_wait().
	 */
	private static function restore_lock_wait( $old ) {
		global $wpdb;
		if ( null !== $old && ctype_digit( (string) $old ) ) {
			$wpdb->query( $wpdb->prepare( 'SET SESSION lock_wait_timeout = %d', (int) $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Add missing performance indexes without blocking writes (ALGORITHM=INPLACE, LOCK=NONE):
	 * the server refuses at once where that is not possible (MyISAM, older servers) instead of
	 * copying the table under a write lock. A key with the columns of an older schema is
	 * replaced in the same statement (dropped and added again, R14-D1), so the table is never
	 * without it.
	 *
	 * @return array Table key => keys that could not be added (left out of dbDelta).
	 */
	public static function add_online_indexes() {
		global $wpdb;
		$skipped = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( self::ONLINE_INDEXES as $table => $keys ) {
			$name = Db::table( $table );
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
				continue; // New table: dbDelta creates it with its indexes.
			}
			$found = self::key_columns( $name );
			foreach ( $keys as $key => $columns ) {
				if ( isset( $found[ $key ] ) && $found[ $key ] === $columns ) {
					continue;
				}
				$drop     = isset( $found[ $key ] ) ? "DROP KEY {$key}, " : '';
				$suppress = $wpdb->suppress_errors( true );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own table, key and column names only.
				$ok = false !== $wpdb->query( "ALTER TABLE {$name} {$drop}ADD KEY {$key} ({$columns}), ALGORITHM=INPLACE, LOCK=NONE" );
				$error = (string) $wpdb->last_error;
				$wpdb->suppress_errors( $suppress );
				if ( ! $ok || '' !== $error ) {
					$skipped[ $table ][] = $key;
					Log::warning( 'Performance index not added (retried later)', array( 'table' => $name, 'index' => $key, 'error' => Db::cut( $error, 190 ) ) );
				}
				self::keep_lock();
			}
		}
		// phpcs:enable
		return $skipped;
	}

	/**
	 * A CREATE TABLE statement without the keys that could not be added online (dbDelta would
	 * otherwise add them with a blocking table copy).
	 *
	 * @param string $statement CREATE TABLE statement.
	 * @param array  $skipped   Table key => keys.
	 * @return string
	 */
	private static function without_indexes( $statement, array $skipped ) {
		foreach ( $skipped as $table => $keys ) {
			if ( ! preg_match( '/^\s*CREATE TABLE ' . preg_quote( Db::table( $table ), '/' ) . '\s/', $statement ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				$statement = (string) preg_replace( '/,\s*KEY ' . preg_quote( $key, '/' ) . ' \([^)]*\)/', '', $statement );
			}
		}
		return $statement;
	}

	/**
	 * Retry missing performance indexes (at most every 5 minutes, cron and WP-CLI only, under the
	 * migration lock) once the schema is current.
	 */
	public static function maybe_retry_indexes() {
		$warnings = get_option( 'wpac_schema_warnings' );
		if ( ! is_array( $warnings ) || ! preg_grep( '/^missing index /', $warnings ) || get_transient( 'wpac_index_retry' ) ) {
			return;
		}
		set_transient( 'wpac_index_retry', 1, 5 * MINUTE_IN_SECONDS );
		$owner = Db::lock( self::LOCK, self::LOCK_TTL );
		if ( '' === $owner ) {
			return;
		}
		self::$lock_owner     = $owner;
		self::$lock_refreshed = time();
		$restore              = self::limit_lock_wait();
		try {
			self::add_online_indexes();
			self::verify_schema(); // Updates the warnings.
		} catch ( \Throwable $e ) {
			Log::exception( 'Index retry failed', $e );
		} finally {
			self::restore_lock_wait( $restore );
			Db::unlock( self::LOCK, self::$lock_owner );
			self::$lock_owner = '';
		}
	}

	/**
	 * Create/update all tables with dbDelta.
	 */
	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c        = $wpdb->get_charset_collate();
		$previous = (int) get_option( self::DB_OPTION, 0 );
		if ( $previous > self::db_version() ) {
			// Schema of a newer plugin version (downgrade): an older dbDelta could undo its changes
			// and lowering the stored version would hide the downgrade and repeat its migrations.
			Log::warning( 'Schema of a newer plugin version left unchanged', array( 'stored' => $previous, 'code' => self::db_version() ) );
			return;
		}
		self::migrate_before( $previous );
		$sql = self::schema_statements( $c );

		self::keep_lock();
		// Schema changes wait at most a few seconds for a metadata lock (an open transaction on
		// the table): otherwise every following INSERT queues behind the waiting ALTER for up to
		// a day/year. A change that gave up is retried by the 5-minute mechanism (R13-M8).
		$restore = self::limit_lock_wait();
		try {
			// Performance indexes first, online (ALGORITHM=INPLACE, LOCK=NONE). One that cannot
			// be added without blocking writes (MyISAM, old servers, lock timeout) is left out of
			// dbDelta (which would copy the table under a write lock) and retried later; a
			// missing performance index is a warning, never a blocker.
			$skipped = self::add_online_indexes();
			foreach ( $sql as $statement ) {
				dbDelta( self::without_indexes( $statement, $skipped ) );
				self::keep_lock();
			}
			if ( $skipped ) {
				// A key over a column that dbDelta has only just added (e.g. visits.sub_id of an
				// install older than schema 11): online again, now that the column exists.
				self::add_online_indexes();
			}
		} finally {
			self::restore_lock_wait( $restore );
		}

		self::migrate_after( $previous );
		self::keep_lock();
		if ( $previous > 0 && $previous < 5 ) {
			// v5: opening balance entries for existing approved, unassigned commissions.
			\WPAffiliateCaptain\Commissions\Balance::open_from_ledger();
			self::keep_lock();
		}
		// Only a verified schema counts as migrated; otherwise the next request tries again.
		$problems = self::verify_schema();
		if ( $problems ) {
			if ( ! get_option( 'wpac_schema_problems_since' ) ) {
				update_option( 'wpac_schema_problems_since', time(), false );
			}
			update_option( 'wpac_schema_problems', $problems, false );
			set_transient( 'wpac_upgrade_retry', 1, 5 * MINUTE_IN_SECONDS );
			Log::error( 'Database migration incomplete', array( 'problems' => $problems ) );
			return;
		}
		delete_option( 'wpac_schema_problems' );
		delete_option( 'wpac_schema_problems_since' );
		update_option( self::DB_OPTION, self::db_version(), true );
	}

	/**
	 * CREATE TABLE statements of all tables, in dbDelta order.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string[]
	 */
	private static function schema_statements( $c ) {
		return array(
			self::schema_affiliates( $c ),
			self::schema_partner_groups( $c ),
			self::schema_partner_codes( $c ),
			self::schema_agreements( $c ),
			self::schema_payout_profiles( $c ),
			self::schema_visits( $c ),
			self::schema_visits_daily( $c ),
			self::schema_attributions( $c ),
			self::schema_rules( $c ),
			self::schema_commissions( $c ),
			self::schema_commission_events( $c ),
			self::schema_payouts( $c ),
			self::schema_payout_items( $c ),
			self::schema_payout_corrections( $c ),
			self::schema_jobs( $c ),
			self::schema_audit( $c ),
			self::schema_tiers( $c ),
			self::schema_customer_links( $c ),
			self::schema_rate_limits( $c ),
			self::schema_creatives( $c ),
			self::schema_balances( $c ),
			self::schema_balance_entries( $c ),
			self::schema_sequences( $c ),
			self::schema_payout_requests( $c ),
		);
	}

	/**
	 * CREATE TABLE statement of the affiliates table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_affiliates( $c ) {
		$table = Db::table( 'affiliates' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			slug varchar(64) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			website varchar(255) NOT NULL DEFAULT '',
			channel varchar(255) NOT NULL DEFAULT '',
			application_note text NULL,
			application_data text NULL,
			landing_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			landing_message text NULL,
			status_reason varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			approved_at datetime NULL,
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			referred_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY user_id (user_id),
			KEY referred_by (referred_by),
			KEY email (email),
			KEY status (status),
			KEY updated_at (updated_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the partner_groups table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_partner_groups( $c ) {
		$table = Db::table( 'partner_groups' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			description text NULL,
			agreement_version varchar(40) NOT NULL DEFAULT '',
			terms_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			public tinyint(1) NOT NULL DEFAULT 0,
			fields text NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the partner_codes table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_partner_codes( $c ) {
		$table = Db::table( 'partner_codes' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			code varchar(64) NOT NULL,
			coupon_code varchar(190) NOT NULL DEFAULT '',
			batch varchar(64) NOT NULL DEFAULT '',
			usage_limit int(10) unsigned NOT NULL DEFAULT 0,
			usage_count int(10) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY affiliate_id (affiliate_id),
			KEY coupon_code (coupon_code),
			KEY batch (batch)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the agreements table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_agreements( $c ) {
		$table = Db::table( 'agreements' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			version varchar(40) NOT NULL,
			doc_hash char(64) NOT NULL DEFAULT '',
			result varchar(20) NOT NULL DEFAULT 'accepted',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the payout_profiles table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_payout_profiles( $c ) {
		$table = Db::table( 'payout_profiles' );
		return "CREATE TABLE {$table} (
			affiliate_id bigint(20) unsigned NOT NULL,
			tax_status varchar(30) NOT NULL DEFAULT '',
			legal_name varchar(190) NOT NULL DEFAULT '',
			address text NULL,
			country char(2) NOT NULL DEFAULT '',
			vat_id varchar(40) NOT NULL DEFAULT '',
			tax_number varchar(40) NOT NULL DEFAULT '',
			account_holder varchar(190) NOT NULL DEFAULT '',
			iban_encrypted text NULL,
			iban_country char(2) NOT NULL DEFAULT '',
			iban_last4 varchar(4) NOT NULL DEFAULT '',
			bic varchar(11) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (affiliate_id),
			KEY updated_at (updated_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the visits table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_visits( $c ) {
		$table = Db::table( 'visits' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			campaign varchar(100) NOT NULL DEFAULT '',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			referrer_host varchar(190) NOT NULL DEFAULT '',
			landing_path varchar(255) NOT NULL DEFAULT '',
			content_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sub_id varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_created (affiliate_id,created_at,campaign,sub_id),
			KEY created_affiliate (created_at,affiliate_id),
			KEY campaign_created (campaign,created_at,affiliate_id),
			KEY content_created (content_id,created_at,affiliate_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the daily visit aggregate (schema 15, R14-D9): visits per partner,
	 * local day (site time zone), campaign and sub-ID. No personal data.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_visits_daily( $c ) {
		$table = Db::table( 'visits_daily' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			day date NOT NULL,
			campaign varchar(100) NOT NULL DEFAULT '',
			sub_id varchar(100) NOT NULL DEFAULT '',
			visits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY affiliate_day (affiliate_id,day,campaign,sub_id),
			KEY day (day)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the attributions table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_attributions( $c ) {
		$table = Db::table( 'attributions' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(40) NOT NULL,
			source_order_id varchar(64) NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			method varchar(20) NOT NULL,
			campaign varchar(100) NOT NULL DEFAULT '',
			content_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sub_id varchar(100) NOT NULL DEFAULT '',
			visit_id bigint(20) unsigned NOT NULL DEFAULT 0,
			consent tinyint(1) NOT NULL DEFAULT 0,
			touch_model varchar(10) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'attributed',
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_order (source,source_order_id),
			KEY affiliate_created (affiliate_id,created_at),
			KEY created_at (created_at),
			KEY content_created (content_id,created_at),
			KEY campaign_created (campaign,created_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the rules table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_rules( $c ) {
		$table = Db::table( 'rules' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope varchar(20) NOT NULL DEFAULT 'global',
			scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target varchar(20) NOT NULL DEFAULT 'all',
			target_value varchar(190) NOT NULL DEFAULT '',
			rate_type varchar(20) NOT NULL DEFAULT 'percent',
			rate varchar(40) NOT NULL DEFAULT '0',
			priority int(11) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			version int(10) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY scope (scope,scope_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the commissions table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_commissions( $c ) {
		$table = Db::table( 'commissions' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			source varchar(40) NOT NULL,
			source_order_id varchar(64) NOT NULL,
			source_item_id varchar(64) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'sale',
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_ref varchar(190) NOT NULL DEFAULT '',
			description varchar(255) NOT NULL DEFAULT '',
			currency char(3) NOT NULL,
			base_minor bigint(20) NOT NULL DEFAULT 0,
			amount_minor bigint(20) NOT NULL DEFAULT 0,
			rate_type varchar(20) NOT NULL DEFAULT '',
			rate varchar(40) NOT NULL DEFAULT '',
			rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rule_snapshot text NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			hold_until datetime NULL,
			idempotency_key varchar(191) NOT NULL,
			payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY affiliate_status (affiliate_id,status),
			KEY source_order (source,source_order_id),
			KEY source_order_id (source_order_id),
			KEY parent_id (parent_id),
			KEY status_hold (status,hold_until),
			KEY payout_id (payout_id),
			KEY affiliate_created (affiliate_id,created_at),
			KEY created_at (created_at),
			KEY updated_at (updated_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the commission_events table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_commission_events( $c ) {
		$table = Db::table( 'commission_events' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			commission_id bigint(20) unsigned NOT NULL,
			event varchar(30) NOT NULL,
			from_status varchar(20) NOT NULL DEFAULT '',
			to_status varchar(20) NOT NULL DEFAULT '',
			amount_minor bigint(20) NOT NULL DEFAULT 0,
			reason varchar(255) NOT NULL DEFAULT '',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_event_id varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY commission_id (commission_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the payouts table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_payouts( $c ) {
		$table = Db::table( 'payouts' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			number varchar(40) NOT NULL DEFAULT '',
			affiliate_id bigint(20) unsigned NOT NULL,
			period_end date NOT NULL,
			currency char(3) NOT NULL,
			amount_minor bigint(20) NOT NULL DEFAULT 0,
			net_minor bigint(20) NOT NULL DEFAULT 0,
			vat_mode varchar(20) NOT NULL DEFAULT '',
			vat_rate varchar(10) NOT NULL DEFAULT '0',
			vat_minor bigint(20) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			profile_snapshot text NULL,
			operator_snapshot text NULL,
			sepa_batch varchar(64) NOT NULL DEFAULT '',
			statement_file varchar(255) NOT NULL DEFAULT '',
			statement_hash char(64) NOT NULL DEFAULT '',
			statement_deleted_at datetime NULL,
			storno_number varchar(40) NULL,
			storno_file varchar(255) NOT NULL DEFAULT '',
			storno_hash char(64) NOT NULL DEFAULT '',
			storno_at datetime NULL,
			note varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			exported_at datetime NULL,
			paid_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			UNIQUE KEY storno_number (storno_number),
			KEY affiliate_id (affiliate_id),
			KEY status (status)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the payout_items table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_payout_items( $c ) {
		$table = Db::table( 'payout_items' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payout_id bigint(20) unsigned NOT NULL,
			commission_id bigint(20) unsigned NOT NULL,
			active_commission_id bigint(20) unsigned NULL,
			amount_minor bigint(20) NOT NULL DEFAULT 0,
			vat_mode varchar(20) NOT NULL DEFAULT '',
			vat_rate varchar(10) NOT NULL DEFAULT '',
			ref_payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			released_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_commission (active_commission_id),
			KEY commission_id (commission_id),
			KEY payout_id (payout_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the payout_corrections table.
	 *
	 * v12: correction credit notes of paid payouts (R5-W55).
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_payout_corrections( $c ) {
		$table = Db::table( 'payout_corrections' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payout_id bigint(20) unsigned NOT NULL,
			number varchar(40) NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			base_number varchar(40) NOT NULL DEFAULT '',
			prev_net_minor bigint(20) NOT NULL DEFAULT 0,
			prev_vat_minor bigint(20) NOT NULL DEFAULT 0,
			net_minor bigint(20) NOT NULL DEFAULT 0,
			vat_minor bigint(20) NOT NULL DEFAULT 0,
			vat_mode varchar(20) NOT NULL DEFAULT '',
			vat_rate varchar(10) NOT NULL DEFAULT '0',
			reason text NULL,
			commission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			request_key varchar(64) NOT NULL,
			profile_snapshot text NULL,
			operator_snapshot text NULL,
			file varchar(255) NOT NULL DEFAULT '',
			hash char(64) NOT NULL DEFAULT '',
			file_deleted_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			UNIQUE KEY request_key (request_key),
			KEY payout_id (payout_id),
			KEY affiliate_id (affiliate_id),
			KEY created_at (created_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the jobs table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_jobs( $c ) {
		$table = Db::table( 'jobs' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL,
			idempotency_key varchar(191) NOT NULL,
			payload longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_next (status,next_attempt_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the audit table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_audit( $c ) {
		$table = Db::table( 'audit' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(60) NOT NULL,
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			details text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type,object_id),
			KEY action_created (action,created_at),
			KEY created_at (created_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the tiers table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_tiers( $c ) {
		$table = Db::table( 'tiers' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scope varchar(20) NOT NULL DEFAULT 'global',
			scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
			basis varchar(20) NOT NULL DEFAULT 'revenue',
			mode varchar(20) NOT NULL DEFAULT 'marginal',
			steps text NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			version int(10) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY scope (scope,scope_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the customer_links table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_customer_links( $c ) {
		$table = Db::table( 'customer_links' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			customer_hash char(64) NOT NULL,
			source varchar(40) NOT NULL DEFAULT '',
			source_order_id varchar(64) NOT NULL DEFAULT '',
			pending tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_hash (customer_hash),
			KEY affiliate_id (affiliate_id),
			KEY source_order (source,source_order_id),
			KEY expires_at (expires_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the rate_limits table.
	 *
	 * Rate-limit counters (own table: millions of short-lived rows must not bloat wp_options).
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_rate_limits( $c ) {
		$table = Db::table( 'rate_limits' );
		return "CREATE TABLE {$table} (
			rl_key binary(16) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			expires_at int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (rl_key),
			KEY expires_at (expires_at)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the creatives table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_creatives( $c ) {
		$table = Db::table( 'creatives' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			family_id bigint(20) unsigned NOT NULL DEFAULT 0,
			version int(10) unsigned NOT NULL DEFAULT 1,
			title varchar(190) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'text',
			content text NULL,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			landing_url varchar(255) NOT NULL DEFAULT '',
			disclosure varchar(255) NOT NULL DEFAULT '',
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'draft',
			change_note varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY family_id (family_id),
			KEY status (status)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the balances table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_balances( $c ) {
		$table = Db::table( 'balances' );
		return "CREATE TABLE {$table} (
			affiliate_id bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			balance_minor bigint(20) NOT NULL DEFAULT 0,
			entries int(10) unsigned NOT NULL DEFAULT 0,
			last_hash char(64) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			verified_id bigint(20) unsigned NOT NULL DEFAULT 0,
			verified_hash char(64) NOT NULL DEFAULT '',
			verified_balance bigint(20) NOT NULL DEFAULT 0,
			verified_at datetime NULL,
			PRIMARY KEY  (affiliate_id,currency)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the balance_entries table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_balance_entries( $c ) {
		$table = Db::table( 'balance_entries' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			type varchar(30) NOT NULL,
			amount_minor bigint(20) NOT NULL,
			balance_minor bigint(20) NOT NULL,
			commission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reference varchar(191) NOT NULL DEFAULT '',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			prev_hash char(64) NOT NULL DEFAULT '',
			hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY account (affiliate_id,currency,id),
			KEY commission_id (commission_id),
			KEY payout_id (payout_id)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the sequences table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_sequences( $c ) {
		$table = Db::table( 'sequences' );
		return "CREATE TABLE {$table} (
			name varchar(20) NOT NULL,
			year smallint(5) unsigned NOT NULL,
			value int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (name,year)
		) $c;";
	}

	/**
	 * CREATE TABLE statement of the payout_requests table.
	 *
	 * @param string $c Charset/collation clause.
	 * @return string
	 */
	private static function schema_payout_requests( $c ) {
		$table = Db::table( 'payout_requests' );
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			amount_minor bigint(20) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'requested',
			payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			processed_at datetime NULL,
			processed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY affiliate_status (affiliate_id,status)
		) $c;";
	}

	/**
	 * Changes dbDelta cannot do (dropping/redefining indexes).
	 *
	 * @param int $previous Previous schema version.
	 */
	private static function migrate_before( $previous ) {
		global $wpdb;
		if ( $previous > 0 && $previous < 10 ) {
			// v10: rate-limit keys become BINARY(16) (half the size of the hex key). The counters only
			// live for minutes: emptying the table first lets the column change without converting rows.
			$rl = Db::table( 'rate_limits' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rl ) ) ) {
				$type = $wpdb->get_row( "SHOW COLUMNS FROM {$rl} LIKE 'rl_key'" );
				if ( $type && 0 !== stripos( (string) $type->Type, 'binary' ) ) {
					$wpdb->query( "TRUNCATE TABLE {$rl}" );
					$wpdb->query( "ALTER TABLE {$rl} MODIFY rl_key binary(16) NOT NULL" );
				}
			}
			// phpcs:enable
		}
		if ( $previous > 0 && $previous < 8 ) {
			// v8: VAT treatment and the original credit note per payout item, operator data per payout.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$add = array(
				Db::table( 'payout_items' ) => array(
					'vat_mode'      => "varchar(20) NOT NULL DEFAULT '' AFTER amount_minor",
					'vat_rate'      => "varchar(10) NOT NULL DEFAULT '' AFTER vat_mode",
					'ref_payout_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0 AFTER vat_rate',
				),
				Db::table( 'payouts' )      => array(
					'operator_snapshot' => 'text NULL AFTER profile_snapshot',
				),
			);
			foreach ( $add as $table => $columns ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
					continue;
				}
				// All missing columns of a table in ONE statement: without INSTANT DDL every ALTER
				// rebuilds the whole table.
				$parts = array();
				foreach ( $columns as $col => $def ) {
					if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$col}'" ) ) {
						$parts[] = "ADD COLUMN {$col} {$def}";
					}
				}
				if ( $parts ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Own table and column definitions only.
					$wpdb->query( "ALTER TABLE {$table} " . implode( ', ', $parts ) );
				}
				self::keep_lock();
			}
			// phpcs:enable
		}
		if ( $previous > 0 && $previous < 4 ) {
			// v4: payout numbers become UNIQUE (credit note numbers), net/VAT/storno columns.
			$t = Db::table( 'payouts' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				$columns = array(
					'net_minor'     => "bigint(20) NOT NULL DEFAULT 0 AFTER amount_minor",
					'vat_mode'      => "varchar(20) NOT NULL DEFAULT '' AFTER net_minor",
					'vat_rate'      => "varchar(10) NOT NULL DEFAULT '0' AFTER vat_mode",
					'vat_minor'     => "bigint(20) NOT NULL DEFAULT 0 AFTER vat_rate",
					'storno_number' => 'varchar(40) NULL',
					'storno_file'   => "varchar(255) NOT NULL DEFAULT ''",
					'storno_hash'   => "char(64) NOT NULL DEFAULT ''",
					'storno_at'     => 'datetime NULL',
				);
				foreach ( $columns as $col => $def ) {
					if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$t} LIKE '{$col}'" ) ) {
						$wpdb->query( "ALTER TABLE {$t} ADD COLUMN {$col} {$def}" );
					}
				}
				// Existing payouts: amount was the net amount without VAT.
				$wpdb->query( "UPDATE {$t} SET net_minor = amount_minor WHERE net_minor = 0 AND vat_mode = ''" );
				if ( $wpdb->get_var( "SHOW INDEX FROM {$t} WHERE Key_name = 'number' AND Non_unique = 1" ) ) {
					$wpdb->query( "ALTER TABLE {$t} DROP INDEX number, ADD UNIQUE KEY number (number)" );
				}
				if ( ! $wpdb->get_var( "SHOW INDEX FROM {$t} WHERE Key_name = 'storno_number'" ) ) {
					$wpdb->query( "ALTER TABLE {$t} ADD UNIQUE KEY storno_number (storno_number)" );
				}
			}
			// phpcs:enable
		}
		if ( $previous > 0 && $previous < 3 ) {
			// v3: payout_items keep released rows → commission_id is no longer UNIQUE.
			$t = Db::table( 'payout_items' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				return;
			}
			if ( $wpdb->get_var( "SHOW INDEX FROM {$t} WHERE Key_name = 'commission_id' AND Non_unique = 0" ) ) {
				$wpdb->query( "ALTER TABLE {$t} DROP INDEX commission_id, ADD KEY commission_id (commission_id)" );
			}
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$t} LIKE 'active_commission_id'" ) ) {
				$wpdb->query( "ALTER TABLE {$t} ADD COLUMN active_commission_id bigint(20) unsigned NULL AFTER commission_id" );
			}
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$t} LIKE 'released_at'" ) ) {
				$wpdb->query( "ALTER TABLE {$t} ADD COLUMN released_at datetime NULL AFTER amount_minor" );
			}
			$wpdb->query( "UPDATE {$t} SET active_commission_id = commission_id WHERE released_at IS NULL AND active_commission_id IS NULL" );
			if ( ! $wpdb->get_var( "SHOW INDEX FROM {$t} WHERE Key_name = 'active_commission'" ) ) {
				$wpdb->query( "ALTER TABLE {$t} ADD UNIQUE KEY active_commission (active_commission_id)" );
			}
			// phpcs:enable
		}
	}

	/**
	 * v9: runtime state written by the scheduled tasks moves out of the settings array into its own
	 * options (no lost admin changes, see Settings::RUNTIME); the VIES check becomes a setting –
	 * kept on where VIES results already exist, off otherwise (it sends data to the EU Commission).
	 */
	private static function migrate_v9_settings() {
		global $wpdb;
		$saved = get_option( Settings::OPTION );
		if ( ! is_array( $saved ) ) {
			return;
		}
		foreach ( Settings::RUNTIME as $key => $option ) {
			if ( ! array_key_exists( $key, $saved ) ) {
				continue;
			}
			if ( null === get_option( $option, null ) && '' !== (string) $saved[ $key ] ) {
				update_option( $option, (string) $saved[ $key ], false );
			}
			unset( $saved[ $key ] );
		}
		if ( ! array_key_exists( 'vies_check', $saved ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$saved['vies_check'] = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( 'wpac_vies_' ) . '%' ) ) ? 1 : 0;
		}
		update_option( Settings::OPTION, $saved, true );
		Settings::flush_cache();
	}

	/**
	 * v9 (multisite with WPAC_STATEMENT_DIR): every site keeps its credit notes in its own subfolder
	 * (Statement::dir()), so deleting the plugin's data on one site can never touch the documents of
	 * another. The site's existing documents are moved there from the shared folder.
	 */
	private static function migrate_v9_statement_files() {
		global $wpdb;
		if ( ! is_multisite() || ! defined( 'WPAC_STATEMENT_DIR' ) || '' === (string) WPAC_STATEMENT_DIR ) {
			return;
		}
		$t = Db::table( 'payouts' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$root  = untrailingslashit( (string) WPAC_STATEMENT_DIR );
		$dir   = \WPAffiliateCaptain\Payouts\Statement::dir();
		$moved = 0;
		$fail  = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( "SELECT statement_file, storno_file FROM {$t} WHERE statement_file <> '' OR storno_file <> ''" ) as $row ) {
			foreach ( array( $row->statement_file, $row->storno_file ) as $file ) {
				$name = basename( (string) $file );
				if ( '' === $name || ! is_file( $root . '/' . $name ) || file_exists( $dir . '/' . $name ) ) {
					continue;
				}
				// One ignore comment per line: a second "phpcs:ignore" for the same line replaces the
				// first one, so both codes are listed here. rename() on purpose: a move within the
				// server, WP_Filesystem may be FTP-based and cannot reach a folder outside the web root.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( @rename( $root . '/' . $name, $dir . '/' . $name ) ) {
					++$moved;
				} else {
					++$fail;
				}
			}
			if ( 0 === ( $moved + $fail ) % 200 ) {
				self::keep_lock();
			}
		}
		if ( $fail ) {
			Log::error( 'Credit notes could not be moved to the site folder', array( 'failed' => $fail, 'folder' => $dir ) );
		}
		if ( $moved || $fail ) {
			Audit::log( 'statement.files_moved', 'setup', 0, array( 'moved' => $moved, 'failed' => $fail ) );
		}
	}

	/**
	 * v11: indexes that are a prefix of another one only cost writes (visits.content_id is the
	 * start of content_created, attributions.affiliate_id of affiliate_created); visits.created_at
	 * is replaced by created_affiliate (created_at, affiliate_id), which dbDelta has added by now,
	 * so retention and reports never run without an index on created_at.
	 */
	private static function migrate_v11_indexes() {
		global $wpdb;
		$drop = array(
			Db::table( 'visits' )       => array(
				'content_id' => 'content_created',
				'created_at' => 'created_affiliate',
			),
			Db::table( 'attributions' ) => array(
				'affiliate_id' => 'affiliate_created',
			),
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery
		foreach ( $drop as $table => $indexes ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			$parts = array();
			foreach ( $indexes as $index => $replacement ) {
				// Only when the covering index exists (never leave a column without any index).
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) ) && $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $replacement ) ) ) {
					$parts[] = 'DROP INDEX ' . $index;
				}
			}
			if ( $parts ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Own table and index names only.
				$wpdb->query( "ALTER TABLE {$table} " . implode( ', ', $parts ) );
			}
			self::keep_lock();
		}
		// phpcs:enable
	}

	/**
	 * Data migrations after dbDelta.
	 *
	 * @param int $previous Previous schema version.
	 */
	private static function migrate_after( $previous ) {
		global $wpdb;
		if ( $previous > 0 && $previous < 15 ) {
			// v15: the daily visit aggregate is filled from the existing visits in the background;
			// the reports read the raw visits until that is complete (R14-D9).
			Scheduler::once( \WPAffiliateCaptain\Reports\VisitsDaily::HOOK, 60 );
		}
		if ( $previous > 0 && $previous < 11 ) {
			self::migrate_v11_indexes();
			// v11: bonus months follow the site's time zone; months closed before keep UTC.
			$closed = Settings::state( 'tier_month_closed' );
			if ( '' !== $closed && false === get_option( \WPAffiliateCaptain\Commissions\TierService::LOCAL_FROM ) ) {
				update_option( \WPAffiliateCaptain\Commissions\TierService::LOCAL_FROM, gmdate( 'Y-m', strtotime( $closed . '-01 +1 month' ) ), false );
			}
		}
		if ( $previous > 0 && $previous < 9 ) {
			self::migrate_v9_settings();
			self::migrate_v9_statement_files();
		}
		if ( $previous > 0 && $previous < 3 ) {
			$t = Db::table( 'payout_items' );
			// Existing items are all active (cancelled ones were deleted before v3).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$t} SET active_commission_id = commission_id WHERE released_at IS NULL AND active_commission_id IS NULL" );
		}
	}
}
