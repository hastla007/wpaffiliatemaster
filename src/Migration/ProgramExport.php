<?php
/**
 * Full program export as a ZIP of CSV files (admin page and `wp wpac export`):
 * partners, groups, codes, rules, tiers, terms acceptances, commissions, payouts, payout items,
 * correction credit notes and payout requests – for backups, audits and moving to another system.
 *
 * Tax and bank data (payout details with the decrypted IBAN, tax snapshots of payouts) are only
 * included when explicitly requested by a user with the capability wpac_view_tax_data.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Migration;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\PrivateFile;
use WPAffiliateCaptain\Payouts\ProfileRepository;

defined( 'ABSPATH' ) || exit;

final class ProgramExport {

	/** File name in the ZIP → table. */
	const FILES = array(
		'partners.csv'           => 'affiliates',
		'groups.csv'             => 'partner_groups',
		'codes.csv'              => 'partner_codes',
		'rules.csv'              => 'rules',
		'tiers.csv'              => 'tiers',
		'agreements.csv'         => 'agreements',
		'commissions.csv'        => 'commissions',
		'payouts.csv'            => 'payouts',
		'payout_items.csv'       => 'payout_items',
		'payout_corrections.csv' => 'payout_corrections',
		'payout_requests.csv'    => 'payout_requests',
	);

	/**
	 * Columns of payout_corrections.csv (correction credit notes of paid payouts): the database columns
	 * plus the payout number, the gross amounts, the differences and the state of the PDF. The
	 * one-time request key of the form is internal and not exported.
	 */
	const CORRECTION_COLUMNS = array(
		'id',
		'number',
		'created_at',
		'payout_id',
		'payout_number',
		'base_number',
		'affiliate_id',
		'currency',
		'prev_net_minor',
		'prev_vat_minor',
		'prev_gross_minor',
		'net_minor',
		'vat_minor',
		'gross_minor',
		'diff_net_minor',
		'diff_vat_minor',
		'diff_gross_minor',
		'vat_mode',
		'vat_rate',
		'reason',
		'commission_id',
		'profile_snapshot',
		'operator_snapshot',
		'file',
		'hash',
		'file_status',
		'file_deleted_at',
		'created_by',
	);

	/** Rows per query. */
	const BATCH = 2000;

	/**
	 * Whether the export can run here (needs the PHP extension "zip").
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Write the export ZIP.
	 *
	 * @param string $zip_path         Target file.
	 * @param bool   $include_tax_data Include payout details with decrypted IBANs and the tax snapshots
	 *                                 of payouts (caller checks the capability).
	 * @return array|\WP_Error File name => row count.
	 */
	public static function write( $zip_path, $include_tax_data = false ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'wpac_export_zip', __( 'The program export needs the PHP extension "zip". Ask your hosting provider to enable it, or use the CSV exports of the individual lists.', 'affiliate-captain' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Large programs; disabled on some hosts.
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'wpac_export_zip', __( 'The export file could not be created.', 'affiliate-captain' ) );
		}
		$counts = array();
		$temps  = array();
		$tables = self::FILES;
		if ( $include_tax_data ) {
			$tables['payout_details.csv'] = 'payout_profiles';
		}
		foreach ( $tables as $file => $table ) {
			// Private (0600, outside the web root where possible) and deleted at the end of the
			// request even if it is aborted: payout_details.csv holds decrypted IBANs.
			$tmp = PrivateFile::create( 'wpac-export-' . $table );
			if ( ! $tmp ) {
				$zip->close();
				foreach ( $temps as $done ) {
					PrivateFile::release( $done );
				}
				return new \WP_Error( 'wpac_export_zip', __( 'The export file could not be created.', 'affiliate-captain' ) );
			}
			$temps[]         = $tmp;
			$counts[ $file ] = self::write_table( $table, $tmp, $include_tax_data );
			$zip->addFile( $tmp, $file );
		}
		/**
		 * Extra files of the program export (file name => content), e.g. data of an add-on.
		 * Core files cannot be replaced.
		 *
		 * @since contract 1.2.0
		 * @param array $files            Extra files.
		 * @param bool  $include_tax_data Whether bank data was requested.
		 */
		$extra = apply_filters( 'wpac_program_export_files', array(), (bool) $include_tax_data );
		foreach ( is_array( $extra ) ? $extra : array() as $file => $content ) {
			$file = sanitize_file_name( (string) $file );
			if ( '' !== $file && 'README.txt' !== $file && ! isset( $counts[ $file ] ) ) {
				$zip->addFromString( $file, (string) $content );
				$counts[ $file ] = substr_count( rtrim( (string) $content, "\n" ), "\n" ); // Rows after a header line.
			}
		}
		$zip->addFromString( 'README.txt', self::readme( $include_tax_data ) );
		$ok = $zip->close(); // Reads the temporary files, so they are deleted afterwards.
		foreach ( $temps as $tmp ) {
			PrivateFile::release( $tmp );
		}
		if ( ! $ok ) {
			return new \WP_Error( 'wpac_export_zip', __( 'The export file could not be created.', 'affiliate-captain' ) );
		}
		Audit::log(
			'program.exported',
			'program',
			0,
			array(
				'tax_data' => $include_tax_data ? 1 : 0,
				'rows'     => array_sum( $counts ),
			)
		);
		return $counts;
	}

	/**
	 * One table as CSV (all columns, in batches, formula-safe cells).
	 *
	 * @param string $table            Short table name.
	 * @param string $path             Target file.
	 * @param bool   $include_tax_data Tax data allowed.
	 * @return int Rows.
	 */
	private static function write_table( $table, $path, $include_tax_data ) {
		global $wpdb;
		$t      = Db::table( $table );
		$fh     = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$count  = 0;
		$header = null;
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		// Keyset paging on the primary key (OFFSET re-reads all earlier rows on every batch).
		$key  = 'payout_profiles' === $table ? 'affiliate_id' : 'id';
		$last = 0;
		while ( true ) {
			if ( 'payout_corrections' === $table ) {
				// With the number of the corrected payout (its credit note may have been corrected before).
				$p = Db::table( 'payouts' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*, p.number AS payout_number FROM {$t} c LEFT JOIN {$p} p ON p.id = c.payout_id WHERE c.id > %d ORDER BY c.id ASC LIMIT %d", $last, self::BATCH ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$key} > %d ORDER BY {$key} ASC LIMIT %d", $last, self::BATCH ), ARRAY_A );
			}
			if ( null === $header ) {
				if ( 'payout_corrections' === $table ) {
					$header = self::CORRECTION_COLUMNS;
				} else {
					$header = $rows ? array_keys( self::row( $table, $rows[0], $include_tax_data ) ) : self::columns( $t );
				}
				Csv::put( $fh, $header, ';' );
			}
			foreach ( $rows as $row ) {
				$last = (int) $row[ $key ];
				Csv::put( $fh, self::row( $table, $row, $include_tax_data ), ';' );
				++$count;
			}
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}

	/**
	 * Column names of an empty table.
	 *
	 * @param string $t Full table name.
	 * @return string[]
	 */
	private static function columns( $t ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" );
		return array_map( 'strval', (array) $cols );
	}

	/**
	 * Row as exported: tax data removed or bank details decrypted.
	 *
	 * @param string $table            Short table name.
	 * @param array  $row              Row.
	 * @param bool   $include_tax_data Tax data allowed.
	 * @return array
	 */
	private static function row( $table, array $row, $include_tax_data ) {
		if ( 'payout_corrections' === $table ) {
			return self::correction_row( $row, $include_tax_data );
		}
		if ( 'payouts' === $table && ! $include_tax_data ) {
			$row['profile_snapshot'] = ''; // Legal name, address, VAT ID of the partner.
			// The random file names are the only protection of a credit note folder that is
			// reachable from the web: never part of an export without tax data.
			foreach ( array( 'statement_file', 'storno_file' ) as $col ) {
				if ( isset( $row[ $col ] ) ) {
					$row[ $col ] = '' !== (string) $row[ $col ] ? 'yes' : '';
				}
			}
		}
		if ( in_array( $table, array( 'payouts', 'payout_items' ), true ) && ! $include_tax_data && isset( $row['vat_mode'] ) ) {
			$row['vat_mode'] = ''; // Reveals the partner's tax status (small business, reverse charge …).
		}
		if ( 'payout_profiles' === $table ) {
			$profile = (object) $row;
			$iban    = ProfileRepository::iban( $profile );
			$details = ProfileRepository::payment_details( $profile );
			unset( $row['iban_encrypted'] );
			$row['iban'] = $iban ? $iban : '';
			// Other payment method (PayPal …): method and decrypted payment details, else they would be lost.
			$row['payout_method']   = ProfileRepository::method( $profile );
			$row['payment_details'] = null !== $details ? $details : '';
			// Bank transfer outside SEPA (since plugin 0.22.0): the decrypted account in its own columns.
			$bank = ProfileRepository::bank_details( $profile );
			foreach ( ProfileRepository::BANK_FIELDS as $f ) {
				$row[ $f ] = null !== $bank ? $bank[ $f ] : '';
			}
		}
		return $row;
	}

	/**
	 * Row of payout_corrections.csv: amounts before, after and the difference (net, VAT, gross) and the
	 * state of the PDF. Without tax data the same is left out as for payouts: the tax snapshot of
	 * the partner, the VAT treatment and the stored (random) file name of the PDF.
	 *
	 * @param array $row              Row of payout_corrections (with payout_number).
	 * @param bool  $include_tax_data Tax data allowed.
	 * @return array Column => value, in the order of CORRECTION_COLUMNS.
	 */
	private static function correction_row( array $row, $include_tax_data ) {
		$int  = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		};
		$str  = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		};
		$file = $str( 'file' );
		if ( '' !== $str( 'file_deleted_at' ) ) {
			$state = 'deleted'; // After the retention period (Statement::purge_expired()).
		} else {
			$state = '' !== $file ? 'stored' : 'pending';
		}
		$out = array(
			'id'                => $int( 'id' ),
			'number'            => $str( 'number' ),
			'created_at'        => $str( 'created_at' ),
			'payout_id'         => $int( 'payout_id' ),
			'payout_number'     => $str( 'payout_number' ),
			'base_number'       => $str( 'base_number' ),
			'affiliate_id'      => $int( 'affiliate_id' ),
			'currency'          => $str( 'currency' ),
			'prev_net_minor'    => $int( 'prev_net_minor' ),
			'prev_vat_minor'    => $int( 'prev_vat_minor' ),
			'prev_gross_minor'  => $int( 'prev_net_minor' ) + $int( 'prev_vat_minor' ),
			'net_minor'         => $int( 'net_minor' ),
			'vat_minor'         => $int( 'vat_minor' ),
			'gross_minor'       => $int( 'net_minor' ) + $int( 'vat_minor' ),
			'diff_net_minor'    => $int( 'net_minor' ) - $int( 'prev_net_minor' ),
			'diff_vat_minor'    => $int( 'vat_minor' ) - $int( 'prev_vat_minor' ),
			'diff_gross_minor'  => ( $int( 'net_minor' ) + $int( 'vat_minor' ) ) - ( $int( 'prev_net_minor' ) + $int( 'prev_vat_minor' ) ),
			'vat_mode'          => $str( 'vat_mode' ),
			'vat_rate'          => $str( 'vat_rate' ),
			'reason'            => $str( 'reason' ),
			'commission_id'     => $int( 'commission_id' ),
			'profile_snapshot'  => $str( 'profile_snapshot' ),
			'operator_snapshot' => $str( 'operator_snapshot' ),
			'file'              => $file,
			'hash'              => $str( 'hash' ),
			'file_status'       => $state,
			'file_deleted_at'   => $str( 'file_deleted_at' ),
			'created_by'        => $int( 'created_by' ),
		);
		if ( ! $include_tax_data ) {
			$out['profile_snapshot'] = ''; // Legal name, address, VAT ID of the partner.
			$out['vat_mode']         = ''; // Reveals the partner's tax status.
			$out['file']             = '' !== $file ? 'yes' : '';
		}
		return $out;
	}

	/**
	 * Explanation inside the ZIP.
	 *
	 * @param bool $include_tax_data Tax data included.
	 * @return string
	 */
	private static function readme( $include_tax_data ) {
		$lines   = array();
		$lines[] = 'Affiliate Captain ' . WPAC_VERSION . ' – program export ' . gmdate( 'Y-m-d H:i' ) . ' UTC (' . home_url( '/' ) . ')';
		$lines[] = '';
		$lines[] = 'CSV files: UTF-8 with BOM, separator ";". Amounts are in minor units (cents) in the columns *_minor; dates are UTC.';
		$lines[] = 'Cells that start with = + - @ are prefixed with an apostrophe (protection against spreadsheet formulas).';
		$lines[] = 'Columns are the database columns of the plugin; see docs/IMPORT-EXPORT.md.';
		$lines[] = '';
		$lines[] = $include_tax_data
			? 'CONTAINS TAX AND BANK DATA (payout_details.csv with decrypted IBANs or – column payout_method "manual" – the decrypted payment details of another payment method such as PayPal, or – "bank" – the decrypted account outside SEPA; tax snapshots in payouts.csv and payout_corrections.csv). Store and transfer it encrypted, delete it when no longer needed.'
			: 'Tax and bank data are not included (payout details, IBANs, payment details, the tax snapshots and the VAT treatment (vat_mode) of payouts and correction credit notes; statement_file/storno_file and file in payout_corrections.csv only say "yes" instead of the stored file names).';
		$lines[] = 'The export contains personal data of partners: handle it according to your privacy policy.';
		return implode( "\n", $lines ) . "\n";
	}
}
