<?php
/**
 * Verifies an unpacked WordPress.org plugin against the official per-file checksums
 * (https://downloads.wordpress.org/plugin-checksums/<slug>/<version>.json), so CI never runs a
 * corrupted or tampered download (R13-N13). The version is read from the plugin header.
 *
 *   php tests/ci/verify-plugin.php <plugin-dir> <slug>
 *
 * Exit 0 when every listed file is present with a matching SHA-256 and no unlisted PHP file
 * exists; exit 1 otherwise.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
$dir  = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$slug = (string) ( $argv[2] ?? '' );
if ( '' === $dir || ! preg_match( '/^[a-z0-9-]+$/', $slug ) || ! is_file( "$dir/$slug.php" ) ) {
	fwrite( STDERR, "usage: verify-plugin.php <plugin-dir> <slug> (main file <slug>.php)\n" );
	exit( 1 );
}
if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*([0-9A-Za-z.\-]+)/mi', (string) file_get_contents( "$dir/$slug.php", false, null, 0, 8192 ), $m ) ) {
	fwrite( STDERR, "$slug: no Version header\n" );
	exit( 1 );
}
$version = $m[1];
$url     = "https://downloads.wordpress.org/plugin-checksums/$slug/$version.json";
$json    = null;
for ( $try = 1; $try <= 3 && null === $json; $try++ ) {
	$raw  = @file_get_contents( $url );
	$json = false === $raw ? null : json_decode( $raw, true );
	if ( null === $json ) {
		sleep( 2 * $try );
	}
}
if ( ! is_array( $json ) || empty( $json['files'] ) || ! is_array( $json['files'] ) ) {
	fwrite( STDERR, "$slug $version: checksums not available from $url\n" );
	exit( 1 );
}
$bad = array();
foreach ( $json['files'] as $file => $sums ) {
	$path = "$dir/$file";
	$want = (array) ( $sums['sha256'] ?? array() );
	if ( ! is_file( $path ) ) {
		$bad[] = "missing $file";
	} elseif ( $want && ! in_array( hash_file( 'sha256', $path ), $want, true ) ) {
		$bad[] = "changed $file";
	}
}
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$rel = substr( $f->getPathname(), strlen( $dir ) + 1 );
	if ( 'php' === strtolower( $f->getExtension() ) && ! isset( $json['files'][ $rel ] ) ) {
		$bad[] = "unexpected $rel";
	}
}
if ( $bad ) {
	fwrite( STDERR, "$slug $version: checksum verification failed (" . count( $bad ) . "):\n  " . implode( "\n  ", array_slice( $bad, 0, 20 ) ) . "\n" );
	exit( 1 );
}
echo "$slug $version: " . count( $json['files'] ) . " files verified\n";
