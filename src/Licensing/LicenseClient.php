<?php
/**
 * Reusable client for products managed by WP License Captain.
 *
 * @package WPLC
 */

namespace WPAffiliateCaptain\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation, authenticated entitlement checks, and premium updates.
 *
 * Product plugins should instantiate this client with a product-specific option
 * key and should gate paid features from the signed entitlement payload.
 */
final class LicenseClient {
	/** @var string */
	private $server_url;
	/** @var string */
	private $product_key;
	/** @var string */
	private $product_version;
	/** @var string */
	private $option_key;

	/**
	 * @param string $server_url WP License Captain site URL.
	 * @param string $product_key Stable product identifier.
	 * @param string $product_version Installed product version.
	 * @param string $option_key Product-specific option key.
	 */
	public function __construct( string $server_url, string $product_key, string $product_version, string $option_key ) {
		$this->server_url = untrailingslashit( esc_url_raw( $server_url, array( 'https' ) ) );
		$this->product_key = sanitize_key( $product_key );
		$this->product_version = sanitize_text_field( $product_version );
		$this->option_key = sanitize_key( $option_key );
	}

	/**
	 * Activate this site and persist only its installation credential.
	 *
	 * @param string $license_key Customer-provided key.
	 * @param string $environment production, staging, or development.
	 * @param string $staging_parent_installation_id Optional production installation UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( string $license_key, string $environment = 'production', string $staging_parent_installation_id = '' ) {
		if ( '' === $this->server_url || '' === $this->product_key || '' === trim( $license_key ) ) {
			return new \WP_Error( 'wplc_configuration_invalid', __( 'License server settings or license key are missing.', 'wp-affiliate-captain' ) );
		}
		$installation_id = wp_generate_uuid4();
		$secret = bin2hex( random_bytes( 32 ) );
		$payload = array(
			'license_key' => strtoupper( trim( sanitize_text_field( $license_key ) ) ),
			'product_key' => $this->product_key,
			'installation_id' => $installation_id,
			'installation_secret' => $secret,
			'site_url' => home_url(),
			'environment' => in_array( $environment, array( 'production', 'staging', 'development' ), true ) ? $environment : 'production',
			'staging_parent_installation_id' => sanitize_text_field( $staging_parent_installation_id ),
			'product_version' => $this->product_version,
			'wp_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
		);
		$response = $this->request( 'POST', '/wplc/v1/activations', $payload, $secret, false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = self::verify_envelope( $response, $secret );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		update_option( $this->option_key, array( 'installation_id' => $installation_id, 'installation_secret' => $secret, 'activation_id' => absint( $verified['activation_id'] ?? 0 ) ), false );

		return $verified;
	}

	/**
	 * Fetch current signed feature rights.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_entitlements() {
		return $this->authenticated_post( '/wplc/v1/entitlements/check', array( 'product_version' => $this->product_version ) );
	}

	/**
	 * Fetch verified entitlements, using the signed local cache only during network/server outages.
	 *
	 * Cached rights expire at the earlier of the server expiry and fourteen days after receipt.
	 *
	 * @return array<string,mixed>|\\WP_Error
	 */
	public function get_entitlements() {
		$online = $this->check_entitlements();
		if ( ! is_wp_error( $online ) ) {
			if ( 'active' === ( $online['license_status'] ?? '' ) ) {
				$this->store_entitlements_cache( $online );
			} else {
				delete_option( $this->option_key . '_entitlements_cache' );
			}
			return $online;
		}

		if ( ! $this->is_temporary_server_error( $online ) ) {
			return $online;
		}

		$cached = $this->read_entitlements_cache();
		return is_wp_error( $cached ) ? $online : $cached;
	}

