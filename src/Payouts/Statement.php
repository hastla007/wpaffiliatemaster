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
use WPAffiliateCaptain\Payouts\Storage\LocalStore;
use WPAffiliateCaptain\Payouts\Storage\S3Store;
use WPAffiliateCaptain\Payouts\Storage\StatementStore;

defined( 'ABSPATH' ) || exit;

final class Statement {

	/**
	 * Local storage folder (created on demand, with deny rules and index.php): see LocalStore.
	 *
	 * Define WPAC_STATEMENT_DIR in wp-config.php to store statements outside the web root
	 * (recommended, required on nginx where .htaccess is ignored). With several app servers use
	 * object storage (WPAC_S3_* constants, see store()) – every node reads and deletes the same
	 * documents – or shared storage (NFS, EFS …) mounted on every node. Without either, a download
	 * on another node finds no file (it is then built again from the stored data, see send()) and
	 * the other nodes remove their copies after the retention period in their own sweep
	 * (StatementSweep). The filter wpac_statement_dir can point the folder elsewhere.
	 *
	 * @return string
	 */
	public static function dir() {
		return LocalStore::folder();
	}

	/**
	 * Configured local storage folder (constant, filter or uploads), without creating it.
	 *
	 * @return string
	 */
	public static function configured_dir() {
		return LocalStore::configured();
	}

	/**
	 * Whether the local folder is a custom one (constant or filter) and not the uploads folder.
	 *
	 * @return bool
	 */
	public static function custom_dir() {
		return LocalStore::custom();
	}

	/**
	 * The active document store for credit notes, correction credit notes and cancellations:
	 * S3-compatible object storage when the WPAC_S3_* constants are complete, otherwise the local
	 * folder (an incomplete configuration falls back to it and is reported in Site Health). The
	 * filter wpac_statement_store can supply another store.
	 *
	 * @return StatementStore
	 */
	public static function store() {
		$default = S3Store::configured() ? S3Store::from_constants() : null;
		$default = $default ? $default : new LocalStore();
		/**
		 * Store of the credit note, correction and cancellation PDFs (an object implementing
		 * WPAffiliateCaptain\Payouts\Storage\StatementStore). Must be the same on every app server.
		 *
		 * @param StatementStore $default Store from the configuration (S3 or local folder).
		 */
		$store = apply_filters( 'wpac_statement_store', $default );
		return $store instanceof StatementStore ? $store : $default;
	}

	/**
	 * Whether the documents are kept in the local folder (no object storage or custom store).
	 *
	 * @return bool
	 */
	public static function local_store() {
		return self::store() instanceof LocalStore;
	}

	/** Option: last failure of the document store (time, store, error; no secrets). */
	const STORE_ERROR_OPTION = 'wpac_statement_store_error';

