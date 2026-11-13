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
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Audit;
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
		$header = fgetcsv( $fh, 0, $sep, '"', '\\' );
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
		while ( false !== ( $cells = fgetcsv( $fh, 0, $sep, '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
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

	/**
	 * Interpret rate and rate type ("15", "15 %", "12,5%", "5,00 €" or rate_type fixed/flat).
	 *
	 * @param string $rate Rate cell.
	 * @param string $type Rate type cell.
	 * @return array|null [ rate_type percent|fixed, decimal string ] or null when invalid; [] when empty.
	 */
	public static function parse_rate( $rate, $type = '' ) {
		$rate = trim( (string) $rate );
		if ( '' === $rate ) {
			return array();
		}
		$type  = strtolower( trim( (string) $type ) );
		$fixed = in_array( $type, array( 'fixed', 'flat', 'flat_rate', 'amount' ), true ) || (bool) preg_match( '/[€$£]|\b[A-Z]{3}\b/u', $rate );
		if ( '' !== $type && ! $fixed && ! in_array( $type, array( 'percent', 'percentage', '%' ), true ) ) {
			return null;
		}
		$number = trim( preg_replace( '/[^0-9.,\-]/', '', $rate ) );
		$dec    = Money::normalize_decimal( $number );
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
		foreach ( $rows as $line => $row ) {
			$errors = array();
			$notes  = array();
			$email  = AffiliateRepository::normalize_email( isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '' );
			if ( ! is_email( $email ) ) {
				$errors[] = __( 'invalid or missing e-mail address', 'affiliate-captain' );
			} elseif ( isset( $seen_emails[ strtolower( $email ) ] ) ) {
				/* translators: %d: line number */
				$errors[] = sprintf( __( 'e-mail address already used in line %d', 'affiliate-captain' ), $seen_emails[ strtolower( $email ) ] );
			} elseif ( AffiliateRepository::find_by_email( $email ) ) {
				$errors[] = __( 'a partner with this e-mail address already exists', 'affiliate-captain' );
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
				if ( strlen( $norm ) < 3 ) {
					/* translators: %s: code */
					$errors[] = sprintf( __( 'code "%s" is too short (at least 3 characters)', 'affiliate-captain' ), $code );
				} elseif ( isset( $seen_codes[ $norm ] ) ) {
					/* translators: 1: code, 2: line number */
					$errors[] = sprintf( __( 'code "%1$s" already used in line %2$d', 'affiliate-captain' ), $norm, $seen_codes[ $norm ] );
				} elseif ( CodeRepository::find_by_code( $norm ) ) {
					/* translators: %s: code */
					$errors[] = sprintf( __( 'code "%s" is already in use', 'affiliate-captain' ), $norm );
				} else {
					$seen_codes[ $norm ] = $line;
					$codes[]             = $norm;
				}
			}
			if ( ! $codes && 'active' === $status ) {
				$notes[] = __( 'a random partner code is created', 'affiliate-captain' );
			}
			$rate = self::parse_rate( isset( $row['rate'] ) ? $row['rate'] : '', isset( $row['rate_type'] ) ? $row['rate_type'] : '' );
			if ( null === $rate ) {
				$errors[] = __( 'invalid commission rate (percent 0–100, or an amount with rate_type "fixed")', 'affiliate-captain' );
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
						$errors[] = sprintf( __( 'partner group #%s does not exist', 'affiliate-captain' ), $group_name );
					}
				} elseif ( isset( $groups[ 'name:' . strtolower( $group_name ) ] ) ) {
					$group_id = $groups[ 'name:' . strtolower( $group_name ) ];
				} elseif ( ! $allow_program ) {
					/* translators: %s: group name */
					$errors[] = sprintf( __( 'partner group "%s" does not exist', 'affiliate-captain' ), $group_name );
				} else {
					/* translators: %s: group name */
					$notes[] = sprintf( __( 'new partner group "%s" is created', 'affiliate-captain' ), $group_name );
				}
			}
			$user_id = absint( isset( $row['user_id'] ) ? $row['user_id'] : 0 );
			if ( $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					/* translators: %d: user ID */
					$errors[] = sprintf( __( 'WordPress user #%d does not exist', 'affiliate-captain' ), $user_id );
				} elseif ( strtolower( (string) $user->user_email ) !== strtolower( $email ) ) {
					/* translators: %d: user ID */
					$errors[] = sprintf( __( 'WordPress user #%d has a different e-mail address', 'affiliate-captain' ), $user_id );
				} elseif ( user_can( $user, 'edit_posts' ) ) {
					/* translators: %d: user ID */
					$errors[] = sprintf( __( 'WordPress user #%d can edit content and is not linked automatically', 'affiliate-captain' ), $user_id );
				} elseif ( AffiliateRepository::find_by_user( $user_id ) ) {
					/* translators: %d: user ID */
					$errors[] = sprintf( __( 'WordPress user #%d already belongs to a partner', 'affiliate-captain' ), $user_id );
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
			);
		}
		return $out;
	}

	/**
	 * Import (or dry run).
	 *
	 * @param string $csv     CSV text.
	 * @param array  $options dry_run (bool), notify (bool: send the usual e-mails to imported partners), max_rows (int),
	 *                        allow_program (bool: rules and new groups may be created).
	 * @return array|\WP_Error [ 'rows' => validate() result with 'result' per line, 'created' => int, 'failed' => int, 'unknown' => string[] ]
	 */
	public static function run( $csv, array $options = array() ) {
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
		$rows    = self::validate( $parsed['rows'], ! empty( $options['allow_program'] ) );
		$created = 0;
		$failed  = 0;
		foreach ( $rows as $line => $r ) {
			if ( $r['errors'] ) {
				++$failed;
				$rows[ $line ]['result'] = 'error';
				continue;
			}
			if ( $options['dry_run'] ) {
				$rows[ $line ]['result'] = 'ok';
				continue;
			}
			$res = self::import_row( $r['data'], ! empty( $options['notify'] ) );
			if ( is_wp_error( $res ) ) {
				++$failed;
				$rows[ $line ]['errors'][] = $res->get_error_message();
				$rows[ $line ]['result']   = 'error';
			} else {
				++$created;
				$rows[ $line ]['result'] = 'created';
				$rows[ $line ]['id']     = (int) $res;
			}
		}
		if ( ! $options['dry_run'] ) {
			Audit::log(
				'affiliate.imported',
				'affiliate',
				0,
				array(
					'created' => $created,
					'failed'  => $failed,
				)
			);
		}
		return array(
			'rows'    => $rows,
			'created' => $created,
			'failed'  => $failed,
			'unknown' => $parsed['unknown'],
		);
	}

	/**
	 * Create one validated partner: account (pending), codes, rule, then the target status.
	 *
	 * @param array $d      Normalised row from validate().
	 * @param bool  $notify Send the usual e-mails (approval, account) to the partner.
	 * @return int|\WP_Error Partner ID.
	 */
	private static function import_row( array $d, $notify ) {
		$mute = static function () {
			return true; // Short-circuit wp_mail(): treated as sent, nothing leaves the server.
		};
		if ( ! $notify ) {
			add_filter( 'pre_wp_mail', $mute, 99 );
		}
		try {
			$group_id = (int) $d['group_id'];
			if ( ! $group_id && '' !== $d['group_name'] ) {
				foreach ( Groups::all() as $g ) {
					if ( strtolower( trim( $g->name ) ) === strtolower( $d['group_name'] ) ) {
						$group_id = (int) $g->id; // Created by an earlier row of this import.
					}
				}
				if ( ! $group_id ) {
					$group_id = (int) Groups::create( $d['group_name'] );
				}
			}
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
			foreach ( $d['codes'] as $code ) {
				$c = CodeRepository::create( $id, $code, array( 'strict' => true ) );
				if ( is_wp_error( $c ) ) {
					/* translators: 1: partner ID, 2: error */
					return new \WP_Error( 'wpac_import_code', sprintf( __( 'Partner #%1$d was created, but a code failed: %2$s', 'affiliate-captain' ), $id, $c->get_error_message() ) );
				}
			}
			if ( $d['rate'] ) {
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
				if ( ! is_wp_error( $rule ) ) {
					RuleRepository::save( $rule, 0, __( 'CSV import', 'affiliate-captain' ) );
				}
			}
			if ( 'pending' !== $d['status'] ) {
				$set = AffiliateRepository::set_status( $id, $d['status'], __( 'CSV import', 'affiliate-captain' ) );
				if ( is_wp_error( $set ) ) {
					/* translators: 1: partner ID, 2: error */
					return new \WP_Error( 'wpac_import_status', sprintf( __( 'Partner #%1$d was created, but the status could not be set: %2$s', 'affiliate-captain' ), $id, $set->get_error_message() ) );
				}
			}
			return (int) $id;
		} finally {
			if ( ! $notify ) {
				remove_filter( 'pre_wp_mail', $mute, 99 );
			}
		}
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
