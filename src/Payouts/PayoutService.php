<?php
/**
 * Payout runs.
 *
 * - A run collects approved, not yet assigned commissions (incl. negative
 *   adjustments) up to the period end per affiliate and currency.
 * - Partners below the minimum (or with a negative balance) are carried forward.
 * - payout_items has a UNIQUE commission_id → a commission can never be paid twice.
 * - Commissions become "paid" when the payout is marked as paid; cancelling a
 *   payout releases its commissions for the next run.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Scheduler;
use WPAffiliateCaptain\Core\Sequence;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PayoutService {

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'   => __( 'Created', 'affiliate-captain' ),
			'exported'  => __( 'SEPA exported', 'affiliate-captain' ),
			'paid'      => __( 'Paid', 'affiliate-captain' ),
			'cancelled' => __( 'Cancelled', 'affiliate-captain' ),
		);
	}

	/**
	 * FROM/WHERE for commissions payable in a run (alias c).
	 *
	 * - approved and not assigned to a payout, in the payout currency;
	 * - booked up to the period end – corrections (refund/chargeback) are always
	 *   included together with their original commission, even if booked later,
	 *   so a chargeback after the cut-off is never left behind;
	 * - original commissions with corrections still in hold/review are held back.
	 *
	 * @param string $currency     Currency.
	 * @param string $end          Period end (Y-m-d H:i:s).
	 * @param int    $affiliate_id Optional partner.
	 * @return array [ sql, params ]
	 */
	private static function payable_sql( $currency, $end, $affiliate_id = 0 ) {
		$t      = Db::table( 'commissions' );
		$sql    = "FROM {$t} c LEFT JOIN {$t} p ON p.id = c.parent_id
			WHERE c.status = 'approved' AND c.payout_id = 0 AND c.currency = %s
			AND ( c.created_at <= %s OR ( c.parent_id > 0 AND p.created_at <= %s ) )
			AND NOT EXISTS ( SELECT 1 FROM {$t} ch WHERE ch.parent_id = c.id AND ch.status IN ('pending','review') )
			AND ( c.parent_id = 0 OR p.status IN ('approved','paid') )";
		$params = array( $currency, $end, $end );
		if ( $affiliate_id ) {
			$sql     .= ' AND c.affiliate_id = %d';
			$params[] = (int) $affiliate_id;
		}
		return array( $sql, $params );
	}

	/**
	 * Cut-off of a payout period: the end of the period's last day in the site's time zone, as
	 * UTC (commissions store created_at in UTC, see Db::now()). With UTC+1/+2 the first hours of
	 * the next local month must not end up in the run of the previous month (R12-M6).
	 *
	 * @param string $period_end Last day of the period (Y-m-d, local).
	 * @return string UTC "Y-m-d H:i:s" (inclusive).
	 * @throws IntegrationException For a date that does not exist ("2026-02-30" would roll over
	 *                              to March and be stored as 0000-00-00 on the credit note).
	 */
	public static function cutoff( $period_end ) {
		if ( ! self::is_date( $period_end ) ) {
			throw new IntegrationException( 'invalid_date', esc_html__( 'Please choose a valid period end.', 'affiliate-captain' ) );
		}
		$range = Db::local_range( (string) $period_end, (string) $period_end );
		return $range[1];
	}

	/**
	 * Whether a value is an existing calendar date "Y-m-d" (checkdate(): no 2026-02-30).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_date( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * What a payout of a partner would contain right now: the same rows as create_for_affiliate()
	 * (corrections still in review held back) and the gross amount incl. VAT as it would be paid.
	 *
	 * @param int         $affiliate_id Partner.
	 * @param string      $currency     Currency.
	 * @param string|null $period_end   Y-m-d (default: today).
	 * @return array net, vat, gross (minor units), count.
	 */
	public static function payable( $affiliate_id, $currency, $period_end = null ) {
		global $wpdb;
		$end                   = self::cutoff( null === $period_end ? wp_date( 'Y-m-d' ) : (string) $period_end );
		list( $from, $params ) = self::payable_sql( strtoupper( (string) $currency ), $end, (int) $affiliate_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor, c.kind, c.parent_id, c.created_at, p.created_at AS parent_created_at, p.payout_id AS parent_payout_id {$from}", $params ) );
		// Same VAT treatment as the credit note: tax data and rate at the time of each service,
		// corrections as on the original credit note, repayments and write-offs without VAT.
		list( $net, $vat_minor, $gross ) = self::row_totals( (int) $affiliate_id, $rows );
		return array(
			'net'   => (int) $net,
			'vat'   => (int) $vat_minor,
			'gross' => (int) $gross,
			'count' => count( $rows ),
		);
	}

	/**
	 * Balances available for a run.
	 *
	 * @param string $period_end Y-m-d.
	 * @param string $currency   Currency.
	 * @return array List of [ affiliate, total_minor, count, eligible, reason ].
	 */
	public static function preview( $period_end, $currency ) {
		global $wpdb;
		list( $from, $params ) = self::payable_sql( $currency, self::cutoff( $period_end ) );
		// $from is built by payable_sql() from constants; its values are placeholders in $params.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.affiliate_id, SUM(c.amount_minor) total, COUNT(*) c {$from} GROUP BY c.affiliate_id ORDER BY total DESC", $params ) );
		$operator = self::operator_missing();
		$out      = array();
		// One query for all partners instead of one per row; profiles are only loaded for
		// partners above the minimum (the others are carried forward anyway).
		AffiliateRepository::find_many( wp_list_pluck( $rows, 'affiliate_id' ) );
		foreach ( $rows as $r ) {
			$aff    = AffiliateRepository::find( (int) $r->affiliate_id );
			$min    = \WPAffiliateCaptain\Core\Fx::min_payout( $currency, (int) $r->affiliate_id ); // Per program (wpac_setting).
			$total  = (int) $r->total;
			$reason = '';
			$code   = '';
			if ( ! $aff ) {
				$reason = __( 'Affiliate not found', 'affiliate-captain' );
				$code   = 'not_found';
			} elseif ( $operator ) {
				$reason = $operator;
				$code   = 'operator';
			} elseif ( null === $min ) {
				/* translators: %s: currency */
				$reason = sprintf( __( 'No reference rate for %s – minimum cannot be checked', 'affiliate-captain' ), $currency );
				$code   = 'no_rate';
			} elseif ( $total <= 0 ) {
				$reason = __( 'Negative balance – carried forward', 'affiliate-captain' );
				$code   = 'negative';
			} else {
				// The minimum and the sign apply to the amount paid out: VAT included (and VAT
				// corrections of earlier credit notes).
				$gross = self::payable_gross( (int) $r->affiliate_id, $currency, self::cutoff( $period_end ) );
				if ( $gross <= 0 ) {
					$reason = __( 'Balance including VAT corrections is not positive – carried forward', 'affiliate-captain' );
					$code   = 'negative';
				} elseif ( $gross < $min ) {
					$reason = __( 'Below minimum – carried forward', 'affiliate-captain' );
					$code   = 'below_minimum';
				} else {
					$reason = self::blocked_reason( (int) $r->affiliate_id, ProfileRepository::get( (int) $r->affiliate_id ), $code );
				}
			}
			$out[] = array(
				'affiliate'   => $aff,
				'total_minor' => $total,
				'count'       => (int) $r->c,
				'eligible'    => '' === $reason,
				'reason'      => $reason,
				'code'        => $code,
			);
		}
		return $out;
	}

	/**
	 * VAT treatment per commission row:
	 * - repayments and write-offs are no service, differences of correction credit notes had their
	 *   VAT shown there (not taxable, Vat::NONE);
	 * - corrections whose original commission was settled in an earlier (not cancelled) credit
	 *   note take over the treatment the original commission had there (§17 UStG);
	 * - everything else gets the treatment valid at the time of the service ($resolver), or
	 *   the current treatment ($vat).
	 *
	 * @param array         $rows     Rows with id, kind, parent_id, parent_payout_id.
	 * @param array         $vat      Current treatment (mode, rate).
	 * @param callable|null $resolver Treatment of one row at the time of its service.
	 * @return array commission ID => [ mode, rate, ref ]
	 */
	public static function item_treatments( array $rows, array $vat, $resolver = null ) {
		global $wpdb;
		$out   = array();
		$cache = array();
		foreach ( $rows as $r ) {
			$kind = isset( $r->kind ) ? (string) $r->kind : '';
			if ( in_array( $kind, Vat::NON_TAXABLE_KINDS, true ) ) {
				$out[ (int) $r->id ] = Vat::for_kind( $kind, $vat ) + array( 'ref' => 0 );
				continue;
			}
			$ref = (int) ( isset( $r->parent_payout_id ) ? $r->parent_payout_id : 0 );
			if ( $ref && ! array_key_exists( $ref, $cache ) ) {
				$cache[ $ref ] = self::find( $ref );
			}
			$orig = $ref ? $cache[ $ref ] : null;
			if ( $orig && 'cancelled' !== $orig->status ) {
				$mode = (string) $orig->vat_mode;
				$rate = (string) $orig->vat_rate;
				if ( ! empty( $r->parent_id ) ) {
					// The original commission's own line on that credit note (it may differ from the
					// credit note's main treatment, e.g. a service before a change of the tax status).
					$it   = Db::table( 'payout_items' );
					$line = $wpdb->get_row( $wpdb->prepare( "SELECT vat_mode, vat_rate FROM {$it} WHERE payout_id = %d AND commission_id = %d ORDER BY id DESC LIMIT 1", $ref, (int) $r->parent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $line && '' !== (string) $line->vat_rate && '' !== (string) $line->vat_mode ) {
						$mode = (string) $line->vat_mode;
						$rate = (string) $line->vat_rate;
					}
				}
				$out[ (int) $r->id ] = array(
					'mode' => $mode,
					'rate' => $rate,
					'ref'  => $ref,
				);
			} else {
				$t = $resolver ? (array) call_user_func( $resolver, $r ) : $vat;
				$out[ (int) $r->id ] = array(
					'mode' => (string) $t['mode'],
					'rate' => (string) $t['rate'],
					'ref'  => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Treatment of a partner's commission at the time of its service (commission date; for a
	 * correction the date of the original commission): tax data and VAT rate valid then.
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $snapshot     Current tax data (profile snapshot).
	 * @return callable Row => [ mode, rate ].
	 */
	public static function treatment_resolver( $affiliate_id, array $snapshot ) {
		$memo = array();
		return static function ( $row ) use ( $affiliate_id, $snapshot, &$memo ) {
			$when = ! empty( $row->parent_created_at ) ? (string) $row->parent_created_at : ( ! empty( $row->created_at ) ? (string) $row->created_at : Db::now() );
			list( $data, $rate ) = TaxHistory::at( (int) $affiliate_id, $when, $snapshot );
			$key = md5( wp_json_encode( array( $data, $rate ) ) );
			if ( ! isset( $memo[ $key ] ) ) {
				$vat = Vat::treatment(
					isset( $data['tax_status'] ) ? (string) $data['tax_status'] : '',
					isset( $data['country'] ) ? (string) $data['country'] : '',
					(string) Settings::get( 'operator_country' ),
					$rate
				);
				/** This filter is documented in PayoutService::create_for_affiliate(). */
				$memo[ $key ] = (array) apply_filters( 'wpac_vat_treatment', $vat, $data, (int) $affiliate_id );
			}
			return $memo[ $key ];
		};
	}

	/**
	 * Open (approved, not assigned) bookings of a partner in a currency with net, VAT and
	 * gross – what the partner would get (positive) or owes (negative), VAT included.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return array [ net, vat, gross ]
	 */
	public static function open_totals( $affiliate_id, $currency ) {
		global $wpdb;
		$t    = Db::table( 'commissions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor, c.kind, c.parent_id, c.created_at, p.created_at AS parent_created_at, p.payout_id AS parent_payout_id FROM {$t} c LEFT JOIN {$t} p ON p.id = c.parent_id WHERE c.affiliate_id = %d AND c.currency = %s AND c.status = 'approved' AND c.payout_id = 0", (int) $affiliate_id, strtoupper( (string) $currency ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::row_totals( (int) $affiliate_id, (array) $rows );
	}

	/**
	 * Net, VAT and gross of bookings of a partner (treatment as on a credit note).
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $rows         Rows (id, amount_minor, kind, parent_id, created_at, parent_created_at, parent_payout_id).
	 * @return array [ net, vat, gross ]
	 */
	public static function row_totals( $affiliate_id, array $rows ) {
		if ( ! $rows ) {
			return array( 0, 0, 0 );
		}
		$snapshot = ProfileRepository::snapshot( ProfileRepository::get( (int) $affiliate_id ) );
		$resolver = self::treatment_resolver( (int) $affiliate_id, $snapshot );
		$current  = $resolver( (object) array( 'created_at' => Db::now() ) );
		return Vat::totals( $rows, self::item_treatments( $rows, $current, $resolver ) );
	}

	/**
	 * Net sums per VAT rate (the VAT is computed per rate, not per line).
	 *
	 * @param array $rows  Rows with id and amount_minor.
	 * @param array $treat item_treatments().
	 * @return array rate => [ rate, net ]
	 */
	public static function vat_groups( array $rows, array $treat ) {
		$groups = array();
		foreach ( $rows as $r ) {
			$rate = $treat[ (int) $r->id ]['rate'];
			$key  = Vat::rate_key( $rate ); // "19" and "19.0" are one group (R13-N8).
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'rate' => $rate,
					'net'  => 0,
				);
			}
			$groups[ $key ]['net'] += (int) $r->amount_minor;
		}
		return $groups;
	}

	/**
	 * Operator details as printed on the credit note, frozen with each payout
	 * (a later cancellation shows the data of the original document).
	 *
	 * @param int $affiliate_id Partner of the document (its language, SET-AC-17; 0 = site language).
	 * @return array
	 */
	public static function operator_snapshot( $affiliate_id = 0 ) {
		$name = (string) Settings::get( 'debtor_name' );
		// Language and date format of the document are frozen too: a credit note is written in the
		// partner's document language (default: site language), whoever triggers it (and can be
		// built again identically).
		$locale = \WPAffiliateCaptain\Core\Locale::site();
		return array(
			'name'       => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'address'    => (string) Settings::get( 'operator_address' ),
			'country'    => strtoupper( (string) Settings::get( 'operator_country' ) ),
			'vat_id'     => (string) Settings::get( 'operator_vat_id' ),
			'tax_number' => (string) Settings::get( 'operator_tax_number' ),
			'self_billing' => (bool) Settings::get( 'self_billing' ),
			'locale'       => Statement::document_locale( $locale, (int) $affiliate_id ),
			'date_format'  => (string) get_option( 'date_format' ),
		);
	}

	/**
	 * Gross amount (VAT included) of what a run would pay a partner.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param string $end          Period end (Y-m-d H:i:s).
	 * @return int
	 */
	public static function payable_gross( $affiliate_id, $currency, $end ) {
		global $wpdb;
		list( $from, $params ) = self::payable_sql( $currency, $end, (int) $affiliate_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor, c.kind, c.parent_id, c.created_at, p.created_at AS parent_created_at, p.payout_id AS parent_payout_id {$from}", $params ) );
		$sum  = self::row_totals( (int) $affiliate_id, (array) $rows );
		return (int) $sum[2];
	}

	/**
	 * Why a partner cannot be paid out right now ('' = can be paid). Checked for every
	 * payout, also single payouts from requests.
	 *
	 * @param int         $affiliate_id Partner.
	 * @param array|null  $profile      Payout profile (loaded when null).
	 * @param string|null $code         Out: reason code (maintenance|terminated|tax_pending|profile|terms_page|terms|operator_vat_id|vies, '' = none).
	 * @return string
	 */
	public static function blocked_reason( $affiliate_id, $profile = null, &$code = null ) {
		$code = '';
		if ( Settings::get( 'maintenance_mode' ) ) {
			$code = 'maintenance';
			return __( 'Maintenance mode is on (Settings → Data & maintenance)', 'affiliate-captain' );
		}
		$aff = AffiliateRepository::find( (int) $affiliate_id );
		if ( $aff && 'terminated' === $aff->status && 'reject' === Settings::get( 'terminated_open' ) ) {
			// Termination with "open commissions are rejected": nothing is paid out any more.
			$code = 'terminated';
			return __( 'Partnership terminated – open commissions are rejected (Settings → Program)', 'affiliate-captain' );
		}
		$profile = null === $profile ? ProfileRepository::get( (int) $affiliate_id ) : $profile;
		$missing = ProfileRepository::missing( $profile );
		if ( true !== $missing && 'tax_status' === $missing && TaxHistory::pending( (int) $affiliate_id ) ) {
			$code = 'tax_pending';
			return __( 'The partner\'s tax data wait for your approval (partner page → Payout details)', 'affiliate-captain' );
		}
		if ( true !== $missing ) {
			$code   = 'profile';
			$fields = array(
				'tax_status'     => __( 'tax status', 'affiliate-captain' ),
				'legal_name'     => __( 'legal name', 'affiliate-captain' ),
				'address'        => __( 'address', 'affiliate-captain' ),
				'country'        => __( 'country', 'affiliate-captain' ),
				'account_holder' => __( 'account holder', 'affiliate-captain' ),
				'iban'           => __( 'IBAN', 'affiliate-captain' ),
				'vat_id'         => __( 'VAT ID (business)', 'affiliate-captain' ),
				'tax_number'     => __( 'tax number or VAT ID (small business)', 'affiliate-captain' ),
			);
			/* translators: %s: missing field */
			return sprintf( __( 'Payout details incomplete: %s missing', 'affiliate-captain' ), isset( $fields[ $missing ] ) ? $fields[ $missing ] : (string) $missing );
		}
		if ( Settings::get( 'self_billing' ) && ! Settings::get( 'terms_page_id' ) ) {
			// Self-billing needs a prior agreement (§14 Abs. 2 S. 2 UStG); without partner terms there is none.
			$code = 'terms_page';
			return __( 'Self-billing needs partner terms with the credit note agreement (Settings → Program → Partner program terms)', 'affiliate-captain' );
		}
		if ( Settings::get( 'self_billing' ) && ! \WPAffiliateCaptain\Affiliates\Agreements::has_current( (int) $affiliate_id ) ) {
			// Self-billing needs a prior agreement (§14 Abs. 2 UStG): the current terms (with the
			// credit note clause) of the partner's own terms page – an older version is not enough.
			$code = 'terms';
			return __( 'Has not accepted the partner terms yet (needed for credit notes) – the partner is asked to accept them on the next login to the partner area', 'affiliate-captain' );
		}
		$vat = Vat::treatment( isset( $profile->tax_status ) ? (string) $profile->tax_status : '', isset( $profile->country ) ? (string) $profile->country : '', (string) Settings::get( 'operator_country' ), (string) Settings::get( 'vat_rate' ) );
		if ( Vat::REVERSE_CHARGE === $vat['mode'] && '' === trim( (string) Settings::get( 'operator_vat_id' ) ) ) {
			// Reverse-charge documents must show the VAT ID of the recipient (the operator).
			$code = 'operator_vat_id';
			return __( 'Reverse charge needs your VAT ID (Settings → Payouts & credit notes)', 'affiliate-captain' );
		}
		if ( in_array( $vat['mode'], array( Vat::REVERSE_CHARGE, Vat::STANDARD ), true ) && isset( $profile->vat_id ) && '' !== (string) $profile->vat_id && Vies::is_invalid( (int) $affiliate_id, (string) $profile->vat_id ) ) {
			// Also a domestic VAT ID: VAT on a credit note to a partner who is no business would be
			// owed without being deductible (§14c UStG).
			$code = 'vies';
			return __( 'The partner\'s VAT ID is not valid according to VIES (no credit note with VAT or reverse charge possible)', 'affiliate-captain' );
		}
		return '';
	}

	const CLAWBACK_OPTION = 'wpac_clawback';

	/**
	 * Remember that an order of an unpaid, not yet exported payout was refunded afterwards.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $order     Order label.
	 */
	public static function flag_clawback( $payout_id, $order ) {
		$p = self::find( (int) $payout_id );
		if ( ! $p || 'pending' !== $p->status ) {
			return; // Exported or paid: the correction is offset in the next payout as usual.
		}
		$all                        = get_option( self::CLAWBACK_OPTION, array() );
		$all                        = is_array( $all ) ? $all : array();
		$all[ (int) $payout_id ][] = $order;
		$all[ (int) $payout_id ]   = array_values( array_unique( $all[ (int) $payout_id ] ) );
		update_option( self::CLAWBACK_OPTION, $all, false );
	}

	/**
	 * Payouts with later refunds that are still pending: payout ID => orders.
	 *
	 * @return array
	 */
	public static function clawbacks() {
		$all  = get_option( self::CLAWBACK_OPTION, array() );
		$all  = is_array( $all ) ? $all : array();
		$keep = array();
		foreach ( $all as $id => $orders ) {
			$p = self::find( (int) $id );
			if ( $p && 'pending' === $p->status ) {
				$keep[ (int) $id ] = $orders;
			}
		}
		if ( count( $keep ) !== count( $all ) ) {
			update_option( self::CLAWBACK_OPTION, $keep, false );
		}
		return $keep;
	}

	/**
	 * Cancel a pending payout and create it again (the refund is then deducted right away).
	 *
	 * @param int $id Payout.
	 * @return int|\WP_Error New payout ID (0 if nothing is payable any more).
	 */
	public static function rebuild( $id ) {
		$p = self::find( (int) $id );
		if ( ! $p || 'pending' !== $p->status ) {
			return new \WP_Error( 'wpac_state', __( 'Only payouts that were not exported yet can be rebuilt.', 'affiliate-captain' ) );
		}
		// Check first whether the payout can be created again: a cancellation (with its own
		// document number) must not be left behind without a replacement.
		$blocked = self::operator_missing();
		$blocked = '' !== $blocked ? $blocked : self::blocked_reason( (int) $p->affiliate_id );
		if ( '' !== $blocked ) {
			/* translators: %s: reason */
			return new \WP_Error( 'wpac_not_eligible', sprintf( __( 'The payout cannot be created again (%s). It was not cancelled.', 'affiliate-captain' ), $blocked ) );
		}
		// Cancellation and new payout in one transaction: if nothing (or less than the minimum)
		// would be payable, both are rolled back. Documents are written after the COMMIT.
		self::$deferred = array();
		try {
			$new = Db::transaction(
				static function () use ( $p, $id ) {
					$cancelled = self::cancel( (int) $id, __( 'Rebuilt after a refund', 'affiliate-captain' ) );
					if ( is_wp_error( $cancelled ) ) {
						throw new IntegrationException( 'cancel', esc_html( $cancelled->get_error_message() ) );
					}
					$new = self::create_for_affiliate( (int) $p->affiliate_id, (string) $p->period_end, (string) $p->currency );
					if ( ! $new ) {
						throw new IntegrationException( 'rebuild_empty', esc_html__( 'After the refund the balance is below the minimum payout, so no new payout can be created. The payout was not cancelled; cancel it manually if it should not be paid – the balance is then carried forward.', 'affiliate-captain' ) );
					}
					return (int) $new;
				}
			);
		} catch ( \Throwable $e ) {
			self::$deferred = null;
			if ( ! $e instanceof IntegrationException || ! in_array( $e->error_code, array( 'cancel', 'rebuild_empty' ), true ) ) {
				// Unexpected (database error …): the message alone does not say where it came from.
				\WPAffiliateCaptain\Core\Log::exception( 'Payout could not be rebuilt', $e, array( 'payout' => (int) $id ) );
			}
			return new \WP_Error( 'wpac_payout', $e->getMessage() );
		}
		$deferred       = self::$deferred;
		self::$deferred = null;
		foreach ( (array) $deferred as $fn ) {
			$fn();
		}
		$all = get_option( self::CLAWBACK_OPTION, array() );
		if ( is_array( $all ) ) {
			unset( $all[ (int) $id ] );
			update_option( self::CLAWBACK_OPTION, $all, false );
		}
		Audit::log( 'payout.rebuilt', 'payout', (int) $id, array( 'new' => (int) $new ) );
		return (int) $new;
	}

	/**
	 * Steps that must only run after the COMMIT (documents, requests) while rebuild() runs
	 * several steps in one transaction; null = run immediately.
	 *
	 * @var callable[]|null
	 */
	private static $deferred = null;

	/**
	 * Run a step now, or after the COMMIT of rebuild()'s transaction.
	 *
	 * @param callable $fn Step.
	 */
	private static function after_commit( callable $fn ) {
		if ( is_array( self::$deferred ) ) {
			self::$deferred[] = $fn;
			return;
		}
		// Inside a caller's transaction (e.g. PayoutRequests::process()) only after its COMMIT;
		// right away otherwise.
		Db::after_commit( $fn );
	}

	/**
	 * Mandatory data of the operator for credit notes (self-billing): name, address and
	 * VAT ID or tax number. Returns the reason when something is missing, '' otherwise.
	 *
	 * @return string
	 */
	public static function operator_missing() {
		if ( ! Settings::get( 'self_billing' ) ) {
			return '';
		}
		if ( '' === trim( (string) Settings::get( 'operator_address' ) ) || ( '' === (string) Settings::get( 'operator_vat_id' ) && '' === trim( (string) Settings::get( 'operator_tax_number' ) ) ) ) {
			return __( 'Your address and VAT ID or tax number are missing (Settings → Payouts & credit notes) – credit notes need them.', 'affiliate-captain' );
		}
		return '';
	}

	/**
	 * Create payouts for all eligible affiliates.
	 *
	 * @param string $period_end Y-m-d.
	 * @param string $currency   Currency.
	 * @return array [ created payout IDs, skipped count, details ] – details: list of
	 *               [ affiliate_id, name, kind carried|blocked|failed, reason ] for every partner
	 *               that was not paid.
	 */
	public static function create_run( $period_end, $currency ) {
		$created = array();
		$skipped = 0;
		$details = array();
		// Each payout is still created in its own transaction; only the credit notes (PDF) are
		// written afterwards by the job queue, so a run with many partners does not time out.
		$async                   = self::$async_statements;
		self::$async_statements  = true;
		try {
			self::create_run_rows( $period_end, $currency, $created, $skipped, $details );
		} finally {
			self::$async_statements = $async;
		}
		if ( $created ) {
			Scheduler::once( Jobs::DRAIN_HOOK, 0 ); // Start writing the credit notes right away.
		}
		Audit::log(
			'payout.run',
			'payout',
			0,
			array(
				'period_end' => $period_end,
				'currency'   => $currency,
				'created'    => count( $created ),
				'skipped'    => $skipped,
			)
		);
		return array( $created, $skipped, $details );
	}

	/**
	 * Whether payouts created now get their credit note from the job queue (payout run)
	 * instead of right after the commit (single payouts, requests, rebuild).
	 *
	 * @var bool
	 */
	private static $async_statements = false;

	/** Job type that writes the credit note of a payout. */
	const STATEMENT_JOB = 'statement';

	/**
	 * Register the job handler for credit notes (Plugin boot).
	 */
	public static function register_hooks() {
		add_filter( 'wpac_run_job', array( __CLASS__, 'run_job' ), 10, 3 );
	}

	/**
	 * Job handler: write the credit note of a payout created by a run, then announce the payout
	 * (wpac_payout_created fires once the credit note exists, as for single payouts). Throws
	 * while the document cannot be written, so the queue retries with back-off.
	 *
	 * @param bool   $handled Handled before.
	 * @param string $type    Job type.
	 * @param array  $payload Payload (payout_id, affiliate_id).
	 * @return bool
	 * @throws IntegrationException When the credit note could not be written yet.
	 */
	public static function run_job( $handled, $type, $payload ) {
		if ( $handled || self::STATEMENT_JOB !== $type ) {
			return $handled;
		}
		$id = isset( $payload['payout_id'] ) ? (int) $payload['payout_id'] : 0;
		$p  = self::find( $id );
		if ( ! $p || 'cancelled' === $p->status || $p->statement_deleted_at ) {
			return true; // Nothing to write any more.
		}
		if ( '' === (string) $p->statement_file ) {
			Statement::generate( $id );
			$p = self::find( $id );
		}
		if ( ! $p || '' === (string) $p->statement_file ) {
			throw new IntegrationException( 'statement', esc_html( 'The credit note of payout ' . $id . ' could not be written yet.' ) );
		}
		$aff_id = (int) $p->affiliate_id;
		Db::after_commit(
			static function () use ( $id, $aff_id ) {
				/** This action is documented in create_for_affiliate(). */
				do_action( 'wpac_payout_created', $id, $aff_id );
			}
		);
		return true;
	}

	/**
	 * Create the payouts of a run (see create_run()).
	 *
	 * @param string $period_end Y-m-d.
	 * @param string $currency   Currency.
	 * @param array  $created    Out: created payout IDs.
	 * @param int    $skipped    Out: skipped partners.
	 * @param array  $details    Out: details of skipped partners.
	 */
	private static function create_run_rows( $period_end, $currency, array &$created, &$skipped, array &$details ) {
		foreach ( self::preview( $period_end, $currency ) as $row ) {
			$aff_id = $row['affiliate'] ? (int) $row['affiliate']->id : 0;
			$name   = $row['affiliate'] ? (string) $row['affiliate']->name : '#';
			if ( ! $row['eligible'] ) {
				++$skipped;
				$carried   = isset( $row['code'] ) && in_array( $row['code'], array( 'negative', 'below_minimum' ), true );
				$details[] = array( $aff_id, $name, $carried ? 'carried' : 'blocked', (string) $row['reason'] );
				continue;
			}
			try {
				$id = self::create_for_affiliate( $aff_id, $period_end, $currency );
				if ( $id ) {
					$created[] = $id;
				} else {
					++$skipped;
					$details[] = array( $aff_id, $name, 'carried', __( 'Nothing payable any more (changed meanwhile) – carried forward', 'affiliate-captain' ) );
				}
			} catch ( \Throwable $e ) {
				++$skipped;
				$reason    = $e instanceof IntegrationException && in_array( $e->error_code, array( 'operator_data', 'not_eligible' ), true )
					? $e->getMessage()
					: __( 'Technical error while saving (details in the audit log) – please run the payout again', 'affiliate-captain' );
				$details[] = array( $aff_id, $name, 'failed', $reason );
				Audit::log( 'payout.failed', 'affiliate', $aff_id, array( 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * Create one payout (transactional, locks the commission rows).
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $period_end   Y-m-d.
	 * @param string $currency     Currency.
	 * @param bool   $settle       Correction document for a settled balance (gross exactly 0, e.g.
	 *                             VAT corrections offset by a repayment or write-off): no minimum,
	 *                             no transfer; see settle().
	 * @return int Payout ID or 0 when nothing is payable.
	 */
	public static function create_for_affiliate( $affiliate_id, $period_end, $currency, $settle = false ) {
		global $wpdb;
		$operator = self::operator_missing();
		if ( $operator ) {
			throw new IntegrationException( 'operator_data', esc_html( $operator ) );
		}
		$blocked = $settle ? '' : self::blocked_reason( (int) $affiliate_id );
		if ( '' !== $blocked ) {
			throw new IntegrationException( 'not_eligible', esc_html( $blocked ) );
		}
		$payout_id = Db::transaction(
			static function () use ( $wpdb, $affiliate_id, $period_end, $currency, $settle ) {
				$t                     = Db::table( 'commissions' );
				list( $from, $params ) = self::payable_sql( $currency, self::cutoff( $period_end ), $affiliate_id );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor, c.kind, c.parent_id, c.created_at, p.created_at AS parent_created_at, p.payout_id AS parent_payout_id {$from} FOR UPDATE", $params ) );
				Db::check( 'Locking the payable commissions' ); // R13-K1: an error is not "nothing payable".
				$total = 0;
				foreach ( $rows as $r ) {
					$total += (int) $r->amount_minor;
				}
				$min = \WPAffiliateCaptain\Core\Fx::min_payout( $currency, (int) $affiliate_id );
				if ( ! $rows || ( ! $settle && ( $total <= 0 || null === $min ) ) ) {
					return 0;
				}
				$profile  = ProfileRepository::get( $affiliate_id );
				$snapshot = ProfileRepository::snapshot( $profile );
				if ( $settle && ! $snapshot ) {
					return 0; // No partner data for the document (e.g. erased).
				}
				// Commission rates are net; VAT is added for domestic VAT-registered partners. The
				// treatment follows the time of each service (tax status and rate valid then).
				$resolver = self::treatment_resolver( (int) $affiliate_id, $snapshot );
				/**
				 * Filter the VAT treatment of a credit note (mode, rate). Applied to the current
				 * tax data and to earlier ones (services before a change of the tax status/rate).
				 *
				 * @param array $vat          Treatment.
				 * @param array $snapshot     Partner tax data snapshot.
				 * @param int   $affiliate_id Partner.
				 */
				$vat = $resolver( (object) array( 'created_at' => Db::now() ) );
				// Corrections of commissions settled in an earlier credit note keep that credit note's
				// VAT treatment (§17 UStG) and refer to it; repayments and write-offs are not taxable.
				$treat = self::item_treatments( $rows, $vat, $resolver );
				$vat_minor = 0;
				foreach ( self::vat_groups( $rows, $treat ) as $group ) {
					$vat_minor += Vat::amount( $group['net'], $group['rate'] );
				}
				$gross = $total + $vat_minor;
				if ( $settle ? 0 !== $gross : ( $gross <= 0 || $gross < $min ) ) {
					// What is transferred is the gross amount: never a negative or too small transfer
					// (e.g. VAT corrections of earlier credit notes after a change of the tax status).
					return 0;
				}
				$operator_snapshot = self::operator_snapshot( (int) $affiliate_id );
				if ( $settle ) {
					$operator_snapshot['document'] = 'correction';
				}
				$year      = (int) wp_date( 'Y' );
				// Gapless credit note number, allocated inside this transaction.
				$number = Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $year );
				$ok     = $wpdb->insert(
					Db::table( 'payouts' ),
					array(
						'number'           => $number,
						'affiliate_id'     => (int) $affiliate_id,
						'period_end'       => $period_end,
						'currency'         => $currency,
						'net_minor'        => $total,
						'vat_mode'         => $vat['mode'],
						'vat_rate'         => (string) $vat['rate'],
						'vat_minor'        => $vat_minor,
						'amount_minor'     => $total + $vat_minor, // Amount paid out (gross).
						'status'           => 'pending',
						'profile_snapshot' => wp_json_encode( $snapshot ),
						'operator_snapshot' => wp_json_encode( $operator_snapshot ),
						'created_by'       => get_current_user_id(),
						'created_at'       => Db::now(),
					)
				);
				if ( ! $ok ) {
					throw new IntegrationException( 'db_payout', esc_html( $wpdb->last_error ) );
				}
				$payout_id = (int) $wpdb->insert_id;

				foreach ( $rows as $r ) {
					$ok = $wpdb->insert(
						Db::table( 'payout_items' ),
						array(
							'payout_id'            => $payout_id,
							'commission_id'        => (int) $r->id,
							'active_commission_id' => (int) $r->id, // UNIQUE: one active payout per commission.
							'amount_minor'         => (int) $r->amount_minor,
							'vat_mode'             => $treat[ (int) $r->id ]['mode'],
							'vat_rate'             => $treat[ (int) $r->id ]['rate'],
							'ref_payout_id'        => $treat[ (int) $r->id ]['ref'],
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'double_payout', esc_html( 'Commission ' . $r->id . ' is already part of a payout.' ) );
					}
					// Compare-and-set: only if still approved and unassigned.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$moved = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET payout_id = %d, updated_at = %s WHERE id = %d AND payout_id = 0 AND status = 'approved'", $payout_id, Db::now(), (int) $r->id ) );
					if ( 1 !== $moved ) {
						throw new IntegrationException( 'commission_changed', esc_html( 'Commission ' . $r->id . ' changed during the payout run.' ) );
					}
					Ledger::event( (int) $r->id, 'payout_assigned', 'approved', 'approved', (int) $r->amount_minor, $number );
				}
				\WPAffiliateCaptain\Commissions\Balance::post( (int) $affiliate_id, $currency, 'payout', -$total, 0, $payout_id, $number );
				Audit::log(
					$settle ? 'payout.correction_created' : 'payout.created',
					'payout',
					$payout_id,
					array(
						'number' => $number,
						'amount' => $total,
						'vat'    => $vat_minor,
					)
				);
				return $payout_id;
			}
		);
		if ( $payout_id ) {
			$async = self::$async_statements;
			self::after_commit(
				static function () use ( $payout_id, $affiliate_id, $currency, $async, $settle ) {
					if ( ! $settle ) {
						// A regular run also settles an open payout request of the partner (a correction
						// document for a settled balance transfers nothing, so it pays no request).
						PayoutRequests::fulfil( (int) $affiliate_id, (int) $payout_id, $currency );
					}
					if ( $async && Jobs::enqueue( self::STATEMENT_JOB, (string) (int) $payout_id, array( 'payout_id' => (int) $payout_id, 'affiliate_id' => (int) $affiliate_id ), '', '', 0 ) ) {
						return; // The queue writes the credit note and then fires wpac_payout_created (run_job()).
					}
					Statement::generate( $payout_id );
					/**
					 * A payout was created (after the commit, statement generated), e.g. for an
					 * add-on that pays it through another payment provider and then calls
					 * PayoutService::mark_paid(). For payouts of a payout run it fires from the job
					 * queue once the credit note was written.
					 *
					 * @since contract 1.2.0
					 * @param int $payout_id    Payout.
					 * @param int $affiliate_id Partner.
					 */
					do_action( 'wpac_payout_created', (int) $payout_id, (int) $affiliate_id );
				}
			);
		}
		return $payout_id;
	}

	/**
	 * Correction document for a settled balance (§17 UStG): when the open bookings of a partner
	 * add up to exactly 0 including VAT – VAT corrections of earlier credit notes offset by a
	 * repayment or a write-off – they are settled with a document of their own (no transfer).
	 * Partners whose partnership ended would otherwise never get a document that reduces the
	 * VAT of the original credit note.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return int Payout ID (0 = nothing to settle).
	 */
	public static function settle( $affiliate_id, $currency ) {
		$currency = strtoupper( (string) $currency );
		$sum      = self::open_totals( (int) $affiliate_id, $currency );
		if ( 0 !== $sum[2] || ( 0 === $sum[0] && 0 === $sum[1] && ! self::has_open( (int) $affiliate_id, $currency ) ) ) {
			return 0;
		}
		try {
			$id = self::create_for_affiliate( (int) $affiliate_id, wp_date( 'Y-m-d' ), $currency, true );
		} catch ( \Throwable $e ) {
			Audit::log( 'payout.correction_failed', 'affiliate', (int) $affiliate_id, array( 'error' => $e->getMessage() ) );
			return 0;
		}
		if ( $id ) {
			$paid = self::mark_paid( (int) $id ); // Nothing to transfer: settled right away.
			if ( is_wp_error( $paid ) ) {
				Audit::log( 'payout.correction_failed', 'payout', (int) $id, array( 'error' => $paid->get_error_message() ) );
			}
		}
		return (int) $id;
	}

	/**
	 * Whether a partner has open (approved, unassigned) bookings in a currency.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return bool
	 */
	private static function has_open( $affiliate_id, $currency ) {
		global $wpdb;
		$t = Db::table( 'commissions' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0 LIMIT 1", (int) $affiliate_id, $currency ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Partners with the tax status "small business" whose credit notes exceed the limits of the
	 * small business scheme (default §19 UStG: 25,000 € in the previous year or 100,000 € in the
	 * current year; filter wpac_small_business_limits). Only an indication: the limit counts all
	 * turnover of the partner, not only ours – but if our credit notes alone exceed it, the
	 * partner is very likely liable for VAT.
	 *
	 * @param int|null $year Current year (default: this year).
	 * @return array List of [ affiliate_id, currency, previous, current, which ].
	 */
	public static function small_business_warnings( $year = null ) {
		global $wpdb;
		$year = null === $year ? (int) wp_date( 'Y' ) : (int) $year;
		/**
		 * Limits of the small business scheme per currency in minor units: [ previous year, current year ].
		 *
		 * @param array  $limits   Currency => [ previous, current ].
		 * @param string $operator Operator country.
		 */
		$limits = (array) apply_filters( 'wpac_small_business_limits', 'DE' === strtoupper( (string) Settings::get( 'operator_country' ) ) ? array( 'EUR' => array( 2500000, 10000000 ) ) : array(), strtoupper( (string) Settings::get( 'operator_country' ) ) );
		if ( ! $limits ) {
			return array();
		}
		$t                    = Db::table( 'payouts' );
		$pp                   = Db::table( 'payout_profiles' );
		list( $from )         = Statement::year_bounds( $year - 1 );
		list( $cur_from, $to ) = Statement::year_bounds( $year );
		// Credit notes that were not cancelled, of partners who are small businesses now.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.affiliate_id, p.currency, SUM( CASE WHEN p.created_at < %s THEN p.net_minor ELSE 0 END ) prev_total, SUM( CASE WHEN p.created_at >= %s THEN p.net_minor ELSE 0 END ) cur_total FROM {$t} p JOIN {$pp} f ON f.affiliate_id = p.affiliate_id WHERE f.tax_status = 'small_business' AND p.status <> 'cancelled' AND p.created_at BETWEEN %s AND %s GROUP BY p.affiliate_id, p.currency", $cur_from, $cur_from, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// R12-M7: correction credit notes count with their net difference on their own date; the
		// gross difference settled on a later credit note is not counted a second time there.
		// R13-K2: repayments and write-offs on a credit note are no turnover either.
		$it    = Db::table( 'payout_items' );
		$ct    = Db::table( 'commissions' );
		$kt    = Db::table( Corrections::TABLE );
		$kinds = implode( ',', array_fill( 0, count( Vat::NON_TAXABLE_KINDS ), '%s' ) );
		$kg    = $wpdb->get_results( $wpdb->prepare( "SELECT p.affiliate_id, p.currency, SUM( CASE WHEN p.created_at < %s THEN -i.amount_minor ELSE 0 END ) prev_total, SUM( CASE WHEN p.created_at >= %s THEN -i.amount_minor ELSE 0 END ) cur_total FROM {$t} p JOIN {$pp} f ON f.affiliate_id = p.affiliate_id JOIN {$it} i ON i.payout_id = p.id AND i.released_at IS NULL JOIN {$ct} c ON c.id = i.commission_id AND ( c.kind IN ({$kinds}) OR i.vat_mode = %s ) WHERE f.tax_status = 'small_business' AND p.status <> 'cancelled' AND p.created_at BETWEEN %s AND %s GROUP BY p.affiliate_id, p.currency", array_merge( array( $cur_from, $cur_from ), Vat::NON_TAXABLE_KINDS, array( Vat::NONE, $from, $to ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Kind placeholders built above.
		$kd   = $wpdb->get_results( $wpdb->prepare( "SELECT k.affiliate_id, k.currency, SUM( CASE WHEN k.created_at < %s THEN k.net_minor - k.prev_net_minor ELSE 0 END ) prev_total, SUM( CASE WHEN k.created_at >= %s THEN k.net_minor - k.prev_net_minor ELSE 0 END ) cur_total FROM {$kt} k JOIN {$pp} f ON f.affiliate_id = k.affiliate_id WHERE f.tax_status = 'small_business' AND k.created_at BETWEEN %s AND %s GROUP BY k.affiliate_id, k.currency", $cur_from, $cur_from, $from, $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sums = array();
		foreach ( array_merge( (array) $rows, (array) $kg, (array) $kd ) as $r ) {
			$k = (int) $r->affiliate_id . '|' . (string) $r->currency;
			if ( ! isset( $sums[ $k ] ) ) {
				$sums[ $k ] = array( (int) $r->affiliate_id, (string) $r->currency, 0, 0 );
			}
			$sums[ $k ][2] += (int) $r->prev_total;
			$sums[ $k ][3] += (int) $r->cur_total;
		}
		$out = array();
		foreach ( $sums as $r ) {
			if ( ! isset( $limits[ $r[1] ] ) ) {
				continue;
			}
			$which = Vat::small_business_exceeded( $r[2], $r[3], array_values( (array) $limits[ $r[1] ] ) );
			if ( '' !== $which ) {
				$out[] = array( $r[0], $r[1], $r[2], $r[3], $which );
			}
		}
		return $out;
	}

	/**
	 * Every payout needs its document before money moves: a missing statement (e.g. the
	 * file could not be written) is generated now; if that fails, an error is returned.
	 * Only for payouts that can still be paid (created or exported): a cancelled or paid payout
	 * gets no credit note from here – the caller refuses it under the row lock anyway (R12-K2).
	 *
	 * @param int $id Payout.
	 * @return \WP_Error|null
	 */
	public static function statement_missing( $id ) {
		$p = self::find( $id );
		if ( ! $p || '' !== (string) $p->statement_file || $p->statement_deleted_at || ! in_array( (string) $p->status, array( 'pending', 'exported' ), true ) ) {
			return null;
		}
		// R13-N9: written right now by another request (job queue after a payout run): wait a
		// little, and if it is still busy say so – it is not a folder problem.
		Statement::generate( (int) $id, 20 );
		$p = self::find( $id );
		if ( $p && '' === (string) $p->statement_file && Statement::was_busy() ) {
			/* translators: %s: payout number */
			return new \WP_Error( 'wpac_statement_busy', sprintf( __( 'The statement of payout %s is being generated right now. Please try again in a minute.', 'affiliate-captain' ), $p->number ) );
		}
		if ( $p && '' === (string) $p->statement_file ) {
			/* translators: %s: payout number */
			return new \WP_Error( 'wpac_statement', sprintf( __( 'The statement of payout %s could not be stored. Please check that the statement folder is writable and try again.', 'affiliate-captain' ), $p->number ) );
		}
		return null;
	}

	/**
	 * Generate statements that are still missing (hourly cron).
	 *
	 * @param int   $limit  Payouts per run.
	 * @param float $budget Seconds (0 = no limit).
	 * @return int Number generated.
	 */
	public static function generate_missing_statements( $limit = 100, $budget = 20 ) {
		global $wpdb;
		$t = Db::table( 'payouts' );
		// Payouts in back-off (failed before, e.g. folder not writable) are left out in the query:
		// otherwise they fill the batch and block every newer payout (head-of-line blocking).
		$retry   = get_option( Statement::RETRY_OPTION, array() );
		$waiting = array();
		foreach ( is_array( $retry ) ? $retry : array() as $id => $entry ) {
			if ( isset( $entry['next'] ) && (int) $entry['next'] > time() ) {
				$waiting[] = (int) $id;
			}
		}
		$skip  = $waiting ? ' AND id NOT IN (' . implode( ',', $waiting ) . ')' : ''; // Integers only.
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE statement_file = '' AND statement_deleted_at IS NULL AND status <> 'cancelled'{$skip} ORDER BY id ASC LIMIT %d", max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n     = 0;
		$start = microtime( true );
		foreach ( $ids as $id ) {
			if ( $budget > 0 && microtime( true ) - $start > $budget ) {
				break; // Time budget: the rest follows in the next hour (or from the job queue).
			}
			$n += Statement::generate( (int) $id ) ? 1 : 0;
		}
		// Cancellation documents whose file could not be written (number already allocated).
		$n += Statement::generate_missing_stornos();
		// Correction credit notes whose file could not be written.
		$n += Corrections::generate_missing();
		return $n;
	}

	/**
	 * Lock and read a payout inside a transaction.
	 *
	 * @param int $id Payout.
	 * @return object|null
	 */
	private static function lock( $id ) {
		global $wpdb;
		$t = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d FOR UPDATE", $id ) );
	}

	/**
	 * Mark a payout as paid → commissions become "paid". Atomic: either the
	 * payout and all its commissions change, or nothing does.
	 *
	 * @param int         $id    Payout.
	 * @param string|null $claim Only pay when the payout is exported under this claim (see claim()).
	 * @return true|\WP_Error
	 */
	public static function mark_paid( $id, $claim = null ) {
		global $wpdb;
		$missing = self::statement_missing( $id );
		if ( $missing ) {
			return $missing;
		}
		try {
			$paid = Db::transaction(
				static function () use ( $wpdb, $id, $claim ) {
					$p = self::lock( $id );
					if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
						throw new IntegrationException( 'state', 'not payable' );
					}
					if ( null !== $claim && ( 'exported' !== $p->status || ! hash_equals( (string) $p->sepa_batch, (string) $claim ) ) ) {
						throw new IntegrationException( 'claim', 'not claimed with this reference' );
					}
					$t = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'paid', paid_at = %s WHERE id = %d AND status IN ('pending','exported')", Db::now(), $id ) );
					if ( 1 !== $rows ) {
						throw new IntegrationException( 'state', 'changed' );
					}
					$ids = array();
					foreach ( self::items( $id, true ) as $item ) {
						if ( ! Ledger::transition( (int) $item->commission_id, 'paid', $p->number, get_current_user_id(), (int) $id, 'approved', false ) ) {
							throw new IntegrationException( 'commission_changed', esc_html( 'Commission ' . $item->commission_id . ' could not be marked as paid.' ) );
						}
						$ids[] = (int) $item->commission_id;
					}
					return array( $p, $ids );
				}
			);
		} catch ( IntegrationException $e ) {
			if ( 'claim' === $e->error_code ) {
				return new \WP_Error( 'wpac_claim', __( 'The payout is not claimed with this reference.', 'affiliate-captain' ) );
			}
			return new \WP_Error( 'wpac_state', __( 'This payout cannot be marked as paid.', 'affiliate-captain' ) );
		}
		list( $p, $ids ) = $paid;
		Audit::log( 'payout.paid', 'payout', (int) $id, array( 'number' => $p->number ) );
		// After COMMIT also when called inside an outer transaction (dropped on its rollback, AFF-WF-02).
		Db::after_commit(
			static function () use ( $ids, $id ) {
				foreach ( $ids as $cid ) {
					do_action( 'wpac_commission_paid', $cid, 'approved' ); // Only approved commissions are paid (Ledger::TRANSITIONS).
				}
				do_action( 'wpac_payout_paid', (int) $id );
			}
		);
		return true;
	}

	/**
	 * Cancel a payout that was not paid yet; commissions return to the pool.
	 * Payout items are kept (marked as released) for the audit trail.
	 *
	 * An exported payout (SEPA file created) may already have been sent to the bank;
	 * it can only be cancelled with an explicit confirmation that the transfer was not executed.
	 *
	 * @param int    $id               Payout.
	 * @param string $reason           Reason.
	 * @param bool   $confirm_not_sent Confirmation for exported payouts.
	 * @return true|\WP_Error
	 */
	public static function cancel( $id, $reason, $confirm_not_sent = false ) {
		global $wpdb;
		if ( '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		try {
			$p = Db::transaction(
				static function () use ( $wpdb, $id, $reason, $confirm_not_sent ) {
					$p = self::lock( $id );
					if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
						throw new IntegrationException( 'state', esc_html__( 'Only unpaid payouts can be cancelled.', 'affiliate-captain' ) );
					}
					$claimed = 'exported' === $p->status && 0 === strpos( (string) $p->sepa_batch, self::CLAIM_PREFIX );
					if ( $claimed && strtotime( (string) $p->exported_at . ' UTC' ) > time() - self::claim_cancel_after() ) {
						/* translators: 1: payout number, 2: claim reference, 3: number of days */
						throw new IntegrationException( 'claimed', esc_html( sprintf( __( 'Payout %1$s is being paid through a payment provider (reference %2$s). The add-on that claimed it gives it back when the provider does not pay it. It can only be cancelled here %3$d days after the claim, after checking in the account of the payment provider that it was not paid.', 'affiliate-captain' ), $p->number, $p->sepa_batch, (int) ceil( self::claim_cancel_after() / DAY_IN_SECONDS ) ) ) );
					}
					if ( $claimed && ! $confirm_not_sent ) {
						/* translators: 1: payout number, 2: claim reference */
						throw new IntegrationException( 'exported', esc_html( sprintf( __( 'Payout %1$s was handed to a payment provider (reference %2$s). Confirm that the provider did not pay it before cancelling – otherwise the commissions could be paid twice.', 'affiliate-captain' ), $p->number, $p->sepa_batch ) ) );
					}
					if ( 'exported' === $p->status && ! $confirm_not_sent ) {
						/* translators: %s: payout number */
						throw new IntegrationException( 'exported', esc_html( sprintf( __( 'Payout %s was already exported to SEPA. Confirm that the bank did not execute the transfer before cancelling – otherwise the commissions could be paid twice.', 'affiliate-captain' ), $p->number ) ) );
					}
					$pt = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'cancelled', note = %s WHERE id = %d AND status = %s", Db::cut( sanitize_text_field( $reason ), 255 ), $id, $p->status ) );
					if ( false === $rows ) {
						throw new IntegrationException( 'db_error', esc_html__( 'The cancellation could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ) );
					}
					if ( 1 !== (int) $rows ) {
						throw new IntegrationException( 'state', esc_html__( 'Only unpaid payouts can be cancelled.', 'affiliate-captain' ) );
					}
					// A credit note is cancelled by its own document with a new number. The credit note
					// number was allocated when the payout was created (gapless), so it is cancelled
					// even when its PDF was not written yet (payout run: the job queue writes it later).
					// generate_storno() then writes the credit note first and the cancellation
					// afterwards – exactly as for a credit note that already existed – so no number
					// is left without a document and no credit note without its cancellation (R12-K2).
					if ( '' !== (string) $p->number || '' !== (string) $p->statement_file || '' !== (string) $p->statement_hash ) {
						$storno = Sequence::number( (string) Settings::get( 'storno_prefix' ), 'storno', (int) wp_date( 'Y' ) );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET storno_number = %s, storno_at = %s WHERE id = %d", $storno, Db::now(), $id ) ) ) {
							throw new IntegrationException( 'db_error', esc_html__( 'The cancellation could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ) );
						}
					}
					$items    = self::items( $id, true );
					$released = 0;
					foreach ( $items as $item ) {
						$released += (int) $item->amount_minor;
					}
					$it = Db::table( 'payout_items' );
					$ct = Db::table( 'commissions' );
					// R13-K1: commissions first, the partner account after them – the order of
					// Ledger::record_refund() (commission → account). The other way round, a refund
					// of a commission of this payout and the cancellation deadlock each other.
					// Both UPDATEs are checked: a commission left on a cancelled payout would never
					// be paid (payable rows need payout_id = 0) while the account counts it again.
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$freed = $wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = 0, updated_at = %s WHERE payout_id = %d AND status = 'approved'", Db::now(), $id ) );
					$unset = false === $freed ? false : $wpdb->query( $wpdb->prepare( "UPDATE {$it} SET released_at = %s, active_commission_id = NULL WHERE payout_id = %d AND released_at IS NULL", Db::now(), $id ) );
					// phpcs:enable
					if ( false === $freed || false === $unset ) {
						throw new IntegrationException( 'db_error', esc_html__( 'The cancellation could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ) );
					}
					foreach ( $items as $item ) {
						Ledger::event( (int) $item->commission_id, 'payout_released', 'approved', 'approved', (int) $item->amount_minor, $p->number . ': ' . $reason );
					}
					\WPAffiliateCaptain\Commissions\Balance::post( (int) $p->affiliate_id, $p->currency, 'payout_cancelled', $released, 0, (int) $id, $p->number );
					self::reopen_request( $p );
					return $p;
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		self::after_commit(
			static function () use ( $id ) {
				Statement::generate_storno( (int) $id );
				// A cancelled payout needs no credit note any more: no further retries or alarms.
				Statement::forget_failures( (int) $id );
			}
		);
		Audit::log(
			'payout.cancelled',
			'payout',
			(int) $id,
			array(
				'reason'       => $reason,
				'was_exported' => 'exported' === $p->status,
			)
		);
		/**
		 * A payout that was not paid was cancelled (after the COMMIT; inside rebuild() only once
		 * the rebuild is committed, never when it is rolled back). Since contract 1.3.0.
		 *
		 * @param int $payout_id Payout.
		 */
		Db::after_commit(
			static function () use ( $id ) {
				do_action( 'wpac_payout_cancelled', (int) $id );
			}
		);
		return true;
	}

	/**
	 * A payout request that was settled by a payout that is now cancelled is open again: the
	 * partner still waits for the money (the next payout – e.g. the rebuilt one – settles it).
	 * Not when the partnership ended with "open commissions are rejected".
	 *
	 * @param object $p Cancelled payout (locked row).
	 */
	private static function reopen_request( $p ) {
		global $wpdb;
		$aff = AffiliateRepository::find( (int) $p->affiliate_id );
		if ( ! $aff || ( 'terminated' === $aff->status && 'reject' === Settings::get( 'terminated_open' ) ) || PayoutRequests::open_for( (int) $p->affiliate_id ) ) {
			return;
		}
		$rt = Db::table( 'payout_requests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$rt} SET status = 'requested', payout_id = 0, processed_at = NULL, processed_by = 0 WHERE payout_id = %d AND status = 'processed' ORDER BY id DESC LIMIT 1", (int) $p->id ) );
		if ( $n ) {
			Audit::log( 'payout_request.reopened', 'payout', (int) $p->id );
		}
	}

	/**
	 * Cancel the payouts of a partner that were not exported yet (termination with "open
	 * commissions are rejected": they must not be paid any more). Exported payouts may already be
	 * at the bank; they are returned for the operator to check.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $reason       Reason (cancellation document).
	 * @return array [ cancelled payout IDs, exported payout numbers left as they are ]
	 */
	public static function cancel_open_for_affiliate( $affiliate_id, $reason ) {
		$cancelled = array();
		$exported  = array();
		list( $rows ) = self::query(
			array(
				'affiliate_id' => (int) $affiliate_id,
				'limit'        => 1000,
			)
		);
		foreach ( $rows as $p ) {
			if ( 'pending' === $p->status && true === self::cancel( (int) $p->id, $reason ) ) {
				$cancelled[] = (int) $p->id;
			} elseif ( 'exported' === $p->status ) {
				$exported[] = (string) $p->number;
			}
		}
		if ( $exported ) {
			Audit::log( 'payout.termination_exported', 'affiliate', (int) $affiliate_id, array( 'payouts' => $exported ) );
		}
		return array( $cancelled, $exported );
	}

	/**
	 * Payout method recorded with a payout (snapshot): manual (PayPal etc.) or sepa.
	 *
	 * @param object $p Payout.
	 * @return string
	 */
	public static function snapshot_method( $p ) {
		$snap = json_decode( (string) ( isset( $p->profile_snapshot ) ? $p->profile_snapshot : '' ), true );
		return is_array( $snap ) && isset( $snap['payout_method'] ) && ProfileRepository::METHOD_MANUAL === $snap['payout_method'] ? ProfileRepository::METHOD_MANUAL : ProfileRepository::METHOD_SEPA;
	}

	/**
	 * PayPal Payouts file (mass payment upload in the PayPal business account, "Payouts" →
	 * "Upload file") for payouts of partners with another payment method whose payment details
	 * contain an e-mail address. Marks them exported like the other exports; payouts without a
	 * PayPal address are left out (and stay as they are). After PayPal executed the payouts, mark
	 * them as paid.
	 *
	 * @param int[] $ids            Payout IDs.
	 * @param bool  $allow_reexport Include payouts that were already exported.
	 * @return array|\WP_Error [ batch, rows (email, amount, currency, reference, note), left-out payout numbers ]
	 */
	public static function paypal_csv( array $ids, $allow_reexport = false ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		foreach ( $ids as $id ) {
			$missing = self::statement_missing( $id );
			if ( $missing ) {
				return $missing;
			}
		}
		try {
			$result = Db::transaction(
				static function () use ( $wpdb, $ids, $allow_reexport ) {
					$rows    = array();
					$used    = array();
					$skipped = array();
					$prefix  = (string) Settings::get( 'remittance_prefix' );
					foreach ( $ids as $id ) {
						$p = self::lock( $id );
						if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
							continue;
						}
						self::refuse_claimed( $p );
						if ( 'exported' === $p->status && ! $allow_reexport ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'reexport', esc_html( sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'affiliate-captain' ), $p->number ) ) );
						}
						$profile = ProfileRepository::get( (int) $p->affiliate_id );
						$email   = ProfileRepository::paypal_email( ProfileRepository::payment_details( $profile ) );
						if ( '' === $email ) {
							$skipped[] = (string) $p->number; // Bank transfer or no PayPal address: not in this file.
							continue;
						}
						if ( strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'profile_changed', esc_html( sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'affiliate-captain' ), $p->number ) ) );
						}
						$rows[]               = array( $email, Money::to_decimal( (int) $p->amount_minor, $p->currency ), strtoupper( (string) $p->currency ), (string) $p->number, trim( $prefix . ' ' . $p->number ) );
						$used[ (int) $p->id ] = $p->status;
					}
					if ( ! $rows ) {
						throw new IntegrationException( 'empty', esc_html__( 'None of the selected payouts goes to a PayPal address (other payment method with an e-mail address in the payment details).', 'affiliate-captain' ) );
					}
					$batch = 'ACPP' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 4, false ) );
					$pt    = Db::table( 'payouts' );
					foreach ( $used as $id => $status ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = %s", $batch, Db::now(), $id, $status ) ) ) {
							throw new IntegrationException( 'state', esc_html( 'Payout ' . $id . ' changed during export.' ) );
						}
					}
					return array( $batch, $rows, array_keys( $used ), $skipped );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		Audit::log( 'payout.paypal_exported', 'payout', 0, array( 'batch' => $result[0], 'payouts' => $result[2] ) );
		return array( $result[0], $result[1], $result[3] );
	}

	/**
	 * A payout an add-on claimed for a payment provider (see claim()) is never put into an export
	 * file, not even with "export again": the provider may be paying it right now, and the export
	 * would overwrite the claim (complete_claim()/release_claim() would fail afterwards). Only
	 * release_claim() gives it back (R12-K1).
	 *
	 * @param object $p Locked payout row.
	 * @throws IntegrationException wpac_claimed.
	 */
	private static function refuse_claimed( $p ) {
		if ( 'exported' === $p->status && 0 === strpos( (string) $p->sepa_batch, self::CLAIM_PREFIX ) ) {
			/* translators: 1: payout number, 2: claim reference */
			throw new IntegrationException( 'claimed', esc_html( sprintf( __( 'Payout %1$s is being paid through a payment provider (reference %2$s) and cannot be exported. The add-on that claimed it gives it back when the provider does not pay it.', 'affiliate-captain' ), $p->number, $p->sepa_batch ) ) );
		}
	}

	/**
	 * Text of a PayPal Payouts file: comma separated, no header line, no BOM, decimal point
	 * (recipient e-mail, amount, currency, reference ID, note to the recipient).
	 *
	 * @param array $rows Rows from paypal_csv().
	 * @return string
	 */
	public static function paypal_csv_text( array $rows ) {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memory stream.
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( \WPAffiliateCaptain\Core\Csv::class, 'safe_cell' ), array_values( (array) $row ) ), ',', '"', '' );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Memory stream.
		return $csv;
	}

	/**
	 * Transfer list (CSV) for payouts in any currency, e.g. for foreign banks or payment
	 * providers; marks them exported like the SEPA file (same checks: no second export without
	 * "export again", no export after the partner changed the bank details).
	 *
	 * @param int[] $ids            Payout IDs.
	 * @param bool  $allow_reexport Include payouts that were already exported.
	 * @return array|\WP_Error [ batch, rows ]
	 */
	public static function transfer_csv( array $ids, $allow_reexport = false ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		foreach ( $ids as $id ) {
			$missing = self::statement_missing( $id );
			if ( $missing ) {
				return $missing;
			}
		}
		try {
			$result = Db::transaction(
				static function () use ( $wpdb, $ids, $allow_reexport ) {
					$rows   = array();
					$used   = array();
					$prefix = (string) Settings::get( 'remittance_prefix' );
					foreach ( $ids as $id ) {
						$p = self::lock( $id );
						if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
							continue;
						}
						self::refuse_claimed( $p );
						if ( 'exported' === $p->status && ! $allow_reexport ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'reexport', esc_html( sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'affiliate-captain' ), $p->number ) ) );
						}
						$profile = ProfileRepository::get( (int) $p->affiliate_id );
						if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'profile_changed', esc_html( sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'affiliate-captain' ), $p->number ) ) );
						}
						if ( ProfileRepository::METHOD_MANUAL === ProfileRepository::method( $profile ) ) {
							// Other payment method (e.g. PayPal): the list carries the payment details;
							// the operator pays manually and marks the payout as paid.
							$details = ProfileRepository::payment_details( $profile );
							if ( null === $details || '' === $details ) {
								/* translators: %s: payout number */
								throw new IntegrationException( 'details', esc_html( sprintf( __( 'Payment details for payout %s are missing or cannot be decrypted.', 'affiliate-captain' ), $p->number ) ) );
							}
							$holder = '' !== trim( (string) $profile->account_holder ) ? (string) $profile->account_holder : (string) $profile->legal_name;
							$rows[] = array( $p->number, $holder, '', '', Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->currency, trim( $prefix . ' ' . $p->number ), ProfileRepository::METHOD_MANUAL, str_replace( array( "\r\n", "\r", "\n" ), ' / ', $details ) );
						} else {
							$iban = ProfileRepository::iban( $profile );
							if ( ! $iban ) {
								/* translators: %s: payout number */
								throw new IntegrationException( 'iban', esc_html( sprintf( __( 'Bank details for payout %s are missing or cannot be decrypted.', 'affiliate-captain' ), $p->number ) ) );
							}
							$rows[] = array( $p->number, (string) $profile->account_holder, $iban, (string) $profile->bic, Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->currency, trim( $prefix . ' ' . $p->number ), ProfileRepository::METHOD_SEPA, '' );
						}
						$used[ (int) $p->id ] = $p->status;
					}
					if ( ! $rows ) {
						throw new IntegrationException( 'empty', esc_html__( 'No exportable payouts selected.', 'affiliate-captain' ) );
					}
					$batch = 'ACCSV' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 4, false ) );
					$pt    = Db::table( 'payouts' );
					foreach ( $used as $id => $status ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = %s", $batch, Db::now(), $id, $status ) ) ) {
							throw new IntegrationException( 'state', esc_html( 'Payout ' . $id . ' changed during export.' ) );
						}
					}
					return array( $batch, $rows, array_keys( $used ) );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		Audit::log( 'payout.csv_exported', 'payout', 0, array( 'batch' => $result[0], 'payouts' => $result[2] ) );
		/**
		 * Rows of the transfer list (one per payout, without the header line), e.g. extra columns
		 * of an add-on (append values at the end).
		 *
		 * @since contract 1.2.0
		 * @param array  $rows    Rows.
		 * @param string $batch   Export batch.
		 * @param int[]  $payouts Exported payouts.
		 */
		$rows = apply_filters( 'wpac_transfer_rows', $result[1], $result[0], $result[2] );
		return array( $result[0], is_array( $rows ) ? $rows : $result[1] );
	}

	/**
	 * Build a SEPA file for payouts and mark them exported (atomically).
	 *
	 * Already exported payouts are only included again with $allow_reexport
	 * (e.g. the bank rejected the file) to avoid sending the same transfers twice.
	 * Payouts whose partner changed bank details after the payout was created are refused.
	 * Payouts of partners with another payment method (manual) are left out; they stay
	 * pending for the transfer list and "mark as paid".
	 *
	 * @param int[] $ids            Payout IDs.
	 * @param bool  $allow_reexport Include payouts that were already exported.
	 * @return array|\WP_Error [ filename, xml, number of payouts left out (other payment method), exported payout IDs ]
	 */
	public static function sepa_export( array $ids, $allow_reexport = false ) {
		global $wpdb;
		$debtor = array(
			'name' => (string) Settings::get( 'debtor_name' ),
			'iban' => (string) Settings::get( 'debtor_iban' ),
			'bic'  => (string) Settings::get( 'debtor_bic' ),
		);
		if ( '' === $debtor['name'] || '' === $debtor['iban'] ) {
			return new \WP_Error( 'wpac_debtor', __( 'Please enter the account holder and IBAN of your company account in the settings first.', 'affiliate-captain' ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'wpac_empty', __( 'No exportable payouts selected.', 'affiliate-captain' ) );
		}
		foreach ( $ids as $id ) {
			$missing = self::statement_missing( $id );
			if ( $missing ) {
				return $missing;
			}
		}
		try {
			$result = Db::transaction(
				static function () use ( $wpdb, $ids, $allow_reexport, $debtor ) {
					$transfers = array();
					$prefix    = (string) Settings::get( 'remittance_prefix' );
					$used      = array();
					$manual    = 0;
					foreach ( $ids as $id ) {
						$p = self::lock( $id ); // Serializes concurrent exports and cancellations.
						if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
							continue;
						}
						self::refuse_claimed( $p );
						if ( 'exported' === $p->status && ! $allow_reexport ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'reexport', esc_html( sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'affiliate-captain' ), $p->number ) ) );
						}
						$profile = ProfileRepository::get( (int) $p->affiliate_id );
						if ( ProfileRepository::METHOD_MANUAL === ProfileRepository::method( $profile ) ) {
							// Paid by another method (transfer list, then "mark as paid"): never in the SEPA file.
							++$manual;
							continue;
						}
						if ( 'EUR' !== $p->currency ) {
							throw new IntegrationException( 'currency', esc_html__( 'SEPA transfers are only possible in EUR.', 'affiliate-captain' ) );
						}
						if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'profile_changed', esc_html( sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'affiliate-captain' ), $p->number ) ) );
						}
						$iban = ProfileRepository::iban( $profile );
						if ( ! $iban || '' === (string) $p->number ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'iban', esc_html( sprintf( __( 'Bank details for payout %s are missing or cannot be decrypted.', 'affiliate-captain' ), $p->number ) ) );
						}
						$transfers[]          = array(
							'end_to_end_id' => $p->number,
							'name'          => $profile->account_holder,
							'iban'          => $iban,
							'bic'           => $profile->bic,
							'amount_minor'  => (int) $p->amount_minor,
							'remittance'    => trim( $prefix . ' ' . $p->number ),
						);
						$used[ (int) $p->id ] = $p->status;
					}
					if ( ! $transfers ) {
						throw new IntegrationException(
							'empty',
							$manual
								? esc_html__( 'The selected payouts use another payment method (e.g. PayPal) and are not part of the SEPA file. Download the transfer list (CSV) for their payment details, pay them manually and mark them as paid.', 'affiliate-captain' )
								: esc_html__( 'No exportable payouts selected.', 'affiliate-captain' )
						);
					}
					// Unique message ID, even for two exports in the same second.
					$batch = 'ACSEPA' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 4, false ) );
					try {
						$xml = SepaXml::build( $debtor, $transfers, $batch, SepaXml::next_business_day( time() ) );
					} catch ( \InvalidArgumentException $e ) {
						throw new IntegrationException( 'sepa', esc_html( $e->getMessage() ) );
					}
					$pt = Db::table( 'payouts' );
					foreach ( $used as $id => $status ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = %s", $batch, Db::now(), $id, $status ) );
						if ( 1 !== $rows ) {
							throw new IntegrationException( 'state', esc_html( 'Payout ' . $id . ' changed during export.' ) );
						}
					}
					return array( $batch, $xml, array_keys( $used ), $manual );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		list( $batch, $xml, $used, $manual ) = $result;
		Audit::log(
			'payout.sepa_exported',
			'payout',
			0,
			array(
				'batch'   => $batch,
				'payouts' => $used,
			)
		);
		return array( $batch . '.xml', $xml, (int) $manual, $used );
	}

	/**
	 * Prefix of the batch reference of a payout an add-on claimed for a payment provider.
	 */
	const CLAIM_PREFIX = 'API-';

	/**
	 * A claimed payout can be cancelled in the admin only this long after the claim (seconds).
	 */
	const CLAIM_CANCEL_AFTER = 45 * DAY_IN_SECONDS;

	/**
	 * Seconds after a claim before a claimed payout may be cancelled in the admin. Default 45 days:
	 * payment providers can settle late (PayPal keeps unclaimed or pending payouts up to 30–40 days),
	 * and cancelling earlier could pay the commissions twice. Filter: wpac_claim_cancel_after.
	 *
	 * @return int
	 */
	public static function claim_cancel_after() {
		/**
		 * Seconds after a claim before a claimed payout may be cancelled in the admin.
		 *
		 * @param int $seconds Default 45 days.
		 */
		return max( DAY_IN_SECONDS, (int) apply_filters( 'wpac_claim_cancel_after', max( 1, (int) Settings::get( 'claim_cancel_days' ) ) * DAY_IN_SECONDS ) );
	}

	/**
	 * A payout as the public contract describes it (wpac_get_payout()).
	 *
	 * The PayPal address is only filled for the manual method whose payment details contain an
	 * e-mail address (the same rule as the PayPal payouts file); bank details never leave.
	 *
	 * @param object                  $p      Payout row.
	 * @param array<int, string>|null $emails PayPal addresses per affiliate, preloaded for a page (contract_list()); null loads the profile.
	 * @return array
	 */
	public static function contract_view( $p, $emails = null ) {
		$method = self::snapshot_method( $p );
		$email  = '';
		if ( self::wants_paypal_email( $p, $method ) ) {
			if ( is_array( $emails ) && array_key_exists( (int) $p->affiliate_id, $emails ) ) {
				$email = $emails[ (int) $p->affiliate_id ];
			} else {
				$email = self::paypal_emails( array( (int) $p->affiliate_id ) )[ (int) $p->affiliate_id ];
			}
		}
		$batch = (string) $p->sepa_batch;
		return array(
			'id'           => (int) $p->id,
			'number'       => (string) $p->number,
			'affiliate_id' => (int) $p->affiliate_id,
			'status'       => (string) $p->status,
			'currency'     => strtoupper( (string) $p->currency ),
			'amount_minor' => (int) $p->amount_minor,
			'method'       => $method,
			'paypal_email' => $email,
			'claim'        => 0 === strpos( $batch, self::CLAIM_PREFIX ) ? $batch : '',
			'created_at'   => (string) $p->created_at,
			'exported_at'  => (string) $p->exported_at,
			'paid_at'      => (string) $p->paid_at,
		);
	}

	/**
	 * Whether contract_view() shows the PayPal address of a payout (manual method, not paid yet).
	 *
	 * @param object $p      Payout row.
	 * @param string $method Method from the snapshot.
	 * @return bool
	 */
	private static function wants_paypal_email( $p, $method ) {
		return ProfileRepository::METHOD_MANUAL === $method && in_array( (string) $p->status, array( 'pending', 'exported' ), true );
	}

	/**
	 * PayPal addresses of several partners with one query and one decryption per partner (no
	 * query per payout in wpac_get_payouts()).
	 *
	 * @param int[] $affiliate_ids Partners.
	 * @return array<int, string> Address per partner ('' without manual profile or address).
	 */
	private static function paypal_emails( array $affiliate_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $affiliate_ids ) ) ) );
		$out = array_fill_keys( $ids, '' );
		if ( ! $ids ) {
			return $out;
		}
		$t = Db::table( 'payout_profiles' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Table name; one %d per ID.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) );
		foreach ( $rows as $profile ) {
			if ( ProfileRepository::METHOD_MANUAL === ProfileRepository::method( $profile ) ) {
				$out[ (int) $profile->affiliate_id ] = (string) ProfileRepository::paypal_email( ProfileRepository::payment_details( $profile ) );
			}
		}
		return $out;
	}

	/**
	 * Payouts for the public contract (wpac_get_payouts()).
	 *
	 * Page through all payouts with after_id: pass the id of the last payout of the previous
	 * page. The method filter is applied before the limit (a page is filled with matching payouts
	 * as long as there are any). An unknown status or method gives an empty list, as documented
	 * in contract 1.3.0 (no WP_Error: callers expect an array).
	 *
	 * @param array $args status (pending|exported|paid|cancelled), method (sepa|manual), affiliate_id, after_id, limit (1–200, default 50).
	 * @return array[] Payouts as contract_view(), oldest first.
	 */
	public static function contract_list( array $args = array() ) {
		global $wpdb;
		$t      = Db::table( 'payouts' );
		$where  = array( '1=1' );
		$params = array();
		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		if ( '' !== $status ) {
			if ( ! array_key_exists( $status, self::status_labels() ) ) {
				return array();
			}
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( ! empty( $args['affiliate_id'] ) ) {
			$where[]  = 'affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		$method = isset( $args['method'] ) ? sanitize_key( (string) $args['method'] ) : '';
		if ( '' !== $method ) {
			if ( ! in_array( $method, array( ProfileRepository::METHOD_SEPA, ProfileRepository::METHOD_MANUAL ), true ) ) {
				return array();
			}
			// Pre-filter on the snapshot (JSON written by wp_json_encode()); snapshot_method()
			// below stays the rule, so a row the pattern lets through by mistake is dropped.
			$pattern  = '%' . $wpdb->esc_like( '"payout_method":"' . ProfileRepository::METHOD_MANUAL . '"' ) . '%';
			$where[]  = ProfileRepository::METHOD_MANUAL === $method ? 'profile_snapshot LIKE %s' : '( profile_snapshot IS NULL OR profile_snapshot NOT LIKE %s )';
			$params[] = $pattern;
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$after = isset( $args['after_id'] ) ? max( 0, (int) $args['after_id'] ) : 0;
		$out   = array();
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name and fixed fragments ($where holds only literal conditions); values are placeholders.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' AND id > %d ORDER BY id ASC LIMIT %d', array_merge( $params, array( $after, $limit ) ) ) );
			$need = array();
			foreach ( $rows as $p ) {
				if ( self::wants_paypal_email( $p, self::snapshot_method( $p ) ) ) {
					$need[] = (int) $p->affiliate_id;
				}
			}
			$emails = self::paypal_emails( $need );
			foreach ( $rows as $p ) {
				$after = (int) $p->id;
				if ( '' !== $method && self::snapshot_method( $p ) !== $method ) {
					continue;
				}
				$out[] = self::contract_view( $p, $emails );
				if ( count( $out ) >= $limit ) {
					break 2;
				}
			}
		} while ( count( $rows ) === $limit );
		return $out;
	}

	/**
	 * Claim a created payout for a payment provider (wpac_claim_payout()).
	 *
	 * The same guards as the export files: the credit note must exist, the payout must still be
	 * "created" (an exported payout may already be at the bank or with another provider), and
	 * the partner must not have changed the payout details after the payout was created. The
	 * payout becomes "exported" with the claim as batch reference, so neither an export file nor
	 * a second claim can pay it again.
	 *
	 * @param int    $id       Payout.
	 * @param string $provider Provider key, e.g. paypal (a–z, 0–9, _ and -, 2–20 characters).
	 * @return array|\WP_Error The payout (contract_view()) with its claim.
	 */
	public static function claim( $id, $provider ) {
		global $wpdb;
		$id       = (int) $id;
		$provider = strtolower( (string) $provider );
		if ( ! preg_match( '/^[a-z0-9_-]{2,20}$/', $provider ) ) {
			return new \WP_Error( 'wpac_provider', 'Invalid provider key.' );
		}
		$missing = self::statement_missing( $id );
		if ( $missing ) {
			return $missing;
		}
		$claim = self::CLAIM_PREFIX . strtoupper( $provider ) . '-' . $id . '-' . strtoupper( wp_generate_password( 6, false ) );
		try {
			Db::transaction(
				static function () use ( $wpdb, $id, $claim ) {
					$p = self::lock( $id );
					if ( ! $p || 'pending' !== $p->status ) {
						throw new IntegrationException( 'payout_state', 'not claimable' );
					}
					$profile = ProfileRepository::get( (int) $p->affiliate_id );
					if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
						throw new IntegrationException( 'profile_changed', 'profile changed' );
					}
					$t = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = 'pending'", $claim, Db::now(), $id ) ) ) {
						throw new IntegrationException( 'payout_state', 'changed' );
					}
				}
			);
		} catch ( IntegrationException $e ) {
			if ( 'profile_changed' === $e->error_code ) {
				return new \WP_Error( 'wpac_profile_changed', __( 'The partner changed the payout details after the payout was created. Please verify the change, cancel the payout and create it again.', 'affiliate-captain' ) );
			}
			return new \WP_Error( 'wpac_payout_state', __( 'Only a created payout that was not exported yet can be paid through a payment provider.', 'affiliate-captain' ) );
		}
		Audit::log( 'payout.claimed', 'payout', $id, array( 'provider' => $provider, 'claim' => $claim ) );
		return self::contract_view( self::find( $id ) );
	}

	/**
	 * Give a claimed payout back, e.g. after the provider refused it (wpac_release_payout()).
	 * The payout is "created" again and can be exported or claimed anew.
	 *
	 * @param int    $id     Payout.
	 * @param string $claim  Claim returned by claim().
	 * @param string $reason Reason for the audit log.
	 * @return true|\WP_Error
	 */
	public static function release_claim( $id, $claim, $reason = '' ) {
		global $wpdb;
		$id    = (int) $id;
		$claim = (string) $claim;
		if ( 0 !== strpos( $claim, self::CLAIM_PREFIX ) ) {
			return new \WP_Error( 'wpac_claim', 'Not a claim reference.' );
		}
		$t = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'pending', sepa_batch = '', exported_at = NULL WHERE id = %d AND status = 'exported' AND sepa_batch = %s", $id, $claim ) ) ) {
			return new \WP_Error( 'wpac_claim', __( 'The payout is not claimed with this reference.', 'affiliate-captain' ) );
		}
		Audit::log( 'payout.claim_released', 'payout', $id, array( 'claim' => $claim, 'reason' => mb_substr( sanitize_text_field( (string) $reason ), 0, 200 ) ) );
		return true;
	}

	/**
	 * Mark a claimed payout as paid after the provider executed it (wpac_complete_payout()).
	 * Same effect as "mark as paid" in the admin: commissions become paid, wpac_payout_paid fires.
	 *
	 * @param int    $id        Payout.
	 * @param string $claim     Claim returned by claim().
	 * @param string $reference Transaction or batch ID of the provider, for the audit log.
	 * @return true|\WP_Error
	 */
	public static function complete_claim( $id, $claim, $reference ) {
		$id    = (int) $id;
		$claim = (string) $claim;
		if ( 0 !== strpos( $claim, self::CLAIM_PREFIX ) ) {
			return new \WP_Error( 'wpac_claim', __( 'The payout is not claimed with this reference.', 'affiliate-captain' ) );
		}
		// The claim is checked again under the row lock inside mark_paid().
		$paid = self::mark_paid( $id, $claim );
		if ( is_wp_error( $paid ) ) {
			return $paid;
		}
		Audit::log( 'payout.provider_paid', 'payout', $id, array( 'claim' => (string) $claim, 'reference' => mb_substr( sanitize_text_field( (string) $reference ), 0, 100 ) ) );
		return true;
	}

	/**
	 * Find.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Items with commission data.
	 *
	 * @param int  $id          Payout.
	 * @param bool $active_only Only items not released by a cancellation.
	 * @return array
	 */
	public static function items( $id, $active_only = false ) {
		global $wpdb;
		$i = Db::table( 'payout_items' );
		$c = Db::table( 'commissions' );
		$active = $active_only ? ' AND i.released_at IS NULL' : ''; // Constant fragment, no input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT i.*, c.source, c.source_order_id, c.description, c.kind, c.created_at AS commission_date FROM {$i} i LEFT JOIN {$c} c ON c.id = i.commission_id WHERE i.payout_id = %d{$active} ORDER BY i.id ASC", $id ) );
	}

	/**
	 * List payouts.
	 *
	 * @param array $args affiliate_id, status, search, from/to (local days of created_at, Y-m-d),
	 *                    portal (bool: only what the partner area lists – no cancelled payout
	 *                    without cancellation document, R14-U2), limit, offset, orderby
	 *                    (whitelisted), order.
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t      = Db::table( 'payouts' );
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['affiliate_id'] ) ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		$from = isset( $args['from'] ) && is_string( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ? $args['from'] : '';
		$to   = isset( $args['to'] ) && is_string( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ? $args['to'] : '';
		if ( '' !== $from || '' !== $to ) {
			list( $start, $end ) = Db::local_range( '' !== $from ? $from : '1970-01-02', '' !== $to ? $to : '9999-12-30' );
			$where              .= ' AND created_at BETWEEN %s AND %s';
			$params[]            = $start;
			$params[]            = $end;
		}
		if ( ! empty( $args['portal'] ) ) {
			$where .= " AND NOT ( status = 'cancelled' AND COALESCE(storno_file, '') = '' )";
		}
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			// Document number, SEPA batch, or the partner's name or e-mail (admin list search, R5-O47).
			$like     = '%' . $wpdb->esc_like( Db::cut( $search, 100 ) ) . '%';
			$where   .= ' AND ( number LIKE %s OR storno_number LIKE %s OR sepa_batch LIKE %s OR affiliate_id IN ( SELECT id FROM ' . Db::table( 'affiliates' ) . ' WHERE name LIKE %s OR email LIKE %s ) )';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}
		return \WPAffiliateCaptain\Core\Paged::fetch( $t, $where, $params, $args, array( 'id', 'created_at', 'amount_minor', 'status', 'period_end' ) );
	}
}
