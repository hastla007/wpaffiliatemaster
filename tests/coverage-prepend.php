<?php
/**
 * Line coverage for the plain PHP test runners (no PHPUnit): php -d pcov.enabled=1
 * -d auto_prepend_file=tests/coverage-prepend.php tests/run.php. Every run adds its
 * covered lines to coverage/lines.json; tests/coverage-report.php prints the summary.
 */
if ( ! function_exists( '\pcov\start' ) ) {
	return;
}
\pcov\start();
register_shutdown_function(
	static function () {
		\pcov\stop();
		$src  = realpath( __DIR__ . '/../src' );
		$data = \pcov\collect( \pcov\inclusive );
		$dir  = __DIR__ . '/../coverage';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$file = $dir . '/lines.json';
		$all  = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
		foreach ( $data as $path => $lines ) {
			if ( 0 !== strpos( (string) realpath( $path ), $src ) ) {
				continue;
			}
			$key = substr( realpath( $path ), strlen( $src ) + 1 );
			foreach ( $lines as $line => $hit ) {
				$prev                  = isset( $all[ $key ][ $line ] ) ? $all[ $key ][ $line ] : -1;
				$all[ $key ][ $line ] = max( $prev, $hit > 0 ? 1 : -1 );
			}
		}
		file_put_contents( $file, json_encode( $all ) );
	}
);
