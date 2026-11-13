<?php
/**
 * Summary of coverage/lines.json (see coverage-prepend.php): total and per file, Markdown.
 */
$all = json_decode( (string) file_get_contents( __DIR__ . '/../coverage/lines.json' ), true );
$sum = array( 0, 0 );
$out = array();
// Files that no test loaded at all count with zero coverage.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$key = substr( realpath( (string) $f ), strlen( realpath( __DIR__ . '/../src' ) ) + 1 );
	if ( '.php' === substr( $key, -4 ) && ! isset( $all[ $key ] ) ) {
		$all[ $key ] = array();
	}
}
ksort( $all );
foreach ( $all as $file => $lines ) {
	$hit   = count( array_filter( $lines, static function ( $v ) { return $v > 0; } ) );
	$total = count( $lines );
	$sum[0] += $hit;
	$sum[1] += $total;
	$out[]   = sprintf( '| %s | %d/%d | %s |', $file, $hit, $total, $total ? round( 100 * $hit / $total ) . ' %' : '–' );
}
echo '### Coverage: ', $sum[1] ? round( 100 * $sum[0] / $sum[1], 1 ) : 0, " % of executable lines in src/\n\n| File | Lines | % |\n|---|---|---|\n", implode( "\n", $out ), "\n";
