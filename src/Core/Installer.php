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

	public static function activate() {
		self::install_schema();
		Capabilities::install();
		Cron::schedule();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
	}

	public static function deactivate() {
		Cron::unschedule();
	}

	/**
	 * Run migrations when the stored schema version is outdated.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_OPTION, 0 ) < WPAC_DB_VERSION ) {
			self::install_schema();
			Capabilities::install();
			Cron::schedule();
		}
	}

	/**
	 * Create/update all tables with dbDelta.
	 */
	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
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
			KEY content_id (content_id)
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
			KEY affiliate_id (affiliate_id)
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
			KEY payout_id (payout_id)
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
			status varchar(20) NOT NULL DEFAULT 'pending',
			profile_snapshot text NULL,
			sepa_batch varchar(64) NOT NULL DEFAULT '',
			statement_file varchar(255) NOT NULL DEFAULT '',
			statement_hash char(64) NOT NULL DEFAULT '',
			statement_deleted_at datetime NULL,
			note varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			exported_at datetime NULL,
			paid_at datetime NULL,
			PRIMARY KEY  (id),
			KEY number (number),
			KEY affiliate_id (affiliate_id),
			KEY status (status)
		) $c;";

		$sql[] = "CREATE TABLE {$t('payout_items')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payout_id bigint(20) unsigned NOT NULL,
			commission_id bigint(20) unsigned NOT NULL,
			amount_minor bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY commission_id (commission_id),
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

		update_option( self::DB_OPTION, WPAC_DB_VERSION, false );
	}
}
