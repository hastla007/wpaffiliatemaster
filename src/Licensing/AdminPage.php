<?php
/**
 * License activation and status screen.
 *
 * @package wp-affiliate-captain
 */

namespace WPAffiliateCaptain\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a site administrator activate or deactivate this product license.
 */
final class AdminPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_wpac_activate_license', array( $this, 'activate' ) );
		add_action( 'admin_post_wpac_deactivate_license', array( $this, 'deactivate' ) );
	}

	public function add_page(): void {
		add_options_page(
			esc_html__( 'License', 'wp-affiliate-captain' ),
			esc_html__( 'License', 'wp-affiliate-captain' ),
			'manage_options',
			'wpac-license',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$server = (string) get_option( 'wpac_wplc_server_url', '' );
		$credentials = get_option( 'wpac_wplc_credentials', array() );
		$license = null;
		$error = '';
		if ( is_array( $credentials ) && ! empty( $credentials['installation_id'] ) && '' !== $server ) {
			$license = ( new LicenseClient( $server, 'affiliate-captain', WPAC_VERSION, 'wpac_wplc_credentials' ) )->check_entitlements();
			if ( is_wp_error( $license ) ) {
				$error = $license->get_error_message();
				$license = null;
			}
		}
		$notice_key = 'wpac_license_notice_' . get_current_user_id();
		$notice = get_transient( $notice_key );
		delete_transient( $notice_key );
		echo '<div class="wrap"><h1>' . esc_html__( 'License', 'wp-affiliate-captain' ) . '</h1>';
		if ( is_array( $notice ) ) {
			$notice_class = 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( is_array( $license ) ) {
			echo '<h2>' . esc_html__( 'Current license status', 'wp-affiliate-captain' ) . '</h2><dl>';
			echo '<dt>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</dt><dd>' . esc_html( (string) ( $license['license_status'] ?? 'unknown' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Paid through', 'wp-affiliate-captain' ) . '</dt><dd>' . esc_html( (string) ( $license['paid_until'] ?? '—' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Sites', 'wp-affiliate-captain' ) . '</dt><dd>' . esc_html( (string) ( $license['site_count'] ?? 0 ) . ' / ' . (string) ( $license['site_limit'] ?? 0 ) ) . '</dd>';
			echo '</dl><h3>' . esc_html__( 'Feature rights', 'wp-affiliate-captain' ) . '</h3><pre>' . esc_html( wp_json_encode( $license['entitlements'] ?? array(), JSON_PRETTY_PRINT ) ) . '</pre>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpac_deactivate_license">';
			wp_nonce_field( 'wpac_deactivate_license' );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Deactivate this site', 'wp-affiliate-captain' ) . '</button></p></form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpac_activate_license">';
			wp_nonce_field( 'wpac_activate_license' );
			echo '<table class="form-table"><tr><th><label for="wplc-server">' . esc_html__( 'License server URL', 'wp-affiliate-captain' ) . '</label></th><td><input id="wplc-server" name="server_url" type="url" class="regular-text" value="' . esc_attr( $server ) . '" required placeholder="https://licenses.example.com"></td></tr>';
			echo '<tr><th><label for="wplc-key">' . esc_html__( 'License key', 'wp-affiliate-captain' ) . '</label></th><td><input id="wplc-key" name="license_key" type="password" class="regular-text" autocomplete="off" required></td></tr></table>';
			echo '<p>' . esc_html__( 'The license key is used once for activation and is not stored by this plugin. Activation credentials are stored locally without autoloading.', 'wp-affiliate-captain' ) . '</p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Activate license', 'wp-affiliate-captain' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	public function activate(): void {
		$this->authorize( 'wpac_activate_license' );
		$server = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ), array( 'https' ) ) : '';
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( '' === $server || '' === $key ) {
			$this->redirect( 'error', __( 'Enter a valid HTTPS license server URL and license key.', 'wp-affiliate-captain' ) );
		}
		update_option( 'wpac_wplc_server_url', $server, false );
		$result = ( new LicenseClient( $server, 'affiliate-captain', WPAC_VERSION, 'wpac_wplc_credentials' ) )->activate( $key );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$this->redirect( 'success', __( 'License activated for this site.', 'wp-affiliate-captain' ) );
	}

	public function deactivate(): void {
		$this->authorize( 'wpac_deactivate_license' );
		$server = (string) get_option( 'wpac_wplc_server_url', '' );
		$result = ( new LicenseClient( $server, 'affiliate-captain', WPAC_VERSION, 'wpac_wplc_credentials' ) )->deactivate();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$this->redirect( 'success', __( 'This site was deactivated.', 'wp-affiliate-captain' ) );
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this license.', 'wp-affiliate-captain' ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $type, string $message ): void {
		set_transient( 'wpac_license_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=wpac-license' ) );
		exit;
	}
}
