<?php
/**
 * Premium update integration with checksum verification.
 *
 * @package wp-affiliate-captain
 */

namespace WPAffiliateCaptain\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds license-authorized updates and verifies ZIP checksums before install.
 */
final class PremiumUpdater {
	private $plugin_file;
	private $product_key;
	private $version;
	private $server_option;
	private $credentials_option;
	private $source_url;

	public function __construct() {
		$this->plugin_file = plugin_basename( WPAC_FILE );
		$this->product_key = 'affiliate-captain';
		$this->version = WPAC_VERSION;
		$this->server_option = 'wpac_wplc_server_url';
		$this->credentials_option = 'wpac_wplc_credentials';
		$this->source_url = 'https://github.com/hastla007/wpaffiliatemaster';
	}

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'upgrader_pre_download', array( $this, 'download_checked_package' ), 10, 4 );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! current_user_can( 'update_plugins' ) ) {
			return $transient;
		}
		$server = (string) get_option( $this->server_option, '' );
		if ( '' === $server ) {
			return $transient;
		}
		$client = new LicenseClient( $server, $this->product_key, $this->version, $this->credentials_option );
		$update = $client->check_update();
		if ( is_wp_error( $update ) || empty( $update['update_available'] ) ) {
			if ( isset( $transient->response[ $this->plugin_file ] ) && ! empty( $transient->response[ $this->plugin_file ]->wplc_managed ) ) {
				unset( $transient->response[ $this->plugin_file ] );
			}
			return $transient;
		}
		$package = esc_url_raw( (string) ( $update['package_url'] ?? '' ), array( 'https' ) );
		$checksum = strtolower( (string) ( $update['sha256'] ?? '' ) );
		if ( '' === $package || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			return $transient;
		}
		set_transient( $this->checksum_key( $package ), $checksum, 15 * MINUTE_IN_SECONDS );
		$transient->response[ $this->plugin_file ] = (object) array(
			'slug' => $this->product_key,
			'plugin' => $this->plugin_file,
			'new_version' => sanitize_text_field( (string) $update['version'] ),
			'url' => $this->source_url,
			'package' => $package,
			'tested' => sanitize_text_field( (string) ( $update['tested_wp'] ?? '' ) ),
			'requires' => sanitize_text_field( (string) ( $update['requires_wp'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $update['requires_php'] ?? '' ) ),
			'wplc_managed' => true,
		);
		return $transient;
	}

	public function download_checked_package( $reply, $package, $upgrader, $hook_extra ) {
		if ( ! $this->is_license_package( (string) $package ) ) {
			return $reply;
		}
		$checksum = (string) get_transient( $this->checksum_key( (string) $package ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			$refreshed = $this->refresh_package();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$package = $refreshed['package'];
			$checksum = $refreshed['checksum'];
		}
		$result = $this->download_and_verify( (string) $package, $checksum );
		if ( is_wp_error( $result ) && 'wplc_download_redirect_invalid' === $result->get_error_code() ) {
			$refreshed = $this->refresh_package();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return $this->download_and_verify( $refreshed['package'], $refreshed['checksum'] );
		}
		return $result;
	}

	private function refresh_package() {
		$server = (string) get_option( $this->server_option, '' );
		if ( '' === $server ) {
			return new \WP_Error( 'wplc_update_not_configured', __( 'The license server URL is not configured.', 'wp-affiliate-captain' ) );
		}
		$update = ( new LicenseClient( $server, $this->product_key, $this->version, $this->credentials_option ) )->check_update();
		if ( is_wp_error( $update ) || empty( $update['update_available'] ) ) {
			return is_wp_error( $update ) ? $update : new \WP_Error( 'wplc_update_unavailable', __( 'No authorized product update is available.', 'wp-affiliate-captain' ) );
		}
		$package = esc_url_raw( (string) ( $update['package_url'] ?? '' ), array( 'https' ) );
		$checksum = strtolower( (string) ( $update['sha256'] ?? '' ) );
		if ( '' === $package || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			return new \WP_Error( 'wplc_update_metadata_invalid', __( 'The update checksum metadata is invalid.', 'wp-affiliate-captain' ) );
		}
		set_transient( $this->checksum_key( $package ), $checksum, 15 * MINUTE_IN_SECONDS );
		return array( 'package' => $package, 'checksum' => $checksum );
	}

	private function download_and_verify( string $package, string $checksum ) {
		$probe = wp_remote_get( $package, array( 'timeout' => 20, 'redirection' => 0 ) );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		$code = (int) wp_remote_retrieve_response_code( $probe );
		if ( 302 !== $code && 301 !== $code ) {
			return new \WP_Error( 'wplc_download_redirect_invalid', __( 'The license server did not return a package redirect.', 'wp-affiliate-captain' ) );
		}
		$location = esc_url_raw( (string) wp_remote_retrieve_header( $probe, 'location' ), array( 'https' ) );
		if ( ! $this->is_allowed_package_host( $location ) ) {
			return new \WP_Error( 'wplc_download_host_invalid', __( 'The update package host is not allowed.', 'wp-affiliate-captain' ) );
		}
		$tmp = wp_tempnam( 'wplc-update.zip' );
		if ( ! $tmp ) {
			return new \WP_Error( 'wplc_download_temp_failed', __( 'A temporary update file could not be created.', 'wp-affiliate-captain' ) );
		}
		$response = wp_remote_get(
			$location,
			array( 'timeout' => 90, 'redirection' => 0, 'stream' => true, 'filename' => $tmp )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_file( $tmp ) ) {
			if ( is_file( $tmp ) ) { unlink( $tmp ); }
			return is_wp_error( $response ) ? $response : new \WP_Error( 'wplc_download_failed', __( 'The premium update could not be downloaded.', 'wp-affiliate-captain' ) );
		}
		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( $checksum, strtolower( $actual ) ) ) {
			unlink( $tmp );
			return new \WP_Error( 'wplc_checksum_mismatch', __( 'The update package checksum did not match. Installation was stopped.', 'wp-affiliate-captain' ) );
		}
		return $tmp;
	}

	private function is_allowed_package_host( string $url ): bool {
		$parts = wp_parse_url( $url );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$server = wp_parse_url( (string) get_option( $this->server_option, '' ) );
		$allowed = apply_filters( 'wplc_client_update_hosts', array( strtolower( (string) ( $server['host'] ?? '' ) ) ), $this->product_key );
		$allowed = is_array( $allowed ) ? array_map( 'strtolower', array_map( 'sanitize_text_field', $allowed ) ) : array();
		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) && in_array( $host, $allowed, true );
	}

	private function is_license_package( string $url ): bool {
		$server = wp_parse_url( (string) get_option( $this->server_option, '' ) );
		$package = wp_parse_url( $url );
		return ! empty( $server['host'] )
			&& strtolower( (string) ( $server['host'] ) ) === strtolower( (string) ( $package['host'] ?? '' ) )
			&& false !== strpos( (string) ( $package['path'] ?? '' ), '/wplc/v1/updates/download/' );
	}

	private function checksum_key( string $package ): string {
		return 'wplc_update_checksum_' . hash( 'sha256', $package );
	}
}