	/**
	 * Remember a failure of the store (Site Health) and log it.
	 *
	 * @param StatementStore $store   Store.
	 * @param string         $message Log message.
	 * @param array          $context Log context (e.g. payout).
	 */
	public static function store_failed( StatementStore $store, $message, array $context = array() ) {
		$cause = Db::cut( (string) $store->last_error(), 300 );
		update_option(
			self::STORE_ERROR_OPTION,
			array(
				'time'  => time(),
				'store' => (string) $store->id(),
				'error' => $cause,
			),
			false
		);
		$context += array(
			'store' => (string) $store->id(),
			'cause' => $cause,
		);
		if ( $store instanceof LocalStore ) {
			$context['dir_writable'] = is_writable( self::dir() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		}
		\WPAffiliateCaptain\Core\Log::error( $message, $context );
	}

	/**
	 * A write to the store worked: the warning of an earlier failure goes.
	 */
	public static function store_ok() {
		if ( false !== get_option( self::STORE_ERROR_OPTION, false ) ) {
			delete_option( self::STORE_ERROR_OPTION );
		}
	}

	/**
	 * Last failure of the store (null = none since the last successful write).
	 *
	 * @return array|null time, store, error.
	 */
	public static function store_error() {
		$e = get_option( self::STORE_ERROR_OPTION );
		return is_array( $e ) ? $e : null;
	}

	/**
	 * Test the active store: write, read and delete a probe object. Nothing secret is returned.
	 *
	 * @return array ok, store, label, write, read, delete, error.
	 */
	public static function test_store() {
		$store = self::store();
		$name  = 'wpac-probe-' . wp_generate_password( 12, false ) . '.txt';
		$data  = 'wpac-probe ' . wp_generate_password( 24, false );
		$out   = array(
			'ok'     => false,
			'store'  => (string) $store->id(),
			'label'  => (string) $store->label(),
			'write'  => false,
			'read'   => false,
			'delete' => false,
			'error'  => '',
		);
		$out['write'] = $store->put( $name, $data );
		if ( $out['write'] ) {
			$out['read']   = $data === $store->get( $name );
			$out['delete'] = $store->delete( $name ) && ! $store->exists( $name );
		}
		$out['error'] = (string) $store->last_error();
		$out['ok']    = $out['write'] && $out['read'] && $out['delete'];
		if ( $out['ok'] ) {
			self::store_ok();
		}
		return $out;
	}

	/**
	 * Bytes of a stored document: from the active store; with object storage also from the local
	 * folders of this server (documents not migrated yet).
	 *
	 * @param string $name Stored file name.
	 * @return string|null
	 */
	public static function read_document( $name ) {
		$store = self::store();
		$bytes = $store->get( $name );
		if ( null === $bytes && ! $store instanceof LocalStore ) {
			$bytes = ( new LocalStore() )->get( $name );
		}
		return $bytes;
	}

	/**
	 * Path of a just stored document for listeners (partner mail attachment): the file in the
	 * local folder, or a private temporary copy deleted at the end of the request.
	 *
	 * @param StatementStore $store Store.
	 * @param string         $name  File name.
	 * @param string         $bytes Content.
	 * @return string '' when no copy could be made.
	 */
	public static function attachment_path( StatementStore $store, $name, $bytes ) {
		if ( $store instanceof LocalStore ) {
			return self::dir() . '/' . $name;
		}
		$clean = LocalStore::clean( $name );
		$dir   = \WPAffiliateCaptain\Core\PrivateFile::dir() . '/wpac-doc-' . wp_generate_password( 16, false );
		$path  = $dir . '/' . $clean;
		if ( '' === $clean || ! wp_mkdir_p( $dir ) || false === @file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			return '';
		}
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		\WPAffiliateCaptain\Core\PrivateFile::track( $path );
		self::$temp_dirs[] = $dir;
		if ( 1 === count( self::$temp_dirs ) ) {
			register_shutdown_function( array( __CLASS__, 'remove_temp_dirs' ) );
		}
		return $path;
	}

	/** @var string[] Folders of temporary attachment copies (removed at shutdown). */
	private static $temp_dirs = array();

	/**
	 * Shutdown: remove the folders of temporary attachment copies (their files are removed by
	 * PrivateFile::cleanup()).
	 */
	public static function remove_temp_dirs() {
		\WPAffiliateCaptain\Core\PrivateFile::cleanup();
		foreach ( self::$temp_dirs as $dir ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		self::$temp_dirs = array();
	}

	/**
	 * Whether the storage directory is publicly reachable over HTTP (checked
	 * with a probe file; cached for a day). Only relevant inside the uploads dir.
	 *
	 * @return bool|null True = exposed, false = protected, null = unknown/outside web root.
	 */
	public static function publicly_accessible() {
		if ( self::custom_dir() || ! self::local_store() ) {
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
		$code    = (int) wp_remote_retrieve_response_code( $res );
		$body    = (string) wp_remote_retrieve_body( $res );
		$exposed = self::probe_verdict( $code, $body, true );
		if ( true !== $exposed ) {
			$exposed = self::probe_verdict( $code, $body, self::control_reachable( $upload ) );
		}
		if ( false === $exposed ) {
			// Dotfiles too (the sweep marker .wpac-sweep): some servers block *.txt/*.pdf by rule
			// but serve hidden files (R12-K5).
			$dot = '.wpac-probe-' . wp_generate_password( 12, false );
			file_put_contents( $dir . '/' . $dot, 'wpac' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$res = wp_remote_get( trailingslashit( $upload['baseurl'] ) . 'wpac-statements/' . $dot, array( 'timeout' => 5 ) );
			wp_delete_file( $dir . '/' . $dot );
			if ( ! is_wp_error( $res ) && true === self::probe_verdict( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ), true ) ) {
				$exposed = true;
			}
		}
		if ( null === $exposed ) {
			set_transient( 'wpac_statement_probe', 'unknown', HOUR_IN_SECONDS );
			$memo = null;
			return null;
		}
		set_transient( 'wpac_statement_probe', $exposed ? 'exposed' : 'protected', DAY_IN_SECONDS );
		$memo = $exposed;
		return $exposed;
	}

	/**
	 * Verdict of the probe request: exposed only when the probe file was served; protected only
	 * when it was refused (401/403/404) while a control file outside the protected folder was
	 * reachable - a refusal of every loopback request (firewall, basic auth) or a server error
	 * (5xx) proves nothing.
	 *
	 * @param int    $code    HTTP status of the probe.
	 * @param string $body    Body of the probe.
	 * @param bool   $control Whether the control file was reachable.
	 * @return bool|null True = exposed, false = protected, null = unknown.
	 */
	public static function probe_verdict( $code, $body, $control ) {
		if ( 200 === (int) $code && 'wpac' === trim( (string) $body ) ) {
			return true;
		}
		if ( $control && in_array( (int) $code, array( 401, 403, 404, 410 ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Whether a control file directly in the uploads folder (not protected) is reachable: only
	 * then a refused probe in the statements folder means "protected".
	 *
	 * @param array $upload wp_upload_dir().
	 * @return bool
	 */
	private static function control_reachable( array $upload ) {
		$name = 'wpac-probe-' . wp_generate_password( 12, false ) . '.txt';
		$path = trailingslashit( $upload['basedir'] ) . $name;
		if ( false === @file_put_contents( $path, 'wpac' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		$res = wp_remote_get( trailingslashit( $upload['baseurl'] ) . $name, array( 'timeout' => 5 ) );
		wp_delete_file( $path );
		return ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) && 'wpac' === trim( (string) wp_remote_retrieve_body( $res ) );
	}

	/**
	 * Note text for the VAT treatment. Legal references follow the operator country: German,
	 * Austrian and Swiss law for DE/AT/CH, the EU VAT Directive for everyone else.
	 *
	 * @param string $mode    Mode.
	 * @param string $country Operator country (legal references follow it).
	 * @param array  $op      Operator snapshot of the document (its own frozen notes win, SET-AC-04 c).
	 * @return string
	 */
	public static function vat_note( $mode, $country = '', array $op = array() ) {
		$own = self::own_note( $op, (string) $mode );
		if ( '' !== $own ) {
			return $own;
		}
		$country = strtoupper( '' !== (string) $country ? (string) $country : (string) Settings::get( 'operator_country' ) );
		$country = '' !== $country ? $country : 'DE';
		switch ( $mode ) {
			case Vat::STANDARD:
				return __( 'The commission rates are net amounts; VAT is added at the statutory rate.', 'affiliate-captain' );
			case Vat::REVERSE_CHARGE:
				if ( 'DE' === $country ) {
					return __( 'Reverse charge: the recipient of the service is liable for VAT (Steuerschuldnerschaft des Leistungsempfängers, §13b UStG / Art. 196 VAT Directive).', 'affiliate-captain' );
				}
				if ( 'AT' === $country ) {
					return __( 'Reverse charge: the recipient of the service is liable for VAT (Übergang der Steuerschuld, §19 Abs. 1 UStG (AT) / Art. 196 VAT Directive).', 'affiliate-captain' );
				}
				if ( 'CH' === $country ) {
					return __( 'Reverse charge: the recipient of the service is liable for VAT (Bezugsteuer, Art. 45 MWSTG).', 'affiliate-captain' );
				}
				return __( 'Reverse charge: the recipient of the service is liable for VAT (Art. 196 VAT Directive).', 'affiliate-captain' );
			case Vat::SMALL_BUSINESS:
				if ( 'DE' === $country ) {
					return __( 'No VAT is shown because the partner is a small business (§19 UStG).', 'affiliate-captain' );
				}
				if ( 'AT' === $country ) {
					return __( 'No VAT is shown because the partner is a small business (§6 Abs. 1 Z 27 UStG (AT)).', 'affiliate-captain' );
				}
				if ( 'CH' === $country ) {
					return __( 'No VAT is shown because the partner is not registered for VAT (Art. 10 MWSTG).', 'affiliate-captain' );
				}
				return __( 'No VAT is shown because the partner applies the small business scheme (VAT exemption for small enterprises).', 'affiliate-captain' );
			case Vat::PRIVATE_PERSON:
				return __( 'No VAT: the partner is a private individual and not an entrepreneur.', 'affiliate-captain' );
			case Vat::NONE:
				return __( 'Repayments by the partner and claims written off are no service and not subject to VAT.', 'affiliate-captain' );
		}
		return '';
	}

	/**
	 * Own note text frozen with a document (SET-AC-04 c; '' = the built-in text applies).
	 *
	 * @param array  $op   Operator snapshot.
	 * @param string $kind self_billing or a VAT treatment (Vat::REVERSE_CHARGE, …).
	 * @return string
	 */
	public static function own_note( array $op, $kind ) {
		return isset( $op['notes'][ $kind ] ) && is_string( $op['notes'][ $kind ] ) ? trim( $op['notes'][ $kind ] ) : '';
	}

	/**
	 * Self-billing note of a credit note: the own text frozen with it, else the built-in text.
	 *
	 * @param string $country Operator country.
	 * @param array  $op      Operator snapshot.
	 * @return string
	 */
	public static function self_billing_note( $country, array $op = array() ) {
		$own = self::own_note( $op, 'self_billing' );
		if ( '' !== $own ) {
			return $own;
		}
		return 'DE' === $country
			? __( 'This document is a credit note (self-billing) agreed with the partner; the partner does not issue a separate invoice. The partner may object to it (§14 Abs. 2 S. 3 UStG) – it then no longer counts as an invoice and we issue a corrected credit note. Objections can be filed in the partner area.', 'affiliate-captain' )
			: __( 'This document is a self-billing invoice agreed with the partner (Art. 224 VAT Directive); the partner does not issue a separate invoice. The partner may object to it – we then issue a corrected document. Objections can be filed in the partner area.', 'affiliate-captain' );
	}

	/**
	 * VAT notes of a document: one per treatment that occurs on it (the main treatment first),
	 * so a correction block with another treatment (e.g. reverse charge or VAT of an earlier
	 * credit note after a change of the tax status) carries its own note (§14a UStG).
	 *
	 * @param string   $main    Main treatment of the document.
	 * @param string[] $modes   Treatments of the lines.
	 * @param string   $country Operator country.
	 * @param array    $op      Operator snapshot of the document (own notes).
	 * @return string[]
	 */
	public static function vat_notes( $main, array $modes, $country, array $op = array() ) {
		$modes = array_values( array_unique( array_filter( array_merge( array( (string) $main ), array_map( 'strval', $modes ) ) ) ) );
		$notes = array();
		foreach ( $modes as $mode ) {
			$note = self::vat_note( $mode, $country, $op );
			if ( '' !== $note ) {
				$notes[] = $note;
			}
		}
		$taxable = array_diff( $modes, array( Vat::NONE ) );
		if ( count( $taxable ) > 1 ) {
			$notes[] = __( 'Lines with different VAT treatments: each treatment applies to the lines it is shown for. Corrections of earlier credit notes keep the treatment of the original credit note; commissions keep the treatment valid at the time of the service.', 'affiliate-captain' );
		}
		return $notes;
	}

	/**
	 * Language of the documents: the language chosen in the partner profile, otherwise the site
	 * language (SET-AC-17). Languages the PDF fonts cannot show (Cyrillic, Greek, Arabic, Asian
	 * scripts …) fall back to English (filter wpac_statement_locale).
	 *
	 * @param string $locale       Site language.
	 * @param int    $affiliate_id Partner (0 = none: site language).
	 * @return string
	 */
	public static function document_locale( $locale, $affiliate_id = 0 ) {
		$chosen    = (int) $affiliate_id > 0 ? \WPAffiliateCaptain\Core\Locale::partner( (int) $affiliate_id ) : '';
		$locale    = '' !== $chosen ? $chosen : ( '' !== (string) $locale ? (string) $locale : 'en_US' );
		$lang      = strtolower( (string) strtok( $locale, '_' ) );
		$non_latin = array( 'ru', 'uk', 'be', 'bg', 'mk', 'sr', 'el', 'ar', 'ary', 'he', 'fa', 'ur', 'zh', 'ja', 'ko', 'th', 'hi', 'bn', 'ka', 'hy', 'kk', 'ky', 'mn', 'ta', 'te', 'ml', 'kn', 'mr', 'gu', 'pa', 'ne', 'si', 'km', 'lo', 'my', 'am', 'ps', 'ug', 'tg', 'bo', 'dv', 'yi', 'tt', 'ckb', 'azb', 'haz', 'sah', 'os' );
		$doc       = in_array( $lang, $non_latin, true ) ? 'en_US' : $locale;
		/**
		 * Language of credit notes and cancellations (frozen with each document).
		 *
		 * @param string $doc          Document language.
		 * @param string $locale       Language chosen for the partner, else the site language.
		 * @param int    $affiliate_id Partner (0 = unknown; SET-AC-17).
		 */
		return (string) apply_filters( 'wpac_statement_locale', $doc, $locale, (int) $affiliate_id );
	}

	/**
	 * Build the PDF bytes for a payout (credit note) or its cancellation.
	 *
	 * Self-billing credit notes contain the mandatory details of §14 Abs. 4 UStG:
	 * names/addresses, tax number or VAT ID, date, unique number, service and
	 * period, net amount, rate and VAT amount, and the word "Gutschrift".
	 *
	 * Everything printed comes from the payout and its frozen snapshots (language and date
	 * format included), so the same document can be built again byte for byte.
	 *
	 * @param object $p         Payout.
	 * @param bool   $storno    Build the cancellation document.
	 * @param bool   $duplicate Mark as a duplicate built again from the stored data.
	 * @return string
	 */
	public static function build( $p, $storno = false, $duplicate = false ) {
		// Operator data as frozen with the payout (older payouts: current settings).
		$op       = json_decode( (string) ( isset( $p->operator_snapshot ) ? $p->operator_snapshot : '' ), true );
		$op       = is_array( $op ) ? $op : PayoutService::operator_snapshot();
		$switched = ! empty( $op['locale'] ) ? \WPAffiliateCaptain\Core\Locale::switch_to( (string) $op['locale'] ) : false;
		try {
			return self::render( $p, $op, $storno, $duplicate );
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $switched );
		}
	}

	/**
	 * Lay out the document (in the document language).
	 *
	 * @param object $p         Payout.
	 * @param array  $op        Operator snapshot.
	 * @param bool   $storno    Cancellation document.
	 * @param bool   $duplicate Duplicate.
	 * @return string
	 */
	private static function render( $p, array $op, $storno, $duplicate ) {
		$aff    = AffiliateRepository::find( (int) $p->affiliate_id );
		$snap   = json_decode( (string) $p->profile_snapshot, true );
		$snap   = is_array( $snap ) ? $snap : array();
		$labels = ProfileRepository::tax_status_labels();
		$issuer = (string) $op['name'];
		$self   = ! empty( $op['self_billing'] );
		$country = strtoupper( isset( $op['country'] ) && '' !== (string) $op['country'] ? (string) $op['country'] : 'DE' );
		$german  = in_array( $country, array( 'DE', 'AT', 'CH' ), true );
		$correct = isset( $op['document'] ) && 'correction' === $op['document'];
		$sign   = $storno ? -1 : 1;
		$net    = (int) $p->net_minor ? (int) $p->net_minor : (int) $p->amount_minor - (int) $p->vat_minor;
		$format = ! empty( $op['date_format'] ) ? (string) $op['date_format'] : get_option( 'date_format' );
		$date   = static function ( $mysql ) use ( $format ) {
			return mysql2date( $format, $mysql );
		};

		if ( $storno ) {
			/* translators: %s: original document number */
			$title = sprintf( __( 'Cancellation of credit note %s', 'affiliate-captain' ), $p->number );
			$doc   = (string) $p->storno_number;
		} elseif ( $correct ) {
			$title = $self && $german ? __( 'Correction of credit notes (Gutschrift)', 'affiliate-captain' ) : __( 'Correction of credit notes', 'affiliate-captain' );
			$doc   = (string) $p->number;
		} else {
			if ( $self ) {
				$title = $german ? __( 'Credit note (Gutschrift)', 'affiliate-captain' ) : __( 'Credit note (self-billing)', 'affiliate-captain' );
			} else {
				$title = __( 'Commission statement', 'affiliate-captain' );
			}
			$doc = (string) $p->number;
		}

		$pdf = new Pdf(
			array(
				'title'   => $title . ' ' . $doc,
				'author'  => $issuer,
				'created' => strtotime( ( $storno && $p->storno_at ? $p->storno_at : $p->created_at ) . ' UTC' ),
			)
		);
		// Logo and footer frozen with the document (SET-AC-16); without them the layout is unchanged.
		$y = DocumentBrand::apply( $pdf, $op );
		$pdf->text( 50, $y, $title, 15, true );
		if ( $duplicate ) {
			$pdf->text( 545, $y, __( 'DUPLICATE', 'affiliate-captain' ), 11, true, 'right' );
		}
		$y += 30;
		$pdf->text( 50, $y, $self ? __( 'Issued by (recipient of the service)', 'affiliate-captain' ) : __( 'Issued by', 'affiliate-captain' ), 9, true );
		$pdf->text( 310, $y, $self ? __( 'Partner (provider of the service)', 'affiliate-captain' ) : __( 'Partner', 'affiliate-captain' ), 9, true );
		$y   += 14;
		$left = array_merge(
			array( $issuer ),
			preg_split( '/\r\n|\r|\n/', isset( $op['address'] ) ? (string) $op['address'] : '' ),
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
		// Each column wraps within its width (long names never run into the other column).
		$column = static function ( array $lines ) {
			$out = array();
			foreach ( array_filter( array_map( 'trim', $lines ), 'strlen' ) as $line ) {
				$out = array_merge( $out, Pdf::wrap( $line, 245, 10 ) );
			}
			return $out;
		};
		$left    = $column( $left );
		$partner = $column( $partner );
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
			$values = Pdf::wrap( (string) $v, 355, 9 );
			$pdf->text( 50, $y, $k . ':', 9, true );
			foreach ( $values as $line ) {
				$pdf->text( 190, $y, $line, 9 );
				$y += 13;
			}
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
		// reference to the original document and its VAT treatment (§17 UStG), then repayments
		// and write-offs (not taxable).
		$items  = PayoutService::items( (int) $p->id );
		$blocks = array( 0 => array() );
		$modes  = array();
		$settles = false;
		foreach ( $items as $item ) {
			$mode = isset( $item->vat_mode ) ? (string) $item->vat_mode : '';
			if ( Corrections::KIND === (string) $item->kind ) {
				// Gross difference of a correction credit note: its VAT is shown on that document.
				$blocks['kg'][] = $item;
				$settles        = true;
				continue;
			}
			$ref  = Vat::NONE === $mode ? 'none' : ( isset( $item->ref_payout_id ) ? (int) $item->ref_payout_id : 0 );
			$blocks[ $ref ][] = $item;
			if ( '' !== $mode ) {
				$modes[] = $mode;
			}
		}
		foreach ( array( 'none', 'kg' ) as $last ) {
			if ( isset( $blocks[ $last ] ) ) {
				$move = $blocks[ $last ];
				unset( $blocks[ $last ] );
				$blocks[ $last ] = $move; // Last.
			}
		}
		$rate_of = static function ( $item ) use ( $p ) {
			return isset( $item->vat_rate ) && '' !== (string) $item->vat_rate ? (string) $item->vat_rate : (string) $p->vat_rate;
		};
		$mode_labels = array(
			Vat::REVERSE_CHARGE => __( 'reverse charge', 'affiliate-captain' ),
			Vat::SMALL_BUSINESS => __( 'small business, no VAT', 'affiliate-captain' ),
			Vat::PRIVATE_PERSON => __( 'private individual, no VAT', 'affiliate-captain' ),
		);
		$groups = array();
		$shown  = array();
		foreach ( $blocks as $ref => $list ) {
			if ( ! $list ) {
				continue;
			}
			$orig = null;
			if ( $ref ) {
				$y += 8;
				if ( $y > $pdf->limit( 740 ) ) {
					$pdf->add_page();
					$y = 60;
				}
				if ( 'none' === $ref ) {
					$pdf->text( 50, $y, __( 'Repayments and write-offs (not taxable)', 'affiliate-captain' ), 10, true );
				} elseif ( 'kg' === $ref ) {
					$pdf->text( 50, $y, __( 'Correction credit notes settled (VAT shown on the correction credit note)', 'affiliate-captain' ), 10, true );
				} else {
					$orig = PayoutService::find( (int) $ref );
					/* translators: 1: credit note number, 2: date */
					$pdf->text( 50, $y, sprintf( __( 'Correction of credit note %1$s of %2$s', 'affiliate-captain' ), $orig ? $orig->number : '#' . $ref, $orig ? $date( get_date_from_gmt( $orig->created_at ) ) : '—' ), 10, true );
				}
				$y += 16;
			}
			$y   = $head( $pdf, $y );
			$sum = 0;
			foreach ( $list as $item ) {
				if ( in_array( $item->kind, array( 'sale', 'bonus' ), true ) ) {
					$desc = (string) $item->description;
				} elseif ( in_array( $item->kind, Vat::NON_TAXABLE_KINDS, true ) ) {
					$desc = \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $item->kind ) . ( '' !== (string) $item->description ? ': ' . $item->description : '' );
				} else {
					$desc = __( 'Correction', 'affiliate-captain' ) . ' (' . \WPAffiliateCaptain\Commissions\Ledger::kind_label( (string) $item->kind ) . ')';
				}
				// Every column wraps within its own width: nothing runs into the next column.
				$cells = array(
					array( 50, Pdf::wrap( $date( get_date_from_gmt( (string) $item->commission_date ) ), 66, 9 ) ),
					array( 120, Pdf::wrap( ( 'wpac' === $item->source ? '' : '#' . $item->source_order_id ), 106, 9 ) ),
					array( 230, Pdf::wrap( $desc, 236, 9 ) ),
				);
				$height = max( count( $cells[0][1] ), count( $cells[1][1] ), count( $cells[2][1] ) );
				if ( $y + 13 * ( $height - 1 ) > $pdf->limit( 760 ) ) {
					$pdf->add_page();
					$y = $head( $pdf, 60 );
				}
				foreach ( $cells as $cell ) {
					foreach ( $cell[1] as $n => $line ) {
						if ( '' !== $line ) {
							$pdf->text( $cell[0], $y + 13 * $n, $line, 9 );
						}
					}
				}
				$pdf->text( 545, $y, Money::format( $sign * (int) $item->amount_minor, $p->currency ), 9, false, 'right' );
				$y   += 13 * $height;
				$sum += (int) $item->amount_minor;
				if ( 'kg' === $ref || 'none' === $ref ) {
					continue; // Settled correction credit notes, repayments, write-offs: lines of their own below, no VAT group.
				}
				$rate = Vat::rate_key( $rate_of( $item ) ); // "19" and "19.0" are one group (R13-N8).
				if ( ! isset( $shown[ $rate ] ) ) {
					$shown[ $rate ] = $rate_of( $item ); // Printed as stored.
				}
				$groups[ $rate ] = ( isset( $groups[ $rate ] ) ? $groups[ $rate ] : 0 ) + (int) $item->amount_minor;
			}
			if ( $ref && 'none' !== $ref && 'kg' !== $ref ) {
				$first = reset( $list );
				$imode = isset( $first->vat_mode ) && '' !== (string) $first->vat_mode ? (string) $first->vat_mode : ( $orig ? (string) $orig->vat_mode : '' );
				$pdf->line( 50, $y - 6, 545, $y - 6 );
				if ( isset( $mode_labels[ $imode ] ) ) {
					/* translators: %s: VAT treatment */
					$pdf->text( 330, $y + 4, sprintf( __( 'Correction net (%s)', 'affiliate-captain' ), $mode_labels[ $imode ] ), 9 );
				} else {
					/* translators: %s: VAT rate */
					$pdf->text( 330, $y + 4, sprintf( __( 'Correction net (VAT %s %%)', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( $rate_of( $first ) ) ), 9 );
				}
				$pdf->text( 545, $y + 4, Money::format( $sign * $sum, $p->currency ), 9, false, 'right' );
				$y += 18;
			}
		}
		$pdf->line( 50, $y - 6, 545, $y - 6 );
		$y     += 6;
		// R12-M7: the gross differences of settled correction credit notes are no net amount of
		// this document (their VAT is shown on the correction credit note): a line of their own.
		// R13-K2: repayments and write-offs (not taxable) are no net amount either.
		$kg_sum   = 0;
		$none_sum = 0;
		foreach ( isset( $blocks['kg'] ) ? $blocks['kg'] : array() as $item ) {
			$kg_sum += (int) $item->amount_minor;
		}
		foreach ( isset( $blocks['none'] ) ? $blocks['none'] : array() as $item ) {
			$none_sum += (int) $item->amount_minor;
		}
		$untaxed = ( isset( $blocks['kg'] ) ? count( $blocks['kg'] ) : 0 ) + ( isset( $blocks['none'] ) ? count( $blocks['none'] ) : 0 );
		$taxable = count( $items ) > $untaxed;
		$totals  = array(
			array( __( 'Net amount', 'affiliate-captain' ), $sign * ( $net - $kg_sum - $none_sum ), false ),
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
				$totals[] = array( sprintf( __( 'plus VAT %s %%', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( $legacy || ! $groups ? $p->vat_rate : (string) $shown[ key( $groups ) ] ) ), $sign * (int) $p->vat_minor, false );
			} else {
				$totals[] = array( __( 'VAT', 'affiliate-captain' ), 0, false );
			}
		} else {
			ksort( $groups );
			foreach ( $groups as $rate => $group_net ) {
				/* translators: 1: rate, 2: net amount */
				$totals[] = array( sprintf( __( 'VAT %1$s %% on %2$s', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( $shown[ $rate ] ), Money::format( $sign * $group_net, $p->currency ) ), $sign * Vat::amount( $group_net, (string) $rate ), false );
			}
		}
		if ( ! $taxable ) {
			$totals = array(); // Only settled corrections, repayments, write-offs: no net amount, no VAT.
		}
		// Lines of their own (wrapped: long labels must not run into the amount column).
		if ( isset( $blocks['none'] ) ) {
			$totals[] = array( __( 'Repayments and write-offs (not taxable)', 'affiliate-captain' ), $sign * $none_sum, false, true );
		}
		if ( $kg_sum ) {
			$totals[] = array( __( 'Correction credit notes settled (gross, VAT shown there)', 'affiliate-captain' ), $sign * $kg_sum, false, true );
		}
		// SET-AC-20: a withholding is a line of its own after the gross total (only when there is one).
		$withholding = Withholding::of_payout( $p );
		if ( $withholding ) {
			$totals[] = array( __( 'Total credit note', 'affiliate-captain' ), $sign * ( (int) $p->amount_minor + (int) $withholding['minor'] ), false );
			$totals[] = array( Withholding::label( $withholding, (string) $p->currency ), -$sign * (int) $withholding['minor'], false, true );
		}
		if ( $storno ) {
			$total_label = __( 'Total cancelled', 'affiliate-captain' );
		} elseif ( $correct ) {
			$total_label = __( 'Balance (settled, no transfer)', 'affiliate-captain' );
		} else {
			$total_label = __( 'Total payout', 'affiliate-captain' );
		}
		$totals[] = array( $total_label, $sign * (int) $p->amount_minor, true );
		if ( $pdf->limit( 800 ) < 800 && $y + 15 * count( $totals ) + 12 > $pdf->limit( 800 ) ) {
			// With a footer (SET-AC-16) the totals never run into it.
			$pdf->add_page();
			$y = 60;
		}
		foreach ( $totals as $t ) {
			$amount = Money::format( $t[1], $p->currency );
			$labels = array( $t[0] );
			if ( ! empty( $t[3] ) ) {
				// The first line ends before the amount; the rest may use the whole column.
				$labels = Pdf::wrap( $t[0], 545 - 330 - 12 - Pdf::width( $amount, 10 ), 10 );
				$first  = array_shift( $labels );
				$labels = $labels ? array_merge( array( $first ), Pdf::wrap( implode( ' ', $labels ), 545 - 330, 10 ) ) : array( $first );
			}
			foreach ( $labels as $n => $label ) {
				$pdf->text( 330, $y + 12 * $n, $label, 10, $t[2] );
			}
			$pdf->text( 545, $y, $amount, 10, $t[2], 'right' );
			$y += 15 + 12 * ( count( $labels ) - 1 );
		}
		$y += 15;
		if ( $self && ! $storno ) {
			$self_note = self::self_billing_note( $country, $op );
		} else {
			$self_note = '';
		}
		$notes = array_merge(
			self::vat_notes( (string) $p->vat_mode, $modes, $country, $op ),
			array_filter(
				array(
					$correct ? __( 'This document settles corrections of earlier credit notes (refunds and cancellations of referred orders) with the repayment received or the claim written off. It reduces the net amounts and VAT of the credit notes named above; nothing is transferred.', 'affiliate-captain' ) : '',
					$settles ? __( 'Correction credit notes settled: the amounts are the gross differences of the correction credit notes named, whose VAT is shown there; no VAT is added on this document.', 'affiliate-captain' ) : '',
					$self_note,
					$withholding ? Withholding::note( $withholding, (string) $p->currency ) : '',
					$storno ? __( 'This document cancels the credit note named above in full. The commissions remain available for a later payout.', 'affiliate-captain' ) : '',
					$duplicate ? __( 'Duplicate: built again from the stored data because the original file was not available on this server.', 'affiliate-captain' ) : '',
				)
			)
		);
		/** This filter is documented in docs/CONTRACT.md. */
		$note  = (string) apply_filters( 'wpac_statement_note', implode( "\n", $notes ), $p );
		$lines = Pdf::wrap( $note, 495, 8 );
		$orig  = $pdf->transliterated();
		if ( $orig ) {
			// The fonts cannot show some characters: say so and keep the original spelling.
			/* translators: %s: original texts */
			$lines = array_merge( $lines, Pdf::wrap( sprintf( __( 'Some characters cannot be shown in this document and were transliterated. Original spelling: %s', 'affiliate-captain' ), implode( ' | ', $orig ) ), 495, 8 ) );
		}
		foreach ( $lines as $line ) {
			if ( $y > $pdf->limit( 800 ) ) {
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
		// Differences of correction credit notes settled on a credit note are counted with the
		// correction credit note (below), not a second time with the credit note that paid them.
		// Repayments and write-offs are no turnover either (R13-K2): shown on a line of their own.
		$settled  = array();
		$untaxed  = array();
		$not_paid = array();
		$withheld = array();
		if ( $rows ) {
			$it    = Db::table( 'payout_items' );
			$ct    = Db::table( 'commissions' );
			$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
			$kinds = implode( ',', array_fill( 0, count( Vat::NON_TAXABLE_KINDS ), '%s' ) );
			$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders built above.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.payout_id, SUM(CASE WHEN c.kind = %s THEN i.amount_minor ELSE 0 END) s, SUM(CASE WHEN c.kind <> %s AND ( c.kind IN ({$kinds}) OR i.vat_mode = %s ) THEN i.amount_minor ELSE 0 END) u FROM {$it} i JOIN {$ct} c ON c.id = i.commission_id WHERE i.payout_id IN ({$in}) GROUP BY i.payout_id", array_merge( array( Corrections::KIND, Corrections::KIND ), Vat::NON_TAXABLE_KINDS, array( Vat::NONE ), $ids ) ) ) as $s ) {
				$settled[ (int) $s->payout_id ] = (int) $s->s;
				$untaxed[ (int) $s->payout_id ] = (int) $s->u;
			}
		}
		foreach ( $rows as $p ) {
			$kg   = isset( $settled[ (int) $p->id ] ) ? $settled[ (int) $p->id ] : 0;
			$none = isset( $untaxed[ (int) $p->id ] ) ? $untaxed[ (int) $p->id ] : 0;
			$net  = ( (int) $p->net_minor ? (int) $p->net_minor : (int) $p->amount_minor - (int) $p->vat_minor ) - $kg - $none;
			$held = Withholding::minor( $p ); // SET-AC-20: the gross of the document includes it.
			$sum  = (int) $p->amount_minor + $held - $kg - $none;
			// A cancellation only reduces the year when the credit note it cancels was issued: a
			// credit note never written (its cancellation document failing for good, R13-N8)
			// shows neither line instead of a lone minus.
			$issued = '' !== (string) $p->statement_file || '' !== (string) $p->statement_hash;
			if ( $p->statement_file && $p->created_at >= $from && $p->created_at <= $to ) {
				$line( (string) $p->number, $date( $p->created_at ), $net, (int) $p->vat_minor, $sum, $p->currency );
				$not_paid[ $p->currency ] = ( isset( $not_paid[ $p->currency ] ) ? $not_paid[ $p->currency ] : 0 ) + $none;
				$withheld[ $p->currency ] = ( isset( $withheld[ $p->currency ] ) ? $withheld[ $p->currency ] : 0 ) + $held;
			}
			if ( $issued && $p->storno_at && $p->storno_at >= $from && $p->storno_at <= $to && (string) $p->storno_number ) {
				/* translators: %s: credit note number */
				$line( (string) $p->storno_number . ' (' . sprintf( __( 'cancels %s', 'affiliate-captain' ), $p->number ) . ')', $date( $p->storno_at ), -$net, -(int) $p->vat_minor, -$sum, $p->currency );
				$not_paid[ $p->currency ] = ( isset( $not_paid[ $p->currency ] ) ? $not_paid[ $p->currency ] : 0 ) - $none;
				$withheld[ $p->currency ] = ( isset( $withheld[ $p->currency ] ) ? $withheld[ $p->currency ] : 0 ) - $held;
			}
		}
		// Correction credit notes dated in the year: their difference changes the year's amounts.
		$k = Db::table( Corrections::TABLE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$corrections = (array) $wpdb->get_results( $wpdb->prepare( "SELECT k.*, p.number AS payout_number FROM {$k} k LEFT JOIN {$t} p ON p.id = k.payout_id WHERE k.affiliate_id = %d AND k.created_at BETWEEN %s AND %s ORDER BY k.created_at ASC", $affiliate_id, $from, $to ) );
		foreach ( $corrections as $kg ) {
			$diff_net = (int) $kg->net_minor - (int) $kg->prev_net_minor;
			$diff_vat = (int) $kg->vat_minor - (int) $kg->prev_vat_minor;
			/* translators: %s: credit note number */
			$line( (string) $kg->number . ' (' . sprintf( __( 'corrects %s', 'affiliate-captain' ), (string) $kg->payout_number ) . ')', $date( $kg->created_at ), $diff_net, $diff_vat, $diff_net + $diff_vat, (string) $kg->currency );
		}
		if ( ! $rows && ! $corrections ) {
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
		foreach ( $not_paid as $cur => $amount ) {
			if ( $amount ) {
				// Settled with the credit notes, not part of the totals (no service).
				$pdf->text( 50, $y, __( 'Repayments and write-offs (not taxable)', 'affiliate-captain' ), 9 );
				$pdf->text( 545, $y, Money::format( $amount, $cur ), 9, false, 'right' );
				$y += 13;
			}
		}
		foreach ( $withheld as $cur => $amount ) {
			if ( $amount ) {
				// SET-AC-20: included in the gross totals above, not transferred to the partner.
				/* translators: %s: currency */
				$pdf->text( 50, $y, sprintf( __( 'Withheld from the payouts (included in the total %s)', 'affiliate-captain' ), $cur ), 9 );
				$pdf->text( 545, $y, Money::format( $amount, $cur ), 9, false, 'right' );
				$y += 13;
			}
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
		$k = Db::table( Corrections::TABLE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT created_at FROM {$k} WHERE affiliate_id = %d", (int) $affiliate_id ) ) as $when ) {
			$years[ (int) get_date_from_gmt( (string) $when, 'Y' ) ] = true;
		}
		krsort( $years );
		return array_keys( $years );
	}

	/**
	 * Generate and store the statement of a payout (only once).
	 *
	 * A cancelled payout only gets its credit note together with its cancellation document: when
	 * cancel() allocated a cancellation number before the credit note was written (payout run
	 * with the job queue), generate_storno() writes the credit note first and then the
	 * cancellation. Without a cancellation number (cancelled by an earlier version) it gets none – a
	 * credit note of a cancelled payout without its cancellation would count as turnover (R12-K2).
	 *
	 * @param int $payout_id Payout.
	 * @param int $wait      Seconds to wait while another request writes it (R13-N9; 0 = none).
	 * @return bool
	 */
	public static function generate( $payout_id, $wait = 0 ) {
		global $wpdb;
		self::$busy = false;
		// Claim the payout: the payout run and the hourly cron (or two cron runs) must not both
		// write a credit note for it (two files, the second overwriting the stored hash).
		$owner = self::claim( 'wpac_stmt_' . (int) $payout_id, $wait );
		if ( '' === $owner ) {
			self::$busy = true; // Being written right now – not a write error (see was_busy()).
			return false;
		}
		try {
			$p = PayoutService::find( $payout_id );
			// Deleted after the retention period: never issued again (statement_file is empty then).
			if ( ! $p || '' !== (string) $p->statement_file || $p->statement_deleted_at || ( 'cancelled' === (string) $p->status && '' === (string) $p->storno_number ) ) {
				return false;
			}
			$bytes = self::build( $p );
			$name  = sanitize_file_name( $p->number . '-' . wp_generate_password( 16, false ) . '.pdf' );
			$store = self::store();
			if ( ! $store->put( $name, $bytes ) ) {
				// Number and data stay with the payout: the hourly cron writes it again (growing pauses).
				Audit::log( 'statement.write_failed', 'payout', (int) $payout_id );
				self::store_failed( $store, 'Credit note could not be written', array( 'payout' => (int) $payout_id ) );
				self::backoff( (int) $payout_id );
				return false;
			}
			// Only if still without a file (compare-and-set).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$done = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payouts' ) . " SET statement_file = %s, statement_hash = %s WHERE id = %d AND statement_file = '' AND statement_deleted_at IS NULL AND ( status <> 'cancelled' OR storno_number <> '' )", $name, hash( 'sha256', $bytes ), (int) $payout_id ) );
			if ( 1 !== $done ) {
				$store->delete( $name );
				return false;
			}
			self::store_ok();
			$path = self::attachment_path( $store, $name, $bytes );
			self::clear_backoff( (int) $payout_id );
			Audit::log( 'statement.generated', 'payout', (int) $payout_id, array( 'hash' => hash( 'sha256', $bytes ) ) );
		} finally {
			Db::unlock( 'wpac_stmt_' . (int) $payout_id, $owner );
		}
		/**
		 * A credit note (or correction document) was issued and stored – e.g. to send it to the
		 * partner (a credit note only takes effect once the partner received it, §14 Abs. 2 UStG).
		 *
		 * @param int    $payout_id Payout.
		 * @param string $path      Stored PDF (with object storage: a private temporary copy that is
		 *                          deleted at the end of the request; '' if none could be made).
		 */
		do_action( 'wpac_statement_generated', (int) $payout_id, $path );
		return true;
	}

	/** @var bool Whether the last generate() found the credit note being written by another request. */
	private static $busy = false;

	/**
	 * Whether the last generate() returned false because another request is writing the same
	 * credit note right now (e.g. the job queue right after a payout run) – then "check the
	 * folder" would be the wrong advice: it is there in a moment.
	 *
	 * @return bool
	 */
	public static function was_busy() {
		return self::$busy;
	}

	/**
	 * Take a document lock, waiting up to $wait seconds while another request holds it (a
	 * PDF takes a few seconds; the lock of a request that died expires after 10 minutes).
	 *
	 * @param string $name Lock name.
	 * @param int    $wait Seconds (0 = try once).
	 * @return string Owner ('' = still held by someone else).
	 */
	private static function claim( $name, $wait ) {
		/**
		 * Seconds to wait for a credit note or cancellation document that another request is
		 * writing right now, before the caller reports "being generated, try again".
		 *
		 * @param int    $wait Default given by the caller (20–30 seconds; 0 for cron runs).
		 * @param string $name Lock name.
		 */
		$wait     = $wait > 0 ? max( 0, (int) apply_filters( 'wpac_statement_lock_wait', (int) $wait, $name ) ) : 0;
		$deadline = microtime( true ) + $wait;
		while ( true ) {
			$owner = Db::lock( $name, 10 * MINUTE_IN_SECONDS );
			if ( '' !== $owner || microtime( true ) >= $deadline ) {
				return $owner;
			}
			usleep( 250000 );
		}
	}

	const RETRY_OPTION = 'wpac_statement_retry';

	/**
	 * Remember a failed write and wait longer each time (1, 2, 4 … 24 hours).
	 *
	 * @param int    $payout_id Payout.
	 * @param string $option    Retry option.
	 */
	private static function backoff( $payout_id, $option = self::RETRY_OPTION ) {
		$all              = get_option( $option, array() );
		$all              = is_array( $all ) ? $all : array();
		$n                = isset( $all[ $payout_id ] ) ? (int) $all[ $payout_id ]['n'] + 1 : 1;
		$all[ $payout_id ] = array(
			'n'    => $n,
			'next' => time() + min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** ( $n - 1 ) ) ),
		);
		update_option( $option, $all, false );
	}

	/**
	 * Forget the failures of a payout.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $option    Retry option.
	 */
	private static function clear_backoff( $payout_id, $option = self::RETRY_OPTION ) {
		$all = get_option( $option, array() );
		if ( is_array( $all ) && isset( $all[ $payout_id ] ) ) {
			unset( $all[ $payout_id ] );
			update_option( $option, $all, false );
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
		global $wpdb;
		if ( ! $all ) {
			return $all;
		}
		// One query for all entries (runs on every admin page while credit notes are waiting).
		$ids   = array_map( 'intval', array_keys( $all ) );
		$t     = Db::table( 'payouts' );
		$still = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ") AND status <> 'cancelled' AND statement_file = '' AND statement_deleted_at IS NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Integers only.
		$keep  = array();
		foreach ( $all as $id => $entry ) {
			if ( in_array( (int) $id, $still, true ) ) {
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
	 * Stream the stored PDF (caller checks permissions) and exit. A file missing on this server
	 * is built again from the stored data (stored_bytes()).
	 *
	 * @param object $p      Payout.
	 * @param bool   $storno Cancellation document.
	 */
	public static function send( $p, $storno = false ) {
		$bytes = self::stored_bytes( $p, $storno );
		if ( null === $bytes ) {
			wp_die( esc_html__( 'The statement is not available (anymore).', 'affiliate-captain' ), 404 );
		}
		if ( false === $bytes ) {
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
	 * Bytes of a stored document (read through the store). A file that is missing (several app
	 * servers without shared storage, lost file, store unreachable) is built again from the stored data: if the result
	 * matches the stored hash it is the original and is stored again; otherwise a copy marked
	 * as duplicate is returned. Documents deleted after the retention period (on any server: the
	 * database decides) are neither served nor rebuilt, and a copy left on this server is deleted.
	 *
	 * @param object $p      Payout (statement_file/statement_hash of the document to send).
	 * @param bool   $storno Cancellation document.
	 * @return string|null|false Bytes, null = not available, false = integrity check failed.
	 */
	public static function stored_bytes( $p, $storno = false ) {
		if ( '' === (string) $p->statement_file ) {
			return null;
		}
		$path = self::path( (string) $p->statement_file );
		$orig = PayoutService::find( (int) $p->id );
		if ( ! $orig || $orig->statement_deleted_at ) {
			StatementSweep::forget( $path, (string) $p->statement_hash );
			return null;
		}
		$bytes = self::read_document( (string) $p->statement_file );
		if ( null !== $bytes ) {
			return hash_equals( (string) $p->statement_hash, hash( 'sha256', $bytes ) ) ? $bytes : false;
		}
		$bytes = self::build( $orig, $storno );
		if ( hash_equals( (string) $p->statement_hash, hash( 'sha256', $bytes ) ) ) {
			$store = self::store();
			$name  = basename( (string) $p->statement_file );
			$store->put( $name, $bytes );
			// The retention deletion may have run meanwhile (on another server): no copy outlives it.
			$now = PayoutService::find( (int) $p->id );
			if ( ! $now || $now->statement_deleted_at ) {
				if ( $store instanceof LocalStore ) {
					StatementSweep::forget( self::dir() . '/' . $name, (string) $p->statement_hash );
				} else {
					$store->delete( $name, (string) $p->statement_hash );
				}
				return null;
			}
			Audit::log( 'statement.restored', 'payout', (int) $p->id, array( 'storno' => (bool) $storno ) );
			return $bytes;
		}
		Audit::log( 'statement.duplicate', 'payout', (int) $p->id, array( 'storno' => (bool) $storno ) );
		return self::build( $orig, $storno, true );
	}

	/**
	 * Earlier storage folders: the uploads folder (before WPAC_STATEMENT_DIR was defined) and the
	 * flat WPAC_STATEMENT_DIR itself; only those that differ from the current folder.
	 *
	 * @return string[]
	 */
	public static function legacy_dirs() {
		return LocalStore::legacy();
	}

	/**
	 * Full local path of a stored statement: the current folder, or an earlier one when the folder
	 * was changed later (e.g. WPAC_STATEMENT_DIR defined after the first credit notes).
	 *
	 * @param string $file Stored file name.
	 * @return string
	 */
	public static function path( $file ) {
		return LocalStore::resolve( $file );
	}

	/**
	 * Statements (and cancellations) that are still in an earlier folder – with object storage
	 * (or a custom store): every document still in a local folder of this server (not migrated).
	 *
	 * All payouts are looked at, in pages by ID (no fixed limit: documents of later payouts were
	 * never found before). It touches the file system for every document, so pages call
	 * Monitor's daily result (Monitor::check_migrations()) instead of this.
	 *
	 * @param int $limit Max. results (0 = all).
	 * @return array List of [ payout ID, file name, old path ].
	 */
	public static function misplaced( $limit = 0 ) {
		if ( ! self::local_store() ) {
			return array_map(
				static function ( $doc ) {
					return array( $doc[0], $doc[1], $doc[2] );
				},
				self::local_documents( $limit )
			);
		}
		global $wpdb;
		$legacy = self::legacy_dirs();
		if ( ! $legacy ) {
			return array();
		}
		$t       = Db::table( 'payouts' );
		$current = self::dir();
		$out     = array();
		$last    = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, statement_file, storno_file FROM {$t} WHERE id > %d AND ( statement_file <> '' OR storno_file <> '' ) ORDER BY id ASC LIMIT 500", $last ) );
			foreach ( $rows as $r ) {
				$last = (int) $r->id;
				foreach ( array( $r->statement_file, $r->storno_file ) as $file ) {
					$name = basename( (string) $file );
					if ( '' === $name || file_exists( $current . '/' . $name ) ) {
						continue;
					}
					foreach ( $legacy as $dir ) {
						if ( is_readable( $dir . '/' . $name ) ) {
							$out[] = array( (int) $r->id, $name, $dir . '/' . $name );
							break;
						}
					}
					if ( $limit > 0 && count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		} while ( 500 === count( $rows ) );
		return $out;
	}

	/**
	 * Documents of this site's payouts and correction credit notes that are in a local folder of
	 * this server (current or earlier one), with their stored hash.
	 *
	 * @param int $limit Max. results (0 = all).
	 * @return array List of [ payout ID, file name, path, hash ].
	 */
	private static function local_documents( $limit = 0 ) {
		global $wpdb;
		$t    = Db::table( 'payouts' );
		$out  = array();
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, statement_file, statement_hash, storno_file, storno_hash FROM {$t} WHERE id > %d AND ( statement_file <> '' OR storno_file <> '' ) ORDER BY id ASC LIMIT 500", $last ) );
			foreach ( $rows as $r ) {
				$last = (int) $r->id;
				foreach ( array( array( $r->statement_file, $r->statement_hash ), array( $r->storno_file, $r->storno_hash ) ) as $doc ) {
					$name = LocalStore::clean( (string) $doc[0] );
					if ( '' === $name ) {
						continue;
					}
					$path = LocalStore::resolve( $name );
					if ( is_readable( $path ) ) {
						$out[] = array( (int) $r->id, $name, $path, (string) $doc[1] );
					}
					if ( $limit > 0 && count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		} while ( 500 === count( $rows ) );
		// Correction credit notes (same folder and store).
		$k    = Db::table( Corrections::TABLE );
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, payout_id, file, hash FROM {$k} WHERE id > %d AND file <> '' ORDER BY id ASC LIMIT 500", $last ) );
			foreach ( $rows as $r ) {
				$last = (int) $r->id;
				$name = LocalStore::clean( (string) $r->file );
				$path = '' !== $name ? LocalStore::resolve( $name ) : '';
				if ( '' !== $path && is_readable( $path ) ) {
					$out[] = array( (int) $r->payout_id, $name, $path, (string) $r->hash );
				}
				if ( $limit > 0 && count( $out ) >= $limit ) {
					return $out;
				}
			}
		} while ( 500 === count( $rows ) );
		return $out;
	}

	/**
	 * Copy the local documents of this server into a store (e.g. object storage): upload, read
	 * back and compare the hash; only a verified copy lets the local file go. A local file whose
	 * content no longer matches the stored hash is not copied (it stays for inspection).
	 *
	 * @param StatementStore $target  Target store.
	 * @param bool           $dry_run Only count.
	 * @return array found, copied, failed, files (names; dry run: those that would be copied).
	 */
	public static function migrate( StatementStore $target, $dry_run = false ) {
		$out = array(
			'found'  => 0,
			'copied' => 0,
			'failed' => 0,
			'files'  => array(),
		);
		if ( $target instanceof LocalStore ) {
			return $out;
		}
		foreach ( self::local_documents() as $doc ) {
			list( $id, $name, $path, $hash ) = $doc;
			++$out['found'];
			if ( $dry_run ) {
				$out['files'][] = $name;
				continue;
			}
			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$sum   = false === $bytes ? '' : hash( 'sha256', (string) $bytes );
			$valid = '' !== $sum && ( '' === $hash || hash_equals( $hash, $sum ) );
			$back  = $valid && $target->put( $name, (string) $bytes ) ? $target->get( $name ) : null;
			if ( null === $back || ! hash_equals( $sum, hash( 'sha256', $back ) ) ) {
				++$out['failed'];
				\WPAffiliateCaptain\Core\Log::warning(
					'Credit note could not be copied to the document store',
					array(
						'payout' => (int) $id,
						'store'  => (string) $target->id(),
						'cause'  => Db::cut( $valid ? (string) $target->last_error() : 'local file does not match the stored hash', 300 ),
					)
				);
				continue;
			}
			wp_delete_file( $path );
			++$out['copied'];
			$out['files'][] = $name;
		}
		if ( ! $dry_run && ( $out['copied'] || $out['failed'] ) ) {
			Audit::log(
				'statement.migrated',
				'settings',
				0,
				array(
					'store'  => (string) $target->id(),
					'copied' => $out['copied'],
					'failed' => $out['failed'],
				)
			);
		}
		return $out;
	}

	/**
	 * Move statements from earlier folders into the current one (copy, verify, then delete the
	 * old file) – with object storage into the store (migrate()).
	 *
	 * @return array [ moved, failed ]
	 */
	public static function move_misplaced() {
		$store = self::store();
		if ( ! $store instanceof LocalStore ) {
			$res = self::migrate( $store );
			return array( $res['copied'], $res['failed'] );
		}
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
		// The cancellation refers to the credit note: when the payout was cancelled before its
		// credit note was written, write the credit note first (same order as for a credit note
		// that already existed). Retried with the cancellation (generate_missing_stornos()).
		$p = PayoutService::find( $payout_id );
		if ( $p && '' !== (string) $p->storno_number && '' === (string) $p->storno_file && ! $p->statement_deleted_at && '' === (string) $p->statement_file && '' === (string) $p->statement_hash ) {
			// R13-N9: cancelled while the job queue writes its credit note – wait for it instead
			// of an hour of back-off (and a cancellation alarm) for a lock that is just busy.
			self::generate( (int) $payout_id, 30 );
			$p = PayoutService::find( $payout_id );
			if ( ! $p || '' === (string) $p->statement_file ) {
				if ( ! self::$busy ) {
					self::backoff( (int) $payout_id, self::STORNO_RETRY_OPTION );
				}
				return false; // Still busy: the next cron run (generate_missing_stornos()) writes it.
			}
		}
		$owner = self::claim( 'wpac_storno_' . (int) $payout_id, 30 );
		if ( '' === $owner ) {
			return false;
		}
		try {
			$p = PayoutService::find( $payout_id );
			if ( ! $p || '' === (string) $p->storno_number || '' !== (string) $p->storno_file || $p->statement_deleted_at ) {
				return false;
			}
			$bytes = self::build( $p, true );
			$name  = sanitize_file_name( $p->storno_number . '-' . wp_generate_password( 16, false ) . '.pdf' );
			$store = self::store();
			if ( ! $store->put( $name, $bytes ) ) {
				Audit::log( 'statement.storno_write_failed', 'payout', (int) $payout_id );
				self::store_failed( $store, 'Cancellation document could not be written', array( 'payout' => (int) $payout_id ) );
				self::backoff( (int) $payout_id, self::STORNO_RETRY_OPTION );
				return false;
			}
			// Only if still without a file (compare-and-set).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$done = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::table( 'payouts' ) . " SET storno_file = %s, storno_hash = %s WHERE id = %d AND storno_file = '' AND statement_deleted_at IS NULL", $name, hash( 'sha256', $bytes ), (int) $payout_id ) );
			if ( 1 !== $done ) {
				$store->delete( $name );
				return false;
			}
			self::store_ok();
			$path = self::attachment_path( $store, $name, $bytes );
			self::clear_backoff( (int) $payout_id, self::STORNO_RETRY_OPTION );
			Audit::log( 'statement.storno_generated', 'payout', (int) $payout_id, array( 'number' => $p->storno_number ) );
		} finally {
			Db::unlock( 'wpac_storno_' . (int) $payout_id, $owner );
		}
		/**
		 * A cancellation document was issued and stored (e.g. to send it to the partner).
		 *
		 * @param int    $payout_id Payout.
		 * @param string $path      Stored PDF (object storage: temporary copy, see wpac_statement_generated).
		 */
		do_action( 'wpac_storno_generated', (int) $payout_id, $path );
		return true;
	}

	const STORNO_RETRY_OPTION = 'wpac_storno_retry';

	/**
	 * Cancellation documents that are still missing (number allocated, file not written, e.g.
	 * the folder was not writable): generated again by the hourly cron with growing pauses.
	 *
	 * @param int $limit Max. documents per run.
	 * @return int Number generated.
	 */
	public static function generate_missing_stornos( $limit = 20 ) {
		global $wpdb;
		$t   = Db::table( 'payouts' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE storno_number <> '' AND storno_number IS NOT NULL AND storno_file = '' AND statement_deleted_at IS NULL ORDER BY id ASC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all = get_option( self::STORNO_RETRY_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			if ( isset( $all[ (int) $id ] ) && (int) $all[ (int) $id ]['next'] > time() ) {
				continue;
			}
			$n += self::generate_storno( (int) $id ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Cancellations whose document is still missing (for the admin notice): payout ID => tries.
	 *
	 * @return array
	 */
	public static function storno_failures() {
		$all = get_option( self::STORNO_RETRY_OPTION, array() );
		return is_array( $all ) ? $all : array();
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
		self::send( $clone, true );
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, statement_file, statement_hash, storno_file, storno_hash FROM {$t} WHERE statement_deleted_at IS NULL AND created_at <= %s AND ( storno_at IS NULL OR storno_at <= %s ) LIMIT 200", $cutoff, $cutoff ) );
		// Through the store (with object storage the only copy, reached from every app server).
		// The local folders are cleaned as well: the current folder and the earlier ones (files not
		// moved or migrated yet must not outlive the period). An earlier folder may be shared (flat
		// WPAC_STATEMENT_DIR of a network): there only the document with this payout's hash is
		// deleted, never another site's file of the same name (LocalStore::delete()).
		$store = self::store();
		$local = $store instanceof LocalStore ? null : new LocalStore();
		$done  = 0;
		foreach ( $rows as $r ) {
			$ok = true;
			foreach ( array( array( $r->statement_file, $r->statement_hash ), array( $r->storno_file, $r->storno_hash ) ) as $doc ) {
				$name = LocalStore::clean( (string) $doc[0] );
				if ( '' === $name ) {
					continue;
				}
				$ok = $store->delete( $name, (string) $doc[1] ) && $ok;
				if ( $local ) {
					$local->delete( $name, (string) $doc[1] );
				}
			}
			if ( ! $ok ) {
				// Not marked as deleted while the document still exists: the next daily run tries again.
				self::store_failed( $store, 'Credit note could not be deleted after the retention period', array( 'payout' => (int) $r->id ) );
				continue;
			}
			++$done;
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
		// Correction credit notes: the period starts with their own date.
		return $done + Corrections::purge_expired( $cutoff );
	}
}
