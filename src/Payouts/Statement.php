<?php
/**
 * Commission statements as PDF.
 *
 * - Generated once when a payout is created and never changed afterwards
 *   (SHA-256 stored and verified on every download).
 * - Stored outside public access in uploads/wpac-statements (deny rules +
 *   random file names); downloads go through capability-checked handlers.
 * - Retention: after the retention period (Retention::years(): setting, never below the
 *   legal minimum of the operator country) the PDF and the partner data snapshot of the
 *   payout are deleted; amounts remain.
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
	 * Storage directory (created on demand).
	 *
	 * Define WPAC_STATEMENT_DIR in wp-config.php to store statements outside the
	 * web root (recommended, required on nginx where .htaccess is ignored).
	 *
	 * @return string
	 */
	public static function dir() {
		if ( defined( 'WPAC_STATEMENT_DIR' ) && '' !== (string) WPAC_STATEMENT_DIR ) {
			$dir = untrailingslashit( (string) WPAC_STATEMENT_DIR );
			if ( is_multisite() ) {
				// All sites of a network share the constant: one subfolder per site, so the documents
				// (and their deletion on uninstall) stay apart. Older files are moved by the v9 migration.
				$dir .= '/site-' . (int) get_current_blog_id();
			}
		} else {
			$upload = wp_upload_dir( null, false );
			$dir    = trailingslashit( $upload['basedir'] ) . 'wpac-statements';
		}
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
	 * Whether the storage directory is publicly reachable over HTTP (checked
	 * with a probe file; cached for a day). Only relevant inside the uploads dir.
	 *
	 * @return bool|null True = exposed, false = protected, null = unknown/outside web root.
	 */
	public static function publicly_accessible() {
		if ( defined( 'WPAC_STATEMENT_DIR' ) && '' !== (string) WPAC_STATEMENT_DIR ) {
			return null;
		}
		static $memo = false;
		if ( false !== $memo ) {
			return $memo;
		}
		$cached = get_transient( 'wpac_statement_probe' );
		if ( false !== $cached ) {
			$memo = 'unknown' === $cached ? null : 'exposed' === $cached;
			return $memo;
		}
		$dir   = self::dir();
		$name  = 'probe-' . wp_generate_password( 12, false ) . '.txt';
		file_put_contents( $dir . '/' . $name, 'wpac' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$upload = wp_upload_dir( null, false );
		// TLS is verified; an unverifiable answer counts as "unknown", never as "protected".
		$res    = wp_remote_get( trailingslashit( $upload['baseurl'] ) . 'wpac-statements/' . $name, array( 'timeout' => 5 ) );
		wp_delete_file( $dir . '/' . $name );
		if ( is_wp_error( $res ) ) {
			// Hosts that block loopback requests: remember "unknown" for an hour instead of
			// probing (with timeouts) on every page view.
			set_transient( 'wpac_statement_probe', 'unknown', HOUR_IN_SECONDS );
			$memo = null;
			return null;
		}
		$exposed = 200 === (int) wp_remote_retrieve_response_code( $res ) && 'wpac' === trim( (string) wp_remote_retrieve_body( $res ) );
		set_transient( 'wpac_statement_probe', $exposed ? 'exposed' : 'protected', DAY_IN_SECONDS );
		$memo = $exposed;
		return $exposed;
	}

	/**
	 * Note text for the VAT treatment.
	 *
	 * @param string $mode    Mode.
	 * @param string $country Operator country (legal references follow it).
	 * @return string
	 */
	public static function vat_note( $mode, $country = '' ) {
		$country = strtoupper( '' !== (string) $country ? (string) $country : (string) Settings::get( 'operator_country' ) );
		switch ( $mode ) {
			case Vat::STANDARD:
				return __( 'The commission rates are net amounts; VAT is added at the statutory rate.', 'affiliate-captain' );
			case Vat::REVERSE_CHARGE:
				if ( 'AT' === $country ) {
					return __( 'Reverse charge: the recipient of the service is liable for VAT (Übergang der Steuerschuld, §19 Abs. 1 UStG (AT) / Art. 196 VAT Directive).', 'affiliate-captain' );
				}
				if ( 'CH' === $country ) {
					return __( 'Reverse charge: the recipient of the service is liable for VAT (Bezugsteuer, Art. 45 MWSTG).', 'affiliate-captain' );
				}
				return __( 'Reverse charge: the recipient of the service is liable for VAT (Steuerschuldnerschaft des Leistungsempfängers, §13b UStG / Art. 196 VAT Directive).', 'affiliate-captain' );
			case Vat::SMALL_BUSINESS:
				if ( 'AT' === $country ) {
					return __( 'No VAT is shown because the partner is a small business (§6 Abs. 1 Z 27 UStG (AT)).', 'affiliate-captain' );
				}
				if ( 'CH' === $country ) {
					return __( 'No VAT is shown because the partner is not registered for VAT (Art. 10 MWSTG).', 'affiliate-captain' );
				}
				return __( 'No VAT is shown because the partner is a small business (§19 UStG).', 'affiliate-captain' );
			case Vat::PRIVATE_PERSON:
				return __( 'No VAT: the partner is a private individual and not an entrepreneur.', 'affiliate-captain' );
		}
		return '';
	}

	/**
	 * Build the PDF bytes for a payout (credit note) or its cancellation.
	 *
	 * Self-billing credit notes contain the mandatory details of §14 Abs. 4 UStG:
	 * names/addresses, tax number or VAT ID, date, unique number, service and
	 * period, net amount, rate and VAT amount, and the word "Gutschrift".
	 *
	 * @param object $p      Payout.
	 * @param bool   $storno Build the cancellation document.
	 * @return string
	 */
	public static function build( $p, $storno = false ) {
		$aff    = AffiliateRepository::find( (int) $p->affiliate_id );
		$snap   = json_decode( (string) $p->profile_snapshot, true );
		$snap   = is_array( $snap ) ? $snap : array();
		$labels = ProfileRepository::tax_status_labels();
		// Operator data as frozen with the payout (older payouts: current settings).
		$op     = json_decode( (string) ( isset( $p->operator_snapshot ) ? $p->operator_snapshot : '' ), true );
		$op     = is_array( $op ) ? $op : PayoutService::operator_snapshot();
		$issuer = (string) $op['name'];
		$self   = ! empty( $op['self_billing'] );
		$sign   = $storno ? -1 : 1;
		$net    = (int) $p->net_minor ? (int) $p->net_minor : (int) $p->amount_minor - (int) $p->vat_minor;
		$date   = static function ( $mysql ) {
			return mysql2date( get_option( 'date_format' ), $mysql );
		};

		if ( $storno ) {
			/* translators: %s: original document number */
			$title = sprintf( __( 'Cancellation of credit note %s', 'affiliate-captain' ), $p->number );
			$doc   = (string) $p->storno_number;
		} else {
			$title = $self ? __( 'Credit note (Gutschrift)', 'affiliate-captain' ) : __( 'Commission statement', 'affiliate-captain' );
			$doc   = (string) $p->number;
		}

		$pdf = new Pdf(
			array(
				'title'   => $title . ' ' . $doc,
				'author'  => $issuer,
				'created' => strtotime( ( $storno && $p->storno_at ? $p->storno_at : $p->created_at ) . ' UTC' ),
			)
		);
		$y = 60;
		$pdf->text( 50, $y, $title, 15, true );
		$y += 30;
		$pdf->text( 50, $y, $self ? __( 'Issued by (recipient of the service)', 'affiliate-captain' ) : __( 'Issued by', 'affiliate-captain' ), 9, true );
		$pdf->text( 310, $y, $self ? __( 'Partner (provider of the service)', 'affiliate-captain' ) : __( 'Partner', 'affiliate-captain' ), 9, true );
		$y   += 14;
		$left = array_merge(
			array( $issuer ),
			preg_split( '/\r\n|\r|\n/', (string) $op['address'] ),
			array_filter(
				array(
					$op['vat_id'] ? __( 'VAT ID', 'affiliate-captain' ) . ': ' . $op['vat_id'] : '',
					$op['tax_number'] ? __( 'Tax number', 'affiliate-captain' ) . ': ' . $op['tax_number'] : '',
				)
			)
		);
		$partner = array_merge(
			array( ! empty( $snap['legal_name'] ) ? $snap['legal_name'] : ( $aff ? $aff->name : '' ) ),
			preg_split( '/\r\n|\r|\n/', isset( $snap['address'] ) ? (string) $snap['address'] : '' ),
			array( isset( $snap['country'] ) ? $snap['country'] : '' )
		);
		$left    = array_values( array_filter( array_map( 'trim', $left ), 'strlen' ) );
		$partner = array_values( array_filter( array_map( 'trim', $partner ), 'strlen' ) );
		$rows    = max( count( $left ), count( $partner ) );
		for ( $i = 0; $i < $rows; $i++ ) {
			if ( isset( $left[ $i ] ) ) {
				$pdf->text( 50, $y, $left[ $i ] );
			}
			if ( isset( $partner[ $i ] ) ) {
				$pdf->text( 310, $y, $partner[ $i ] );
			}
			$y += 13;
		}
		$y    += 10;
		$facts = array(
			( $storno ? __( 'Cancellation number', 'affiliate-captain' ) : ( $self ? __( 'Credit note number', 'affiliate-captain' ) : __( 'Statement number', 'affiliate-captain' ) ) ) => $doc,
			__( 'Date', 'affiliate-captain' ) => $date( get_date_from_gmt( $storno && $p->storno_at ? $p->storno_at : $p->created_at ) ),
		);
		if ( $storno ) {
			$facts[ __( 'Cancels', 'affiliate-captain' ) ] = $p->number . ' (' . $date( get_date_from_gmt( $p->created_at ) ) . ')';
		}
		$facts += array(
			__( 'Service', 'affiliate-captain' )         => __( 'Referral of customers (affiliate commission)', 'affiliate-captain' ),
			__( 'Service period until', 'affiliate-captain' ) => $date( $p->period_end ),
			__( 'Tax status', 'affiliate-captain' )      => isset( $snap['tax_status'], $labels[ $snap['tax_status'] ] ) ? $labels[ $snap['tax_status'] ] : '—',
			__( 'VAT ID of the partner', 'affiliate-captain' ) => ! empty( $snap['vat_id'] ) ? $snap['vat_id'] : '—',
		);
		if ( ! empty( $snap['tax_number'] ) ) {
			$facts[ __( 'Tax number of the partner', 'affiliate-captain' ) ] = $snap['tax_number'];
		}
		if ( isset( $snap['payout_method'] ) && ProfileRepository::METHOD_MANUAL === $snap['payout_method'] ) {
			$facts[ __( 'Payment method', 'affiliate-captain' ) ] = __( 'Other payment method as agreed with the partner', 'affiliate-captain' );
		} else {
			$facts[ __( 'Bank account', 'affiliate-captain' ) ] = ! empty( $snap['iban_masked'] ) ? $snap['iban_masked'] : '—';
		}
		foreach ( $facts as $k => $v ) {
			$pdf->text( 50, $y, $k . ':', 9, true );
			$pdf->text( 190, $y, (string) $v, 9 );
			$y += 13;
		}

		$y   += 14;
		$head = static function ( $pdf, $y ) {
			$pdf->text( 50, $y, __( 'Date', 'affiliate-captain' ), 9, true );
			$pdf->text( 120, $y, __( 'Order', 'affiliate-captain' ), 9, true );
			$pdf->text( 230, $y, __( 'Description', 'affiliate-captain' ), 9, true );
			$pdf->text( 545, $y, __( 'Net', 'affiliate-captain' ), 9, true, 'right' );
			$pdf->line( 50, $y + 4, 545, $y + 4 );
			return $y + 16;
		};
		// Regular items first, then corrections of earlier credit notes, each block with a
		// reference to the original document and its VAT treatment (§17 UStG).
		$items  = PayoutService::items( (int) $p->id );
		$blocks = array( 0 => array() );
		foreach ( $items as $item ) {
			$ref              = isset( $item->ref_payout_id ) ? (int) $item->ref_payout_id : 0;
			$blocks[ $ref ][] = $item;
		}
		$rate_of = static function ( $item ) use ( $p ) {
			return isset( $item->vat_rate ) && '' !== (string) $item->vat_rate ? (string) $item->vat_rate : (string) $p->vat_rate;
		};
		$groups = array();
		foreach ( $blocks as $ref => $list ) {
			if ( ! $list ) {
				continue;
			}
			if ( $ref ) {
				$orig = PayoutService::find( $ref );
				$y   += 8;
				if ( $y > 740 ) {
					$pdf->add_page();
					$y = 60;
				}
				/* translators: 1: credit note number, 2: date */
				$pdf->text( 50, $y, sprintf( __( 'Correction of credit note %1$s of %2$s', 'affiliate-captain' ), $orig ? $orig->number : '#' . $ref, $orig ? $date( get_date_from_gmt( $orig->created_at ) ) : '—' ), 10, true );
				$y += 16;
			}
			$y   = $head( $pdf, $y );
			$sum = 0;
			foreach ( $list as $item ) {
				if ( $y > 760 ) {
					$pdf->add_page();
					$y = $head( $pdf, 60 );
				}
				$desc = in_array( $item->kind, array( 'sale', 'bonus' ), true ) ? (string) $item->description : __( 'Correction', 'affiliate-captain' ) . ' (' . \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $item->kind ) . ')';
				$desc = Pdf::wrap( $desc, 230, 9 );
				$pdf->text( 50, $y, $date( get_date_from_gmt( (string) $item->commission_date ) ), 9 );
				$pdf->text( 120, $y, ( 'wpac' === $item->source ? '' : '#' . $item->source_order_id ), 9 );
				$pdf->text( 230, $y, $desc[0], 9 );
				$pdf->text( 545, $y, Money::format( $sign * (int) $item->amount_minor, $p->currency ), 9, false, 'right' );
				$y   += 13;
				$sum += (int) $item->amount_minor;
				$rate = $rate_of( $item );
				$groups[ $rate ] = ( isset( $groups[ $rate ] ) ? $groups[ $rate ] : 0 ) + (int) $item->amount_minor;
			}
			if ( $ref ) {
				$orig_rate = $orig ? (string) $orig->vat_rate : '0';
				$pdf->line( 50, $y - 6, 545, $y - 6 );
				/* translators: %s: VAT rate */
				$pdf->text( 330, $y + 4, sprintf( __( 'Correction net (VAT %s %%)', 'affiliate-captain' ), $orig_rate ), 9 );
				$pdf->text( 545, $y + 4, Money::format( $sign * $sum, $p->currency ), 9, false, 'right' );
				$y += 18;
			}
		}
		$pdf->line( 50, $y - 6, 545, $y - 6 );
		$y     += 6;
		$totals = array(
			array( __( 'Net amount', 'affiliate-captain' ), $sign * $net, false ),
		);
		$legacy = true;
		foreach ( $items as $item ) {
			if ( isset( $item->vat_rate ) && '' !== (string) $item->vat_rate ) {
				$legacy = false;
			}
		}
		if ( $legacy || count( $groups ) <= 1 ) {
			if ( (int) $p->vat_minor ) {
				/* translators: %s: rate */
				$totals[] = array( sprintf( __( 'plus VAT %s %%', 'affiliate-captain' ), $legacy || ! $groups ? $p->vat_rate : (string) key( $groups ) ), $sign * (int) $p->vat_minor, false );
			} else {
				$totals[] = array( __( 'VAT', 'affiliate-captain' ), 0, false );
			}
		} else {
			ksort( $groups );
			foreach ( $groups as $rate => $group_net ) {
				/* translators: 1: rate, 2: net amount */
				$totals[] = array( sprintf( __( 'VAT %1$s %% on %2$s', 'affiliate-captain' ), $rate, Money::format( $sign * $group_net, $p->currency ) ), $sign * Vat::amount( $group_net, (string) $rate ), false );
			}
		}
		$totals[] = array( $storno ? __( 'Total cancelled', 'affiliate-captain' ) : __( 'Total payout', 'affiliate-captain' ), $sign * (int) $p->amount_minor, true );
		foreach ( $totals as $t ) {
			$pdf->text( 330, $y, $t[0], 10, $t[2] );
			$pdf->text( 545, $y, Money::format( $t[1], $p->currency ), 10, $t[2], 'right' );
			$y += 15;
		}
		$y += 15;
		$notes = array_filter(
			array(
				self::vat_note( (string) $p->vat_mode, (string) $op['country'] ),
				$self && ! $storno ? __( 'This document is a credit note (self-billing) agreed with the partner; the partner does not issue a separate invoice. The partner may object to it (§14 Abs. 2 S. 3 UStG) – it then no longer counts as an invoice and we issue a corrected credit note. Objections can be filed in the partner area.', 'affiliate-captain' ) : '',
				$storno ? __( 'This document cancels the credit note named above in full. The commissions remain available for a later payout.', 'affiliate-captain' ) : '',
			)
		);
		/** This filter is documented in docs/CONTRACT.md. */
		$note = (string) apply_filters( 'wpac_statement_note', implode( "\n", $notes ), $p );
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
	 * Annual overview of a partner's credit notes and cancellations (for the partner's tax
	 * return): every document of the year with net, VAT and gross, and the totals per currency.
	 * Not a tax document itself.
	 *
	 * @param int $affiliate_id Partner.
	 * @param int $year         Year.
	 * @return string PDF bytes.
	 */
	public static function annual( $affiliate_id, $year ) {
		global $wpdb;
		$aff  = AffiliateRepository::find( (int) $affiliate_id );
		$t    = Db::table( 'payouts' );
		// Calendar year in the site's time zone (documents are dated locally), as UTC bounds.
		list( $from, $to ) = self::year_bounds( (int) $year );
		// Credit notes issued in the year, and cancellations dated in the year (they reduce it).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d AND ( ( created_at BETWEEN %s AND %s AND statement_file <> '' ) OR ( storno_at BETWEEN %s AND %s ) ) ORDER BY created_at ASC", $affiliate_id, $from, $to, $from, $to ) );
		$op   = PayoutService::operator_snapshot();
		$pdf  = new Pdf(
			array(
				/* translators: %d: year */
				'title'  => sprintf( __( 'Annual overview %d', 'affiliate-captain' ), (int) $year ),
				'author' => $op['name'],
			)
		);
		$date = static function ( $mysql ) {
			return mysql2date( get_option( 'date_format' ), get_date_from_gmt( $mysql ) );
		};
		$y = 60;
		/* translators: %d: year */
		$pdf->text( 50, $y, sprintf( __( 'Annual overview %d', 'affiliate-captain' ), (int) $year ), 15, true );
		$y += 24;
		$pdf->text( 50, $y, $op['name'] . ' – ' . __( 'partner program', 'affiliate-captain' ), 10 );
		$y += 14;
		$pdf->text( 50, $y, __( 'Partner', 'affiliate-captain' ) . ': ' . ( $aff ? $aff->name : '#' . (int) $affiliate_id ), 10 );
		$y += 24;
		$cols = array( 50 => __( 'Document', 'affiliate-captain' ), 190 => __( 'Date', 'affiliate-captain' ), 395 => __( 'Net', 'affiliate-captain' ), 470 => __( 'VAT', 'affiliate-captain' ), 545 => __( 'Gross', 'affiliate-captain' ) );
		foreach ( $cols as $x => $label ) {
			$pdf->text( $x, $y, $label, 9, true, $x > 300 ? 'right' : 'left' );
		}
		$pdf->line( 50, $y + 4, 545, $y + 4 );
		$y   += 16;
		$sums = array();
		$line = static function ( $doc, $when, $net, $vat, $gross, $cur ) use ( $pdf, &$y, &$sums ) {
			if ( $y > 780 ) {
				$pdf->add_page();
				$y = 60;
			}
			$pdf->text( 50, $y, $doc, 9 );
			$pdf->text( 190, $y, $when, 9 );
			$pdf->text( 395, $y, Money::format( $net, $cur ), 9, false, 'right' );
			$pdf->text( 470, $y, Money::format( $vat, $cur ), 9, false, 'right' );
			$pdf->text( 545, $y, Money::format( $gross, $cur ), 9, false, 'right' );
			$y += 13;
			if ( ! isset( $sums[ $cur ] ) ) {
				$sums[ $cur ] = array( 0, 0, 0 );
			}
			$sums[ $cur ][0] += $net;
			$sums[ $cur ][1] += $vat;
			$sums[ $cur ][2] += $gross;
		};
		foreach ( $rows as $p ) {
			$net = (int) $p->net_minor ? (int) $p->net_minor : (int) $p->amount_minor - (int) $p->vat_minor;
			if ( $p->statement_file && $p->created_at >= $from && $p->created_at <= $to ) {
				$line( (string) $p->number, $date( $p->created_at ), $net, (int) $p->vat_minor, (int) $p->amount_minor, $p->currency );
			}
			if ( $p->storno_at && $p->storno_at >= $from && $p->storno_at <= $to && (string) $p->storno_number ) {
				/* translators: %s: credit note number */
				$line( (string) $p->storno_number . ' (' . sprintf( __( 'cancels %s', 'affiliate-captain' ), $p->number ) . ')', $date( $p->storno_at ), -$net, -(int) $p->vat_minor, -(int) $p->amount_minor, $p->currency );
			}
		}
		if ( ! $rows ) {
			$pdf->text( 50, $y, __( 'No credit notes in this year.', 'affiliate-captain' ), 9 );
			$y += 13;
		}
		$pdf->line( 50, $y - 6, 545, $y - 6 );
		$y += 6;
		foreach ( $sums as $cur => $s ) {
			/* translators: %s: currency */
			$pdf->text( 50, $y, sprintf( __( 'Total %s', 'affiliate-captain' ), $cur ), 10, true );
			$pdf->text( 395, $y, Money::format( $s[0], $cur ), 10, true, 'right' );
			$pdf->text( 470, $y, Money::format( $s[1], $cur ), 10, true, 'right' );
			$pdf->text( 545, $y, Money::format( $s[2], $cur ), 10, true, 'right' );
			$y += 15;
		}
		$y += 15;
		foreach ( Pdf::wrap( __( 'This overview summarises your credit notes and cancellations of the year. It is not a tax document itself; the credit notes are. Please keep them for your records.', 'affiliate-captain' ), 495, 8 ) as $l ) {
			$pdf->text( 50, $y, $l, 8 );
			$y += 11;
		}
		return $pdf->output();
	}

	/**
	 * UTC bounds (MySQL) of a calendar year in the site's time zone.
	 *
	 * @param int $year Year.
	 * @return string[] [ from, to ]
	 */
	public static function year_bounds( $year ) {
		return array(
			get_gmt_from_date( sprintf( '%04d-01-01 00:00:00', (int) $year ) ),
			get_gmt_from_date( sprintf( '%04d-12-31 23:59:59', (int) $year ) ),
		);
	}

	/**
	 * Years (site time zone) with credit notes or cancellations of a partner, newest first:
	 * the years an annual overview exists for.
	 *
	 * @param int $affiliate_id Partner.
	 * @return int[]
	 */
	public static function annual_years( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, statement_file, storno_number, storno_at FROM {$t} WHERE affiliate_id = %d AND ( statement_file <> '' OR storno_number <> '' )", (int) $affiliate_id ) );
		$years = array();
		foreach ( (array) $rows as $r ) {
			if ( '' !== (string) $r->statement_file ) {
				$years[ (int) get_date_from_gmt( (string) $r->created_at, 'Y' ) ] = true;
			}
			if ( '' !== (string) $r->storno_number && $r->storno_at ) {
				$years[ (int) get_date_from_gmt( (string) $r->storno_at, 'Y' ) ] = true;
			}
		}
		krsort( $years );
		return array_keys( $years );
	}

	/**
	 * Generate and store the statement of a payout (only once).
	 *
	 * @param int $payout_id Payout.
	 * @return bool
	 */
	public static function generate( $payout_id ) {
		global $wpdb;
		// Claim the payout: the payout run and the hourly cron (or two cron runs) must not both
		// write a credit note for it (two files, the second overwriting the stored hash).
		$owner = Db::lock( 'wpac_stmt_' . (int) $payout_id, 10 * MINUTE_IN_SECONDS );
		if ( '' === $owner ) {
			return false;
		}
		try {
			$p = PayoutService::find( $payout_id );
			if ( ! $p || '' !== (string) $p->statement_file ) {
				return false;
			}
			$bytes = self::build( $p );
			$name  = sanitize_file_name( $p->number . '-' . wp_generate_password( 16, false ) . '.pdf' );
			$path  = self::dir() . '/' . $name;
			if ( false === @file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				Audit::log( 'statement.write_failed', 'payout', (int) $payout_id );
				$last = error_get_last(); // The cause (disk full, permissions, open_basedir …), hidden by @.
				\WPAffiliateCaptain\Core\Log::error(
					'Credit note could not be written',
					array(
						'payout'       => (int) $payout_id,
						'dir_writable' => is_writable( self::dir() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
						'cause'        => is_array( $last ) ? substr( (string) $last['message'], 0, 300 ) : '',
					)
				);
				self::backoff( (int) $payout_id );
				return false;
			}
			// Only if still without a file (compare-and-set).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$done = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payouts' ) . " SET statement_file = %s, statement_hash = %s WHERE id = %d AND statement_file = ''", $name, hash( 'sha256', $bytes ), (int) $payout_id ) );
			if ( 1 !== $done ) {
				wp_delete_file( $path );
				return false;
			}
			self::clear_backoff( (int) $payout_id );
			Audit::log( 'statement.generated', 'payout', (int) $payout_id, array( 'hash' => hash( 'sha256', $bytes ) ) );
			return true;
		} finally {
			Db::unlock( 'wpac_stmt_' . (int) $payout_id, $owner );
		}
	}

	const RETRY_OPTION = 'wpac_statement_retry';

	/**
	 * Remember a failed write and wait longer each time (1, 2, 4 … 24 hours).
	 *
	 * @param int $payout_id Payout.
	 */
	private static function backoff( $payout_id ) {
		$all              = get_option( self::RETRY_OPTION, array() );
		$all              = is_array( $all ) ? $all : array();
		$n                = isset( $all[ $payout_id ] ) ? (int) $all[ $payout_id ]['n'] + 1 : 1;
		$all[ $payout_id ] = array(
			'n'    => $n,
			'next' => time() + min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** ( $n - 1 ) ) ),
		);
		update_option( self::RETRY_OPTION, $all, false );
	}

	/**
	 * Forget the failures of a payout.
	 *
	 * @param int $payout_id Payout.
	 */
	private static function clear_backoff( $payout_id ) {
		$all = get_option( self::RETRY_OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $payout_id ] ) ) {
			unset( $all[ $payout_id ] );
			update_option( self::RETRY_OPTION, $all, false );
		}
	}

	/**
	 * Drop failure entries of payouts that no longer need a statement: cancelled (the
	 * cancellation replaces it), generated meanwhile, deleted after the retention period or
	 * gone. Otherwise they would stay a critical finding (Site Health, alarm mail) forever.
	 *
	 * @param array $all Failure entries: payout ID => [ n, next ].
	 * @return array Remaining entries.
	 */
	private static function prune_backoff( array $all ) {
		if ( ! $all ) {
			return $all;
		}
		$keep = array();
		foreach ( $all as $id => $entry ) {
			$p = PayoutService::find( (int) $id );
			if ( $p && 'cancelled' !== $p->status && '' === (string) $p->statement_file && ! $p->statement_deleted_at ) {
				$keep[ $id ] = $entry;
			}
		}
		if ( count( $keep ) !== count( $all ) ) {
			update_option( self::RETRY_OPTION, $keep, false );
		}
		return $keep;
	}

	/**
	 * Forget the failures of a payout that no longer needs its statement (e.g. cancelled).
	 *
	 * @param int $payout_id Payout.
	 */
	public static function forget_failures( $payout_id ) {
		self::clear_backoff( (int) $payout_id );
	}

	/**
	 * Whether a payout waits after failed attempts; and the failures (for the admin notice).
	 *
	 * @param int $payout_id Payout (0 = all failures).
	 * @return bool|array
	 */
	public static function waiting( $payout_id = 0 ) {
		$all = get_option( self::RETRY_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( ! $payout_id ) {
			return self::prune_backoff( $all );
		}
		return isset( $all[ $payout_id ] ) && (int) $all[ $payout_id ]['next'] > time();
	}

	/**
	 * Stream the stored PDF (caller checks permissions) and exit.
	 *
	 * @param object $p Payout.
	 */
	public static function send( $p ) {
		$path = self::path( (string) $p->statement_file );
		if ( '' === (string) $p->statement_file || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The statement is not available (anymore).', 'affiliate-captain' ), 404 );
		}
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! hash_equals( (string) $p->statement_hash, hash( 'sha256', $bytes ) ) ) {
			Audit::log( 'statement.hash_mismatch', 'payout', (int) $p->id );
			wp_die( esc_html__( 'The stored statement failed the integrity check.', 'affiliate-captain' ), 500 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $p->number . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Earlier storage folders: the uploads folder (before WPAC_STATEMENT_DIR was defined) and the
	 * flat WPAC_STATEMENT_DIR itself; only those that differ from the current folder.
	 *
	 * @return string[]
	 */
	public static function legacy_dirs() {
		$upload = wp_upload_dir( null, false );
		$dirs   = array( trailingslashit( $upload['basedir'] ) . 'wpac-statements' );
		if ( defined( 'WPAC_STATEMENT_DIR' ) && '' !== (string) WPAC_STATEMENT_DIR ) {
			$dirs[] = untrailingslashit( (string) WPAC_STATEMENT_DIR );
		}
		$current = untrailingslashit( self::dir() );
		$out     = array();
		foreach ( array_unique( $dirs ) as $dir ) {
			if ( untrailingslashit( $dir ) !== $current && is_dir( $dir ) ) {
				$out[] = untrailingslashit( $dir );
			}
		}
		return $out;
	}

	/**
	 * Full path of a stored statement: the current folder, or an earlier one when the folder
	 * was changed later (e.g. WPAC_STATEMENT_DIR defined after the first credit notes).
	 *
	 * @param string $file Stored file name.
	 * @return string
	 */
	public static function path( $file ) {
		$name = basename( (string) $file );
		$path = self::dir() . '/' . $name;
		if ( '' === $name || is_readable( $path ) ) {
			return $path;
		}
		foreach ( self::legacy_dirs() as $dir ) {
			if ( is_readable( $dir . '/' . $name ) ) {
				return $dir . '/' . $name;
			}
		}
		return $path;
	}

	/**
	 * Statements (and cancellations) that are still in an earlier folder.
	 *
	 * @param int $limit Max. payouts to look at.
	 * @return array List of [ payout ID, file name, old path ].
	 */
	public static function misplaced( $limit = 5000 ) {
		global $wpdb;
		$legacy = self::legacy_dirs();
		if ( ! $legacy ) {
			return array();
		}
		$t   = Db::table( 'payouts' );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, statement_file, storno_file FROM {$t} WHERE statement_file <> '' OR storno_file <> '' ORDER BY id ASC LIMIT %d", (int) $limit ) ) as $r ) {
			foreach ( array( $r->statement_file, $r->storno_file ) as $file ) {
				$name = basename( (string) $file );
				if ( '' === $name || file_exists( self::dir() . '/' . $name ) ) {
					continue;
				}
				foreach ( $legacy as $dir ) {
					if ( is_readable( $dir . '/' . $name ) ) {
						$out[] = array( (int) $r->id, $name, $dir . '/' . $name );
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Move statements from earlier folders into the current one (copy, verify, then delete the
	 * old file).
	 *
	 * @return array [ moved, failed ]
	 */
	public static function move_misplaced() {
		$moved  = 0;
		$failed = 0;
		foreach ( self::misplaced() as $m ) {
			$target = self::dir() . '/' . $m[1];
			$bytes  = file_get_contents( $m[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $bytes && false !== file_put_contents( $target, $bytes ) && hash_file( 'sha256', $target ) === hash( 'sha256', $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				wp_delete_file( $m[2] );
				++$moved;
			} else {
				++$failed;
			}
		}
		if ( $moved || $failed ) {
			Audit::log(
				'statement.moved',
				'settings',
				0,
				array(
					'moved'  => $moved,
					'failed' => $failed,
				)
			);
		}
		return array( $moved, $failed );
	}

	/**
	 * Generate and store the cancellation document of a payout (only once).
	 *
	 * @param int $payout_id Payout.
	 * @return bool
	 */
	public static function generate_storno( $payout_id ) {
		global $wpdb;
		$p = PayoutService::find( $payout_id );
		if ( ! $p || '' === (string) $p->storno_number || '' !== (string) $p->storno_file ) {
			return false;
		}
		$bytes = self::build( $p, true );
		$name  = sanitize_file_name( $p->storno_number . '-' . wp_generate_password( 16, false ) . '.pdf' );
		if ( false === file_put_contents( self::dir() . '/' . $name, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			Audit::log( 'statement.storno_write_failed', 'payout', (int) $payout_id );
			$last = error_get_last();
			\WPAffiliateCaptain\Core\Log::error(
				'Cancellation document could not be written',
				array(
					'payout'       => (int) $payout_id,
					'dir_writable' => is_writable( self::dir() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
					'cause'        => is_array( $last ) ? substr( (string) $last['message'], 0, 300 ) : '',
				)
			);
			return false;
		}
		$wpdb->update(
			Db::table( 'payouts' ),
			array(
				'storno_file' => $name,
				'storno_hash' => hash( 'sha256', $bytes ),
			),
			array( 'id' => (int) $payout_id )
		);
		Audit::log( 'statement.storno_generated', 'payout', (int) $payout_id, array( 'number' => $p->storno_number ) );
		return true;
	}

	/**
	 * Stream the stored cancellation document and exit.
	 *
	 * @param object $p Payout.
	 */
	public static function send_storno( $p ) {
		$clone                 = clone $p;
		$clone->statement_file = $p->storno_file;
		$clone->statement_hash = $p->storno_hash;
		$clone->number         = (string) $p->storno_number;
		self::send( $clone );
	}

	/**
	 * Delete statements and partner snapshots after the retention period (cron).
	 *
	 * @return int Number of payouts purged.
	 */
	public static function purge_expired() {
		global $wpdb;
		// One source of truth with the booking records, acceptance records and the financial change
		// log (Retention::cutoff(): setting, never below the legal minimum of the operator country;
		// null = keep forever or deletion suspended by a tax audit/legal hold).
		$cutoff = \WPAffiliateCaptain\Privacy\Retention::cutoff();
		if ( null === $cutoff ) {
			return 0;
		}
		$t = Db::table( 'payouts' );
		// Retention starts at the end of the calendar year of the statement (cancellations: of their own date).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, statement_file, storno_file FROM {$t} WHERE statement_deleted_at IS NULL AND created_at <= %s AND ( storno_at IS NULL OR storno_at <= %s ) LIMIT 200", $cutoff, $cutoff ) );
		foreach ( $rows as $r ) {
			foreach ( array( $r->statement_file, $r->storno_file ) as $file ) {
				if ( '' !== (string) $file && file_exists( self::dir() . '/' . basename( (string) $file ) ) ) {
					wp_delete_file( self::dir() . '/' . basename( (string) $file ) );
				}
			}
			$wpdb->update(
				$t,
				array(
					'statement_file'       => '',
					'storno_file'          => '',
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
