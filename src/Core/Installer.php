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
	 * Activation hook. On a network-wide activation every site of the network is set up.
	 *
	 * @param bool $network_wide Network-wide activation (multisite).
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// Small networks are set up right away; large ones in batches (cron) so the network
			// activation request never runs into the time limit. Every site also migrates itself
			// on its next admin or cron request (maybe_upgrade()).
			$ids = array_map( 'intval', (array) get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) ) );
			$now = array_slice( $ids, 0, self::NETWORK_BATCH );
			foreach ( $now as $id ) {
				self::run_on_site( $id, array( __CLASS__, 'activate_site' ) );
			}
			$rest = array_slice( $ids, self::NETWORK_BATCH );
			if ( $rest ) {
				update_site_option( 'wpac_network_pending', $rest );
				Scheduler::once( self::NETWORK_HOOK, 30 );
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Set up the current site: schema, capabilities, cron, default settings.
	 */
	public static function activate_site() {
		\WPAffiliateCaptain\Promotion\Landing::reset_rewrite();
		self::install_schema();
		Capabilities::install();
		Cron::schedule();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}
	}

	/**
	 * Deactivation hook (data is kept).
	 *
	 * @param bool $network_wide Network-wide deactivation (multisite).
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( __CLASS__, 'deactivate_site' ) );
			return;
		}
		self::deactivate_site();
	}

	public static function deactivate_site() {
		Cron::unschedule();
		delete_option( 'wpac_rewrite_flushed' );
		flush_rewrite_rules( false );
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
		foreach ( array_slice( $pending, 0, self::NETWORK_BATCH ) as $id ) {
			self::run_on_site( $id, array( __CLASS__, 'activate_site' ) );
		}
		$rest = array_slice( $pending, self::NETWORK_BATCH );
		if ( $rest ) {
			update_site_option( 'wpac_network_pending', $rest );
			Scheduler::once( self::NETWORK_HOOK, 30 );
		} else {
			delete_site_option( 'wpac_network_pending' );
		}
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
		foreach ( Db::TABLES as $name ) {
			$tables[] = $prefix . 'wpac_' . $name;
		}
		return $tables;
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

	/**
	 * Run a callback on every site of the current network.
	 *
	 * @param callable $callback Callback.
	 */
	public static function for_each_site( callable $callback ) {
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			)
		);
		foreach ( $ids as $id ) {
			switch_to_blog( (int) $id );
			try {
				call_user_func( $callback );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Run migrations when the stored schema version is outdated.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= WPAC_DB_VERSION ) {
			return;
		}
		// A migration that failed is retried at most every 5 minutes (not on every page load).
		if ( get_option( 'wpac_schema_problems' ) && get_transient( 'wpac_upgrade_retry' ) ) {
			return;
		}
		// Migrations run in admin, cron or WP-CLI requests – never inline in a checkout, payment
		// webhook or REST call. Background work waits meanwhile (schema_current()).
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			if ( ! wp_next_scheduled( Cron::HOURLY ) ) {
				Cron::schedule(); // Make sure a cron request comes to run it.
			}
			return;
		}
		// Only one request migrates (parallel runs would repeat ALTERs and data migrations).
		$owner = Db::lock( 'wpac_upgrade_lock', 15 * MINUTE_IN_SECONDS );
		if ( '' === $owner ) {
			return; // Another request is migrating.
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			self::install_schema();
			Capabilities::install();
			Cron::schedule();
		} finally {
			Db::unlock( 'wpac_upgrade_lock', $owner );
		}
	}

	/**
	 * Whether the stored schema matches this plugin version (background work waits otherwise).
	 *
	 * @return bool
	 */
	public static function schema_current() {
		return (int) get_option( self::DB_OPTION, 0 ) >= WPAC_DB_VERSION;
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
			'affiliates'      => array( 'id', 'email', 'status', 'status_reason' ),
			'commissions'     => array( 'id', 'idempotency_key', 'payout_id', 'rule_snapshot' ),
			'payouts'         => array( 'id', 'number', 'storno_number', 'statement_file', 'statement_deleted_at', 'operator_snapshot' ),
			'payout_items'    => array( 'id', 'payout_id', 'commission_id', 'vat_mode', 'vat_rate', 'ref_payout_id' ),
			'payout_profiles' => array( 'affiliate_id', 'tax_number' ),
			'balances'        => array( 'affiliate_id', 'last_hash' ),
			'balance_entries' => array( 'id', 'hash', 'prev_hash' ),
			'jobs'            => array( 'id', 'idempotency_key', 'attempts' ),
			'attributions'    => array( 'id', 'content_id', 'campaign' ),
			'visits'          => array( 'id', 'content_id', 'campaign' ),
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
		if ( ! $problems || ! $since || $since > time() - HOUR_IN_SECONDS || get_transient( 'wpac_schema_alerted' ) ) {
			return;
		}
		set_transient( 'wpac_schema_alerted', 1, DAY_IN_SECONDS );
		$admin = (string) Settings::get( 'admin_email' );
		wp_mail(
			is_email( $admin ) ? $admin : (string) get_option( 'admin_email' ),
			/* translators: %s: site name */
			sprintf( __( '[%s] Partner program: database update is stuck', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: %s: problems */
			sprintf( __( "The database update of WP Affiliate Captain has not completed for over an hour. Approvals, payouts and mails wait until it is done; tracking and privacy clean-ups keep running.\n\nProblems:\n%s\n\nPlease check the database user's rights (ALTER, CREATE, INDEX) or run: wp wpac upgrade", 'wp-affiliate-captain' ), implode( "\n", (array) $problems ) )
		);
		Audit::log( 'schema.stuck', 'setup', 0, array( 'problems' => count( (array) $problems ) ) );
	}

	/**
	 * Create/update all tables with dbDelta.
	 */
	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c        = $wpdb->get_charset_collate();
		$previous = (int) get_option( self::DB_OPTION, 0 );
		self::migrate_before( $previous );
		$t = static function ( $name ) {
			return Db::table( $name );
		};

		$sql = array();

		$sql[] = "CREATE TABLE {$t('affiliates')} (
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
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY user_id (user_id),
			KEY email (email),
			KEY status (status)
		) $c;";

		$sql[] = "CREATE TABLE {$t('partner_groups')} (
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

		$sql[] = "CREATE TABLE {$t('partner_codes')} (
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

		$sql[] = "CREATE TABLE {$t('agreements')} (
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

		$sql[] = "CREATE TABLE {$t('payout_profiles')} (
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
			PRIMARY KEY  (affiliate_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('visits')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			campaign varchar(100) NOT NULL DEFAULT '',
			utm_source varchar(100) NOT NULL DEFAULT '',
			utm_medium varchar(100) NOT NULL DEFAULT '',
			referrer_host varchar(190) NOT NULL DEFAULT '',
			landing_path varchar(255) NOT NULL DEFAULT '',
			content_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_created (affiliate_id,created_at),
			KEY created_at (created_at),
			KEY content_id (content_id),
			KEY campaign_created (campaign,created_at,affiliate_id),
			KEY content_created (content_id,created_at,affiliate_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t('attributions')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(40) NOT NULL,
			source_order_id varchar(64) NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			method varchar(20) NOT NULL,
			campaign varchar(100) NOT NULL DEFAULT '',
			content_id bigint(20) unsigned NOT NULL DEFAULT 0,
			consent tinyint(1) NOT NULL DEFAULT 0,
			touch_model varchar(10) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'attributed',
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_order (source,source_order_id),
			KEY affiliate_id (affiliate_id),
			KEY affiliate_created (affiliate_id,created_at),
			KEY created_at (created_at),
			KEY content_created (content_id,created_at),
			KEY campaign_created (campaign,created_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t('rules')} (
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

		$sql[] = "CREATE TABLE {$t('commissions')} (
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
			KEY parent_id (parent_id),
			KEY status_hold (status,hold_until),
			KEY payout_id (payout_id),
			KEY affiliate_created (affiliate_id,created_at),
			KEY created_at (created_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t('commission_events')} (
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

		$sql[] = "CREATE TABLE {$t('payouts')} (
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

		$sql[] = "CREATE TABLE {$t('payout_items')} (
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

		$sql[] = "CREATE TABLE {$t('jobs')} (
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

		$sql[] = "CREATE TABLE {$t('audit')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(60) NOT NULL,
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			details text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type,object_id),
			KEY created_at (created_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t('tiers')} (
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

		$sql[] = "CREATE TABLE {$t('customer_links')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			customer_hash char(64) NOT NULL,
			source varchar(40) NOT NULL DEFAULT '',
			source_order_id varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_hash (customer_hash),
			KEY affiliate_id (affiliate_id)
		) $c;";

		// Rate-limit counters (own table: millions of short-lived rows must not bloat wp_options).
		$sql[] = "CREATE TABLE {$t('rate_limits')} (
			rl_key char(32) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			expires_at int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (rl_key),
			KEY expires_at (expires_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t('creatives')} (
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

		$sql[] = "CREATE TABLE {$t('balances')} (
			affiliate_id bigint(20) unsigned NOT NULL,
			currency char(3) NOT NULL,
			balance_minor bigint(20) NOT NULL DEFAULT 0,
			entries int(10) unsigned NOT NULL DEFAULT 0,
			last_hash char(64) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (affiliate_id,currency)
		) $c;";

		$sql[] = "CREATE TABLE {$t('balance_entries')} (
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

		$sql[] = "CREATE TABLE {$t('sequences')} (
			name varchar(20) NOT NULL,
			year smallint(5) unsigned NOT NULL,
			value int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (name,year)
		) $c;";

		$sql[] = "CREATE TABLE {$t('payout_requests')} (
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

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::migrate_after( $previous );
		if ( $previous > 0 && $previous < 5 ) {
			// v5: opening balance entries for existing approved, unassigned commissions.
			\WPAffiliateCaptain\Commissions\Balance::open_from_ledger();
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
		update_option( self::DB_OPTION, WPAC_DB_VERSION, true );
	}

	/**
	 * Changes dbDelta cannot do (dropping/redefining indexes).
	 *
	 * @param int $previous Previous schema version.
	 */
	private static function migrate_before( $previous ) {
		global $wpdb;
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
				foreach ( $columns as $col => $def ) {
					if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$col}'" ) ) {
						$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" );
					}
				}
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
	 * Data migrations after dbDelta.
	 *
	 * @param int $previous Previous schema version.
	 */
	private static function migrate_after( $previous ) {
		global $wpdb;
		if ( $previous > 0 && $previous < 3 ) {
			$t = Db::table( 'payout_items' );
			// Existing items are all active (cancelled ones were deleted before v3).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$t} SET active_commission_id = commission_id WHERE released_at IS NULL AND active_commission_id IS NULL" );
		}
	}
}
