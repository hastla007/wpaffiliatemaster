<?php
/**
 * Admin menu, routing and form actions.
 *
 * All state changing actions go through admin-post.php?action=wpac with a
 * per-action nonce and capability check, then redirect back (POST-redirect-GET).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin;

use WPAffiliateCaptain\Admin\Pages\AffiliatesPage;
use WPAffiliateCaptain\Admin\Pages\AuditPage;
use WPAffiliateCaptain\Admin\Pages\CommissionsPage;
use WPAffiliateCaptain\Admin\Pages\CreativesPage;
use WPAffiliateCaptain\Admin\Pages\DashboardPage;
use WPAffiliateCaptain\Admin\Pages\IntegrationsPage;
use WPAffiliateCaptain\Admin\Pages\PayoutsPage;
use WPAffiliateCaptain\Admin\Pages\RulesPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/**
	 * Rows-per-page screen options of the other list tables (R5-O47): option => [ page, default ].
	 */
	const LIST_PER_PAGE = array(
		'wpac_payouts_per_page'   => array( 'wpac-payouts', 50 ),
		'wpac_creatives_per_page' => array( 'wpac-creatives', 50 ),
		'wpac_rules_per_page'     => array( 'wpac-rules', 50 ),
		'wpac_audit_per_page'     => array( 'wpac-audit', 100 ),
	);

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpac', array( __CLASS__, 'dispatch' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_filter( 'set_screen_option_wpac_partners_per_page', array( __CLASS__, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_wpac_commissions_per_page', array( __CLASS__, 'save_screen_option' ), 10, 3 );
		foreach ( array_keys( self::LIST_PER_PAGE ) as $option ) {
			add_filter( 'set_screen_option_' . $option, array( __CLASS__, 'save_screen_option' ), 10, 3 );
		}
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 ); // WordPress before 5.4.2.
		add_filter( 'default_hidden_columns', array( __CLASS__, 'default_hidden_columns' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'cron_notice' ) );
		add_action( 'wp_ajax_wpac_partner_search', array( __CLASS__, 'ajax_partner_search' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPAC_FILE ), array( __CLASS__, 'action_links' ) );
		Pages\ImportExportPage::register_hooks(); // Own menu entry and admin-post actions.
	}

	public static function menu() {
		$bubble = self::review_bubble( \WPAffiliateCaptain\Commissions\ReviewAlert::count_for_user() );
		add_menu_page( __( 'Affiliate Captain', 'affiliate-captain' ), __( 'Affiliate Captain', 'affiliate-captain' ) . $bubble, Capabilities::MANAGE_AFFILIATES, 'wpac', array( DashboardPage::class, 'render' ), 'dashicons-groups', 56 );
		$dashboard = add_submenu_page( 'wpac', __( 'Dashboard', 'affiliate-captain' ), __( 'Dashboard', 'affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, 'wpac', array( DashboardPage::class, 'render' ) );
		$partners = add_submenu_page( 'wpac', __( 'Partners', 'affiliate-captain' ), __( 'Partners', 'affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, 'wpac-affiliates', array( AffiliatesPage::class, 'render' ) );
		$ledger   = add_submenu_page( 'wpac', __( 'Commissions', 'affiliate-captain' ), __( 'Commissions', 'affiliate-captain' ) . $bubble, Capabilities::MANAGE_AFFILIATES, 'wpac-commissions', array( CommissionsPage::class, 'render' ) );
		// List table bulk actions are processed before any output (then redirected).
		add_action( 'load-' . $partners, array( AffiliatesPage::class, 'process_bulk' ) );
		add_action( 'load-' . $ledger, array( CommissionsPage::class, 'process_bulk' ) );
		$payouts = add_submenu_page( 'wpac', __( 'Payouts', 'affiliate-captain' ), __( 'Payouts', 'affiliate-captain' ), Capabilities::payouts_page_cap(), 'wpac-payouts', array( PayoutsPage::class, 'render' ) );
		$creatives = add_submenu_page( 'wpac', __( 'Creatives', 'affiliate-captain' ), __( 'Creatives', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-creatives', array( CreativesPage::class, 'render' ) );
		$rules     = add_submenu_page( 'wpac', __( 'Rules & groups', 'affiliate-captain' ), __( 'Rules & groups', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-rules', array( RulesPage::class, 'render' ) );
		// Daily work first, technical pages and settings last (R5-O46).
		Pages\ImportExportPage::menu();
		add_submenu_page( 'wpac', __( 'Integrations', 'affiliate-captain' ), __( 'Integrations', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-integrations', array( IntegrationsPage::class, 'render' ) );
		$audit    = add_submenu_page( 'wpac', __( 'Audit log', 'affiliate-captain' ), __( 'Audit log', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-audit', array( AuditPage::class, 'render' ) );
		$settings = add_submenu_page( 'wpac', __( 'Settings', 'affiliate-captain' ), __( 'Settings', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-settings', array( SettingsPage::class, 'render' ) );
		// Screen options (rows per page, columns) and help tabs (R5-O47).
		add_action( 'load-' . $partners, array( __CLASS__, 'screen_partners' ) );
		add_action( 'load-' . $ledger, array( __CLASS__, 'screen_commissions' ) );
		foreach ( array( $payouts, $creatives, $rules, $audit ) as $hook ) {
			if ( $hook ) {
				add_action( 'load-' . $hook, array( __CLASS__, 'screen_list' ) );
			}
		}
		foreach ( array( $dashboard, $partners, $ledger, $payouts, $settings ) as $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'help_tabs' ) );
		}
		/**
		 * Add-ons add their own pages below the plugin menu (parent slug "wpac").
		 *
		 * @param string $parent Parent menu slug.
		 */
		do_action( 'wpac_admin_menu', 'wpac' );
	}

	/**
	 * Count bubble (WordPress "awaiting-mod" markup) for commissions that need review (R5-W58).
	 *
	 * @param int $count Commissions waiting for review.
	 * @return string HTML ('' when there are none).
	 */
	public static function review_bubble( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}
		/* translators: %d: number of commissions */
		$label = sprintf( _n( '%d commission needs review', '%d commissions need review', $count, 'affiliate-captain' ), $count );
		return ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}

	/**
	 * After a manual activation (one plugin, not in bulk, not network-wide): open the dashboard
	 * with the setup checklist once, for the user who activated the plugin (R5-W61).
	 */
	public static function activation_redirect() {
		$user = (int) get_option( 'wpac_activation_redirect', 0 );
		if ( ! $user ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		if ( get_current_user_id() !== $user || 'get' !== $method ) {
			return; // Left for the user who activated it; never interrupts a form post.
		}
		delete_option( 'wpac_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to skip the redirect.
		$bulk = isset( $_GET['activate-multi'] );
		if ( $bulk || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || is_network_admin() || ! current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			return;
		}
		wp_safe_redirect( self::url( 'wpac' ) );
		exit;
	}

	/**
	 * Screen options of the partner list: rows per page and columns.
	 */
	public static function screen_partners() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Partners per page', 'affiliate-captain' ),
				'default' => 30,
				'option'  => 'wpac_partners_per_page',
			)
		);
		// The list table registers its columns for the "Columns" checkboxes.
		$GLOBALS['wpac_list_table'] = new Tables\AffiliatesTable();
	}

	/**
	 * Screen options of the commission list: rows per page and columns.
	 */
	public static function screen_commissions() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Commissions per page', 'affiliate-captain' ),
				'default' => 50,
				'option'  => 'wpac_commissions_per_page',
			)
		);
	}

	/**
	 * Screen option "rows per page" of the payout, creative, rule and audit lists (R5-O47).
	 */
	public static function screen_list() {
		$page   = self::get( 'page' );
		$labels = array(
			'wpac_payouts_per_page'   => __( 'Payouts per page', 'affiliate-captain' ),
			'wpac_creatives_per_page' => __( 'Creatives per page', 'affiliate-captain' ),
			'wpac_rules_per_page'     => __( 'Rules per page', 'affiliate-captain' ),
			'wpac_audit_per_page'     => __( 'Entries per page', 'affiliate-captain' ),
		);
		foreach ( self::LIST_PER_PAGE as $option => $def ) {
			if ( $def[0] === $page ) {
				add_screen_option(
					'per_page',
					array(
						'label'   => $labels[ $option ],
						'default' => $def[1],
						'option'  => $option,
					)
				);
				return;
			}
		}
	}

	/**
	 * Store a rows-per-page screen option of the plugin (1–500).
	 *
	 * @param mixed  $keep   Value to keep (false = do not store).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $keep, $option, $value ) {
		if ( in_array( $option, array_merge( array( 'wpac_partners_per_page', 'wpac_commissions_per_page' ), array_keys( self::LIST_PER_PAGE ) ), true ) ) {
			return max( 1, min( 500, (int) $value ) );
		}
		return $keep;
	}

	/**
	 * Columns of the partner list that are hidden until switched on in the screen options.
	 *
	 * @param string[]   $hidden Hidden columns.
	 * @param \WP_Screen $screen Screen.
	 * @return string[]
	 */
	public static function default_hidden_columns( $hidden, $screen ) {
		if ( $screen && false !== strpos( (string) $screen->id, 'wpac-affiliates' ) ) {
			return array_merge( (array) $hidden, Tables\AffiliatesTable::default_hidden() );
		}
		return $hidden;
	}

	/**
	 * Help tabs of the main pages (WordPress "Help" button) with a link to the handbook.
	 */
	public static function help_tabs() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page slug only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tabs = self::help_texts();
		if ( ! isset( $tabs[ $page ] ) ) {
			return;
		}
		foreach ( $tabs[ $page ] as $i => $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'wpac-help-' . $page . '-' . $i,
					'title'   => $tab[0],
					'content' => '<p>' . esc_html( $tab[1] ) . '</p>',
				)
			);
		}
		$screen->set_help_sidebar( '<p><strong>' . esc_html__( 'More help', 'affiliate-captain' ) . '</strong></p><p><a href="https://github.com/hastla007/wpaffiliatemaster/blob/main/docs/HANDBOOK.md" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Handbook', 'affiliate-captain' ) . '</a></p>' );
	}

	/**
	 * Help texts per page: [ [ title, text ], … ].
	 *
	 * @return array
	 */
	public static function help_texts() {
		return array(
			'wpac'             => array(
				array( __( 'Overview', 'affiliate-captain' ), __( 'The dashboard shows visits, attributed orders and commissions of the chosen period, by partner, content or campaign. Until the setup is complete, the checklist at the top shows what is still missing.', 'affiliate-captain' ) ),
			),
			'wpac-affiliates'  => array(
				array( __( 'Overview', 'affiliate-captain' ), __( 'All partners with their status. New applications are listed under "Application pending" and can be approved or rejected there, also in bulk. Use "Screen Options" to choose the columns and the number of rows.', 'affiliate-captain' ) ),
				array( __( 'Partner page', 'affiliate-captain' ), __( 'Open a partner to edit the details, codes and coupons, payout details and tax status, and to see the account and the history.', 'affiliate-captain' ) ),
			),
			'wpac-commissions' => array(
				array( __( 'Overview', 'affiliate-captain' ), __( 'Every commission with its status: pending (hold period), needs review, approved, paid, rejected. Approved commissions are paid with the next payout run. Filter by partner name, e-mail or number.', 'affiliate-captain' ) ),
			),
			'wpac-payouts'     => array(
				array( __( 'Overview', 'affiliate-captain' ), __( 'Create a payout run: choose the period end, check the preview and create the payouts. Each payout gets a numbered credit note. Then download the SEPA file or transfer list and mark the payouts as paid once the bank executed them.', 'affiliate-captain' ) ),
			),
			'wpac-settings'    => array(
				array( __( 'Overview', 'affiliate-captain' ), __( 'Each tab saves only its own fields. Invalid values are not saved and are shown again with a message next to the field.', 'affiliate-captain' ) ),
			),
		);
	}

	/**
	 * Admin CSS.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, 'wpac' ) ) {
			wp_enqueue_style( 'wpac-admin', WPAC_URL . 'assets/css/admin.css', array(), WPAC_VERSION );
			wp_enqueue_script( 'wpac-admin', WPAC_URL . 'assets/js/admin.js', array(), WPAC_VERSION, true );
		}
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'wpac-settings' ) ) . '">' . esc_html__( 'Settings', 'affiliate-captain' ) . '</a>' );
		return $links;
	}

	/**
	 * Warn when the scheduled tasks do not run (commissions are then never released,
	 * retries and retention stop). Missing events are scheduled again.
	 */
	public static function cron_notice() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			return;
		}
		$schema = get_option( 'wpac_schema_problems' );
		if ( is_array( $schema ) && $schema ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Affiliate Captain: the database update is incomplete. Scheduled tasks are paused until it succeeds; it is retried on the next page load.', 'affiliate-captain' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $schema, 0, 10 ) ) ) . '</li></ul></div>';
		}
		if ( \WPAffiliateCaptain\Core\Settings::get( 'maintenance_mode' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Affiliate Captain is in maintenance mode: approvals, payouts, report mails and partner area changes wait. Sales are still recorded.', 'affiliate-captain' ) . ' <a href="' . esc_url( self::url( 'wpac-settings', array( 'tab' => 'data' ) ) ) . '">' . esc_html__( 'Settings', 'affiliate-captain' ) . '</a></p></div>';
		}
		$warnings = get_option( 'wpac_schema_warnings' );
		if ( is_array( $warnings ) && $warnings ) {
			$state = md5( wp_json_encode( $warnings ) );
			if ( ! self::notice_hidden( 'engine', $state ) ) {
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Affiliate Captain: some database tables use an outdated storage engine.', 'affiliate-captain' ) . '</strong> ' . esc_html__( 'Without the InnoDB engine, a booking that stops halfway (e.g. a timeout) cannot be undone, so commissions and partner balances can get out of step. Your host can convert the tables in a few minutes – send them this message and the list below (technically: ALTER TABLE … ENGINE=InnoDB).', 'affiliate-captain' ) . self::hide_link( 'engine', $state ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $warnings, 0, 10 ) ) ) . '</li></ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hide_link() is escaped.
			}
		}
		$balance = get_option( 'wpac_balance_problems' );
		if ( is_array( $balance ) && ! empty( $balance['accounts'] ) ) {
			/* translators: 1: number, 2: accounts */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Affiliate Captain: %1$d partner accounts do not match their bookings (%2$s). Please check them before the next payout.', 'affiliate-captain' ), count( $balance['accounts'] ), implode( ', ', array_slice( $balance['accounts'], 0, 10 ) ) ) ) . '</p></div>';
		}
		$stmt = \WPAffiliateCaptain\Payouts\Statement::waiting();
		if ( $stmt ) {
			/* translators: %d: number */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( _n( 'Affiliate Captain: %d credit note could not be written (statement folder not writable?). It is tried again with increasing pauses.', 'Affiliate Captain: %d credit notes could not be written (statement folder not writable?). They are tried again with increasing pauses.', count( $stmt ), 'affiliate-captain' ), count( $stmt ) ) ) . '</p></div>';
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'wpac' ) && current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			self::blocked_payouts_notice();
		}
		\WPAffiliateCaptain\Core\Lifecycle::notices();
		$migration = get_option( \WPAffiliateCaptain\Core\Monitor::MIGRATION_STATUS );
		if ( is_array( $migration ) && current_user_can( Capabilities::MANAGE_PAYOUTS ) && ( ! empty( $migration['broken'] ) || ! empty( $migration['legacy'] ) || ! empty( $migration['misplaced'] ) ) ) {
			$state = md5( wp_json_encode( array( $migration['broken'], $migration['legacy'], $migration['misplaced'] ) ) );
			if ( ! empty( $migration['broken'] ) || ! self::notice_hidden( 'migration', $state ) ) {
				$text = ! empty( $migration['broken'] )
					/* translators: 1: number, 2: partner IDs */
					? sprintf( __( 'Affiliate Captain: the bank details of %1$d partners cannot be decrypted (partner IDs %2$s). They get no payouts until they enter them again.', 'affiliate-captain' ), (int) $migration['broken'], implode( ', ', array_map( 'intval', (array) $migration['broken_ids'] ) ) )
					: __( 'Affiliate Captain: bank details or credit notes still need to be migrated (re-encryption or moving files).', 'affiliate-captain' );
				echo '<div class="notice ' . ( ! empty( $migration['broken'] ) ? 'notice-error' : 'notice-warning' ) . '"><p>' . esc_html( $text ) . ' <a href="' . esc_url( self::url( 'wpac-integrations', array( 'tab' => 'status' ) ) ) . '">' . esc_html__( 'Open the integrations page', 'affiliate-captain' ) . '</a>' . ( empty( $migration['broken'] ) ? self::hide_link( 'migration', $state ) : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hide_link() is escaped.
			}
		}
		\WPAffiliateCaptain\Core\Cron::schedule(); // Cheap; restores events lost e.g. after a migration.
		if ( \WPAffiliateCaptain\Core\Monitor::cron_running() ) {
			return;
		}
		$state = md5( 'cron' . gmdate( 'Y-m-d' ) ); // Hidden for the rest of the day at most.
		if ( self::notice_hidden( 'cron', $state ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Affiliate Captain: the scheduled tasks have not run for more than two hours.', 'affiliate-captain' ) . '</strong> ' . esc_html__( 'Until they run again, commissions are not released after the hold period, failed sales are not retried and report mails are not sent.', 'affiliate-captain' ) . ' ';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo esc_html__( 'WordPress\' own scheduler (WP-Cron) is switched off on this site (DISABLE_WP_CRON), so your server must start it: ask your host to set up a "cron job" that runs "wp cron event run --due-now" every 5 minutes.', 'affiliate-captain' );
		} else {
			echo esc_html__( 'WordPress starts scheduled tasks only when someone visits the site; with few visits or when the server blocks requests to itself, they stall. Ask your host to set up a "cron job" that runs "wp cron event run --due-now" every 5 minutes.', 'affiliate-captain' );
		}
		echo self::hide_link( 'cron', $state ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hide_link().
	}

	/**
	 * Fingerprint of everything that changes who can be paid out (settings, payout details,
	 * terms acceptances, partner status, payouts): the cached list is rebuilt when it changes.
	 *
	 * @return string
	 */
	private static function blocked_state() {
		global $wpdb;
		$parts = array( wp_json_encode( \WPAffiliateCaptain\Core\Settings::all() ) );
		foreach ( array( 'payout_profiles' => 'MAX(updated_at)', 'agreements' => 'MAX(id)', 'affiliates' => 'MAX(updated_at)', 'payouts' => 'MAX(id)' ) as $table => $expr ) {
			$parts[] = (string) $wpdb->get_var( "SELECT {$expr} FROM " . \WPAffiliateCaptain\Core\Db::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- constants only.
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Where the cause of a blocked payout is fixed: [ URL, link text ].
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $code         Reason code (PayoutService::blocked_reason()).
	 * @return array
	 */
	public static function blocked_fix( $affiliate_id, $code ) {
		switch ( $code ) {
			case 'maintenance':
				return array( self::url( 'wpac-settings', array( 'tab' => 'data' ) ), __( 'Switch off maintenance mode', 'affiliate-captain' ) );
			case 'operator':
			case 'operator_vat_id':
				return array( self::url( 'wpac-settings', array( 'tab' => 'payouts' ) ), __( 'Complete your details in the payout settings', 'affiliate-captain' ) );
			case 'terms_page':
				return array( self::url( 'wpac-settings', array( 'tab' => 'program' ) ), __( 'Choose the terms page', 'affiliate-captain' ) );
			case 'no_rate':
				return array( self::url( 'wpac-settings', array( 'tab' => 'commissions' ) ), __( 'Add an exchange rate', 'affiliate-captain' ) );
			case 'profile':
			case 'vies':
				return array( self::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $affiliate_id, 'tab' => 'payout' ) ), __( 'Open the payout details', 'affiliate-captain' ) );
			case 'terms':
				return array( self::url( 'wpac-affiliates' ), __( 'The partner accepts the terms in the partner area – send a reminder from the partner list', 'affiliate-captain' ) );
		}
		return array( self::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => (int) $affiliate_id ) ), __( 'Open the partner', 'affiliate-captain' ) );
	}

	/**
	 * Partners with a payable balance who cannot be paid out (operator data, profile, terms,
	 * VAT ID): listed with a link to where the cause is fixed. The list is rebuilt as soon as
	 * settings, payout details, acceptances or payouts change, and can be hidden until it changes.
	 */
	private static function blocked_payouts_notice() {
		$blocked = self::blocked_payouts();
		if ( ! $blocked ) {
			return;
		}
		$hide = md5( wp_json_encode( array_map( null, wp_list_pluck( $blocked, 0 ), wp_list_pluck( $blocked, 3 ) ) ) ); // IDs and codes, not the language.
		if ( self::notice_hidden( 'blocked', $hide ) ) {
			return;
		}
		/* translators: %d: number of partners */
		echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( _n( 'Affiliate Captain: %d partner with a payable balance cannot be paid out:', 'Affiliate Captain: %d partners with a payable balance cannot be paid out:', count( $blocked ), 'affiliate-captain' ), count( $blocked ) ) ) . self::hide_link( 'blocked', $hide ) . '</p><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hide_link().
		foreach ( array_slice( $blocked, 0, 10 ) as $b ) {
			$fix = self::blocked_fix( $b[0], isset( $b[3] ) ? $b[3] : '' );
			echo '<li><a href="' . esc_url( self::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $b[0] ) ) ) . '">' . esc_html( $b[1] ) . '</a>: ' . esc_html( $b[2] ) . ' – <a href="' . esc_url( $fix[0] ) . '">' . esc_html( $fix[1] ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	/** Transient: partners who cannot be paid out, language-neutral (IDs and reason codes). */
	const BLOCKED_CACHE = 'wpac_blocked_payouts';

	/**
	 * Partners with a payable balance who cannot be paid out: [ id, name, reason, code ].
	 *
	 * The cache keeps only IDs and reason codes (no translated text: admins with another language
	 * would otherwise see the reasons in the language of whoever built it); the reasons of the
	 * listed partners are put into words for the current user. It is rebuilt when the
	 * fingerprint (blocked_state(), indexed MAX() lookups only) changes.
	 *
	 * @return array
	 */
	public static function blocked_payouts() {
		$state    = self::blocked_state();
		$cache    = get_transient( self::BLOCKED_CACHE );
		$currency = (string) \WPAffiliateCaptain\Core\Settings::get( 'currency' );
		if ( ! is_array( $cache ) || ! isset( $cache['state'], $cache['ids'] ) || $cache['state'] !== $state ) {
			$ids = array();
			$min = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
			foreach ( \WPAffiliateCaptain\Payouts\PayoutService::preview( gmdate( 'Y-m-d' ), $currency ) as $row ) {
				if ( ! $row['eligible'] && $row['affiliate'] && null !== $min && $row['total_minor'] >= $min ) {
					$ids[] = array( (int) $row['affiliate']->id, isset( $row['code'] ) ? (string) $row['code'] : '' );
				}
			}
			$cache = array(
				'state' => $state,
				'ids'   => $ids,
			);
			set_transient( self::BLOCKED_CACHE, $cache, HOUR_IN_SECONDS );
		}
		$out = array();
		\WPAffiliateCaptain\Affiliates\AffiliateRepository::find_many( wp_list_pluck( $cache['ids'], 0 ) );
		foreach ( $cache['ids'] as $i => $entry ) {
			$aff  = \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( (int) $entry[0] );
			$code = (string) $entry[1];
			// Reasons only for the entries shown (10), the rest only count.
			$out[] = array( (int) $entry[0], $aff ? (string) $aff->name : '#' . (int) $entry[0], $i < 10 ? self::blocked_text( (int) $entry[0], $code, $currency ) : '', $code );
		}
		return $out;
	}

	/**
	 * Reason text of a blocked payout in the current user's language.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $code         Reason code (PayoutService::preview()).
	 * @param string $currency     Currency.
	 * @return string
	 */
	private static function blocked_text( $affiliate_id, $code, $currency ) {
		switch ( $code ) {
			case 'operator':
				return (string) \WPAffiliateCaptain\Payouts\PayoutService::operator_missing();
			case 'negative':
				return __( 'Balance including VAT corrections is not positive – carried forward', 'affiliate-captain' );
			case 'below_minimum':
				return __( 'Below minimum – carried forward', 'affiliate-captain' );
			case 'no_rate':
				/* translators: %s: currency */
				return sprintf( __( 'No reference rate for %s – minimum cannot be checked', 'affiliate-captain' ), $currency );
		}
		$text = \WPAffiliateCaptain\Payouts\PayoutService::blocked_reason( $affiliate_id );
		return '' !== $text ? $text : __( 'Cannot be paid out right now', 'affiliate-captain' );
	}

	/**
	 * Hint on other plugin screens until the setup is complete (the dashboard shows the checklist).
	 */
	public static function setup_notice() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			return;
		}
		$screen = get_current_screen();
		// On the plugin's screens (the dashboard shows the checklist itself) and on the plugins
		// and WordPress dashboard screens, until every required item of the checklist is done (R5-W61).
		$ours = $screen && false !== strpos( (string) $screen->id, 'wpac' );
		if ( ! $screen || 'toplevel_page_wpac' === $screen->id || ( ! $ours && ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) ) {
			return;
		}
		if ( ! DashboardPage::setup_open() ) {
			return;
		}
		$state = 'setup';
		if ( ! $ours && self::notice_hidden( 'setup', $state ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Affiliate Captain is not fully set up yet.', 'affiliate-captain' ) . ' <a href="' . esc_url( self::url( 'wpac' ) ) . '">' . esc_html__( 'Open the setup checklist', 'affiliate-captain' ) . '</a>' . ( $ours ? '' : self::hide_link( 'setup', $state ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hide_link() is escaped.
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Hidden fields for an action form.
	 *
	 * @param string $do Action.
	 */
	public static function form_fields( $do ) {
		echo '<input type="hidden" name="action" value="wpac">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'wpac_' . $do, '_wpac_nonce' );
	}

	/**
	 * Form open tag.
	 *
	 * @param string $do    Action.
	 * @param string $class CSS class.
	 */
	public static function form_open( $do, $class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
		self::form_fields( $do );
	}

	/**
	 * Show a flash notice from the query string.
	 */
	public static function notice() {
		// Messages are stored per user (not in the URL): no spoofed notices via crafted links,
		// and they do not reappear on reload or end up in the browser history.
		$key  = 'wpac_notice_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type    = in_array( $data['type'], array( 'error', 'warning', 'info' ), true ) ? $data['type'] : 'success';
		$details = '';
		if ( ! empty( $data['details'] ) && is_array( $data['details'] ) ) {
			$details = '<ul class="wpac-notice-details"><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $data['details'], 0, 30 ) ) ) . '</li></ul>';
		}
		printf( '<div class="notice notice-%s is-dismissible" role="%s"><p>%s</p>%s</div>', esc_attr( $type ), in_array( $type, array( 'error', 'warning' ), true ) ? 'alert' : 'status', esc_html( $data['message'] ), $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $details is escaped above.
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string   $message Message.
	 * @param string   $type    success|error|warning|info.
	 * @param string   $url     Target (defaults to referer).
	 * @param string[] $details Optional list shown below the message (e.g. what failed and why).
	 */
	public static function back( $message, $type = 'success', $url = '', array $details = array() ) {
		$url = $url ? $url : wpac_return_url();
		$url = $url ? $url : admin_url( 'admin.php?page=wpac' );
		$url = remove_query_arg( array( 'wpac_notice', 'wpac_type' ), $url );
		set_transient(
			'wpac_notice_' . get_current_user_id(),
			array(
				'message' => (string) $message,
				'type'    => $type,
				'details' => array_values( array_map( 'strval', $details ) ),
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Keep the input of a form that was rejected, so the page can show it again (per user,
	 * 15 minutes, read once).
	 *
	 * @param string $form Form key.
	 * @param array  $data Data (e.g. values and errors).
	 */
	public static function keep_form( $form, array $data ) {
		set_transient( 'wpac_form_' . sanitize_key( $form ) . '_' . get_current_user_id(), $data, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Kept input of a rejected form (empty array when none).
	 *
	 * @param string $form Form key.
	 * @return array
	 */
	public static function kept_form( $form ) {
		$key  = 'wpac_form_' . sanitize_key( $form ) . '_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return array();
		}
		delete_transient( $key );
		return $data;
	}

	/**
	 * Whether the current user hid a notice in its current state.
	 *
	 * @param string $key   Notice key.
	 * @param string $state Hash of the content (a changed content shows the notice again).
	 * @return bool
	 */
	public static function notice_hidden( $key, $state ) {
		$hidden = get_user_option( 'wpac_hidden_notices', get_current_user_id() );
		return is_array( $hidden ) && isset( $hidden[ $key ] ) && hash_equals( (string) $hidden[ $key ], (string) $state );
	}

	/**
	 * Link that hides a notice until its content changes.
	 *
	 * @param string $key   Notice key.
	 * @param string $state Hash of the content.
	 * @return string HTML.
	 */
	public static function hide_link( $key, $state ) {
		$url = add_query_arg(
			array(
				'action'      => 'wpac',
				'do'          => 'notice_hide',
				'notice'      => $key,
				'state'       => $state,
				'_wpac_nonce' => wp_create_nonce( 'wpac_notice_hide' ),
			),
			admin_url( 'admin-post.php' )
		);
		return ' <a href="' . esc_url( $url ) . '" class="wpac-hide-notice">' . esc_html__( 'Hide until something changes', 'affiliate-captain' ) . '</a>';
	}

	/**
	 * Hide a notice for the current user (admin-post action "notice_hide").
	 */
	private static function hide_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in dispatch().
		$key   = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable
		if ( in_array( $key, array( 'blocked', 'engine', 'cron', 'migration', 'setup' ), true ) ) {
			$hidden         = get_user_option( 'wpac_hidden_notices', get_current_user_id() );
			$hidden         = is_array( $hidden ) ? $hidden : array();
			$hidden[ $key ] = $state;
			// Per site (on a network the notices of one site say nothing about another).
			update_user_option( get_current_user_id(), 'wpac_hidden_notices', $hidden );
		}
		wp_safe_redirect( wpac_return_url() ? wpac_return_url() : self::url( 'wpac' ) );
		exit;
	}

	/**
	 * Unslashed POST value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function post( $key, $default = '' ) {
		// Nonce verified in dispatch(); callers sanitize per field.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Route admin-post actions to page handlers.
	 */
	public static function dispatch() {
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ( isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '' );
		$nonce = isset( $_REQUEST['_wpac_nonce'] ) ? sanitize_key( wp_unslash( $_REQUEST['_wpac_nonce'] ) ) : '';
		if ( '' === $do || ! wp_verify_nonce( $nonce, 'wpac_' . $do ) ) {
			wp_die( esc_html__( 'The link has expired. Please go back and try again.', 'affiliate-captain' ), 403 );
		}
		if ( 'notice_hide' === $do ) {
			if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) && ! current_user_can( Capabilities::MANAGE_PAYOUTS ) && ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
				wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
			}
			self::hide_notice();
		}

		$routes = array(
			'affiliate_create'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'affiliate_update'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'affiliate_status'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'public_change'     => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'vies_check'        => array( AffiliatesPage::class, Capabilities::VIEW_TAX_DATA ),
			'tax_approve'       => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ),
			'tax_reject'        => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ),
			'user_link_resend'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'user_link_manual'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'user_unlink'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'terms_remind'      => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'reencrypt'         => array( IntegrationsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'statements_move'   => array( IntegrationsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'restriction'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_create'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_batch'        => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_status'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_coupon'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_export'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'profile_update'    => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ), // Changing bank details redirects payouts.
			'balance_writeoff'  => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ),
			'dunning_send'      => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ),
			'repayment'         => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ),
			'commission_status' => array( CommissionsPage::class, Capabilities::MANAGE_AFFILIATES ),
			'commission_export' => array( CommissionsPage::class, Capabilities::MANAGE_AFFILIATES ),
			'commission_manual' => array( CommissionsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'payout_run'        => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'objection_resolve' => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'payout_rebuild'    => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'payout_bulk'       => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'payout_export'     => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'payout_request_process' => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'payout_request_reject'  => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
			'statement_download'     => array( PayoutsPage::class, Capabilities::VIEW_TAX_DATA ),
			'rule_save'         => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'rule_delete'       => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'group_create'      => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'group_update'      => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'tier_create'       => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'tier_deactivate'   => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'tier_close_month'  => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'group_delete'      => array( RulesPage::class, Capabilities::MANAGE_PROGRAM ),
			'creative_save'     => array( CreativesPage::class, Capabilities::MANAGE_PROGRAM ),
			'creative_approve'  => array( CreativesPage::class, Capabilities::MANAGE_PROGRAM ),
			'creative_archive'  => array( CreativesPage::class, Capabilities::MANAGE_PROGRAM ),
			'job_retry'         => array( IntegrationsPage::class, Capabilities::MANAGE_PROGRAM ),
			'job_retry_all'     => array( IntegrationsPage::class, Capabilities::MANAGE_PROGRAM ),
			'job_dismiss'       => array( IntegrationsPage::class, Capabilities::MANAGE_PROGRAM ),
			'settings_save'     => array( SettingsPage::class, Capabilities::MANAGE_PROGRAM ),
			'ropa_download'     => array( SettingsPage::class, Capabilities::MANAGE_PROGRAM ),
			'report_export'     => array( DashboardPage::class, Capabilities::MANAGE_AFFILIATES ),
			'setup_pages'       => array( DashboardPage::class, Capabilities::MANAGE_PROGRAM ),
		);
		if ( ! isset( $routes[ $do ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'affiliate-captain' ), 400 );
		}
		list( $class, $cap ) = $routes[ $do ];
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'affiliate-captain' ), 403 );
		}
		try {
			call_user_func( array( $class, 'handle' ), $do );
		} catch ( \WPAffiliateCaptain\Core\IntegrationException $e ) {
			// A booking the database refused was rolled back: an error notice, never "saved".
			/* translators: %s: technical reason */
			self::back( sprintf( __( 'A change could not be saved because of a database error and was not applied. Please check and try again. (%s)', 'affiliate-captain' ), wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) ), 'error' );
		}
		self::back( __( 'Done.', 'affiliate-captain' ) );
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 * @param string $label  Label.
	 * @return string
	 */
	public static function badge( $status, $label ) {
		return '<span class="wpac-badge wpac-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Pagination links.
	 *
	 * @param int $total Total.
	 * @param int $per   Per page.
	 */
	public static function pager( $total, $per ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $current,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}

	/**
	 * Current page number.
	 *
	 * @return int
	 */
	public static function paged() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	}

	/**
	 * GET param (sanitized text).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function get( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Local date/time display of a UTC MySQL datetime.
	 *
	 * @param string|null $utc UTC datetime.
	 * @param bool        $time Include time.
	 * @return string
	 */
	public static function date( $utc, $time = false ) {
		if ( ! $utc ) {
			return '—';
		}
		$format = get_option( 'date_format' ) . ( $time ? ' ' . get_option( 'time_format' ) : '' );
		$ts     = strtotime( $utc . ' UTC' );
		if ( false === $ts ) {
			return '—';
		}
		// wp_date() translates month and day names (get_date_from_gmt() prints "3. March 2026")
		// and converts to the site time zone.
		return (string) wp_date( $format, $ts );
	}

	/** Up to this many partners a selection field is a plain list; above it, a search field. */
	const PICKER_MAX = 200;

	/**
	 * Partner selection field without a fixed limit: a list for small programs, a search field
	 * (with suggestions from wpac_partner_search) for large ones, so every partner can be chosen.
	 * The value is resolved with AffiliateRepository::resolve_input().
	 *
	 * @param string $name    Field name.
	 * @param int    $current Selected partner (0 = none).
	 * @param string $status  Only partners of this status ('' = all).
	 * @param array  $attrs   Extra attributes (id, aria-label, style, data-*).
	 * @param int[]  $exclude Partners not offered.
	 * @return string HTML.
	 */
	public static function partner_picker( $name, $current = 0, $status = '', array $attrs = array(), array $exclude = array() ) {
		$html = '';
		foreach ( $attrs as $k => $v ) {
			$html .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		$current = (int) $current;
		wp_enqueue_script( 'wpac-admin', WPAC_URL . 'assets/js/admin.js', array(), WPAC_VERSION, true );
		$rows = \WPAffiliateCaptain\Affiliates\AffiliateRepository::options( '', $status, self::PICKER_MAX + 1 );
		if ( count( $rows ) <= self::PICKER_MAX ) {
			$out   = '<select name="' . esc_attr( $name ) . '"' . $html . '><option value="">—</option>';
			$found = false;
			foreach ( $rows as $a ) {
				if ( in_array( (int) $a->id, $exclude, true ) ) {
					continue;
				}
				$found = $found || (int) $a->id === $current;
				$out  .= '<option value="' . (int) $a->id . '"' . selected( $current, (int) $a->id, false ) . '>' . esc_html( $a->name . ' (' . $a->slug . ')' ) . '</option>';
			}
			if ( $current && ! $found ) {
				// Keep the current partner (e.g. paused) selectable, so saving never drops it by accident.
				$aff  = \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( $current );
				$out .= '<option value="' . $current . '" selected>' . esc_html( $aff ? $aff->name . ' (' . $aff->slug . ') – ' . $aff->status : '#' . $current ) . '</option>';
			}
			return $out . '</select>';
		}
		$value = '';
		if ( $current ) {
			$aff   = \WPAffiliateCaptain\Affiliates\AffiliateRepository::find( $current );
			$value = $aff ? \WPAffiliateCaptain\Affiliates\AffiliateRepository::option_label( $aff ) : (string) $current;
		}
		$list = 'wpac-pl-' . substr( md5( $name . wp_rand() ), 0, 8 );
		return '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" list="' . esc_attr( $list ) . '" autocomplete="off" placeholder="' . esc_attr__( 'Search: name, slug, e-mail or ID', 'affiliate-captain' ) . '" data-wpac-partner-search="' . esc_attr( wp_create_nonce( 'wpac_partner_search' ) ) . '" data-wpac-status="' . esc_attr( $status ) . '" data-wpac-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' . $html . '><datalist id="' . esc_attr( $list ) . '"></datalist>';
	}

	/**
	 * admin-ajax: partner suggestions for partner_picker() (id, name, slug only).
	 */
	public static function ajax_partner_search() {
		check_ajax_referer( 'wpac_partner_search', 'nonce' );
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) && ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			wp_send_json_error( null, 403 );
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$out    = array();
		foreach ( \WPAffiliateCaptain\Affiliates\AffiliateRepository::options( $search, $status, 20 ) as $a ) {
			$out[] = \WPAffiliateCaptain\Affiliates\AffiliateRepository::option_label( $a );
		}
		wp_send_json_success( $out );
	}
}