	/** Store an HMAC-protected snapshot received from the already verified API envelope. */
	private function store_entitlements_cache( array $data ): void {
		$credentials = $this->credentials();
		$expires = strtotime( (string) ( $data['cache_expires_at'] ?? '' ) . ' UTC' );
		if ( is_wp_error( $credentials ) || ! $expires ) {
			return;
		}
		$now = time();
		$expires = min( $expires, $now + 14 * DAY_IN_SECONDS );
		if ( $expires <= $now ) {
			return;
		}
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}
		update_option(
			$this->option_key . '_entitlements_cache',
			array(
				'data' => $data,
				'cached_at' => $now,
				'expires_at' => $expires,
				'signature' => hash_hmac( 'sha256', $json, $credentials['installation_secret'] ),
			),
			false
		);
	}

	/** Return a cache only if its HMAC, timestamp, and hard 14-day limit validate. */
	private function read_entitlements_cache() {
		$cache = get_option( $this->option_key . '_entitlements_cache', array() );
		$credentials = $this->credentials();
		if ( ! is_array( $cache ) || ! is_array( $cache['data'] ?? null ) || is_wp_error( $credentials ) ) {
			return new \\WP_Error( 'wplc_cache_unavailable', __( 'No verified offline entitlement cache is available.', 'voucher-captain' ) );
		}
		$cached_at = absint( $cache['cached_at'] ?? 0 );
		$expires_at = absint( $cache['expires_at'] ?? 0 );
		if ( ! $cached_at || ! $expires_at || time() >= $expires_at || $expires_at > $cached_at + 14 * DAY_IN_SECONDS ) {
			return new \\WP_Error( 'wplc_cache_expired', __( 'The offline entitlement cache has expired.', 'voucher-captain' ) );
		}
		$json = wp_json_encode( $cache['data'], JSON_UNESCAPED_SLASHES );
		$expected = hash_hmac( 'sha256', false === $json ? '' : $json, $credentials['installation_secret'] );
		if ( ! is_string( $cache['signature'] ?? null ) || ! hash_equals( $expected, $cache['signature'] ) ) {
			return new \\WP_Error( 'wplc_cache_signature_invalid', __( 'The offline entitlement cache could not be verified.', 'voucher-captain' ) );
		}
		return $cache['data'];
	}

	/** Only transport failures and server-side 5xx responses may use cached rights. */
	private function is_temporary_server_error( \\WP_Error $error ): bool {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'http_request_failed', 'http_request_timeout', 'connect_timeout', 'request_timeout' ), true ) ) {
			return true;
		}
		$data = $error->get_error_data();
		return 'wplc_http_error' === $code && is_array( $data ) && absint( $data['status'] ?? 0 ) >= 500;
	}

	/**
	 * Check a feature flag in a response returned by check_entitlements().
	 *
	 * The caller must pass the verified response returned by check_entitlements().
	 * Missing, malformed, foreign-product, false, and zero-value entitlements deny access.
	 *
	 * @param string $key Stable namespaced entitlement identifier.
	 * @param array<string,mixed> $verified_entitlements Verified entitlement response data.
	 * @return bool
	 */
	public function allows_entitlement( string $key, array $verified_entitlements ): bool {
		$prefixes = array(
			'voucher-captain' => 'voucher.',
			'wp-pass-captain' => 'pass.',
			'affiliate-captain' => 'affiliate.',
		);
		$prefix = $prefixes[ $this->product_key ] ?? '';
		if ( '' === $prefix || 0 !== strpos( $key, $prefix ) ) {
			return false;
		}

		$features = $verified_entitlements['entitlements'] ?? $verified_entitlements;
		if ( ! is_array( $features ) || ! array_key_exists( $key, $features ) ) {
			return false;
		}

		$value = $features[ $key ];
		return true === $value || ( ( is_int( $value ) || is_float( $value ) ) && $value > 0 );
	}

	/**
	 * Check for a product update and receive a short-lived package URL.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_update() {
		return $this->authenticated_post(
			'/wplc/v1/updates/check',
			array( 'product_key' => $this->product_key, 'current_version' => $this->product_version )
		);
	}

	/**
	 * Deactivate this site and remove the local credential after confirmation.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate() {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$response = $this->request(
			'DELETE',
			'/wplc/v1/activations/' . absint( $credentials['activation_id'] ?? 0 ),
			array(),
			$credentials['installation_secret'],
			true,
			$credentials['installation_id']
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = self::verify_envelope( $response, $credentials['installation_secret'] );
		if ( ! is_wp_error( $verified ) ) {
			delete_option( $this->option_key );
		}
		return $verified;
	}

	/**
	 * Sign and send an authenticated POST using the installation credential.
	 *
	 * @param string $path REST route.
	 * @param array<string,mixed> $payload Request payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function authenticated_post( string $path, array $payload ) {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$response = $this->request( 'POST', $path, $payload, $credentials['installation_secret'], true, $credentials['installation_id'] );
		return is_wp_error( $response ) ? $response : self::verify_envelope( $response, $credentials['installation_secret'] );
	}

	/**
	 * Send a request to the license server.
	 *
	 * @param string $method HTTP method.
	 * @param string $route REST route.
	 * @param array<string,mixed> $payload Data.
	 * @param string $secret Signing secret.
	 * @param bool $authenticated Whether to include authentication headers.
	 * @param string $installation_id Installation UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $route, array $payload, string $secret, bool $authenticated, string $installation_id = '' ) {
		$body = 'GET' === $method || ( 'DELETE' === $method && empty( $payload ) ) ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new \WP_Error( 'wplc_json_error', __( 'Could not encode the license request.', 'wp-affiliate-captain' ) );
		}
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $authenticated ) {
			$timestamp = (string) time();
			$nonce = bin2hex( random_bytes( 16 ) );
			$canonical = implode( "\n", array( $method, $route, $timestamp, $nonce, hash( 'sha256', $body ) ) );
			$headers['X-WPLC-Installation-Id'] = $installation_id;
			$headers['X-WPLC-Timestamp'] = $timestamp;
			$headers['X-WPLC-Nonce'] = $nonce;
			$headers['X-WPLC-Signature'] = hash_hmac( 'sha256', $canonical, $secret );
		}
		$http = wp_remote_request(
			$this->server_url . '/wp-json' . $route,
			array(
				'method' => $method,
				'timeout' => 20,
				'redirection' => 0,
				'sslverify' => true,
				'headers' => $headers,
				'body' => $body,
			)
		);
		if ( is_wp_error( $http ) ) {
			return $http;
		}
		$code = (int) wp_remote_retrieve_response_code( $http );
		$data = json_decode( (string) wp_remote_retrieve_body( $http ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error = is_array( $data ) ? ( $data['code'] ?? 'wplc_http_error' ) : 'wplc_http_error';
			$message = is_array( $data ) ? ( $data['message'] ?? __( 'The license server returned an error.', 'wp-affiliate-captain' ) ) : __( 'The license server returned an invalid response.', 'wp-affiliate-captain' );
			return new \WP_Error( sanitize_key( (string) $error ), sanitize_text_field( (string) $message ), array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'wplc_response_invalid', __( 'The license server returned invalid JSON.', 'wp-affiliate-captain' ) );
		}
		if ( isset( $data['data'] ) && isset( $data['signature'] ) ) {
			return $data;
		}
		return new \WP_Error( 'wplc_response_invalid', __( 'The license server response is missing its signature envelope.', 'wp-affiliate-captain' ) );
	}

	/**
	 * Verify the response HMAC before exposing entitlement data to product code.
	 *
	 * @param array<string,mixed> $envelope Response wrapper.
	 * @param string $secret Installation secret.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function verify_envelope( array $envelope, string $secret ) {
		$json = wp_json_encode( $envelope['data'], JSON_UNESCAPED_SLASHES );
		$expected = hash_hmac( 'sha256', false === $json ? '' : $json, $secret );
		if ( ! is_string( $envelope['signature'] ?? null ) || ! hash_equals( $expected, $envelope['signature'] ) ) {
			return new \WP_Error( 'wplc_signature_invalid', __( 'The license server response signature could not be verified.', 'wp-affiliate-captain' ) );
		}
		return is_array( $envelope['data'] ) ? $envelope['data'] : array();
	}

	/**
	 * Read credentials saved after activation.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	private function credentials() {
		$credentials = get_option( $this->option_key, array() );
		if ( ! is_array( $credentials ) || empty( $credentials['installation_id'] ) || empty( $credentials['installation_secret'] ) || empty( $credentials['activation_id'] ) ) {
			return new \WP_Error( 'wplc_not_activated', __( 'This product installation has not been activated.', 'wp-affiliate-captain' ) );
		}
		return $credentials;
	}
}
