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
		fputcsv( $fh, array_map( array( __CLASS__, 'safe_cell' ), $header ), $sep, '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'safe_cell' ), array_values( (array) $row ) ), $sep, '"', '\\' );
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
