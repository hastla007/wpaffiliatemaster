<?php
/**
 * S3-compatible object storage for credit notes (AWS S3, Hetzner Object Storage, Wasabi, MinIO,
 * Cloudflare R2 …): every app server reads and deletes the same documents, so downloads work on
 * every node and the deletion after the retention period reaches the only copy.
 *
 * Requests are signed with AWS Signature Version 4 and sent with wp_remote_request() (no SDK).
 * Configuration only in wp-config.php – the access key and secret are never stored in the
 * database or shown anywhere:
 *
 *     define( 'WPAC_S3_ENDPOINT', 'https://fsn1.your-objectstorage.com' );
 *     define( 'WPAC_S3_REGION', 'fsn1' );
 *     define( 'WPAC_S3_BUCKET', 'my-credit-notes' );
 *     define( 'WPAC_S3_KEY', '…' );
 *     define( 'WPAC_S3_SECRET', '…' );
 *     define( 'WPAC_S3_PREFIX', 'affiliate-captain/' ); // Optional.
 *     define( 'WPAC_S3_PATH_STYLE', true );              // Optional (MinIO, some providers).
 *     define( 'WPAC_S3_SSE', 'AES256' );                 // Optional server-side encryption.
 *
 * Objects are written without any public ACL (private, like the bucket must be). On a network
 * every site uses its own prefix (site-<ID>/).
 *
 * Self-contained (WordPress functions only): uninstall.php uses it without the plugin loaded.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts\Storage;

defined( 'ABSPATH' ) || exit;

final class S3Store implements StatementStore {

	/** Constants that configure the store. */
	const CONSTANTS = array( 'WPAC_S3_ENDPOINT', 'WPAC_S3_REGION', 'WPAC_S3_BUCKET', 'WPAC_S3_KEY', 'WPAC_S3_SECRET', 'WPAC_S3_PREFIX', 'WPAC_S3_PATH_STYLE', 'WPAC_S3_SSE' );

	/** @var array Configuration. */
	private $cfg;

	/** @var string Reason of the last failure. */
	private $error = '';

	/**
	 * Store with an explicit configuration.
	 *
	 * @param array $cfg endpoint, region, bucket, key, secret, prefix, path_style, sse, timeout.
	 */
	public function __construct( array $cfg ) {
		$cfg += array(
			'endpoint'   => '',
			'region'     => 'us-east-1',
			'bucket'     => '',
			'key'        => '',
			'secret'     => '',
			'prefix'     => '',
			'path_style' => false,
			'sse'        => '',
			'timeout'    => 20,
		);
		$cfg['region'] = '' !== (string) $cfg['region'] ? (string) $cfg['region'] : 'us-east-1';
		$cfg['endpoint'] = untrailingslashit( (string) $cfg['endpoint'] );
		$prefix          = trim( (string) $cfg['prefix'], '/' );
		$cfg['prefix']   = '' !== $prefix ? $prefix . '/' : '';
		$this->cfg       = $cfg;
	}

	/**
	 * Store configured with the WPAC_S3_* constants, null when they are not (completely) defined.
	 * On a network each site gets its own prefix.
	 *
	 * @param int|null $blog_id Site (default: the current one).
	 * @return self|null
	 */
	public static function from_constants( $blog_id = null ) {
		if ( ! self::configured() ) {
			return null;
		}
		$prefix = defined( 'WPAC_S3_PREFIX' ) ? trim( (string) constant( 'WPAC_S3_PREFIX' ), '/' ) : '';
		if ( is_multisite() ) {
			$prefix = ( '' !== $prefix ? $prefix . '/' : '' ) . 'site-' . (int) ( null === $blog_id ? get_current_blog_id() : $blog_id );
		}
		return new self(
			array(
				'endpoint'   => defined( 'WPAC_S3_ENDPOINT' ) ? (string) constant( 'WPAC_S3_ENDPOINT' ) : '',
				'region'     => defined( 'WPAC_S3_REGION' ) ? (string) constant( 'WPAC_S3_REGION' ) : '',
				'bucket'     => (string) constant( 'WPAC_S3_BUCKET' ),
				'key'        => (string) constant( 'WPAC_S3_KEY' ),
				'secret'     => (string) constant( 'WPAC_S3_SECRET' ),
				'prefix'     => $prefix,
				'path_style' => defined( 'WPAC_S3_PATH_STYLE' ) && (bool) constant( 'WPAC_S3_PATH_STYLE' ),
				'sse'        => defined( 'WPAC_S3_SSE' ) ? (string) constant( 'WPAC_S3_SSE' ) : '',
			)
		);
	}

	/**
	 * Whether any WPAC_S3_* constant is defined (the operator wants object storage).
	 *
	 * @return bool
	 */
	public static function requested() {
		foreach ( self::CONSTANTS as $name ) {
			if ( defined( $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Problems of the configuration: names of missing constants and invalid values (never a value).
	 *
	 * @return string[]
	 */
	public static function config_problems() {
		if ( ! self::requested() ) {
			return array();
		}
		$out = array();
		foreach ( array( 'WPAC_S3_BUCKET', 'WPAC_S3_KEY', 'WPAC_S3_SECRET' ) as $name ) {
			if ( ! defined( $name ) || '' === trim( (string) constant( $name ) ) ) {
				$out[] = $name;
			}
		}
		if ( ! defined( 'WPAC_S3_ENDPOINT' ) || '' === (string) constant( 'WPAC_S3_ENDPOINT' ) ) {
			$out[] = 'WPAC_S3_ENDPOINT';
		}
		if ( defined( 'WPAC_S3_ENDPOINT' ) && '' !== (string) constant( 'WPAC_S3_ENDPOINT' ) && ! self::endpoint_ok( (string) constant( 'WPAC_S3_ENDPOINT' ) ) ) {
			$out[] = 'WPAC_S3_ENDPOINT (https://…)';
		}
		if ( defined( 'WPAC_S3_SSE' ) && ! in_array( (string) constant( 'WPAC_S3_SSE' ), array( '', 'AES256', 'aws:kms' ), true ) ) {
			$out[] = 'WPAC_S3_SSE (AES256 / aws:kms)';
		}
		return $out;
	}

	/**
	 * Whether the constants configure a usable store.
	 *
	 * @return bool
	 */
	public static function configured() {
		return self::requested() && ! self::config_problems();
	}

	/**
	 * Endpoints must use TLS (documents and signed requests); plain HTTP only for a local MinIO.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return bool
	 */
	private static function endpoint_ok( $endpoint ) {
		$parts = wp_parse_url( (string) $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		if ( 'https' === strtolower( (string) $parts['scheme'] ) ) {
			return true;
		}
		return 'http' === strtolower( (string) $parts['scheme'] ) && in_array( strtolower( (string) $parts['host'] ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
	}

	/** {@inheritDoc} */
	public function id() {
		return 's3';
	}

	/** {@inheritDoc} */
	public function label() {
		$parts = wp_parse_url( $this->cfg['endpoint'] );
		$host  = is_array( $parts ) && ! empty( $parts['host'] ) ? (string) $parts['host'] : (string) $this->cfg['endpoint'];
		return 's3://' . $this->cfg['bucket'] . '/' . $this->cfg['prefix'] . ' (' . $host . ', ' . $this->cfg['region'] . ')';
	}

	/**
	 * Object key prefix (with a trailing slash, or '').
	 *
	 * @return string
	 */
	public function prefix() {
		return (string) $this->cfg['prefix'];
	}

	/**
	 * Plain object name, '' for nothing usable.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function clean( $name ) {
		$name = basename( (string) $name );
		return '' === $name || '.' === $name[0] ? '' : $name;
	}

	/** {@inheritDoc} */
	public function put( $name, $bytes ) {
		$name = self::clean( $name );
		if ( '' === $name ) {
			$this->error = 'invalid file name';
			return false;
		}
		$headers = array( 'content-type' => '.pdf' === substr( $name, -4 ) ? 'application/pdf' : 'application/octet-stream' );
		if ( '' !== (string) $this->cfg['sse'] ) {
			$headers['x-amz-server-side-encryption'] = (string) $this->cfg['sse'];
		}
		$res = $this->request( 'PUT', $this->cfg['prefix'] . $name, (string) $bytes, array(), $headers );
		return $this->ok( $res, array( 200 ), 'PUT' );
	}

	/** {@inheritDoc} */
	public function get( $name ) {
		$name = self::clean( $name );
		if ( '' === $name ) {
			return null;
		}
		$res = $this->request( 'GET', $this->cfg['prefix'] . $name );
		if ( ! is_wp_error( $res ) && 404 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$this->error = '';
			return null;
		}
		return $this->ok( $res, array( 200 ), 'GET' ) ? (string) wp_remote_retrieve_body( $res ) : null;
	}

	/** {@inheritDoc} */
	public function exists( $name ) {
		$name = self::clean( $name );
		if ( '' === $name ) {
			return false;
		}
		$res = $this->request( 'HEAD', $this->cfg['prefix'] . $name );
		if ( ! is_wp_error( $res ) && 404 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$this->error = '';
			return false;
		}
		return $this->ok( $res, array( 200 ), 'HEAD' );
	}

	/** {@inheritDoc} */
	public function delete( $name, $hash = '' ) {
		$name = self::clean( $name );
		if ( '' === $name ) {
			return true;
		}
		$res = $this->request( 'DELETE', $this->cfg['prefix'] . $name );
		return $this->ok( $res, array( 200, 204, 404 ), 'DELETE' );
	}

	/**
	 * Timeout of each request in seconds (clean-ups use a short one, R15-P2).
	 *
	 * @param int $seconds Seconds (at least 1).
	 */
	public function set_timeout( $seconds ) {
		$this->cfg['timeout'] = max( 1, (int) $seconds );
	}

	/**
	 * Delete many documents with S3 DeleteObjects (up to 1000 per request), R15-P2. Providers
	 * that refuse DeleteObjects (e.g. HTTP 501 NotImplemented) get single DELETE requests instead.
	 * Stops starting new requests once the deadline has passed.
	 *
	 * @param string[] $names    File names.
	 * @param float    $deadline microtime( true ) after which no request is started (0 = none).
	 * @return string[] Names that were not deleted (failed or not reached); see last_error().
	 */
	public function delete_many( array $names, $deadline = 0 ) {
		$names  = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean' ), $names ), 'strlen' ) ) );
		$left   = array();
		$single = false;
		$error  = '';
		foreach ( array_chunk( $names, 1000 ) as $chunk ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$left  = array_merge( $left, $chunk );
				$error = '' !== $error ? $error : 'time budget used up';
				continue;
			}
			if ( ! $single ) {
				$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
				foreach ( $chunk as $name ) {
					$xml .= '<Object><Key>' . htmlspecialchars( $this->cfg['prefix'] . $name, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</Key></Object>';
				}
				$xml .= '</Delete>';
				$res  = $this->request(
					'POST',
					'',
					$xml,
					array( 'delete' => '' ),
					array(
						'content-type' => 'application/xml',
						'content-md5'  => base64_encode( md5( $xml, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by DeleteObjects.
					)
				);
				$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
				if ( 200 === $code ) {
					$failed = array();
					preg_match_all( '#<Error>(.*?)</Error>#s', (string) wp_remote_retrieve_body( $res ), $errors );
					foreach ( $errors[1] as $item ) {
						if ( preg_match( '#<Key>(.*?)</Key>#s', $item, $k ) ) {
							$key = html_entity_decode( $k[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
							if ( preg_match( '#<Code>NoSuchKey</Code>#', $item ) ) {
								continue; // Already gone.
							}
							$failed[] = (string) substr( $key, strlen( $this->cfg['prefix'] ) );
							$error    = 'S3 DeleteObjects: ' . ( preg_match( '#<Code>([A-Za-z0-9.]{1,64})</Code>#', $item, $c ) ? $c[1] : 'error' );
						}
					}
					$left = array_merge( $left, array_values( array_intersect( $chunk, $failed ) ) );
					continue;
				}
				if ( 0 === $code || 403 === $code ) {
					// Not reachable or not allowed: single requests would fail the same way.
					$this->ok( $res, array( 200 ), 'DeleteObjects' );
					$error = $this->error;
					$left  = array_merge( $left, $chunk );
					continue;
				}
				// Refused otherwise (e.g. 501 NotImplemented, 405, 400 of a provider without
				// DeleteObjects): one request per document from now on.
				$single = true;
			}
			foreach ( $chunk as $name ) {
				if ( ( $deadline > 0 && microtime( true ) >= $deadline ) || ! $this->delete( $name ) ) {
					$error  = '' !== $this->error ? $this->error : ( '' !== $error ? $error : 'time budget used up' );
					$left[] = $name;
				}
			}
		}
		$this->error = $left ? $error : '';
		return $left;
	}

	/** {@inheritDoc} */
	public function list_older_than( $timestamp ) {
		$out   = array();
		$token = '';
		$pages = 0;
		do {
			$query = array(
				'list-type' => '2',
				'max-keys'  => '1000',
				'prefix'    => $this->cfg['prefix'],
			);
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}
			$res = $this->request( 'GET', '', '', $query );
			if ( ! $this->ok( $res, array( 200 ), 'LIST' ) ) {
				return $out;
			}
			$xml = (string) wp_remote_retrieve_body( $res );
			preg_match_all( '#<Contents>(.*?)</Contents>#s', $xml, $items );
			foreach ( $items[1] as $item ) {
				if ( ! preg_match( '#<Key>(.*?)</Key>#s', $item, $k ) ) {
					continue;
				}
				$key  = html_entity_decode( $k[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
				$name = (string) substr( $key, strlen( $this->cfg['prefix'] ) );
				if ( 0 !== strpos( $key, $this->cfg['prefix'] ) || '' === $name || false !== strpos( $name, '/' ) ) {
					continue; // Other prefixes (e.g. the sites of a network) are not ours.
				}
				$modified = preg_match( '#<LastModified>(.*?)</LastModified>#s', $item, $m ) ? strtotime( $m[1] ) : false;
				if ( false !== $modified && $modified < (int) $timestamp ) {
					$out[] = $name;
				}
			}
			$token = preg_match( '#<IsTruncated>true</IsTruncated>#', $xml ) && preg_match( '#<NextContinuationToken>(.*?)</NextContinuationToken>#s', $xml, $t ) ? html_entity_decode( $t[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
		} while ( '' !== $token && ++$pages < 1000 );
		return $out;
	}

	/** {@inheritDoc} */
	public function last_error() {
		return $this->error;
	}

	/**
	 * Check a response and remember the reason of a failure (status and S3 error code only).
	 *
	 * @param array|\WP_Error $res  Response.
	 * @param int[]           $good Accepted status codes.
	 * @param string          $op   Operation (for the message).
	 * @return bool
	 */
	private function ok( $res, array $good, $op ) {
		if ( is_wp_error( $res ) ) {
			$this->error = 'S3 ' . $op . ': ' . $res->get_error_message();
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, $good, true ) ) {
			$this->error = '';
			return true;
		}
		$aws         = preg_match( '#<Code>([A-Za-z0-9.]{1,64})</Code>#', (string) wp_remote_retrieve_body( $res ), $m ) ? ' ' . $m[1] : '';
		$this->error = 'S3 ' . $op . ': HTTP ' . $code . $aws;
		return false;
	}

	/**
	 * Send a signed request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $key     Object key ('' = the bucket).
	 * @param string $body    Body.
	 * @param array  $query   Query parameters.
	 * @param array  $headers Additional headers (lowercase names).
	 * @return array|\WP_Error
	 */
	private function request( $method, $key, $body = '', array $query = array(), array $headers = array() ) {
		$parts  = wp_parse_url( $this->cfg['endpoint'] );
		$scheme = is_array( $parts ) && ! empty( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port   = is_array( $parts ) && ! empty( $parts['port'] ) ? (int) $parts['port'] : 0;
		$base   = is_array( $parts ) && ! empty( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		if ( '' === $host ) {
			return new \WP_Error( 'wpac_s3_endpoint', 'invalid endpoint' );
		}
		if ( $this->cfg['path_style'] ) {
			$path = $base . '/' . $this->cfg['bucket'] . '/' . $key;
		} else {
			$host = $this->cfg['bucket'] . '.' . $host;
			$path = $base . '/' . $key;
		}
		$path      = self::encode_path( $path );
		$authority = $host . ( $port && ! ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) ? ':' . $port : '' );
		$payload   = hash( 'sha256', (string) $body );
		$amz_date  = gmdate( 'Ymd\THis\Z' );
		$headers   = array_change_key_case( $headers, CASE_LOWER ) + array(
			'host'                 => $authority,
			'x-amz-content-sha256' => $payload,
			'x-amz-date'           => $amz_date,
		);
		$sig       = self::sign( $method, $path, $query, $headers, $payload, $amz_date, (string) $this->cfg['region'], (string) $this->cfg['key'], (string) $this->cfg['secret'] );
		unset( $headers['host'] ); // Set by the HTTP client from the URL (same value).
		$headers['authorization'] = $sig['authorization'];
		$qs                       = self::canonical_query( $query );
		return wp_remote_request(
			$scheme . '://' . $authority . $path . ( '' !== $qs ? '?' . $qs : '' ),
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => 'PUT' === $method || 'POST' === $method ? (string) $body : null,
				'timeout'     => (int) $this->cfg['timeout'],
				'redirection' => 0,
			)
		);
	}

	/**
	 * URI-encode every segment of a path (RFC 3986, slashes kept), as S3 expects it.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', (string) $path ) ) );
	}

	/**
	 * Canonical query string: sorted by name, names and values RFC 3986 encoded.
	 *
	 * @param array $query Parameters.
	 * @return string
	 */
	public static function canonical_query( array $query ) {
		ksort( $query, SORT_STRING );
		$out = array();
		foreach ( $query as $k => $v ) {
			$out[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}
		return implode( '&', $out );
	}

	/**
	 * AWS Signature Version 4 (header-based) for S3.
	 *
	 * @param string $method       HTTP method.
	 * @param string $path         Encoded path.
	 * @param array  $query        Query parameters.
	 * @param array  $headers      Headers to sign (host and x-amz-* included).
	 * @param string $payload_hash SHA-256 (hex) of the body.
	 * @param string $amz_date     Time, e.g. 20130524T000000Z.
	 * @param string $region       Region.
	 * @param string $key          Access key ID.
	 * @param string $secret       Secret access key.
	 * @param string $service      Service.
	 * @return array canonical_request, string_to_sign, signed_headers, signature, authorization.
	 */
	public static function sign( $method, $path, array $query, array $headers, $payload_hash, $amz_date, $region, $key, $secret, $service = 's3' ) {
		$canon = array();
		foreach ( $headers as $name => $value ) {
			$canon[ strtolower( trim( (string) $name ) ) ] = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		}
		ksort( $canon, SORT_STRING );
		$lines = '';
		foreach ( $canon as $name => $value ) {
			$lines .= $name . ':' . $value . "\n";
		}
		$signed    = implode( ';', array_keys( $canon ) );
		$request   = strtoupper( (string) $method ) . "\n" . ( '' !== (string) $path ? (string) $path : '/' ) . "\n" . self::canonical_query( $query ) . "\n" . $lines . "\n" . $signed . "\n" . $payload_hash;
		$date      = substr( (string) $amz_date, 0, 8 );
		$scope     = $date . '/' . $region . '/' . $service . '/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $request );
		$k         = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k         = hash_hmac( 'sha256', $region, $k, true );
		$k         = hash_hmac( 'sha256', $service, $k, true );
		$k         = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$signature = hash_hmac( 'sha256', $to_sign, $k );
		return array(
			'canonical_request' => $request,
			'string_to_sign'    => $to_sign,
			'signed_headers'    => $signed,
			'signature'         => $signature,
			'authorization'     => 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature,
		);
	}
}
