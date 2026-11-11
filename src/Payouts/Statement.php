<?php
/**
 * Commission statements as PDF.
 *
 * - Generated once when a payout is created and never changed afterwards
 *   (SHA-256 stored and verified on every download).
 * - Stored outside public access in uploads/wpac-statements (deny rules +
 *   random file names); downloads go through capability-checked handlers.
 * - Retention: after the configured number of years (default 10) the PDF and
 *   the partner data snapshot of the payout are deleted; amounts remain.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Statement {

	/**
	 * Protected storage directory (created on demand).
	 *
	 * @return string
	 */
	public static function dir() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'wpac-statements';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Build the PDF bytes for a payout.
	 *
	 * @param object $p Payout.
	 * @return string
	 */
	public static function build( $p ) {
		$aff    = AffiliateRepository::find( (int) $p->affiliate_id );
		$snap   = json_decode( (string) $p->profile_snapshot, true );
		$snap   = is_array( $snap ) ? $snap : array();
		$labels = ProfileRepository::tax_status_labels();
		$issuer = (string) Settings::get( 'debtor_name' );
		$issuer = '' !== $issuer ? $issuer : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$date   = static function ( $mysql ) {
			return mysql2date( get_option( 'date_format' ), $mysql );
		};

		$pdf = new Pdf(
			array(
				'title'   => __( 'Commission statement', 'wp-affiliate-captain' ) . ' ' . $p->number,
				'author'  => $issuer,
				'created' => strtotime( $p->created_at . ' UTC' ),
			)
		);
		$y = 60;
		$pdf->text( 50, $y, __( 'Commission statement', 'wp-affiliate-captain' ) . ' ' . $p->number, 16, true );
		$y += 30;
		$pdf->text( 50, $y, __( 'Issued by', 'wp-affiliate-captain' ), 9, true );
		$pdf->text( 310, $y, __( 'Partner', 'wp-affiliate-captain' ), 9, true );
		$y      += 14;
		$left    = array( $issuer );
		$partner = array_merge(
			array( ! empty( $snap['legal_name'] ) ? $snap['legal_name'] : ( $aff ? $aff->name : '' ) ),
			preg_split( '/\r\n|\r|\n/', isset( $snap['address'] ) ? (string) $snap['address'] : '' ),
			array( isset( $snap['country'] ) ? $snap['country'] : '' )
		);
		$rows = max( count( $left ), count( $partner ) );
		for ( $i = 0; $i < $rows; $i++ ) {
			if ( isset( $left[ $i ] ) ) {
				$pdf->text( 50, $y, $left[ $i ] );
			}
			if ( isset( $partner[ $i ] ) && '' !== trim( $partner[ $i ] ) ) {
				$pdf->text( 310, $y, $partner[ $i ] );
			}
			$y += 13;
		}
		$y += 10;
		$facts = array(
			__( 'Statement number', 'wp-affiliate-captain' ) => $p->number,
			__( 'Statement date', 'wp-affiliate-captain' )   => $date( get_date_from_gmt( $p->created_at ) ),
			__( 'Period until', 'wp-affiliate-captain' )     => $date( $p->period_end ),
			__( 'Tax status', 'wp-affiliate-captain' )       => isset( $snap['tax_status'], $labels[ $snap['tax_status'] ] ) ? $labels[ $snap['tax_status'] ] : '—',
			__( 'VAT ID', 'wp-affiliate-captain' )           => ! empty( $snap['vat_id'] ) ? $snap['vat_id'] : '—',
			__( 'Bank account', 'wp-affiliate-captain' )     => ! empty( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—',
		);
		foreach ( $facts as $k => $v ) {
			$pdf->text( 50, $y, $k . ':', 9, true );
			$pdf->text( 170, $y, (string) $v, 9 );
			$y += 13;
		}

		$y += 14;
		$head = static function ( $pdf, $y ) {
			$pdf->text( 50, $y, __( 'Date', 'wp-affiliate-captain' ), 9, true );
			$pdf->text( 120, $y, __( 'Order', 'wp-affiliate-captain' ), 9, true );
			$pdf->text( 230, $y, __( 'Description', 'wp-affiliate-captain' ), 9, true );
			$pdf->text( 545, $y, __( 'Amount', 'wp-affiliate-captain' ), 9, true, 'right' );
			$pdf->line( 50, $y + 4, 545, $y + 4 );
			return $y + 16;
		};
		$y = $head( $pdf, $y );
		foreach ( PayoutService::items( (int) $p->id ) as $item ) {
			if ( $y > 780 ) {
				$pdf->add_page();
				$y = $head( $pdf, 60 );
			}
			$desc = 'sale' === $item->kind ? (string) $item->description : ( 'bonus' === $item->kind ? (string) $item->description : __( 'Correction', 'wp-affiliate-captain' ) . ' (' . $item->kind . ')' );
			$desc = Pdf::wrap( $desc, 230, 9 );
			$pdf->text( 50, $y, $date( get_date_from_gmt( (string) $item->commission_date ) ), 9 );
			$pdf->text( 120, $y, ( 'wpac' === $item->source ? '' : '#' . $item->source_order_id ), 9 );
			$pdf->text( 230, $y, $desc[0], 9 );
			$pdf->text( 545, $y, Money::format( (int) $item->amount_minor, $p->currency ), 9, false, 'right' );
			$y += 13;
		}
		$pdf->line( 50, $y - 6, 545, $y - 6 );
		$y += 6;
		$pdf->text( 230, $y, __( 'Total payout', 'wp-affiliate-captain' ), 10, true );
		$pdf->text( 545, $y, Money::format( (int) $p->amount_minor, $p->currency ), 10, true, 'right' );
		$y += 30;
		$note = (string) apply_filters(
			'wpac_statement_note',
			__( 'This statement lists the commissions credited in this payout. VAT treatment depends on the partner\'s tax status; please consult your tax advisor. Partners subject to VAT issue a separate invoice unless self-billing (Gutschrift) has been agreed.', 'wp-affiliate-captain' ),
			$p
		);
		foreach ( Pdf::wrap( $note, 495, 8 ) as $line ) {
			if ( $y > 800 ) {
				$pdf->add_page();
				$y = 60;
			}
			$pdf->text( 50, $y, $line, 8 );
			$y += 11;
		}
		return $pdf->output();
	}

	/**
	 * Generate and store the statement of a payout (only once).
	 *
	 * @param int $payout_id Payout.
	 * @return bool
	 */
	public static function generate( $payout_id ) {
		global $wpdb;
		$p = PayoutService::find( $payout_id );
		if ( ! $p || '' !== (string) $p->statement_file ) {
			return false;
		}
		$bytes = self::build( $p );
		$name  = sanitize_file_name( $p->number . '-' . wp_generate_password( 16, false ) . '.pdf' );
		$path  = self::dir() . '/' . $name;
		if ( false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			Audit::log( 'statement.write_failed', 'payout', (int) $payout_id );
			return false;
		}
		$wpdb->update(
			Db::table( 'payouts' ),
			array(
				'statement_file' => $name,
				'statement_hash' => hash( 'sha256', $bytes ),
			),
			array( 'id' => (int) $payout_id )
		);
		Audit::log( 'statement.generated', 'payout', (int) $payout_id, array( 'hash' => hash( 'sha256', $bytes ) ) );
		return true;
	}

	/**
	 * Stream the stored PDF (caller checks permissions) and exit.
	 *
	 * @param object $p Payout.
	 */
	public static function send( $p ) {
		$path = self::dir() . '/' . basename( (string) $p->statement_file );
		if ( '' === (string) $p->statement_file || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The statement is not available (anymore).', 'wp-affiliate-captain' ), 404 );
		}
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! hash_equals( (string) $p->statement_hash, hash( 'sha256', $bytes ) ) ) {
			Audit::log( 'statement.hash_mismatch', 'payout', (int) $p->id );
			wp_die( esc_html__( 'The stored statement failed the integrity check.', 'wp-affiliate-captain' ), 500 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $p->number . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Delete statements and partner snapshots after the retention period (cron).
	 *
	 * @return int Number of payouts purged.
	 */
	public static function purge_expired() {
		global $wpdb;
		$years = (int) Settings::get( 'statement_retention_years' );
		if ( $years <= 0 ) {
			return 0;
		}
		$t      = Db::table( 'payouts' );
		$cutoff = gmdate( 'Y-12-31 23:59:59', strtotime( '-' . ( $years + 1 ) . ' years' ) );
		// Retention starts at the end of the calendar year of the statement.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, statement_file FROM {$t} WHERE statement_deleted_at IS NULL AND created_at <= %s LIMIT 200", $cutoff ) );
		foreach ( $rows as $r ) {
			if ( '' !== (string) $r->statement_file ) {
				$path = self::dir() . '/' . basename( (string) $r->statement_file );
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
			$wpdb->update(
				$t,
				array(
					'statement_file'       => '',
					'profile_snapshot'     => null,
					'statement_deleted_at' => Db::now(),
				),
				array( 'id' => (int) $r->id )
			);
			Audit::log( 'statement.retention_deleted', 'payout', (int) $r->id );
		}
		return count( $rows );
	}
}
