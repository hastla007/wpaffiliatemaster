<?php
/**
 * In-memory S3 stand-in for the K3 checks (R6-CC5): answers the signed requests of S3Store through
 * the pre_http_request filter, like WordPress' HTTP API does. Shared by r15-k3.php and
 * r15-k3-isolated.php.
 *
 * $GLOBALS['k3_s3_objects'] URL without query => [ body, time ]
 * $GLOBALS['k3_s3_log']     requests: [ method, url, headers ]
 * $GLOBALS['k3_s3_fail']    HTTP status every request gets (0 = work normally)
 * $GLOBALS['k3_s3_page']    list page size
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['test_http_calls'][] = $url;
		$pre = apply_filters( 'pre_http_request', false, $args, $url );
		return false !== $pre ? $pre : new WP_Error( 'http', 'offline' );
	}
}

$GLOBALS['k3_s3_objects'] = array();
$GLOBALS['k3_s3_log']     = array();
$GLOBALS['k3_s3_fail']    = 0;
$GLOBALS['k3_s3_page']    = 1000;

function k3_s3_reply( $code, $body = '' ) {
	return array( 'headers' => array(), 'body' => (string) $body, 'response' => array( 'code' => (int) $code, 'message' => '' ), 'cookies' => array() );
}

function k3_fake_s3( $pre, $args, $url ) {
	$parts   = parse_url( (string) $url );
	$base    = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
	$method  = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
	$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	$body    = isset( $args['body'] ) ? (string) $args['body'] : '';
	$GLOBALS['k3_s3_log'][] = array( $method, (string) $url, $headers );
	// Every request must be signed (SigV4) and carry the hash of its body.
	if ( 0 !== strpos( (string) ( $headers['authorization'] ?? '' ), 'AWS4-HMAC-SHA256 Credential=' ) || ( $headers['x-amz-content-sha256'] ?? '' ) !== hash( 'sha256', $body ) ) {
		return k3_s3_reply( 403, '<Error><Code>SignatureDoesNotMatch</Code></Error>' );
	}
	if ( $GLOBALS['k3_s3_fail'] ) {
		return k3_s3_reply( $GLOBALS['k3_s3_fail'], '<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>' );
	}
	parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
	if ( 'GET' === $method && isset( $query['list-type'] ) ) {
		$keys = array();
		foreach ( $GLOBALS['k3_s3_objects'] as $stored => $obj ) {
			if ( 0 === strpos( $stored, $base ) ) {
				$key = rawurldecode( substr( $stored, strlen( $base ) ) );
				if ( 0 === strpos( $key, (string) ( $query['prefix'] ?? '' ) ) ) {
					$keys[ $key ] = $obj[1];
				}
			}
		}
		ksort( $keys );
		$start = (int) ( $query['continuation-token'] ?? 0 );
		$page  = array_slice( $keys, $start, (int) $GLOBALS['k3_s3_page'], true );
		$more  = count( $keys ) > $start + count( $page );
		$xml   = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><IsTruncated>' . ( $more ? 'true' : 'false' ) . '</IsTruncated>';
		foreach ( $page as $key => $time ) {
			$xml .= '<Contents><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><LastModified>' . gmdate( 'Y-m-d\TH:i:s.000\Z', $time ) . '</LastModified><Size>1</Size></Contents>';
		}
		if ( $more ) {
			$xml .= '<NextContinuationToken>' . ( $start + count( $page ) ) . '</NextContinuationToken>';
		}
		return k3_s3_reply( 200, $xml . '</ListBucketResult>' );
	}
	switch ( $method ) {
		case 'PUT':
			$GLOBALS['k3_s3_objects'][ $base ] = array( $body, time() );
			return k3_s3_reply( 200 );
		case 'GET':
			return isset( $GLOBALS['k3_s3_objects'][ $base ] ) ? k3_s3_reply( 200, $GLOBALS['k3_s3_objects'][ $base ][0] ) : k3_s3_reply( 404, '<Error><Code>NoSuchKey</Code></Error>' );
		case 'HEAD':
			return k3_s3_reply( isset( $GLOBALS['k3_s3_objects'][ $base ] ) ? 200 : 404 );
		case 'DELETE':
			unset( $GLOBALS['k3_s3_objects'][ $base ] );
			return k3_s3_reply( 204 );
	}
	return k3_s3_reply( 400 );
}
add_filter( 'pre_http_request', 'k3_fake_s3', 10, 3 );
