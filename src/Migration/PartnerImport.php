<?php
/**
 * CSV import of partners (admin page and `wp wpac import partners`).
 *
 * Columns (header row, any order, case-insensitive; see docs/IMPORT-EXPORT.md):
 * name, email, website, status, codes, rate, rate_type, group, user_id, channel.
 * Common column names of AffiliateWP and SliceWP exports are recognised as aliases.
 *
 * Every row is validated before anything is written; a dry run only reports what would happen.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Migration;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;

defined( 'ABSPATH' ) || exit;

final class PartnerImport {

	/** Rows per import from the admin page (WP-CLI has no limit). */
	const ADMIN_MAX_ROWS = 2000;

	/** Recognised column names (normalised: lower case, letters and digits only) → field. */
	const ALIASES = array(
		'name'             => 'name',
		'partner'          => 'name',
		'partnername'      => 'name',
		'affiliate'        => 'name',
		'affiliatename'    => 'name',
		'fullname'         => 'name',
		'displayname'      => 'name',
		'firstname'        => 'first_name',
		'lastname'         => 'last_name',
		'email'            => 'email',
		'emailaddress'     => 'email',
		'useremail'        => 'email',
		'affiliateemail'   => 'email',
		'paymentemail'     => 'payment_email',
		'website'          => 'website',
		'websiteurl'       => 'website',
		'url'              => 'website',
		'site'             => 'website',
		'status'           => 'status',
		'code'             => 'codes',
		'codes'            => 'codes',
		'partnercode'      => 'codes',
		'partnercodes'     => 'codes',
		'referralcode'     => 'codes',
		'affiliatecode'    => 'codes',
		'rate'             => 'rate',
		'commissionrate'   => 'rate',
		'referralrate'     => 'rate',
		'ratetype'         => 'rate_type',
		'commissiontype'   => 'rate_type',
		'referralratetype' => 'rate_type',
		'group'            => 'group',
		'partnergroup'     => 'group',
		'affiliategroup'   => 'group',
		'userid'           => 'user_id',
		'wpuserid'         => 'user_id',
		'channel'          => 'channel',
	);

	/** Status values of this plugin and of common export formats. */
	const STATUS_MAP = array(
		'active'     => 'active',
		'approved'   => 'active',
		'pending'    => 'pending',
		'paused'     => 'paused',
		'inactive'   => 'paused',
		'terminated' => 'terminated',
		'rejected'   => 'rejected',
	);

	/**
	 * Parse CSV text into rows keyed by field (no WordPress functions: unit tested).
	 *
	 * @param string $csv CSV text (UTF-8, optional BOM; separator ; , or tab).
	 * @return array{fields: string[], rows: array, error: string, unknown: string[]}
	 */
	public static function parse( $csv ) {
		$csv   = (string) $csv;
		$csv   = 0 === strpos( $csv, "\xEF\xBB\xBF" ) ? substr( $csv, 3 ) : $csv;
		$csv   = str_replace( array( "\r\n", "\r" ), "\n", $csv );
		$out   = array(
			'fields'  => array(),
			'rows'    => array(),
			'error'   => '',
			'unknown' => array(),
		);
		$first = strtok( $csv, "\n" );
		if ( false === $first || '' === trim( $first ) ) {
			$out['error'] = 'empty';
			return $out;
		}
		$sep = ';';
		$max = -1;
		foreach ( array( ';', ',', "\t" ) as $candidate ) {
			$n = substr_count( $first, $candidate );
			if ( $n > $max ) {
				$max = $n;
				$sep = $candidate;
			}
		}
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $sep, '"', '' );
		$map    = array();
		foreach ( (array) $header as $i => $label ) {
			$key = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $label ) );
			if ( isset( self::ALIASES[ $key ] ) && ! in_array( self::ALIASES[ $key ], $map, true ) ) {
				$map[ $i ] = self::ALIASES[ $key ];
			} elseif ( '' !== $key ) {
				$out['unknown'][] = (string) $label;
			}
		}
		$out['fields'] = array_values( $map );
		if ( ! in_array( 'email', $out['fields'], true ) && ! in_array( 'payment_email', $out['fields'], true ) ) {
			$out['error'] = 'no_email_column';
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $out;
		}
		$line = 1;
		while ( false !== ( $cells = fgetcsv( $fh, 0, $sep, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( array( null ) === $cells || '' === trim( implode( '', array_map( 'strval', $cells ) ) ) ) {
				continue; // Empty line.
			}
			$row = array();
			foreach ( $map as $i => $field ) {
				$value         = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
				// Cells exported with formula protection ("'=…") come back without the quote.
				$row[ $field ] = ( '' !== $value && "'" === $value[0] && isset( $value[1] ) && in_array( $value[1], array( '=', '+', '-', '@' ), true ) ) ? substr( $value, 1 ) : $value;
			}
			if ( ( ! isset( $row['name'] ) || '' === $row['name'] ) && ( ! empty( $row['first_name'] ) || ! empty( $row['last_name'] ) ) ) {
				$row['name'] = trim( ( isset( $row['first_name'] ) ? $row['first_name'] : '' ) . ' ' . ( isset( $row['last_name'] ) ? $row['last_name'] : '' ) );
			}
			if ( ( ! isset( $row['email'] ) || '' === $row['email'] ) && ! empty( $row['payment_email'] ) ) {
				$row['email'] = $row['payment_email'];
			}
			unset( $row['first_name'], $row['last_name'], $row['payment_email'] );
			$out['rows'][ $line ] = $row;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Split a codes cell ("ANNA10, ANNA-SUMMER" or "A|B") into codes.
	 *
	 * @param string $cell Cell.
	 * @return string[]
	 */
	public static function split_codes( $cell ) {
		$parts = preg_split( '/[\s,;|]+/', (string) $cell );
		return array_values( array_unique( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) ) );
	}

	/** Currency words in rate cells ("15 Euro", "15 eur", "15 Dollar") → ISO code. */
	const CURRENCY_WORDS = array(
		'euro'    => 'EUR',
		'euros'   => 'EUR',
		'dollar'  => 'USD',
		'dollars' => 'USD',
		'pound'   => 'GBP',
		'pounds'  => 'GBP',
		'pfund'   => 'GBP',
		'franken' => 'CHF',
		'francs'  => 'CHF',
		'yen'     => 'JPY',
	);

	/** Currency signs → ISO code ('' = ambiguous, the shop currency is assumed). */
	const CURRENCY_SIGNS = array(
		'€' => 'EUR',
		'£' => 'GBP',
		'¥' => 'JPY',
		'$' => '',
	);

	/**
	 * Interpret rate and rate type ("15", "15 %", "12,5%", "5,00 €", "15 Euro", "1.500 JPY" or
	 * rate_type fixed/flat). Amounts are read with the rules of their currency (no decimals for
	 * JPY: "1.500" = 1500; thousands separators like in the settings).
	 *
	 * @param string $rate     Rate cell.
	 * @param string $type     Rate type cell.
	 * @param string $currency Shop currency ('' = not checked): an amount in another currency is refused.
	 * @return array|null [ rate_type percent|fixed, decimal string ] or null when invalid; [] when empty.
	 */
	public static function parse_rate( $rate, $type = '', $currency = '' ) {
		$rate = trim( (string) $rate );
		if ( '' === $rate ) {
			return array();
		}
		$type     = strtolower( trim( (string) $type ) );
		$currency = strtoupper( (string) $currency );
		$code     = '';
		$sign     = false;
		foreach ( self::CURRENCY_SIGNS as $symbol => $iso ) {
			if ( false !== strpos( $rate, $symbol ) ) {
				$sign = true;
				$code = $iso;
			}
		}
		$words = array();
		preg_match_all( '/[a-z]+/i', $rate, $words );
		foreach ( $words[0] as $word ) {
			$lower = strtolower( $word );
			if ( isset( self::CURRENCY_WORDS[ $lower ] ) ) {
				$code = self::CURRENCY_WORDS[ $lower ];
			} elseif ( 3 === strlen( $word ) && ! in_array( $lower, array( 'pct', 'pro' ), true ) ) {
				$code = strtoupper( $word ); // ISO code ("eur", "USD").
			} elseif ( ! in_array( $lower, array( 'pct', 'percent', 'prozent', 'pro' ), true ) ) {
				return null; // Unknown word: rather ask than guess.
			}
		}
		$fixed = in_array( $type, array( 'fixed', 'flat', 'flat_rate', 'amount' ), true ) || $sign || '' !== $code;
		if ( '' !== $type && ! $fixed && ! in_array( $type, array( 'percent', 'percentage', '%' ), true ) ) {
			return null;
		}
		if ( $fixed && false !== strpos( $rate, '%' ) ) {
			return null; // "5 € %" – contradictory.
		}
		if ( $fixed && '' !== $code && '' !== $currency && $code !== $currency ) {
			return null; // The rule is booked in the shop currency.
		}
		$number = trim( (string) preg_replace( '/[^0-9.,\-\s\']/', '', $rate ) );
		$dec    = $fixed ? Money::parse_amount( $number, '' !== $code ? $code : $currency ) : Money::normalize_decimal( $number );
		if ( null === $dec || (float) $dec < 0 || ( ! $fixed && (float) $dec > 100 ) ) {
			return null;
		}
		return array( $fixed ? 'fixed' : 'percent', $dec );
	}

	/**
	 * Validate parsed rows against the database (nothing is written).
	 *
	 * @param array $rows          Rows from parse() (line => row).
	 * @param bool  $allow_program Whether commission rules and new groups may be created
	 *                             (capability wpac_manage_program; always true on WP-CLI).
	 * @return array line => [ 'data' => normalised row, 'errors' => string[], 'notes' => string[] ]
	 */
	public static function validate( array $rows, $allow_program = true ) {
		$groups = array();
		foreach ( Groups::all() as $g ) {
			$groups[ 'id:' . (int) $g->id ]                      = (int) $g->id;
			$groups[ 'name:' . strtolower( trim( $g->name ) ) ] = (int) $g->id;
		}
		$seen_emails = array();
		$seen_codes  = array();
		$out         = array();
		$currency    = (string) \WPAffiliateCaptain\Core\Settings::get( 'currency' );
		foreach ( $rows as $line => $row ) {
			$errors = array();
			$notes  = array();
			$skip   = '';
			$email  = AffiliateRepository::normalize_email( isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '' );
			if ( ! is_email( $email ) ) {
				$errors[] = __( 'invalid or missing e-mail address – correct it in the file and check it again', 'affiliate-captain' );
			} elseif ( isset( $seen_emails[ strtolower( $email ) ] ) ) {
				/* translators: %d: line number */
				$errors[] = sprintf( __( 'e-mail address already used in line %d – each partner needs its own address', 'affiliate-captain' ), $seen_emails[ strtolower( $email ) ] );
			} elseif ( AffiliateRepository::find_by_email( $email ) ) {
				// Not an error: importing the same file again (or after an interruption) skips the
				// partners that exist already.
				$skip = __( 'a partner with this e-mail address already exists – skipped (change it on the partner page)', 'affiliate-captain' );
			}
			if ( is_email( $email ) ) {
				$seen_emails[ strtolower( $email ) ] = $line;
			}
			$name = sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' );
			if ( '' === $name && is_email( $email ) ) {
				$name = (string) strstr( $email, '@', true );
			}
			$raw_status = strtolower( trim( isset( $row['status'] ) ? (string) $row['status'] : '' ) );
			$status     = '' === $raw_status ? 'active' : ( isset( self::STATUS_MAP[ $raw_status ] ) ? self::STATUS_MAP[ $raw_status ] : '' );
			if ( '' === $status ) {
				/* translators: %s: status value from the file */
				$errors[] = sprintf( __( 'unknown status "%s" (allowed: active, pending, paused, terminated, rejected)', 'affiliate-captain' ), $raw_status );
			}
			$codes = array();
			foreach ( self::split_codes( isset( $row['codes'] ) ? $row['codes'] : '' ) as $code ) {
				$norm = CodeRepository::normalize( $code );
				// A code that cannot be used is left out, the partner is still imported (add another
				// code later on the partner page).
				if ( strlen( $norm ) < 3 ) {
					/* translators: %s: code */
					$notes[] = sprintf( __( 'code "%s" is too short (at least 3 characters) and is not imported – add a longer code on the partner page', 'affiliate-captain' ), $code );
				} elseif ( isset( $seen_codes[ $norm ] ) ) {
					/* translators: 1: code, 2: line number */
					$notes[] = sprintf( __( 'code "%1$s" is already used in line %2$d and is not imported for this partner', 'affiliate-captain' ), $norm, $seen_codes[ $norm ] );
				} elseif ( CodeRepository::find_by_code( $norm ) ) {
					/* translators: %s: code */
					$notes[] = sprintf( __( 'code "%s" is already in use by another partner and is not imported – choose another code on the partner page', 'affiliate-captain' ), $norm );
				} else {
					$seen_codes[ $norm ] = $line;
					$codes[]             = $norm;
				}
			}
			if ( ! $codes && 'active' === $status ) {
				$notes[] = __( 'a random partner code is created', 'affiliate-captain' );
			}
			$rate = self::parse_rate( isset( $row['rate'] ) ? $row['rate'] : '', isset( $row['rate_type'] ) ? $row['rate_type'] : '', $currency );
			if ( null === $rate ) {
				/* translators: %s: shop currency */
				$errors[] = sprintf( __( 'invalid commission rate – use a percentage from 0 to 100 (e.g. "12.5") or an amount in %s with rate_type "fixed" (e.g. "5.00"), or leave the cell empty', 'affiliate-captain' ), $currency );
				$rate     = array();
			} elseif ( $rate && ! $allow_program ) {
				$errors[] = __( 'you are not allowed to create commission rules (leave the rate empty)', 'affiliate-captain' );
				$rate     = array();
			}
			$group_id   = 0;
			$group_name = trim( isset( $row['group'] ) ? sanitize_text_field( $row['group'] ) : '' );
			if ( '' !== $group_name ) {
				if ( ctype_digit( $group_name ) ) {
					if ( isset( $groups[ 'id:' . (int) $group_name ] ) ) {
						$group_id = (int) $group_name;
					} else {
						/* translators: %s: group ID */
						$errors[] = sprintf( __( 'partner group #%s does not exist – use an existing group or leave the cell empty', 'affiliate-captain' ), $group_name );
					}
				} elseif ( isset( $groups[ 'name:' . strtolower( $group_name ) ] ) ) {
					$group_id = $groups[ 'name:' . strtolower( $group_name ) ];
				} elseif ( ! $allow_program ) {
					/* translators: %s: group name */
					$errors[] = sprintf( __( 'partner group "%s" does not exist – create it first (needs the right to manage the program) or leave the cell empty', 'affiliate-captain' ), $group_name );
				} else {
					/* translators: %s: group name */
					$notes[] = sprintf( __( 'new partner group "%s" is created', 'affiliate-captain' ), $group_name );
				}
			}
			$user_id = absint( isset( $row['user_id'] ) ? $row['user_id'] : 0 );
			if ( $user_id ) {
				// A user_id that cannot be used is ignored (the partner is imported without it and
				// can be linked later on the partner page).
				$user    = get_userdata( $user_id );
				$problem = '';
				if ( ! $user ) {
					/* translators: %d: user ID */
					$problem = sprintf( __( 'WordPress user #%d does not exist', 'affiliate-captain' ), $user_id );
				} elseif ( strtolower( (string) $user->user_email ) !== strtolower( $email ) ) {
					/* translators: %d: user ID */
					$problem = sprintf( __( 'WordPress user #%d has a different e-mail address', 'affiliate-captain' ), $user_id );
				} elseif ( user_can( $user, 'edit_posts' ) ) {
					/* translators: %d: user ID */
					$problem = sprintf( __( 'WordPress user #%d can edit content and is not linked automatically', 'affiliate-captain' ), $user_id );
				} elseif ( AffiliateRepository::find_by_user( $user_id ) ) {
					/* translators: %d: user ID */
					$problem = sprintf( __( 'WordPress user #%d already belongs to a partner', 'affiliate-captain' ), $user_id );
				}
				if ( '' !== $problem ) {
					/* translators: %s: problem */
					$notes[] = sprintf( __( '%s – user_id is ignored; link an account later on the partner page', 'affiliate-captain' ), $problem );
					$user_id = 0;
				}
			}
			$website = trim( isset( $row['website'] ) ? (string) $row['website'] : '' );
			if ( '' !== $website && ! preg_match( '#^https?://#i', $website ) ) {
				$website = 'https://' . $website;
			}
			$out[ $line ] = array(
				'data'   => array(
					'name'       => $name,
					'email'      => $email,
					'website'    => '' !== $website ? esc_url_raw( $website ) : '',
					'status'     => $status,
					'codes'      => $codes,
					'rate'       => $rate,
					'group_id'   => $group_id,
					'group_name' => $group_id ? '' : $group_name,
					'user_id'    => $user_id,
					'channel'    => sanitize_text_field( isset( $row['channel'] ) ? $row['channel'] : '' ),
				),
				'errors' => $errors,
				'notes'  => $notes,
				'skip'   => $errors ? '' : $skip,
			);
		}
		return $out;
	}

	/** Lock of a running import (one at a time: a double submit must not import twice). */
	const LOCK = 'wpac_import_lock';

	/**
	 * Import (or dry run) in one go (WP-CLI, tests). The admin page uses start() and step()
	 * in batches instead.
	 *
	 * @param string $csv     CSV text.
	 * @param array  $options dry_run (bool), notify (bool: send the usual e-mails to imported partners), max_rows (int),
	 *                        allow_program (bool: rules and new groups may be created).
	 * @return array|\WP_Error report(): rows (validate() result with 'result' per line: ok, error,
	 *                         skipped, created), created, failed, skipped, warnings, unknown.
	 */
	public static function run( $csv, array $options = array() ) {
		$state = self::start( $csv, $options );
		if ( is_wp_error( $state ) || $state['dry_run'] ) {
			return is_wp_error( $state ) ? $state : self::report( $state );
		}
		$owner = Db::lock( self::LOCK, 300 );
		if ( '' === $owner ) {
			return self::running_error();
		}
		try {
			while ( ! self::step( $state, 0 ) ) {
				$owner = Db::refresh( self::LOCK, $owner );
			}
		} finally {
			Db::unlock( self::LOCK, $owner );
		}
		return self::report( $state );
	}

	/**
	 * Error: another import holds the lock.
	 *
	 * @return \WP_Error
	 */
	public static function running_error() {
		return new \WP_Error( 'wpac_import_running', __( 'Another import is running right now. Please wait until it has finished and check the partner list before you start it again.', 'affiliate-captain' ) );
	}

	/**
	 * Parse and check a file: the state of an import that step() continues (a dry run is
	 * finished right away).
	 *
	 * @param string $csv     CSV text.
	 * @param array  $options See run().
	 * @return array|\WP_Error
	 */
	public static function start( $csv, array $options = array() ) {
		$options = array_merge(
			array(
				'dry_run'       => true,
				'notify'        => false,
				'max_rows'      => 0,
				'allow_program' => true,
			),
			$options
		);
		$parsed  = self::parse( $csv );
		if ( 'empty' === $parsed['error'] ) {
			return new \WP_Error( 'wpac_import_empty', __( 'The file is empty.', 'affiliate-captain' ) );
		}
		if ( 'no_email_column' === $parsed['error'] ) {
			return new \WP_Error( 'wpac_import_header', __( 'The first line must contain the column names, including "email".', 'affiliate-captain' ) );
		}
		if ( ! $parsed['rows'] ) {
			return new \WP_Error( 'wpac_import_empty', __( 'The file contains no partners.', 'affiliate-captain' ) );
		}
		if ( $options['max_rows'] && count( $parsed['rows'] ) > (int) $options['max_rows'] ) {
			/* translators: %d: maximum number of rows */
			return new \WP_Error( 'wpac_import_size', sprintf( __( 'The file has more than %d partners. Split it or use WP-CLI: wp wpac import partners <file>', 'affiliate-captain' ), (int) $options['max_rows'] ) );
		}
		$rows = self::validate( $parsed['rows'], ! empty( $options['allow_program'] ) );
		foreach ( $rows as $line => $r ) {
			$rows[ $line ]['result'] = $r['errors'] ? 'error' : ( '' !== $r['skip'] ? 'skipped' : 'ok' );
		}
		return array(
			'rows'          => $rows,
			'unknown'       => $parsed['unknown'],
			'dry_run'       => (bool) $options['dry_run'],
			'notify'        => ! empty( $options['notify'] ),
			'allow_program' => ! empty( $options['allow_program'] ),
			'cursor'        => 0,
			'started_at'    => '',
			'ids'           => array(),
			'done'          => (bool) $options['dry_run'],
		);
	}

	/**
	 * Import the next rows of a started import until the time budget is used up. Resumable: a
	 * row whose request was aborted (partner created, codes or status missing) is completed by
	 * the next step instead of being reported as "already exists".
	 *
	 * @param array $state  State from start() (updated).
	 * @param float $budget Seconds (0 = no limit; at least one row is imported per step).
	 * @return bool Whether the import is finished.
	 */
	public static function step( array &$state, $budget = 0 ) {
		if ( ! empty( $state['done'] ) ) {
			return true;
		}
		if ( '' === $state['started_at'] ) {
			$state['started_at'] = Db::now( time() - 1 );
		}
		$begin = microtime( true );
		$first = (int) $state['cursor'];
		$lines = array_keys( $state['rows'] );
		$total = count( $lines );
		// Finish a row even if the browser disconnects (no half-imported partner).
		ignore_user_abort( true );
		if ( ! $state['notify'] ) {
			Notifications::suppress( true );
		}
		try {
			while ( $state['cursor'] < $total ) {
				if ( $budget > 0 && $state['cursor'] > $first && microtime( true ) - $begin >= $budget ) { // At least one row per step.
					return false;
				}
				$line = $lines[ $state['cursor'] ];
				$r    = $state['rows'][ $line ];
				if ( 'ok' === $r['result'] ) {
					$state['rows'][ $line ] = self::import_line( $r, $state );
				}
				++$state['cursor'];
			}
		} finally {
			if ( ! $state['notify'] ) {
				Notifications::suppress( false );
			}
		}
		$state['done'] = true;
		$report        = self::report( $state );
		Audit::log(
			'affiliate.imported',
			'affiliate',
			0,
			array(
				'created'  => $report['created'],
				'skipped'  => $report['skipped'],
				'failed'   => $report['failed'],
				'warnings' => $report['warnings'],
				'notify'   => $state['notify'] ? 1 : 0,
			)
		);
		return true;
	}

	/**
	 * Import one checked row.
	 *
	 * @param array $r     Row of the state.
	 * @param array $state State (ids of partners created by this import are added).
	 * @return array Row with result created|skipped|error, id and notes.
	 */
	private static function import_line( array $r, array &$state ) {
		$existing = AffiliateRepository::find_by_email( $r['data']['email'] );
		$resume   = 0;
		if ( $existing ) {
			// Created by this import in a request that did not finish: complete it.
			if ( (string) $existing->created_at >= (string) $state['started_at'] && ! in_array( (int) $existing->id, $state['ids'], true ) ) {
				$resume = (int) $existing->id;
			} else {
				$r['result'] = 'skipped';
				$r['skip']   = __( 'a partner with this e-mail address already exists – skipped (change it on the partner page)', 'affiliate-captain' );
				return $r;
			}
		}
		$res = self::import_row( $r['data'], $state['notify'], $resume, $state['allow_program'] );
		if ( is_wp_error( $res ) ) {
			$r['errors'][] = $res->get_error_message();
			$r['result']   = 'error';
			return $r;
		}
		$state['ids'][] = (int) $res['id'];
		$r['result']    = 'created';
		$r['id']        = (int) $res['id'];
		$r['warnings']  = $res['warnings'];
		return $r;
	}

	/**
	 * Summary of a state.
	 *
	 * @param array $state State.
	 * @return array rows, created, failed (rows with errors), skipped (existing partners),
	 *               warnings (created with a problem), unknown, done, done_rows, total.
	 */
	public static function report( array $state ) {
		$out = array(
			'rows'      => $state['rows'],
			'created'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'warnings'  => 0,
			'unknown'   => $state['unknown'],
			'done'      => ! empty( $state['done'] ),
			'done_rows' => (int) $state['cursor'],
			'total'     => count( $state['rows'] ),
		);
		foreach ( $state['rows'] as $r ) {
			if ( 'error' === $r['result'] ) {
				++$out['failed'];
			} elseif ( 'skipped' === $r['result'] ) {
				++$out['skipped'];
			} elseif ( 'created' === $r['result'] ) {
				++$out['created'];
				$out['warnings'] += empty( $r['warnings'] ) ? 0 : 1;
			}
		}
		return $out;
	}

	/**
	 * Create (or complete) one validated partner: account (pending), codes, rule, then the target
	 * status – without the approval, rejection or status mails (the status of an imported
	 * partner is history, not a decision). With $notify an active partner gets the welcome mail
	 * of a partner created in the admin (portal link, set-password or account link).
	 *
	 * @param array $d             Normalised row from validate().
	 * @param bool  $notify        Send the welcome mail (and the account link).
	 * @param int   $resume        Partner created by an aborted step of this import (0 = new).
	 * @param bool  $allow_program New groups may be created.
	 * @return array|\WP_Error [ id, warnings ]
	 */
	private static function import_row( array $d, $notify, $resume = 0, $allow_program = true ) {
		$warnings = array();
		$group_id = (int) $d['group_id'];
		if ( ! $group_id && '' !== $d['group_name'] && $allow_program ) {
			foreach ( Groups::all() as $g ) {
				if ( strtolower( trim( $g->name ) ) === strtolower( $d['group_name'] ) ) {
					$group_id = (int) $g->id; // Created by an earlier row of this import.
				}
			}
			if ( ! $group_id ) {
				$group_id = (int) Groups::create( $d['group_name'] );
			}
		}
		$id = (int) $resume;
		if ( ! $id ) {
			// Created as pending (no side effects), codes first: activation only adds a random code
			// when the partner has none.
			$id = AffiliateRepository::create(
				array(
					'name'     => $d['name'],
					'email'    => $d['email'],
					'website'  => $d['website'],
					'channel'  => $d['channel'],
					'group_id' => $group_id,
					// Never linked directly (WordPress does not verify account e-mails): on activation
					// the partner gets the one-time link to confirm the account (see on_activated()).
					'user_id'  => 0,
					'status'   => 'pending',
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}
		$have = array();
		foreach ( CodeRepository::for_affiliate( (int) $id ) as $c ) {
			$have[] = CodeRepository::normalize( (string) $c->code );
		}
		foreach ( $d['codes'] as $code ) {
			if ( in_array( $code, $have, true ) ) {
				continue;
			}
			$c = CodeRepository::create( $id, $code, array( 'strict' => true ) );
			if ( is_wp_error( $c ) ) {
				// The partner stays imported; the code can be added on the partner page.
				/* translators: 1: code, 2: error */
				$warnings[] = sprintf( __( 'code "%1$s" was not created: %2$s – add a code on the partner page', 'affiliate-captain' ), $code, $c->get_error_message() );
			}
		}
		if ( $d['rate'] && ! ( $resume && self::has_rule( (int) $id ) ) ) {
			$rule = RuleRepository::sanitize(
				array(
					'scope'     => 'affiliate',
					'scope_id'  => $id,
					'target'    => 'all',
					'rate_type' => $d['rate'][0],
					'rate'      => $d['rate'][1],
					'priority'  => 0,
					'active'    => 1,
					'note'      => __( 'CSV import', 'affiliate-captain' ),
				)
			);
			if ( is_wp_error( $rule ) || ! RuleRepository::save( $rule, 0, __( 'CSV import', 'affiliate-captain' ) ) ) {
				$warnings[] = __( 'the commission rule was not created – add it under Commission rules', 'affiliate-captain' );
			}
		}
		$aff = AffiliateRepository::find( (int) $id );
		if ( $aff && $d['status'] !== $aff->status ) {
			$set = AffiliateRepository::set_status( $id, $d['status'], __( 'CSV import', 'affiliate-captain' ), array( 'notify' => false ) );
			if ( is_wp_error( $set ) ) {
				/* translators: 1: status, 2: error */
				$warnings[] = sprintf( __( 'the status "%1$s" could not be set: %2$s – the partner is pending; change the status on the partner page', 'affiliate-captain' ), $d['status'], $set->get_error_message() );
			} elseif ( 'active' === $d['status'] ) {
				if ( $notify ) {
					Notifications::partner_created( (int) $id );
				} else {
					// No WordPress "login details" mail either; the partner uses "Lost your password?".
					AffiliateRepository::forget_new_user( (int) $id );
				}
			}
		}
		return array(
			'id'       => (int) $id,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether the partner has an own commission rule (resumed row: not created twice).
	 *
	 * @param int $id Partner.
	 * @return bool
	 */
	private static function has_rule( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Db::table( 'rules' ) . " WHERE scope = 'affiliate' AND scope_id = %d LIMIT 1", $id ) );
	}

	/**
	 * Example file with all columns (download on the import page).
	 *
	 * @return array [ header, rows ]
	 */
	public static function template() {
		return array(
			array( 'name', 'email', 'website', 'status', 'codes', 'rate', 'rate_type', 'group', 'user_id', 'channel' ),
			array(
				array( 'Anna Beispiel', 'anna@example.com', 'https://anna.example.com', 'active', 'ANNA10 ANNA-SUMMER', '12.5', 'percent', 'Influencer', '', 'Instagram' ),
				array( 'Segelblog GmbH', 'info@segelblog.example', 'segelblog.example', 'pending', '', '5.00', 'fixed', '', '', 'Blog' ),
			),
		);
	}
}
