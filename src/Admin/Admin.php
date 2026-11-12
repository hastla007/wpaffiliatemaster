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

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpac', array( __CLASS__, 'dispatch' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'cron_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPAC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Affiliate Captain', 'wp-affiliate-captain' ), __( 'Affiliate Captain', 'wp-affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, 'wpac', array( DashboardPage::class, 'render' ), 'dashicons-groups', 56 );
		add_submenu_page( 'wpac', __( 'Dashboard', 'wp-affiliate-captain' ), __( 'Dashboard', 'wp-affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, 'wpac', array( DashboardPage::class, 'render' ) );
		$partners = add_submenu_page( 'wpac', __( 'Partners', 'wp-affiliate-captain' ), __( 'Partners', 'wp-affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, 'wpac-affiliates', array( AffiliatesPage::class, 'render' ) );
		$ledger   = add_submenu_page( 'wpac', __( 'Commissions', 'wp-affiliate-captain' ), __( 'Commissions', 'wp-affiliate-captain' ), Capabilities::MANAGE_AFFILIATES, 'wpac-commissions', array( CommissionsPage::class, 'render' ) );
		// List table bulk actions are processed before any output (then redirected).
		add_action( 'load-' . $partners, array( AffiliatesPage::class, 'process_bulk' ) );
		add_action( 'load-' . $ledger, array( CommissionsPage::class, 'process_bulk' ) );
		add_submenu_page( 'wpac', __( 'Payouts', 'wp-affiliate-captain' ), __( 'Payouts', 'wp-affiliate-captain' ), Capabilities::MANAGE_PAYOUTS, 'wpac-payouts', array( PayoutsPage::class, 'render' ) );
		add_submenu_page( 'wpac', __( 'Creatives', 'wp-affiliate-captain' ), __( 'Creatives', 'wp-affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-creatives', array( CreativesPage::class, 'render' ) );
		add_submenu_page( 'wpac', __( 'Rules & groups', 'wp-affiliate-captain' ), __( 'Rules & groups', 'wp-affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-rules', array( RulesPage::class, 'render' ) );
		add_submenu_page( 'wpac', __( 'Integrations', 'wp-affiliate-captain' ), __( 'Integrations', 'wp-affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-integrations', array( IntegrationsPage::class, 'render' ) );
		add_submenu_page( 'wpac', __( 'Audit log', 'wp-affiliate-captain' ), __( 'Audit log', 'wp-affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-audit', array( AuditPage::class, 'render' ) );
		add_submenu_page( 'wpac', __( 'Settings', 'wp-affiliate-captain' ), __( 'Settings', 'wp-affiliate-captain' ), Capabilities::MANAGE_PROGRAM, 'wpac-settings', array( SettingsPage::class, 'render' ) );
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
		array_unshift( $links, '<a href="' . esc_url( self::url( 'wpac-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-affiliate-captain' ) . '</a>' );
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Affiliate Captain: the database update is incomplete. Scheduled tasks are paused until it succeeds; it is retried on the next page load.', 'wp-affiliate-captain' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $schema, 0, 10 ) ) ) . '</li></ul></div>';
		}
		\WPAffiliateCaptain\Core\Cron::schedule(); // Cheap; restores events lost e.g. after a migration.
		$next = wp_next_scheduled( \WPAffiliateCaptain\Core\Cron::HOURLY );
		if ( ! $next || $next > time() - 2 * HOUR_IN_SECONDS ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Affiliate Captain: the scheduled tasks have not run for more than two hours. Commissions are not released and failed jobs are not retried.', 'wp-affiliate-captain' ) . ' ';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo esc_html__( 'WP-Cron is disabled (DISABLE_WP_CRON): set up a system cron that runs "wp cron event run --due-now" every few minutes.', 'wp-affiliate-captain' );
		} else {
			echo esc_html__( 'Your site may receive too few visits or block loopback requests: set up a system cron that runs "wp cron event run --due-now".', 'wp-affiliate-captain' );
		}
		echo '</p></div>';
	}

	/**
	 * Hint on other plugin screens until the setup is complete (the dashboard shows the checklist).
	 */
	public static function setup_notice() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) || \WPAffiliateCaptain\Core\Settings::get( 'portal_page_id' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'wpac' ) || 'toplevel_page_wpac' === $screen->id ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Affiliate Captain is not fully set up yet.', 'wp-affiliate-captain' ) . ' <a href="' . esc_url( self::url( 'wpac' ) ) . '">' . esc_html__( 'Open the setup checklist', 'wp-affiliate-captain' ) . '</a></p></div>';
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
		$type = 'error' === $data['type'] ? 'error' : 'success';
		printf( '<div class="notice notice-%s is-dismissible" role="%s"><p>%s</p></div>', esc_attr( $type ), 'error' === $type ? 'alert' : 'status', esc_html( $data['message'] ) );
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error.
	 * @param string $url     Target (defaults to referer).
	 */
	public static function back( $message, $type = 'success', $url = '' ) {
		$url = $url ? $url : wpac_return_url();
		$url = $url ? $url : admin_url( 'admin.php?page=wpac' );
		$url = remove_query_arg( array( 'wpac_notice', 'wpac_type' ), $url );
		set_transient(
			'wpac_notice_' . get_current_user_id(),
			array(
				'message' => (string) $message,
				'type'    => $type,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $url );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Route admin-post actions to page handlers.
	 */
	public static function dispatch() {
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ( isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '' );
		$nonce = isset( $_REQUEST['_wpac_nonce'] ) ? sanitize_key( wp_unslash( $_REQUEST['_wpac_nonce'] ) ) : '';
		if ( '' === $do || ! wp_verify_nonce( $nonce, 'wpac_' . $do ) ) {
			wp_die( esc_html__( 'The link has expired. Please go back and try again.', 'wp-affiliate-captain' ), 403 );
		}

		$routes = array(
			'affiliate_create'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'affiliate_update'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'affiliate_status'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_create'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_batch'        => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_status'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_coupon'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'code_export'       => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES ),
			'profile_update'    => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ), // Changing bank details redirects payouts.
			'balance_writeoff'  => array( AffiliatesPage::class, Capabilities::MANAGE_PAYOUTS ),
			'commission_status' => array( CommissionsPage::class, Capabilities::MANAGE_AFFILIATES ),
			'commission_export' => array( CommissionsPage::class, Capabilities::MANAGE_AFFILIATES ),
			'payout_run'        => array( PayoutsPage::class, Capabilities::MANAGE_PAYOUTS ),
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
			'settings_save'     => array( SettingsPage::class, Capabilities::MANAGE_PROGRAM ),
			'report_export'     => array( DashboardPage::class, Capabilities::MANAGE_AFFILIATES ),
			'setup_pages'       => array( DashboardPage::class, Capabilities::MANAGE_PROGRAM ),
		);
		if ( ! isset( $routes[ $do ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'wp-affiliate-captain' ), 400 );
		}
		list( $class, $cap ) = $routes[ $do ];
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-affiliate-captain' ), 403 );
		}
		call_user_func( array( $class, 'handle' ), $do );
		self::back( __( 'Done.', 'wp-affiliate-captain' ) );
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
		return get_date_from_gmt( $utc, $format );
	}
}
