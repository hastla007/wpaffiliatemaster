<?php
/**
 * CSV helpers with formula-injection protection (no WordPress dependency).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

final class Csv {

	/**
	 * Neutralize cells that spreadsheet software would interpret as formulas.
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function safe_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			// Plain negative numbers stay numeric.
			if ( preg_match( '/^-\d+([.,]\d+)?$/', $value ) ) {
				return $value;
			}
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Write one formula-safe row. No escape character (RFC 4180): with PHP's default "\\" a cell
	 * like `x\";=HYPERLINK(…)` is written without doubling its quote, so a spreadsheet ends the
	 * cell there and reads `=HYPERLINK(…)` as a new cell (formula).
	 *
	 * @param resource $fh  Stream.
	 * @param array    $row Cells.
	 * @param string   $sep Separator.
	 */
	public static function put( $fh, $row, $sep = ';' ) {
		fputcsv( $fh, array_map( array( __CLASS__, 'safe_cell' ), array_values( (array) $row ) ), $sep, '"', '' );
	}

	/**
	 * Build CSV text.
	 *
	 * @param array $header Header row.
	 * @param array $rows   Rows (arrays).
	 * @param string $sep   Separator (";" works best for German Excel).
	 * @return string
	 */
	public static function build( array $header, array $rows, $sep = ';' ) {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memory stream, not a file.
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel (memory stream).
		self::put( $fh, $header, $sep );
		foreach ( $rows as $row ) {
			self::put( $fh, $row, $sep );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Memory stream.
		return $csv;
	}

	/**
	 * Separator of CSV downloads: ";" (German Excel) unless changed with the filter
	 * wpac_csv_separator ("," for English Excel and most tools, tab or "|").
	 *
	 * @param string $filename File name of the download.
	 * @return string
	 */
	public static function separator( $filename = '' ) {
		/**
		 * Separator of the CSV downloads of the admin (reports, payouts, commissions, codes).
		 *
		 * @param string $sep      ";" (default), ",", "|" or "\t".
		 * @param string $filename File name of the download.
		 */
		$sep = (string) apply_filters( 'wpac_csv_separator', ';', (string) $filename );
		return in_array( $sep, array( ';', ',', '|', "\t" ), true ) ? $sep : ';';
	}

	/**
	 * Send a CSV download row by row and exit. Rows can be a generator that reads the database
	 * in batches (see Paged::each()), so large lists are never collected in memory.
	 *
	 * @param string   $filename File name.
	 * @param array    $header   Header.
	 * @param iterable $rows     Rows (arrays).
	 */
	public static function stream( $filename, array $header, $rows ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		self::write_stream( 'php://output', $header, $rows, self::separator( $filename ) );
		exit;
	}

	/**
	 * Write CSV (BOM, header, rows) to a stream or file row by row.
	 *
	 * @param string   $target Stream URL or path.
	 * @param array    $header Header.
	 * @param iterable $rows   Rows.
	 * @param string   $sep    Separator.
	 * @return int Rows written.
	 */
	public static function write_stream( $target, array $header, $rows, $sep = ';' ) {
		$fh = fopen( $target, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		self::put( $fh, $header, $sep );
		$n = 0;
		foreach ( $rows as $row ) {
			self::put( $fh, $row, $sep );
			if ( 0 === ++$n % 500 ) {
				fflush( $fh );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $n;
	}

	/**
	 * Send CSV as download and exit.
	 *
	 * @param string $filename File name.
	 * @param array  $header   Header.
	 * @param array  $rows     Rows.
	 */
	public static function download( $filename, array $header, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo self::build( $header, $rows, self::separator( $filename ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
