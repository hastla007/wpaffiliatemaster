<?php
/**
 * Line coverage for the plain PHP test runners (no PHPUnit), with Xdebug:
 * php -d xdebug.mode=coverage -d auto_prepend_file=tests/coverage-prepend.php tests/run.php.
 * Every run adds its covered lines to coverage/lines.json; tests/coverage-report.php prints the summary.
 */
if ( ! function_exists( 'xdebug_start_code_coverage' ) ) {
	return;
}
xdebug_start_code_coverage( XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE );
register_shutdown_function(
	static function () {
		$src  = realpath( __DIR__ . '/../src' );
		$data = xdebug_get_code_coverage();
		xdebug_stop_code_coverage();
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
				if ( -2 === $hit ) {
					continue; // Dead code (not executable).
				}
				$all[ $key ][ $line ] = max( $prev, $hit > 0 ? 1 : -1 );
			}
		}
		file_put_contents( $file, json_encode( $all ) );
	}
);
