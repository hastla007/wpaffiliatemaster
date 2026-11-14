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
		$end                   = ( null === $period_end ? gmdate( 'Y-m-d' ) : (string) $period_end ) . ' 23:59:59';
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
		list( $from, $params ) = self::payable_sql( $currency, $period_end . ' 23:59:59' );
		// $from is built by payable_sql() from constants; its values are placeholders in $params.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.affiliate_id, SUM(c.amount_minor) total, COUNT(*) c {$from} GROUP BY c.affiliate_id ORDER BY total DESC", $params ) );
		$min      = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
		$operator = self::operator_missing();
		$out      = array();
		// One query for all partners instead of one per row; profiles are only loaded for
		// partners above the minimum (the others are carried forward anyway).
		AffiliateRepository::find_many( wp_list_pluck( $rows, 'affiliate_id' ) );
		foreach ( $rows as $r ) {
			$aff    = AffiliateRepository::find( (int) $r->affiliate_id );
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
				$gross = self::payable_gross( (int) $r->affiliate_id, $currency, $period_end . ' 23:59:59' );
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
	 * - repayments and write-offs are no service (not taxable, Vat::NONE);
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
			if ( ! isset( $groups[ $rate ] ) ) {
				$groups[ $rate ] = array(
					'rate' => $rate,
					'net'  => 0,
				);
			}
			$groups[ $rate ]['net'] += (int) $r->amount_minor;
		}
		return $groups;
	}

	/**
	 * Operator details as printed on the credit note, frozen with each payout
	 * (a later cancellation shows the data of the original document).
	 *
	 * @return array
	 */
	public static function operator_snapshot() {
		$name = (string) Settings::get( 'debtor_name' );
		// Language and date format of the document are frozen too: a credit note is always
		// written in the site language, whoever triggers it (and can be built again identically).
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		return array(
			'name'       => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'address'    => (string) Settings::get( 'operator_address' ),
			'country'    => strtoupper( (string) Settings::get( 'operator_country' ) ),
			'vat_id'     => (string) Settings::get( 'operator_vat_id' ),
			'tax_number' => (string) Settings::get( 'operator_tax_number' ),
			'self_billing' => (bool) Settings::get( 'self_billing' ),
			'locale'       => Statement::document_locale( $locale ),
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
			return __( 'Maintenance mode is on (Settings → Reports & data)', 'affiliate-captain' );
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
			return __( 'Self-billing needs partner terms with the credit note agreement (Settings → Program → Terms page)', 'affiliate-captain' );
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
		$fn();
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
			throw new IntegrationException( 'statement', 'The credit note of payout ' . $id . ' could not be written yet.' );
		}
		/** This action is documented in create_for_affiliate(). */
		do_action( 'wpac_payout_created', $id, (int) $p->affiliate_id );
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
				list( $from, $params ) = self::payable_sql( $currency, $period_end . ' 23:59:59', $affiliate_id );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor, c.kind, c.parent_id, c.created_at, p.created_at AS parent_created_at, p.payout_id AS parent_payout_id {$from} FOR UPDATE", $params ) );
				$total = 0;
				foreach ( $rows as $r ) {
					$total += (int) $r->amount_minor;
				}
				$min = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
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
				$operator_snapshot = self::operator_snapshot();
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
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $limits[ $r->currency ] ) ) {
				continue;
			}
			$which = Vat::small_business_exceeded( (int) $r->prev_total, (int) $r->cur_total, array_values( (array) $limits[ $r->currency ] ) );
			if ( '' !== $which ) {
				$out[] = array( (int) $r->affiliate_id, (string) $r->currency, (int) $r->prev_total, (int) $r->cur_total, $which );
			}
		}
		return $out;
	}

	/**
	 * Every payout needs its document before money moves: a missing statement (e.g. the
	 * file could not be written) is generated now; if that fails, an error is returned.
	 *
	 * @param int $id Payout.
	 * @return \WP_Error|null
	 */
	public static function statement_missing( $id ) {
		$p = self::find( $id );
		if ( ! $p || '' !== (string) $p->statement_file || $p->statement_deleted_at ) {
			return null;
		}
		Statement::generate( (int) $id );
		$p = self::find( $id );
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
	 * @param int $id Payout.
	 * @return true|\WP_Error
	 */
	public static function mark_paid( $id ) {
		global $wpdb;
		$missing = self::statement_missing( $id );
		if ( $missing ) {
			return $missing;
		}
		try {
			$paid = Db::transaction(
				static function () use ( $wpdb, $id ) {
					$p = self::lock( $id );
					if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
						throw new IntegrationException( 'state', 'not payable' );
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
			return new \WP_Error( 'wpac_state', __( 'This payout cannot be marked as paid.', 'affiliate-captain' ) );
		}
		list( $p, $ids ) = $paid;
		Audit::log( 'payout.paid', 'payout', (int) $id, array( 'number' => $p->number ) );
		foreach ( $ids as $cid ) {
			do_action( 'wpac_commission_paid', $cid );
		}
		do_action( 'wpac_payout_paid', (int) $id );
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
					if ( 'exported' === $p->status && ! $confirm_not_sent ) {
						/* translators: %s: payout number */
						throw new IntegrationException( 'exported', esc_html( sprintf( __( 'Payout %s was already exported to SEPA. Confirm that the bank did not execute the transfer before cancelling – otherwise the commissions could be paid twice.', 'affiliate-captain' ), $p->number ) ) );
					}
					$pt = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'cancelled', note = %s WHERE id = %d AND status = %s", mb_substr( sanitize_text_field( $reason ), 0, 255 ), $id, $p->status ) );
					if ( false === $rows ) {
						throw new IntegrationException( 'db_error', esc_html__( 'The cancellation could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ) );
					}
					if ( 1 !== (int) $rows ) {
						throw new IntegrationException( 'state', esc_html__( 'Only unpaid payouts can be cancelled.', 'affiliate-captain' ) );
					}
					// A credit note that was issued is cancelled by its own document with a new number.
					if ( '' !== (string) $p->statement_file || '' !== (string) $p->statement_hash ) {
						$storno = Sequence::number( (string) Settings::get( 'storno_prefix' ), 'storno', (int) wp_date( 'Y' ) );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET storno_number = %s, storno_at = %s WHERE id = %d", $storno, Db::now(), $id ) );
					}
					foreach ( self::items( $id, true ) as $item ) {
						Ledger::event( (int) $item->commission_id, 'payout_released', 'approved', 'approved', (int) $item->amount_minor, $p->number . ': ' . $reason );
					}
					$released = 0;
					foreach ( self::items( $id, true ) as $item ) {
						$released += (int) $item->amount_minor;
					}
					\WPAffiliateCaptain\Commissions\Balance::post( (int) $p->affiliate_id, $p->currency, 'payout_cancelled', $released, 0, (int) $id, $p->number );
					$it = Db::table( 'payout_items' );
					$ct = Db::table( 'commissions' );
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE {$it} SET released_at = %s, active_commission_id = NULL WHERE payout_id = %d AND released_at IS NULL", Db::now(), $id ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = 0, updated_at = %s WHERE payout_id = %d AND status = 'approved'", Db::now(), $id ) );
					// phpcs:enable
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
							throw new IntegrationException( 'state', 'Payout ' . $id . ' changed during export.' );
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
	 * Text of a PayPal Payouts file: comma separated, no header line, no BOM, decimal point
	 * (recipient e-mail, amount, currency, reference ID, note to the recipient).
	 *
	 * @param array $rows Rows from paypal_csv().
	 * @return string
	 */
	public static function paypal_csv_text( array $rows ) {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Memory stream.
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( \WPAffiliateCaptain\Core\Csv::class, 'safe_cell' ), array_values( (array) $row ) ), ',', '"', '\\' );
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
	 * @param array $args affiliate_id, status, limit, offset.
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
		return \WPAffiliateCaptain\Core\Paged::fetch( $t, $where, $params, $args, array( 'id', 'created_at', 'amount_minor', 'status', 'period_end' ) );
	}
}
