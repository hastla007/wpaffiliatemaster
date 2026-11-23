<?php
/**
 * Electronic invoice (EN 16931, UN/CEFACT CII) of credit notes, correction credit notes and
 * cancellations (R14-L1).
 *
 * - Built from the same frozen data as the PDF (payout row, its items, the profile and operator
 *   snapshot), so XML and PDF show the same amounts. Nothing is stored: the XML is built again
 *   on every download from the stored data, and only while the PDF exists (deleted together
 *   after the retention period).
 * - Self-billing (§14 Abs. 2 UStG): the partner is the seller, the operator the buyer. Credit
 *   note: type 389 (self-billed invoice); correction credit note and the correction document of
 *   a payout run: 384 (corrected invoice, refers to the corrected credit note); cancellation:
 *   381 (credit note, refers to the cancelled credit note; amounts as on the original, the type
 *   reverses them).
 * - VAT categories per line: S (standard rate), AE (reverse charge), E (small business, §19
 *   UStG) and O (private individual, not subject to VAT). Repayments, write-offs and settled
 *   correction credit notes are no service: they are not invoice lines but the "paid amount"
 *   (BT-113), so the amount due (BT-115) is exactly the payout amount of the PDF.
 * - Profile: XRechnung 3.0 when the data XRechnung demands on top of EN 16931 are known (seller
 *   contact with phone and e-mail, postcode and city of both parties); otherwise the EN 16931
 *   core profile – also a valid electronic invoice under §14 Abs. 1 UStG. The plugin does not
 *   collect phone numbers of partners; add-ons can add them (filter wpac_einvoice_data).
 * - The PDF has no embedded XML (no Factur-X/ZUGFeRD): the PDF writer cannot produce PDF/A-3.
 *   Where both are handed out, the XML is the electronic invoice and the PDF a visual copy.
 *
 * The XML part (xml(), check(), totals(), split_address()) has no WordPress dependency and is
 * unit tested.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class EInvoice {

	/** Specification identifier (BT-24) of XRechnung 3.0 (CIUS of EN 16931). */
	const XRECHNUNG = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

	/** Specification identifier (BT-24) of the EN 16931 core profile. */
	const EN16931 = 'urn:cen.eu:en16931:2017';

	/** Business process (BT-23). */
	const PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

	/** Document types (UNTDID 1001). */
	const SELF_BILLED = '389';
	const CORRECTED   = '384';
	const CREDIT_NOTE = '381';

	/** Namespaces of the CII D16B syntax. */
	const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
	const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

	/** Exemption reason of a German small business (BT-120). */
	const SMALL_BUSINESS_DE = 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG';

	/** @var array|null E-invoice waiting for the partner mail of the document just issued. */
	private static $queued = null;

	/* ---------------------------------------------------------------------------------------
	 * WordPress side: data of the documents, download, mail attachment.
	 * ------------------------------------------------------------------------------------- */

	/**
	 * Hooks: attach the XML to the partner mail of a credit note, cancellation or correction
	 * credit note (setting "Send e-invoice"). The document is remembered just before the mail is
	 * written (priority 9, the mail is sent at 10) and the temporary file removed after it (11).
	 */
	public static function register_hooks() {
		add_action( 'wpac_statement_generated', array( __CLASS__, 'queue_statement' ), 9, 1 );
		add_action( 'wpac_storno_generated', array( __CLASS__, 'queue_storno' ), 9, 1 );
		add_action( 'wpac_correction_generated', array( __CLASS__, 'queue_correction' ), 9, 1 );
		foreach ( array( 'wpac_statement_generated', 'wpac_storno_generated', 'wpac_correction_generated' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'release' ), 11, 0 );
		}
		add_filter( 'wpac_mail', array( __CLASS__, 'attach' ), 10, 3 );
	}

	/**
	 * Whether the XML goes with the partner mail (setting einvoice_mail: on, off or auto = for
	 * partners whose credit note shows domestic VAT, when the operator's country profile expects
	 * e-invoices – by default Germany, where domestic VAT-registered businesses need them from
	 * 2027/2028).
	 *
	 * @param string $mode    VAT treatment of the document.
	 * @param array  $op      Operator snapshot.
	 * @return bool
	 */
	public static function by_mail( $mode, array $op ) {
		$setting = (string) Settings::get( 'einvoice_mail' );
		if ( 'on' === $setting || 'off' === $setting ) {
			return 'on' === $setting;
		}
		$country = strtoupper( isset( $op['country'] ) && '' !== (string) $op['country'] ? (string) $op['country'] : 'DE' );
		// "Automatic" (SET-AC-04 e): credit notes with domestic VAT, where the country profile of the
		// operator expects e-invoices (DE; other countries through wpac_country_profiles).
		return Vat::STANDARD === (string) $mode && \WPAffiliateCaptain\Core\CountryProfiles::einvoice_auto( $country );
	}

	/**
	 * Remember the e-invoice of a credit note for its mail.
	 *
	 * @param int $payout_id Payout.
	 */
	public static function queue_statement( $payout_id ) {
		self::queue_payout( (int) $payout_id, false );
	}

	/**
	 * Remember the e-invoice of a cancellation for its mail.
	 *
	 * @param int $payout_id Payout.
	 */
	public static function queue_storno( $payout_id ) {
		self::queue_payout( (int) $payout_id, true );
	}

	/**
	 * Remember the e-invoice of a payout document.
	 *
	 * @param int  $payout_id Payout.
	 * @param bool $storno    Cancellation.
	 */
	private static function queue_payout( $payout_id, $storno ) {
		self::$queued = null;
		$p = PayoutService::find( $payout_id );
		if ( ! $p || ! self::by_mail( (string) $p->vat_mode, self::op( $p ) ) ) {
			return;
		}
		$xml = self::payout_xml( $p, $storno );
		if ( null !== $xml ) {
			self::$queued = array(
				'type'      => $storno ? 'credit_note_cancelled' : 'credit_note',
				'affiliate' => (int) $p->affiliate_id,
				'name'      => ( $storno ? (string) $p->storno_number : (string) $p->number ) . '.xml',
				'xml'       => $xml,
				'dir'       => '',
			);
		}
	}

	/**
	 * Remember the e-invoice of a correction credit note for its mail.
	 *
	 * @param int $correction_id Correction.
	 */
	public static function queue_correction( $correction_id ) {
		self::$queued = null;
		$c = Corrections::find( (int) $correction_id );
		if ( ! $c || ! self::by_mail( (string) $c->vat_mode, self::op( $c ) ) ) {
			return;
		}
		$xml = self::correction_xml( $c );
		if ( null !== $xml ) {
			self::$queued = array(
				'type'      => 'credit_note_corrected',
				'affiliate' => (int) $c->affiliate_id,
				'name'      => (string) $c->number . '.xml',
				'xml'       => $xml,
				'dir'       => '',
			);
		}
	}

	/**
	 * Filter wpac_mail: add the remembered XML to the partner mail of its document.
	 *
	 * @param array|false $mail    Mail.
	 * @param string      $type    Mail type.
	 * @param array       $context Context (affiliate_id).
	 * @return array|false
	 */
	public static function attach( $mail, $type = '', $context = array() ) {
		$q = self::$queued;
		if ( ! is_array( $mail ) || ! $q || $q['type'] !== (string) $type || ! is_array( $context ) || (int) ( isset( $context['affiliate_id'] ) ? $context['affiliate_id'] : 0 ) !== $q['affiliate'] ) {
			return $mail;
		}
		$base = function_exists( 'get_temp_dir' ) ? (string) get_temp_dir() : sys_get_temp_dir();
		$dir  = rtrim( $base, '/\\' ) . '/wpac-einvoice-' . wp_generate_password( 16, false );
		$file = $dir . '/' . sanitize_file_name( $q['name'] );
		if ( ! wp_mkdir_p( $dir ) || false === @file_put_contents( $file, $q['xml'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			\WPAffiliateCaptain\Core\Log::warning( 'E-invoice could not be attached', array( 'type' => (string) $type ) );
			return $mail;
		}
		self::$queued['dir']   = $dir;
		$mail['attachments']   = isset( $mail['attachments'] ) ? (array) $mail['attachments'] : array();
		$mail['attachments'][] = $file;
		return $mail;
	}

	/**
	 * Remove the temporary attachment after the mail.
	 */
	public static function release() {
		$q            = self::$queued;
		self::$queued = null;
		if ( $q && '' !== $q['dir'] && is_dir( $q['dir'] ) ) {
			foreach ( (array) glob( $q['dir'] . '/*' ) as $file ) {
				wp_delete_file( (string) $file );
			}
			@rmdir( $q['dir'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Operator snapshot of a payout or correction (older documents: current settings).
	 *
	 * @param object $row Payout or correction.
	 * @return array
	 */
	private static function op( $row ) {
		$op = json_decode( (string) ( isset( $row->operator_snapshot ) ? $row->operator_snapshot : '' ), true );
		return is_array( $op ) ? $op : PayoutService::operator_snapshot();
	}

	/**
	 * Whether a payout document has an e-invoice: self-billing credit notes (and their
	 * cancellation) while the PDF exists. Statements without self-billing are no invoices.
	 *
	 * @param object $p      Payout.
	 * @param bool   $storno Cancellation.
	 * @return bool
	 */
	public static function available( $p, $storno = false ) {
		$op = self::op( $p );
		return ! empty( $op['self_billing'] ) && ! $p->statement_deleted_at && '' !== (string) ( $storno ? $p->storno_file : $p->statement_file );
	}

	/**
	 * Whether a correction credit note has an e-invoice.
	 *
	 * @param object $c Correction.
	 * @return bool
	 */
	public static function correction_available( $c ) {
		$op = self::op( $c );
		return ! empty( $op['self_billing'] ) && ! $c->file_deleted_at && '' !== (string) $c->file;
	}

	/**
	 * XML of a credit note or cancellation (null = none, see available()).
	 *
	 * @param object $p      Payout.
	 * @param bool   $storno Cancellation.
	 * @return string|null
	 */
	public static function payout_xml( $p, $storno = false ) {
		if ( ! self::available( $p, $storno ) ) {
			return null;
		}
		$op       = self::op( $p );
		$switched = ! empty( $op['locale'] ) ? \WPAffiliateCaptain\Core\Locale::switch_to( (string) $op['locale'] ) : false;
		try {
			$data = self::payout_data( $p, $op, (bool) $storno );
			return null === $data ? null : self::finish( $data, $storno ? 'cancellation' : 'credit_note', $p );
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $switched );
		}
	}

	/**
	 * XML of a correction credit note (null = none).
	 *
	 * @param object $c Correction.
	 * @return string|null
	 */
	public static function correction_xml( $c ) {
		if ( ! self::correction_available( $c ) ) {
			return null;
		}
		$op       = self::op( $c );
		$switched = ! empty( $op['locale'] ) ? \WPAffiliateCaptain\Core\Locale::switch_to( (string) $op['locale'] ) : false;
		try {
			return self::finish( self::correction_data( $c, $op ), 'correction', $c );
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $switched );
		}
	}

	/**
	 * Filters, XML and the self-check (problems are logged, the XML is still delivered).
	 *
	 * @param array  $data     Document data.
	 * @param string $document credit_note|cancellation|correction.
	 * @param object $row      Payout or correction.
	 * @return string
	 */
	private static function finish( array $data, $document, $row ) {
		/**
		 * Data of an e-invoice before the XML is built (e.g. the seller's phone number for the
		 * XRechnung profile, the partner's IBAN for payment means 58). Keys: see EInvoice::xml().
		 *
		 * @param array  $data     Document data.
		 * @param string $document credit_note|cancellation|correction.
		 * @param object $row      Payout (credit note, cancellation) or correction.
		 */
		$data = (array) apply_filters( 'wpac_einvoice_data', $data, (string) $document, $row );
		$xml  = self::xml( $data );
		/** This filter is documented in docs/CONTRACT.md. */
		$xml      = (string) apply_filters( 'wpac_einvoice_xml', $xml, (string) $document, $row, $data );
		$problems = self::check( $xml );
		if ( $problems ) {
			\WPAffiliateCaptain\Core\Log::warning(
				'E-invoice does not pass all business rules',
				array(
					'document' => (string) $document,
					'number'   => isset( $data['number'] ) ? (string) $data['number'] : '',
					'rules'    => implode( ', ', array_keys( $problems ) ),
				)
			);
		}
		return $xml;
	}

	/**
	 * Document data of a credit note or its cancellation, mirroring Statement::render().
	 *
	 * @param object $p      Payout.
	 * @param array  $op     Operator snapshot.
	 * @param bool   $storno Cancellation.
	 * @return array|null Null when the document has no taxable line (only settled correction
	 *                    credit notes, repayments or write-offs: a payment notice, no invoice).
	 */
	public static function payout_data( $p, array $op, $storno ) {
		$country = self::country( $op );
		$correct = isset( $op['document'] ) && 'correction' === $op['document'];
		$snap    = json_decode( (string) $p->profile_snapshot, true );
		$snap    = is_array( $snap ) ? $snap : array();
		$lines   = array();
		$modes   = array();
		$rates   = array();
		$untaxed = 0;
		$refs    = array();
		$first   = '';
		$names   = array();
		foreach ( (array) PayoutService::items( (int) $p->id ) as $item ) {
			$mode = isset( $item->vat_mode ) && '' !== (string) $item->vat_mode ? (string) $item->vat_mode : (string) $p->vat_mode;
			$day  = self::day( get_date_from_gmt( (string) $item->commission_date ) );
			if ( '' === $first || ( '' !== $day && $day < $first ) ) {
				$first = $day;
			}
			if ( Corrections::KIND === (string) $item->kind || ( isset( $item->vat_mode ) && Vat::NONE === (string) $item->vat_mode ) ) {
				$untaxed += (int) $item->amount_minor; // No service: settled as the paid amount.
				continue;
			}
			$rate     = isset( $item->vat_rate ) && '' !== (string) $item->vat_rate ? (string) $item->vat_rate : (string) $p->vat_rate;
			$category = self::category( $mode, $rate );
			if ( '' !== $mode ) {
				$modes[] = $mode;
			}
			$rates[ Vat::rate_key( $rate ) ] = true; // Groups of the PDF (by rate).
			if ( in_array( (string) $item->kind, array( 'sale', 'bonus' ), true ) ) {
				$desc = (string) $item->description;
			} elseif ( in_array( (string) $item->kind, Vat::NON_TAXABLE_KINDS, true ) ) {
				$desc = \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $item->kind ) . ( '' !== (string) $item->description ? ': ' . $item->description : '' );
			} else {
				$desc = __( 'Correction', 'affiliate-captain' ) . ' (' . \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $item->kind ) . ')';
			}
			$note = array();
			if ( 'wpac' !== (string) $item->source && '' !== (string) $item->source_order_id ) {
				$note[] = __( 'Order', 'affiliate-captain' ) . ' #' . $item->source_order_id;
			}
			$ref = isset( $item->ref_payout_id ) ? (int) $item->ref_payout_id : 0;
			if ( $ref ) {
				if ( ! isset( $names[ $ref ] ) ) {
					$orig           = PayoutService::find( $ref );
					$names[ $ref ]  = $orig ? array( (string) $orig->number, self::day( get_date_from_gmt( (string) $orig->created_at ) ) ) : array( '#' . $ref, '' );
					$refs[]         = $names[ $ref ];
				}
				/* translators: 1: credit note number, 2: date */
				$note[] = sprintf( __( 'Correction of credit note %1$s of %2$s', 'affiliate-captain' ), $names[ $ref ][0], '' !== $names[ $ref ][1] ? self::display_date( $names[ $ref ][1], $op ) : '—' );
			}
			$lines[] = array(
				'name'     => '' !== trim( $desc ) ? $desc : __( 'Referral of customers (affiliate commission)', 'affiliate-captain' ),
				'note'     => implode( ' · ', $note ),
				'net'      => (int) $item->amount_minor,
				'category' => $category[0],
				'rate'     => $category[1],
				'date'     => $day,
			);
		}
		if ( ! $lines ) {
			return null;
		}
		// VAT as on the PDF: one rate group shows the stored VAT of the payout; several groups
		// show the VAT of each rate (Vat::amount()).
		$taxes = array();
		if ( count( $rates ) <= 1 ) {
			foreach ( $lines as $line ) {
				if ( in_array( $line['category'], array( 'S', 'Z' ), true ) ) {
					$taxes[ $line['category'] . '|' . $line['rate'] ] = (int) $p->vat_minor;
					break;
				}
			}
		}

		$self_note = '';
		if ( ! $storno ) {
			$self_note = Statement::self_billing_note( $country, $op );
		}
		$german = in_array( $country, array( 'DE', 'AT', 'CH' ), true );
		if ( $storno ) {
			/* translators: %s: original document number */
			$title = sprintf( __( 'Cancellation of credit note %s', 'affiliate-captain' ), $p->number );
		} elseif ( $correct ) {
			$title = $german ? __( 'Correction of credit notes (Gutschrift)', 'affiliate-captain' ) : __( 'Correction of credit notes', 'affiliate-captain' );
		} else {
			$title = $german ? __( 'Credit note (Gutschrift)', 'affiliate-captain' ) : __( 'Credit note (self-billing)', 'affiliate-captain' );
		}
		$notes = array_merge(
			array( $title ),
			Statement::vat_notes( (string) $p->vat_mode, $modes, $country, $op ),
			array_filter(
				array(
					$correct ? __( 'This document settles corrections of earlier credit notes (refunds and cancellations of referred orders) with the repayment received or the claim written off. It reduces the net amounts and VAT of the credit notes named above; nothing is transferred.', 'affiliate-captain' ) : '',
					$self_note,
					$storno ? __( 'This document cancels the credit note named above in full. The commissions remain available for a later payout.', 'affiliate-captain' ) : '',
					/* translators: %s: amount */
					$untaxed ? sprintf( __( 'Repayments, write-offs and settled correction credit notes (%s) are no service and not subject to VAT; they are shown as the amount already settled, so the amount due equals the payout amount.', 'affiliate-captain' ), html_entity_decode( Money::format( $untaxed, (string) $p->currency ), ENT_QUOTES, 'UTF-8' ) ) : '',
					self::copy_note(),
				)
			)
		);

		if ( $storno ) {
			$type      = self::CREDIT_NOTE;
			$preceding = array( (string) $p->number, self::day( get_date_from_gmt( (string) $p->created_at ) ) );
			$terms     = __( 'This document cancels the credit note named above in full. The commissions remain available for a later payout.', 'affiliate-captain' );
		} elseif ( $correct && $refs ) {
			$type      = self::CORRECTED;
			$preceding = $refs[0];
			$terms     = __( 'Balance (settled, no transfer)', 'affiliate-captain' );
		} else {
			$type      = self::SELF_BILLED;
			$preceding = null;
			$terms     = __( 'Paid by the issuer of this credit note (recipient of the service) by transfer with the payout; the partner does not have to pay anything.', 'affiliate-captain' );
		}
		$end  = self::day( (string) $p->period_end );
		$data = array(
			'number'          => $storno ? (string) $p->storno_number : (string) $p->number,
			'type'            => $type,
			'issue_date'      => self::day( get_date_from_gmt( (string) ( $storno && $p->storno_at ? $p->storno_at : $p->created_at ) ) ),
			'currency'        => (string) $p->currency,
			'notes'           => $notes,
			'buyer_reference' => self::buyer_reference( $storno ? (string) $p->storno_number : (string) $p->number ),
			'seller'          => self::seller( $snap, (int) $p->affiliate_id, $country ),
			'buyer'           => self::buyer( $op ),
			'period'          => array( '' !== $first && ( '' === $end || $first <= $end ) ? $first : '', $end ),
			'lines'           => $lines,
			'taxes'           => $taxes,
			'exemptions'      => self::exemptions( $country, $op ),
			'prepaid'         => -$untaxed,
			'payment'         => self::payment( $snap ),
			'terms'           => $terms,
			'preceding'       => $preceding,
		);
		return $data;
	}

	/**
	 * Document data of a correction credit note, mirroring Corrections::render(): one line with
	 * the difference (net and VAT) of the corrected part of the credit note.
	 *
	 * @param object $c  Correction.
	 * @param array  $op Operator snapshot.
	 * @return array
	 */
	public static function correction_data( $c, array $op ) {
		$country  = self::country( $op );
		$p        = PayoutService::find( (int) $c->payout_id );
		$snap     = json_decode( (string) $c->profile_snapshot, true );
		$snap     = is_array( $snap ) ? $snap : array();
		$cur      = (string) $c->currency;
		$orig_no  = $p ? (string) $p->number : (string) $c->base_number;
		$orig_day = $p ? self::day( get_date_from_gmt( (string) $p->created_at ) ) : '';
		$category = self::category( (string) $c->vat_mode, (string) $c->vat_rate );
		$diff     = (int) $c->net_minor + (int) $c->vat_minor - (int) $c->prev_net_minor - (int) $c->prev_vat_minor;
		$money    = static function ( $minor ) use ( $cur ) {
			return html_entity_decode( Money::format( (int) $minor, $cur ), ENT_QUOTES, 'UTF-8' );
		};
		$settle = $diff > 0
			/* translators: %s: amount */
			? sprintf( __( 'The difference of %s is credited to the partner account and paid with the next payout.', 'affiliate-captain' ), $money( $diff ) )
			/* translators: %s: amount */
			: sprintf( __( 'The overpayment of %s is debited to the partner account and offset against the next commissions.', 'affiliate-captain' ), $money( -$diff ) );
		$german = in_array( $country, array( 'DE', 'AT', 'CH' ), true );
		$notes  = array( $german ? __( 'Correction credit note (Gutschrift)', 'affiliate-captain' ) : __( 'Correction credit note (self-billing)', 'affiliate-captain' ) );
		if ( (string) $c->base_number !== $orig_no ) {
			$notes[] = __( 'Replaces correction', 'affiliate-captain' ) . ': ' . (string) $c->base_number;
		}
		$notes[] = __( 'Reason for the correction', 'affiliate-captain' ) . ': ' . (string) $c->reason;
		$notes[] = $settle;
		if ( isset( $op['parts'] ) && (int) $op['parts'] > 1 ) {
			/* translators: 1: credit note number, 2: VAT treatment or rate */
			$notes[] = sprintf( __( 'This correction concerns only the part of credit note %1$s with %2$s; the other parts of the credit note are unchanged.', 'affiliate-captain' ), $orig_no, Corrections::part_label( array( 'mode' => (string) $c->vat_mode, 'rate' => (string) $c->vat_rate ) ) );
		}
		$notes[] = Statement::vat_note( (string) $c->vat_mode, $country, $op );
		$notes[] = 'DE' === $country
			? __( 'This document corrects the credit note named above (self-billing, §14 Abs. 2 UStG); the change of the amounts is a correction under §17 UStG. The partner may object to it as to the credit note.', 'affiliate-captain' )
			: __( 'This document corrects the self-billing invoice named above (Art. 224 VAT Directive). The partner may object to it as to the original document.', 'affiliate-captain' );
		$notes[] = self::copy_note();
		$day     = self::day( get_date_from_gmt( (string) $c->created_at ) );
		return array(
			'number'          => (string) $c->number,
			'type'            => self::CORRECTED,
			'issue_date'      => $day,
			'currency'        => $cur,
			'notes'           => array_values( array_filter( $notes, 'strlen' ) ),
			'buyer_reference' => self::buyer_reference( (string) $c->number ),
			'seller'          => self::seller( $snap, (int) $c->affiliate_id, $country ),
			'buyer'           => self::buyer( $op ),
			'period'          => array( '', $p ? self::day( (string) $p->period_end ) : '' ),
			'lines'           => array(
				array(
					/* translators: 1: credit note number, 2: date */
					'name'     => sprintf( __( 'Correction of credit note %1$s of %2$s', 'affiliate-captain' ), $orig_no, '' !== $orig_day ? self::display_date( $orig_day, $op ) : '—' ),
					/* translators: 1: document number, 2: net amount before, 3: corrected net amount */
					'note'     => sprintf( __( 'Net amount before (%1$s): %2$s; corrected net amount: %3$s', 'affiliate-captain' ), (string) $c->base_number, $money( (int) $c->prev_net_minor ), $money( (int) $c->net_minor ) ),
					'net'      => (int) $c->net_minor - (int) $c->prev_net_minor,
					'category' => $category[0],
					'rate'     => $category[1],
					'date'     => '',
				),
			),
			// The VAT difference as on the PDF (VAT of the corrected net minus the VAT before).
			'taxes'           => array( $category[0] . '|' . $category[1] => (int) $c->vat_minor - (int) $c->prev_vat_minor ),
			'exemptions'      => self::exemptions( $country, $op ),
			'prepaid'         => 0,
			'payment'         => self::payment( $snap ),
			'terms'           => $settle,
			'preceding'       => array( $orig_no, $orig_day ),
		);
	}

	/**
	 * Note that the XML is the electronic invoice and the PDF a visual copy.
	 *
	 * @return string
	 */
	private static function copy_note() {
		return __( 'This XML file is the electronic invoice (EN 16931) of the document; the PDF with the same content is a visual copy.', 'affiliate-captain' );
	}

	/**
	 * Operator country of a snapshot (default DE, as on the PDF).
	 *
	 * @param array $op Operator snapshot.
	 * @return string
	 */
	private static function country( array $op ) {
		return strtoupper( isset( $op['country'] ) && '' !== (string) $op['country'] ? (string) $op['country'] : 'DE' );
	}

	/**
	 * Date as YYYYMMDD from a MySQL date or datetime ('' when empty).
	 *
	 * @param string $mysql Date.
	 * @return string
	 */
	private static function day( $mysql ) {
		$d = str_replace( '-', '', substr( trim( (string) $mysql ), 0, 10 ) );
		return 1 === preg_match( '/^\d{8}$/', $d ) && '00000000' !== $d ? $d : '';
	}

	/**
	 * Date in the document's date format.
	 *
	 * @param string $day YYYYMMDD.
	 * @param array  $op  Operator snapshot.
	 * @return string
	 */
	private static function display_date( $day, array $op ) {
		$format = ! empty( $op['date_format'] ) ? (string) $op['date_format'] : (string) get_option( 'date_format' );
		return mysql2date( $format, substr( $day, 0, 4 ) . '-' . substr( $day, 4, 2 ) . '-' . substr( $day, 6, 2 ) . ' 00:00:00' );
	}

	/**
	 * Buyer reference (BT-10): the Leitweg-ID or other reference of the operator from the
	 * settings, otherwise the document number (never a placeholder such as "n/a").
	 *
	 * @param string $number Document number.
	 * @return string
	 */
	private static function buyer_reference( $number ) {
		$ref = trim( (string) Settings::get( 'einvoice_buyer_reference' ) );
		return '' !== $ref ? $ref : (string) $number;
	}

	/**
	 * Seller (the partner) from the frozen profile snapshot.
	 *
	 * @param array  $snap         Profile snapshot.
	 * @param int    $affiliate_id Partner.
	 * @param string $country      Operator country (partner without country: domestic, as Vat::treatment()).
	 * @return array
	 */
	private static function seller( array $snap, $affiliate_id, $country ) {
		$aff     = AffiliateRepository::find( (int) $affiliate_id );
		$address = self::split_address( isset( $snap['address'] ) ? (string) $snap['address'] : '' );
		$email   = $aff && is_email( (string) $aff->email ) && 0 !== strpos( (string) $aff->email, 'deleted-' ) ? (string) $aff->email : '';
		$name    = ! empty( $snap['legal_name'] ) ? (string) $snap['legal_name'] : ( $aff ? (string) $aff->name : '' );
		return array(
			'id'         => (string) (int) $affiliate_id,
			'name'       => $name,
			'lines'      => $address['lines'],
			'postcode'   => $address['postcode'],
			'city'       => $address['city'],
			'country'    => strtoupper( ! empty( $snap['country'] ) ? (string) $snap['country'] : $country ),
			'vat_id'     => isset( $snap['vat_id'] ) ? (string) $snap['vat_id'] : '',
			'tax_number' => isset( $snap['tax_number'] ) ? (string) $snap['tax_number'] : '',
			'email'      => $email,
			'phone'      => '',
			'contact'    => $name,
		);
	}

	/**
	 * Buyer (the operator) from the frozen operator snapshot; e-mail from the settings.
	 *
	 * @param array $op Operator snapshot.
	 * @return array
	 */
	private static function buyer( array $op ) {
		$address = self::split_address( isset( $op['address'] ) ? (string) $op['address'] : '' );
		$email   = (string) Settings::get( 'admin_email' );
		$email   = is_email( $email ) ? $email : (string) get_option( 'admin_email' );
		return array(
			'name'     => isset( $op['name'] ) ? (string) $op['name'] : '',
			'lines'    => $address['lines'],
			'postcode' => $address['postcode'],
			'city'     => $address['city'],
			'country'  => self::country( $op ),
			'vat_id'   => isset( $op['vat_id'] ) ? (string) $op['vat_id'] : '',
			'email'    => is_email( $email ) ? $email : '',
		);
	}

	/**
	 * Exemption reasons (BT-120/BT-121) per VAT category, as the notes of the PDF.
	 *
	 * @param string $country Operator country.
	 * @param array  $op      Operator snapshot (own notes frozen with the document win).
	 * @return array category => [ text, code ]
	 */
	private static function exemptions( $country, array $op = array() ) {
		$own_small = '' !== Statement::own_note( $op, Vat::SMALL_BUSINESS );
		return array(
			'AE' => array( Statement::vat_note( Vat::REVERSE_CHARGE, $country, $op ), 'VATEX-EU-AE' ),
			'E'  => array( 'DE' === $country && ! $own_small ? self::SMALL_BUSINESS_DE : Statement::vat_note( Vat::SMALL_BUSINESS, $country, $op ), '' ),
			'O'  => array( Statement::vat_note( Vat::PRIVATE_PERSON, $country, $op ), 'VATEX-EU-O' ),
		);
	}

	/**
	 * Payment means (BG-16): SEPA credit transfer (58) with the partner's IBAN only when the
	 * snapshot holds it (it never does by itself: only the masked IBAN is frozen, filter
	 * wpac_einvoice_data); otherwise "mutually defined" (ZZZ) with the masked account as text.
	 *
	 * @param array $snap Profile snapshot.
	 * @return array code, info, iban, holder, bic
	 */
	private static function payment( array $snap ) {
		$manual = isset( $snap['payout_method'] ) && ProfileRepository::METHOD_MANUAL === $snap['payout_method'];
		$iban   = isset( $snap['iban'] ) ? strtoupper( preg_replace( '/\s+/', '', (string) $snap['iban'] ) ) : '';
		if ( ! $manual && '' !== $iban ) {
			return array(
				'code'   => '58',
				'info'   => __( 'SEPA credit transfer', 'affiliate-captain' ),
				'iban'   => $iban,
				'holder' => isset( $snap['account_holder'] ) ? (string) $snap['account_holder'] : '',
				'bic'    => isset( $snap['bic'] ) ? (string) $snap['bic'] : '',
			);
		}
		if ( $manual ) {
			$info = __( 'Other payment method as agreed with the partner', 'affiliate-captain' );
		} else {
			/* translators: %s: masked IBAN */
			$info = sprintf( __( 'SEPA credit transfer to the partner\'s account %s', 'affiliate-captain' ), ! empty( $snap['iban_masked'] ) ? (string) $snap['iban_masked'] : '—' );
		}
		return array(
			'code'   => 'ZZZ',
			'info'   => $info,
			'iban'   => '',
			'holder' => '',
			'bic'    => '',
		);
	}

	/**
	 * Stream the XML of a credit note or cancellation (caller checks permissions) and exit.
	 *
	 * @param object $p      Payout.
	 * @param bool   $storno Cancellation.
	 */
	public static function send_payout( $p, $storno = false ) {
		self::stream( self::payout_xml( $p, $storno ), $storno ? (string) $p->storno_number : (string) $p->number );
	}

	/**
	 * Stream the XML of a correction credit note (caller checks permissions) and exit.
	 *
	 * @param object $c Correction.
	 */
	public static function send_correction( $c ) {
		self::stream( self::correction_xml( $c ), (string) $c->number );
	}

	/**
	 * Send an XML download.
	 *
	 * @param string|null $xml    XML.
	 * @param string      $number Document number (file name).
	 */
	private static function stream( $xml, $number ) {
		if ( null === $xml ) {
			wp_die( esc_html__( 'There is no electronic invoice for this document (no self-billing credit note, no taxable line, or deleted after the retention period).', 'affiliate-captain' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $number . '.xml' ) . '"' );
		header( 'Content-Length: ' . strlen( (string) $xml ) );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document built with DOMDocument.
		exit;
	}

	/* ---------------------------------------------------------------------------------------
	 * XML (no WordPress dependency).
	 * ------------------------------------------------------------------------------------- */

	/**
	 * VAT category (UNCL 5305) and rate of a treatment.
	 *
	 * @param string $mode Treatment (Vat::*).
	 * @param string $rate Rate.
	 * @return string[] [ category, rate ('' = no rate: category O) ]
	 */
	public static function category( $mode, $rate ) {
		switch ( (string) $mode ) {
			case Vat::REVERSE_CHARGE:
				return array( 'AE', '0' );
			case Vat::SMALL_BUSINESS:
				return array( 'E', '0' );
			case Vat::PRIVATE_PERSON:
			case Vat::NONE:
				return array( 'O', '' );
		}
		$rate = Vat::rate_key( (string) $rate );
		return is_numeric( $rate ) && (float) $rate > 0 ? array( 'S', $rate ) : array( 'Z', '0' );
	}

	/**
	 * Split a free-text address (lines as entered) into street lines, postcode and city: the last
	 * line (or the one before, when the last names the country) of the form "12345 City",
	 * "A-1010 Wien" or "1234 AB Amsterdam". Unknown formats keep all lines and no postcode/city.
	 *
	 * @param string $text Address.
	 * @return array lines (max. 3), postcode, city
	 */
	public static function split_address( $text ) {
		$lines    = array_values( array_filter( array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' ) );
		$postcode = '';
		$city     = '';
		for ( $i = count( $lines ) - 1; $i >= max( 0, count( $lines ) - 2 ); $i-- ) {
			if ( preg_match( '/^(?:[A-Z]{1,2}[\- ])?(\d{4}\s?[A-Z]{2}|\d{3}\s?\d{2}|\d{4,6})\s+(\S.*)$/u', $lines[ $i ], $m ) ) {
				$postcode = $m[1];
				$city     = trim( $m[2] );
				array_splice( $lines, $i, count( $lines ) - $i ); // The country line after it is given separately.
				break;
			}
		}
		if ( count( $lines ) > 3 ) {
			$lines = array( $lines[0], $lines[1], implode( ', ', array_slice( $lines, 2 ) ) );
		}
		return array(
			'lines'    => $lines,
			'postcode' => $postcode,
			'city'     => $city,
		);
	}

	/**
	 * Specification identifier: XRechnung when its additional data are complete, otherwise the
	 * EN 16931 core profile.
	 *
	 * @param array $d Document data.
	 * @return string
	 */
	public static function profile( array $d ) {
		$s = isset( $d['seller'] ) ? (array) $d['seller'] : array();
		$b = isset( $d['buyer'] ) ? (array) $d['buyer'] : array();
		foreach ( array( array( $s, 'email' ), array( $s, 'phone' ), array( $s, 'postcode' ), array( $s, 'city' ), array( $b, 'email' ), array( $b, 'postcode' ), array( $b, 'city' ) ) as $need ) {
			if ( empty( $need[0][ $need[1] ] ) ) {
				return self::EN16931;
			}
		}
		return empty( $d['buyer_reference'] ) ? self::EN16931 : self::XRECHNUNG;
	}

	/**
	 * VAT breakdown and document totals.
	 *
	 * @param array $d Document data (lines, taxes, prepaid).
	 * @return array groups (key => category, rate, basis, tax), lines, tax, grand, prepaid, due (minor units).
	 */
	public static function totals( array $d ) {
		$groups = array();
		$sum    = 0;
		foreach ( (array) $d['lines'] as $line ) {
			$key = $line['category'] . '|' . $line['rate'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'category' => (string) $line['category'],
					'rate'     => (string) $line['rate'],
					'basis'    => 0,
					'tax'      => 0,
				);
			}
			$groups[ $key ]['basis'] += (int) $line['net'];
			$sum                     += (int) $line['net'];
		}
		$tax = 0;
		foreach ( $groups as $key => $g ) {
			if ( isset( $d['taxes'][ $key ] ) ) {
				$groups[ $key ]['tax'] = (int) $d['taxes'][ $key ];
			} elseif ( 'S' === $g['category'] ) {
				$groups[ $key ]['tax'] = Vat::amount( $g['basis'], $g['rate'] );
			}
			$tax += $groups[ $key ]['tax'];
		}
		$prepaid = isset( $d['prepaid'] ) ? (int) $d['prepaid'] : 0;
		return array(
			'groups'  => $groups,
			'lines'   => $sum,
			'tax'     => $tax,
			'grand'   => $sum + $tax,
			'prepaid' => $prepaid,
			'due'     => $sum + $tax - $prepaid,
		);
	}

	/**
	 * Build the CII XML of a document.
	 *
	 * Data keys: number, type (389|384|381), issue_date (YYYYMMDD), currency, notes (string[]),
	 * buyer_reference, seller (id, name, lines, postcode, city, country, vat_id, tax_number,
	 * email, phone, contact), buyer (name, lines, postcode, city, country, vat_id, email),
	 * period [ start, end ] (YYYYMMDD or ''), lines (name, note, net, category, rate), taxes
	 * (optional: "category|rate" => VAT in minor units), exemptions (category => [ text, code ]),
	 * prepaid (minor units), payment (code, info, iban, holder, bic), terms, preceding
	 * ([ number, YYYYMMDD ] or null). Amounts in minor units of the currency.
	 *
	 * @param array $d Document data.
	 * @return string
	 */
	public static function xml( array $d ) {
		$d += array(
			'notes'      => array(),
			'taxes'      => array(),
			'exemptions' => array(),
			'prepaid'    => 0,
			'period'     => array( '', '' ),
			'preceding'  => null,
			'terms'      => '',
		);
		$cur    = (string) $d['currency'];
		$t      = self::totals( $d );
		$amount = static function ( $minor ) use ( $cur ) {
			return Money::to_decimal( (int) $minor, $cur );
		};
		$no_vat = false; // BR-O-02: no VAT identifiers with category O.
		foreach ( $t['groups'] as $g ) {
			$no_vat = $no_vat || 'O' === $g['category'];
		}

		$dom               = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;
		$root              = $dom->createElementNS( self::NS_RSM, 'rsm:CrossIndustryInvoice' );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::NS_QDT );
		$dom->appendChild( $root );
		// Element with an optional text value (set as a text node, never as markup).
		$el = static function ( \DOMElement $parent, $name, $value = null, array $attrs = array() ) use ( $dom ) {
			$prefix = strstr( $name, ':', true );
			$ns     = array(
				'rsm' => self::NS_RSM,
				'ram' => self::NS_RAM,
				'udt' => self::NS_UDT,
				'qdt' => self::NS_QDT,
			);
			$node = $dom->createElementNS( $ns[ $prefix ], $name );
			if ( null !== $value ) {
				$node->appendChild( $dom->createTextNode( self::clean( (string) $value ) ) );
			}
			foreach ( $attrs as $k => $v ) {
				$node->setAttribute( $k, self::clean( (string) $v ) );
			}
			$parent->appendChild( $node );
			return $node;
		};
		$date = static function ( \DOMElement $parent, $name, $day, $type = 'udt' ) use ( $el ) {
			$wrap = $el( $parent, $name );
			$el( $wrap, $type . ':DateTimeString', (string) $day, array( 'format' => '102' ) );
			return $wrap;
		};

		// Context.
		$ctx = $el( $root, 'rsm:ExchangedDocumentContext' );
		$el( $el( $ctx, 'ram:BusinessProcessSpecifiedDocumentContextParameter' ), 'ram:ID', self::PROCESS );
		$el( $el( $ctx, 'ram:GuidelineSpecifiedDocumentContextParameter' ), 'ram:ID', self::profile( $d ) );

		// Document.
		$doc = $el( $root, 'rsm:ExchangedDocument' );
		$el( $doc, 'ram:ID', (string) $d['number'] );
		$el( $doc, 'ram:TypeCode', (string) $d['type'] );
		$date( $doc, 'ram:IssueDateTime', (string) $d['issue_date'] );
		foreach ( (array) $d['notes'] as $note ) {
			if ( '' !== trim( (string) $note ) ) {
				$el( $el( $doc, 'ram:IncludedNote' ), 'ram:Content', trim( (string) $note ) );
			}
		}

		$tx = $el( $root, 'rsm:SupplyChainTradeTransaction' );
		// Lines.
		$n = 0;
		foreach ( (array) $d['lines'] as $line ) {
			++$n;
			$net  = (int) $line['net'];
			$item = $el( $tx, 'ram:IncludedSupplyChainTradeLineItem' );
			$ld   = $el( $item, 'ram:AssociatedDocumentLineDocument' );
			$el( $ld, 'ram:LineID', (string) $n );
			if ( ! empty( $line['note'] ) ) {
				$el( $el( $ld, 'ram:IncludedNote' ), 'ram:Content', (string) $line['note'] );
			}
			$el( $el( $item, 'ram:SpecifiedTradeProduct' ), 'ram:Name', (string) $line['name'] );
			// BR-27: the price is never negative; a negative line has the quantity -1.
			$el( $el( $el( $item, 'ram:SpecifiedLineTradeAgreement' ), 'ram:NetPriceProductTradePrice' ), 'ram:ChargeAmount', $amount( abs( $net ) ) );
			$el( $el( $item, 'ram:SpecifiedLineTradeDelivery' ), 'ram:BilledQuantity', $net < 0 ? '-1' : '1', array( 'unitCode' => 'C62' ) );
			$ls  = $el( $item, 'ram:SpecifiedLineTradeSettlement' );
			$tax = $el( $ls, 'ram:ApplicableTradeTax' );
			$el( $tax, 'ram:TypeCode', 'VAT' );
			$el( $tax, 'ram:CategoryCode', (string) $line['category'] );
			if ( 'O' !== $line['category'] ) {
				$el( $tax, 'ram:RateApplicablePercent', (string) $line['rate'] );
			}
			$el( $el( $ls, 'ram:SpecifiedTradeSettlementLineMonetarySummation' ), 'ram:LineTotalAmount', $amount( $net ) );
		}

		// Agreement: buyer reference, seller, buyer.
		$ag = $el( $tx, 'ram:ApplicableHeaderTradeAgreement' );
		$el( $ag, 'ram:BuyerReference', (string) $d['buyer_reference'] );
		$s      = (array) $d['seller'];
		$seller = $el( $ag, 'ram:SellerTradeParty' );
		if ( ! empty( $s['id'] ) ) {
			$el( $seller, 'ram:ID', (string) $s['id'] );
		}
		$el( $seller, 'ram:Name', (string) $s['name'] );
		if ( ! empty( $s['email'] ) || ! empty( $s['phone'] ) ) {
			$contact = $el( $seller, 'ram:DefinedTradeContact' );
			$el( $contact, 'ram:PersonName', ! empty( $s['contact'] ) ? (string) $s['contact'] : (string) $s['name'] );
			if ( ! empty( $s['phone'] ) ) {
				$el( $el( $contact, 'ram:TelephoneUniversalCommunication' ), 'ram:CompleteNumber', (string) $s['phone'] );
			}
			if ( ! empty( $s['email'] ) ) {
				$el( $el( $contact, 'ram:EmailURIUniversalCommunication' ), 'ram:URIID', (string) $s['email'] );
			}
		}
		self::address( $el, $seller, $s );
		if ( ! empty( $s['email'] ) ) {
			$el( $el( $seller, 'ram:URIUniversalCommunication' ), 'ram:URIID', (string) $s['email'], array( 'schemeID' => 'EM' ) );
		}
		if ( ! empty( $s['tax_number'] ) ) {
			$el( $el( $seller, 'ram:SpecifiedTaxRegistration' ), 'ram:ID', (string) $s['tax_number'], array( 'schemeID' => 'FC' ) );
		}
		if ( ! empty( $s['vat_id'] ) && ! $no_vat ) {
			$el( $el( $seller, 'ram:SpecifiedTaxRegistration' ), 'ram:ID', (string) $s['vat_id'], array( 'schemeID' => 'VA' ) );
		}
		$b     = (array) $d['buyer'];
		$buyer = $el( $ag, 'ram:BuyerTradeParty' );
		$el( $buyer, 'ram:Name', (string) $b['name'] );
		self::address( $el, $buyer, $b );
		if ( ! empty( $b['email'] ) ) {
			$el( $el( $buyer, 'ram:URIUniversalCommunication' ), 'ram:URIID', (string) $b['email'], array( 'schemeID' => 'EM' ) );
		}
		if ( ! empty( $b['vat_id'] ) && ! $no_vat ) {
			$el( $el( $buyer, 'ram:SpecifiedTaxRegistration' ), 'ram:ID', (string) $b['vat_id'], array( 'schemeID' => 'VA' ) );
		}

		$el( $tx, 'ram:ApplicableHeaderTradeDelivery' );

		// Settlement.
		$st = $el( $tx, 'ram:ApplicableHeaderTradeSettlement' );
		$el( $st, 'ram:InvoiceCurrencyCode', $cur );
		$pay   = (array) $d['payment'];
		$means = $el( $st, 'ram:SpecifiedTradeSettlementPaymentMeans' );
		$el( $means, 'ram:TypeCode', (string) $pay['code'] );
		if ( ! empty( $pay['info'] ) ) {
			$el( $means, 'ram:Information', (string) $pay['info'] );
		}
		if ( ! empty( $pay['iban'] ) ) {
			$acc = $el( $means, 'ram:PayeePartyCreditorFinancialAccount' );
			$el( $acc, 'ram:IBANID', (string) $pay['iban'] );
			if ( ! empty( $pay['holder'] ) ) {
				$el( $acc, 'ram:AccountName', (string) $pay['holder'] );
			}
			if ( ! empty( $pay['bic'] ) ) {
				$el( $el( $means, 'ram:PayeeSpecifiedCreditorFinancialInstitution' ), 'ram:BICID', (string) $pay['bic'] );
			}
		}
		foreach ( $t['groups'] as $g ) {
			$tax = $el( $st, 'ram:ApplicableTradeTax' );
			$el( $tax, 'ram:CalculatedAmount', $amount( $g['tax'] ) );
			$el( $tax, 'ram:TypeCode', 'VAT' );
			$reason = isset( $d['exemptions'][ $g['category'] ] ) ? (array) $d['exemptions'][ $g['category'] ] : array( '', '' );
			if ( ! in_array( $g['category'], array( 'S', 'Z' ), true ) && '' !== (string) $reason[0] ) {
				$el( $tax, 'ram:ExemptionReason', (string) $reason[0] );
			}
			$el( $tax, 'ram:BasisAmount', $amount( $g['basis'] ) );
			$el( $tax, 'ram:CategoryCode', $g['category'] );
			if ( ! in_array( $g['category'], array( 'S', 'Z' ), true ) && ! empty( $reason[1] ) ) {
				$el( $tax, 'ram:ExemptionReasonCode', (string) $reason[1] );
			}
			if ( 'O' !== $g['category'] ) {
				$el( $tax, 'ram:RateApplicablePercent', $g['rate'] );
			}
		}
		$period = (array) $d['period'];
		if ( ! empty( $period[0] ) || ! empty( $period[1] ) ) {
			$bp = $el( $st, 'ram:BillingSpecifiedPeriod' );
			if ( ! empty( $period[0] ) ) {
				$date( $bp, 'ram:StartDateTime', (string) $period[0] );
			}
			if ( ! empty( $period[1] ) ) {
				$date( $bp, 'ram:EndDateTime', (string) $period[1] );
			}
		}
		if ( '' !== trim( (string) $d['terms'] ) ) {
			$el( $el( $st, 'ram:SpecifiedTradePaymentTerms' ), 'ram:Description', trim( (string) $d['terms'] ) );
		}
		$sum = $el( $st, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation' );
		$el( $sum, 'ram:LineTotalAmount', $amount( $t['lines'] ) );
		$el( $sum, 'ram:TaxBasisTotalAmount', $amount( $t['lines'] ) );
		$el( $sum, 'ram:TaxTotalAmount', $amount( $t['tax'] ), array( 'currencyID' => $cur ) );
		$el( $sum, 'ram:GrandTotalAmount', $amount( $t['grand'] ) );
		if ( 0 !== $t['prepaid'] ) {
			$el( $sum, 'ram:TotalPrepaidAmount', $amount( $t['prepaid'] ) );
		}
		$el( $sum, 'ram:DuePayableAmount', $amount( $t['due'] ) );
		if ( is_array( $d['preceding'] ) && '' !== (string) $d['preceding'][0] ) {
			$ref = $el( $st, 'ram:InvoiceReferencedDocument' );
			$el( $ref, 'ram:IssuerAssignedID', (string) $d['preceding'][0] );
			if ( ! empty( $d['preceding'][1] ) ) {
				$date( $ref, 'ram:FormattedIssueDateTime', (string) $d['preceding'][1], 'qdt' );
			}
		}
		return (string) $dom->saveXML();
	}

	/**
	 * Postal address of a party (BG-5/BG-8; the country is mandatory).
	 *
	 * @param callable    $el    Element factory.
	 * @param \DOMElement $party Party.
	 * @param array       $p     Party data.
	 */
	private static function address( callable $el, \DOMElement $party, array $p ) {
		$addr = $el( $party, 'ram:PostalTradeAddress' );
		if ( ! empty( $p['postcode'] ) ) {
			$el( $addr, 'ram:PostcodeCode', (string) $p['postcode'] );
		}
		$names = array( 'ram:LineOne', 'ram:LineTwo', 'ram:LineThree' );
		foreach ( array_slice( array_values( (array) ( isset( $p['lines'] ) ? $p['lines'] : array() ) ), 0, 3 ) as $i => $line ) {
			$el( $addr, $names[ $i ], (string) $line );
		}
		if ( ! empty( $p['city'] ) ) {
			$el( $addr, 'ram:CityName', (string) $p['city'] );
		}
		$el( $addr, 'ram:CountryID', strtoupper( (string) $p['country'] ) );
	}

	/**
	 * Text without characters XML 1.0 does not allow (and valid UTF-8).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		$text = (string) $text;
		if ( ! preg_match( '//u', $text ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
	}

	/**
	 * Check the main business rules of EN 16931 (and XRechnung, when declared) on an XML – a
	 * stand-in for the KoSIT validator at runtime (the CI validates samples with the real one,
	 * tests/einvoice): required elements,
	 * totals (BR-CO-10/13/14/15/16/17), VAT breakdown per category (BR-S-08, BR-AE-08, BR-E-08,
	 * BR-O-08, BR-Z-08), exemption reasons, VAT identifiers, decimals, payment means.
	 *
	 * @param string $xml XML.
	 * @return array Rule => message (empty = no problem found).
	 */
	public static function check( $xml ) {
		$out = array();
		$dom = new \DOMDocument();
		if ( '' === trim( (string) $xml ) || ! @$dom->loadXML( (string) $xml ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( 'XML' => 'not well-formed' );
		}
		$x = new \DOMXPath( $dom );
		$x->registerNamespace( 'rsm', self::NS_RSM );
		$x->registerNamespace( 'ram', self::NS_RAM );
		$x->registerNamespace( 'udt', self::NS_UDT );
		$x->registerNamespace( 'qdt', self::NS_QDT );
		$val  = static function ( $path, $ctx = null ) use ( $x ) {
			$nodes = null === $ctx ? $x->query( $path ) : $x->query( $path, $ctx );
			return $nodes && $nodes->length ? trim( (string) $nodes->item( 0 )->textContent ) : '';
		};
		$cent = static function ( $value ) {
			$value = trim( (string) $value );
			if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m ) ) {
				return null;
			}
			$minor = (int) $m[2] * 100 + (int) str_pad( isset( $m[3] ) ? $m[3] : '', 2, '0' );
			return '-' === $m[1] ? -$minor : $minor;
		};
		$st   = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
		$ag   = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';
		$sum  = $st . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
		$spec = $val( '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' );

		// Required elements (BR-01 … BR-13, BR-16, BR-CO-26).
		$required = array(
			'BR-01' => array( $spec, 'specification identifier' ),
			'BR-02' => array( $val( '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID' ), 'invoice number' ),
			'BR-03' => array( $val( '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString' ), 'issue date' ),
			'BR-04' => array( $val( '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode' ), 'type code' ),
			'BR-05' => array( $val( $st . '/ram:InvoiceCurrencyCode' ), 'currency' ),
			'BR-06' => array( $val( $ag . '/ram:SellerTradeParty/ram:Name' ), 'seller name' ),
			'BR-07' => array( $val( $ag . '/ram:BuyerTradeParty/ram:Name' ), 'buyer name' ),
			'BR-09' => array( $val( $ag . '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID' ), 'seller country' ),
			'BR-11' => array( $val( $ag . '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID' ), 'buyer country' ),
			'BR-12' => array( $val( $sum . '/ram:LineTotalAmount' ), 'sum of line net amounts' ),
			'BR-13' => array( $val( $sum . '/ram:TaxBasisTotalAmount' ), 'total without VAT' ),
			'BR-14' => array( $val( $sum . '/ram:GrandTotalAmount' ), 'total with VAT' ),
			'BR-15' => array( $val( $sum . '/ram:DuePayableAmount' ), 'amount due' ),
		);
		foreach ( $required as $rule => $r ) {
			if ( '' === $r[0] ) {
				$out[ $rule ] = 'missing ' . $r[1];
			}
		}
		if ( ! in_array( $val( '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode' ), array( '326', '380', '384', '389', '381', '875', '876', '877' ), true ) ) {
			$out['BR-DE-17'] = 'type code not allowed';
		}
		if ( '' === $val( $ag . '/ram:SellerTradeParty/ram:ID' ) && '' === $val( $ag . '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]' ) && '' === $val( $ag . '/ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID' ) ) {
			$out['BR-CO-26'] = 'seller identifier, legal registration or VAT ID missing';
		}
		$tax_total = $x->query( $sum . '/ram:TaxTotalAmount' );
		$tax_node  = $tax_total && 1 === $tax_total->length ? $tax_total->item( 0 ) : null;
		if ( ! $tax_node instanceof \DOMElement || '' === trim( (string) $tax_node->textContent ) || $tax_node->getAttribute( 'currencyID' ) !== $val( $st . '/ram:InvoiceCurrencyCode' ) ) {
			$out['BR-CO-14'] = 'invoice total VAT amount missing or without currency';
		}
		$means = $val( $st . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode' );
		if ( '' === $means ) {
			$out['BR-49'] = 'payment means type code missing';
		} elseif ( in_array( $means, array( '30', '58' ), true ) && '' === $val( $st . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID' ) . $val( $st . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:ProprietaryID' ) ) {
			$out['BR-61'] = 'credit transfer without payment account';
		}

		// Decimals: at most 2 (BR-DEC-*).
		foreach ( array( '//ram:LineTotalAmount', '//ram:ChargeAmount', '//ram:CalculatedAmount', '//ram:BasisAmount', '//ram:TaxBasisTotalAmount', '//ram:TaxTotalAmount', '//ram:GrandTotalAmount', '//ram:TotalPrepaidAmount', '//ram:DuePayableAmount' ) as $path ) {
			foreach ( $x->query( $path ) as $node ) {
				if ( null === $cent( $node->textContent ) ) {
					$out['BR-DEC'] = 'amount with more than 2 decimals or empty: ' . $node->nodeName;
				}
			}
		}

		// Lines (BR-16, BR-21 … BR-27) and sums per VAT category.
		$lines     = $x->query( '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem' );
		$line_sum  = 0;
		$per_group = array();
		$cats      = array();
		if ( ! $lines || ! $lines->length ) {
			$out['BR-16'] = 'no invoice line';
		}
		foreach ( $lines ? $lines : array() as $line ) {
			$id   = $val( 'ram:AssociatedDocumentLineDocument/ram:LineID', $line );
			$name = $val( 'ram:SpecifiedTradeProduct/ram:Name', $line );
			$net  = $cent( $val( 'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line ) );
			$cat  = $val( 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', $line );
			$rate = $val( 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $line );
			if ( '' === $id ) {
				$out['BR-21'] = 'line without identifier';
			}
			if ( '' === $name ) {
				$out['BR-25'] = 'line without item name';
			}
			if ( null === $net ) {
				$out['BR-24'] = 'line without net amount';
				$net          = 0;
			}
			if ( '' === $cat ) {
				$out['BR-CO-04'] = 'line without VAT category';
			}
			$price = $cent( $val( 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $line ) );
			$qty   = $val( 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $line );
			if ( null === $price || $price < 0 ) {
				$out['BR-27'] = 'item net price missing or negative';
			} elseif ( '' === $qty || abs( (float) $qty * $price - $net ) > 0 ) {
				$out['BR-LINE'] = 'line net amount is not quantity times price';
			}
			if ( 'O' !== $cat && '' === $rate ) {
				$out['BR-' . $cat . '-05'] = 'line without VAT rate';
			}
			if ( in_array( $cat, array( 'AE', 'E', 'Z' ), true ) && 0.0 !== (float) $rate ) {
				$out[ 'BR-' . $cat . '-05' ] = 'line VAT rate must be 0';
			}
			if ( 'O' === $cat && '' !== $rate ) {
				$out['BR-O-05'] = 'line with category O must not have a VAT rate';
			}
			$key               = $cat . '|' . ( '' === $rate ? '' : rtrim( rtrim( number_format( (float) $rate, 2, '.', '' ), '0' ), '.' ) );
			$per_group[ $key ] = ( isset( $per_group[ $key ] ) ? $per_group[ $key ] : 0 ) + $net;
			$cats[ $cat ]      = true;
			$line_sum         += $net;
		}

		$line_total = $cent( $val( $sum . '/ram:LineTotalAmount' ) );
		$basis      = $cent( $val( $sum . '/ram:TaxBasisTotalAmount' ) );
		$tax        = $tax_total && $tax_total->length ? $cent( $tax_total->item( 0 )->textContent ) : null;
		$grand      = $cent( $val( $sum . '/ram:GrandTotalAmount' ) );
		$prepaid    = '' === $val( $sum . '/ram:TotalPrepaidAmount' ) ? 0 : $cent( $val( $sum . '/ram:TotalPrepaidAmount' ) );
		$rounding   = '' === $val( $sum . '/ram:RoundingAmount' ) ? 0 : $cent( $val( $sum . '/ram:RoundingAmount' ) );
		$allowance  = '' === $val( $sum . '/ram:AllowanceTotalAmount' ) ? 0 : $cent( $val( $sum . '/ram:AllowanceTotalAmount' ) );
		$charge     = '' === $val( $sum . '/ram:ChargeTotalAmount' ) ? 0 : $cent( $val( $sum . '/ram:ChargeTotalAmount' ) );
		$due        = $cent( $val( $sum . '/ram:DuePayableAmount' ) );
		if ( $line_total !== $line_sum ) {
			$out['BR-CO-10'] = 'sum of line net amounts ' . $line_sum . ' <> ' . ( null === $line_total ? 'missing' : $line_total );
		}
		if ( null === $basis || $basis !== (int) $line_total - (int) $allowance + (int) $charge ) {
			$out['BR-CO-13'] = 'total without VAT is not lines - allowances + charges';
		}
		if ( null === $grand || $grand !== (int) $basis + (int) $tax ) {
			$out['BR-CO-15'] = 'total with VAT is not total without VAT + VAT';
		}
		if ( null === $due || $due !== (int) $grand - (int) $prepaid + (int) $rounding ) {
			$out['BR-CO-16'] = 'amount due is not total - paid amount + rounding';
		}
		if ( null !== $due && $due > 0 && '' === $val( $st . '/ram:SpecifiedTradePaymentTerms/ram:Description' ) && '' === $val( $st . '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString' ) ) {
			$out['BR-CO-25'] = 'positive amount due without payment terms or due date';
		}

		// VAT breakdown (BR-CO-14/17/18, BR-<cat>-08/09/10).
		$breakdown = $x->query( $st . '/ram:ApplicableTradeTax' );
		$tax_sum   = 0;
		$seen      = array();
		foreach ( $breakdown ? $breakdown : array() as $g ) {
			$cat    = $val( 'ram:CategoryCode', $g );
			$rate   = $val( 'ram:RateApplicablePercent', $g );
			$gbasis = $cent( $val( 'ram:BasisAmount', $g ) );
			$gtax   = $cent( $val( 'ram:CalculatedAmount', $g ) );
			$key    = $cat . '|' . ( '' === $rate ? '' : rtrim( rtrim( number_format( (float) $rate, 2, '.', '' ), '0' ), '.' ) );
			$seen[ $key ] = true;
			$tax_sum     += (int) $gtax;
			if ( null === $gbasis || null === $gtax ) {
				$out['BR-45'] = 'VAT breakdown without taxable or tax amount';
				continue;
			}
			if ( ! isset( $per_group[ $key ] ) || $per_group[ $key ] !== $gbasis ) {
				$out[ 'BR-' . $cat . '-08' ] = 'taxable amount of ' . $key . ' is not the sum of its lines';
			}
			if ( 'S' === $cat ) {
				// BR-CO-17 (the schematron allows a deviation below one currency unit).
				$expected = Money::percent_of( $gbasis, $rate );
				if ( abs( $expected - $gtax ) >= 100 ) {
					$out['BR-CO-17'] = 'VAT of ' . $key . ' is not taxable amount x rate';
				}
			} elseif ( 0 !== $gtax ) {
				$out[ 'BR-' . $cat . '-09' ] = 'VAT amount of category ' . $cat . ' must be 0';
			}
			if ( in_array( $cat, array( 'AE', 'E', 'O' ), true ) && '' === $val( 'ram:ExemptionReason', $g ) && '' === $val( 'ram:ExemptionReasonCode', $g ) ) {
				$out[ 'BR-' . $cat . '-10' ] = 'exemption reason missing';
			}
			if ( 'O' === $cat && '' !== $rate ) {
				$out['BR-O-09'] = 'category O with a VAT rate';
			}
		}
		foreach ( array_keys( $per_group ) as $key ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$out['BR-CO-18'] = 'VAT breakdown missing for ' . $key;
			}
		}
		if ( null !== $tax && $tax_sum !== $tax ) {
			$out['BR-CO-14'] = 'invoice total VAT is not the sum of the breakdown';
		}

		// VAT identifiers per category.
		$seller_vat = $val( $ag . '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]' );
		$seller_fc  = $val( $ag . '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]' );
		$buyer_vat  = $val( $ag . '/ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]' );
		foreach ( array( 'S', 'Z', 'E' ) as $cat ) {
			if ( isset( $cats[ $cat ] ) && '' === $seller_vat . $seller_fc ) {
				$out[ 'BR-' . $cat . '-02' ] = 'seller VAT ID or tax number missing';
			}
		}
		if ( isset( $cats['AE'] ) ) {
			if ( '' === $seller_vat . $seller_fc ) {
				$out['BR-AE-02'] = 'seller VAT ID or tax number missing (reverse charge)';
			}
			if ( '' === $buyer_vat && '' === $val( $ag . '/ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID' ) ) {
				$out['BR-AE-03'] = 'buyer VAT ID missing (reverse charge)';
			}
		}
		if ( isset( $cats['O'] ) ) {
			if ( '' !== $seller_vat || '' !== $buyer_vat ) {
				$out['BR-O-02'] = 'VAT identifiers with category O';
			}
			if ( count( $cats ) > 1 ) {
				$out['BR-O-11'] = 'category O together with other categories';
			}
		}

		// XRechnung (only when declared).
		if ( self::XRECHNUNG === $spec ) {
			$xr = array(
				'BR-DE-1'  => $means,
				'BR-DE-2'  => $val( $ag . '/ram:SellerTradeParty/ram:DefinedTradeContact/ram:PersonName' ),
				'BR-DE-3'  => $val( $ag . '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName' ),
				'BR-DE-4'  => $val( $ag . '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ),
				'BR-DE-6'  => $val( $ag . '/ram:SellerTradeParty/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber' ),
				'BR-DE-7'  => $val( $ag . '/ram:SellerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID' ),
				'BR-DE-8'  => $val( $ag . '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName' ),
				'BR-DE-9'  => $val( $ag . '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ),
				'BR-DE-15' => $val( $ag . '/ram:BuyerReference' ),
				'BT-34'    => $val( $ag . '/ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID' ),
				'BT-49'    => $val( $ag . '/ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID' ),
			);
			foreach ( $xr as $rule => $value ) {
				if ( '' === $value ) {
					$out[ $rule ] = 'required by XRechnung';
				}
			}
		}
		return $out;
	}
}
