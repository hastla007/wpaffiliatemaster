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
use WPAffiliateCaptain\Core\Money;
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
			'pending'   => __( 'Created', 'wp-affiliate-captain' ),
			'exported'  => __( 'SEPA exported', 'wp-affiliate-captain' ),
			'paid'      => __( 'Paid', 'wp-affiliate-captain' ),
			'cancelled' => __( 'Cancelled', 'wp-affiliate-captain' ),
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
				$reason = __( 'Affiliate not found', 'wp-affiliate-captain' );
				$code   = 'not_found';
			} elseif ( $operator ) {
				$reason = $operator;
				$code   = 'operator';
			} elseif ( null === $min ) {
				/* translators: %s: currency */
				$reason = sprintf( __( 'No reference rate for %s – minimum cannot be checked', 'wp-affiliate-captain' ), $currency );
				$code   = 'no_rate';
			} elseif ( $total <= 0 ) {
				$reason = __( 'Negative balance – carried forward', 'wp-affiliate-captain' );
				$code   = 'negative';
			} elseif ( $total < $min ) {
				$reason = __( 'Below minimum – carried forward', 'wp-affiliate-captain' );
				$code   = 'below_minimum';
			} else {
				$reason = self::blocked_reason( (int) $r->affiliate_id, ProfileRepository::get( (int) $r->affiliate_id ), $code );
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
	 * VAT treatment per commission row: corrections whose original commission was settled in
	 * an earlier (not cancelled) credit note take over that credit note's mode and rate.
	 *
	 * @param array $rows Rows with id, parent_id, parent_payout_id.
	 * @param array $vat  Current treatment (mode, rate).
	 * @return array commission ID => [ mode, rate, ref ]
	 */
	public static function item_treatments( array $rows, array $vat ) {
		$out   = array();
		$cache = array();
		foreach ( $rows as $r ) {
			$ref = (int) ( isset( $r->parent_payout_id ) ? $r->parent_payout_id : 0 );
			if ( $ref && ! array_key_exists( $ref, $cache ) ) {
				$cache[ $ref ] = self::find( $ref );
			}
			$orig = $ref ? $cache[ $ref ] : null;
			if ( $orig && 'cancelled' !== $orig->status ) {
				$out[ (int) $r->id ] = array(
					'mode' => (string) $orig->vat_mode,
					'rate' => (string) $orig->vat_rate,
					'ref'  => $ref,
				);
			} else {
				$out[ (int) $r->id ] = array(
					'mode' => (string) $vat['mode'],
					'rate' => (string) $vat['rate'],
					'ref'  => 0,
				);
			}
		}
		return $out;
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
		return array(
			'name'       => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'address'    => (string) Settings::get( 'operator_address' ),
			'country'    => strtoupper( (string) Settings::get( 'operator_country' ) ),
			'vat_id'     => (string) Settings::get( 'operator_vat_id' ),
			'tax_number' => (string) Settings::get( 'operator_tax_number' ),
			'self_billing' => (bool) Settings::get( 'self_billing' ),
		);
	}

	/**
	 * Why a partner cannot be paid out right now ('' = can be paid). Checked for every
	 * payout, also single payouts from requests.
	 *
	 * @param int         $affiliate_id Partner.
	 * @param array|null  $profile      Payout profile (loaded when null).
	 * @param string|null $code         Out: reason code (maintenance|profile|terms_page|terms|operator_vat_id|vies, '' = none).
	 * @return string
	 */
	public static function blocked_reason( $affiliate_id, $profile = null, &$code = null ) {
		$code = '';
		if ( Settings::get( 'maintenance_mode' ) ) {
			$code = 'maintenance';
			return __( 'Maintenance mode is on (Settings → Reports & data)', 'wp-affiliate-captain' );
		}
		$profile = null === $profile ? ProfileRepository::get( (int) $affiliate_id ) : $profile;
		$missing = ProfileRepository::missing( $profile );
		if ( true !== $missing ) {
			$code   = 'profile';
			$fields = array(
				'tax_status'     => __( 'tax status', 'wp-affiliate-captain' ),
				'legal_name'     => __( 'legal name', 'wp-affiliate-captain' ),
				'address'        => __( 'address', 'wp-affiliate-captain' ),
				'country'        => __( 'country', 'wp-affiliate-captain' ),
				'account_holder' => __( 'account holder', 'wp-affiliate-captain' ),
				'iban'           => __( 'IBAN', 'wp-affiliate-captain' ),
				'vat_id'         => __( 'VAT ID (business)', 'wp-affiliate-captain' ),
				'tax_number'     => __( 'tax number or VAT ID (small business)', 'wp-affiliate-captain' ),
			);
			/* translators: %s: missing field */
			return sprintf( __( 'Payout details incomplete: %s missing', 'wp-affiliate-captain' ), isset( $fields[ $missing ] ) ? $fields[ $missing ] : (string) $missing );
		}
		if ( Settings::get( 'self_billing' ) && ! Settings::get( 'terms_page_id' ) ) {
			// Self-billing needs a prior agreement (§14 Abs. 2 S. 2 UStG); without partner terms there is none.
			$code = 'terms_page';
			return __( 'Self-billing needs partner terms with the credit note agreement (Settings → Program → Terms page)', 'wp-affiliate-captain' );
		}
		if ( Settings::get( 'self_billing' ) && ! \WPAffiliateCaptain\Affiliates\Agreements::latest( (int) $affiliate_id ) ) {
			// Self-billing needs a prior agreement (§14 Abs. 2 UStG): the accepted terms.
			$code = 'terms';
			return __( 'Has not accepted the partner terms yet (needed for credit notes) – the partner is asked to accept them on the next login to the partner area', 'wp-affiliate-captain' );
		}
		$vat = Vat::treatment( isset( $profile->tax_status ) ? (string) $profile->tax_status : '', isset( $profile->country ) ? (string) $profile->country : '', (string) Settings::get( 'operator_country' ), (string) Settings::get( 'vat_rate' ) );
		if ( Vat::REVERSE_CHARGE === $vat['mode'] && '' === trim( (string) Settings::get( 'operator_vat_id' ) ) ) {
			// Reverse-charge documents must show the VAT ID of the recipient (the operator).
			$code = 'operator_vat_id';
			return __( 'Reverse charge needs your VAT ID (Settings → Payouts & credit notes)', 'wp-affiliate-captain' );
		}
		if ( Vat::REVERSE_CHARGE === $vat['mode'] && isset( $profile->vat_id ) && Vies::is_invalid( (int) $affiliate_id, (string) $profile->vat_id ) ) {
			$code = 'vies';
			return __( 'The partner\'s VAT ID is not valid according to VIES (reverse charge not possible)', 'wp-affiliate-captain' );
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
			return new \WP_Error( 'wpac_state', __( 'Only payouts that were not exported yet can be rebuilt.', 'wp-affiliate-captain' ) );
		}
		// Check first whether the payout can be created again: a cancellation (with its own
		// document number) must not be left behind without a replacement.
		$blocked = self::operator_missing();
		$blocked = '' !== $blocked ? $blocked : self::blocked_reason( (int) $p->affiliate_id );
		if ( '' !== $blocked ) {
			/* translators: %s: reason */
			return new \WP_Error( 'wpac_not_eligible', sprintf( __( 'The payout cannot be created again (%s). It was not cancelled.', 'wp-affiliate-captain' ), $blocked ) );
		}
		// Cancellation and new payout in one transaction: if nothing (or less than the minimum)
		// would be payable, both are rolled back. Documents are written after the COMMIT.
		self::$deferred = array();
		try {
			$new = Db::transaction(
				static function () use ( $p, $id ) {
					$cancelled = self::cancel( (int) $id, __( 'Rebuilt after a refund', 'wp-affiliate-captain' ) );
					if ( is_wp_error( $cancelled ) ) {
						throw new IntegrationException( 'cancel', $cancelled->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					}
					$new = self::create_for_affiliate( (int) $p->affiliate_id, (string) $p->period_end, (string) $p->currency );
					if ( ! $new ) {
						throw new IntegrationException( 'rebuild_empty', __( 'After the refund the balance is below the minimum payout, so no new payout can be created. The payout was not cancelled; cancel it manually if it should not be paid – the balance is then carried forward.', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					}
					return (int) $new;
				}
			);
		} catch ( \Throwable $e ) {
			self::$deferred = null;
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
			return __( 'Your address and VAT ID or tax number are missing (Settings → Payouts & credit notes) – credit notes need them.', 'wp-affiliate-captain' );
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
					$details[] = array( $aff_id, $name, 'carried', __( 'Nothing payable any more (changed meanwhile) – carried forward', 'wp-affiliate-captain' ) );
				}
			} catch ( \Throwable $e ) {
				++$skipped;
				$reason    = $e instanceof IntegrationException && in_array( $e->error_code, array( 'operator_data', 'not_eligible' ), true )
					? $e->getMessage()
					: __( 'Technical error while saving (details in the audit log) – please run the payout again', 'wp-affiliate-captain' );
				$details[] = array( $aff_id, $name, 'failed', $reason );
				Audit::log( 'payout.failed', 'affiliate', $aff_id, array( 'error' => $e->getMessage() ) );
			}
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
	 * Create one payout (transactional, locks the commission rows).
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $period_end   Y-m-d.
	 * @param string $currency     Currency.
	 * @return int Payout ID or 0 when nothing is payable.
	 */
	public static function create_for_affiliate( $affiliate_id, $period_end, $currency ) {
		global $wpdb;
		$operator = self::operator_missing();
		if ( $operator ) {
			throw new IntegrationException( 'operator_data', $operator ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$blocked = self::blocked_reason( (int) $affiliate_id );
		if ( '' !== $blocked ) {
			throw new IntegrationException( 'not_eligible', $blocked ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$payout_id = Db::transaction(
			static function () use ( $wpdb, $affiliate_id, $period_end, $currency ) {
				$t                     = Db::table( 'commissions' );
				list( $from, $params ) = self::payable_sql( $currency, $period_end . ' 23:59:59', $affiliate_id );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor, c.parent_id, p.payout_id AS parent_payout_id {$from} FOR UPDATE", $params ) );
				$total = 0;
				foreach ( $rows as $r ) {
					$total += (int) $r->amount_minor;
				}
				$min = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
				if ( ! $rows || $total <= 0 || null === $min || $total < $min ) {
					return 0;
				}
				$profile  = ProfileRepository::get( $affiliate_id );
				$snapshot = ProfileRepository::snapshot( $profile );
				// Commission rates are net; VAT is added for domestic VAT-registered partners.
				$vat = Vat::treatment(
					isset( $snapshot['tax_status'] ) ? $snapshot['tax_status'] : '',
					isset( $snapshot['country'] ) ? $snapshot['country'] : '',
					(string) Settings::get( 'operator_country' ),
					(string) Settings::get( 'vat_rate' )
				);
				/**
				 * Filter the VAT treatment of a credit note (mode, rate).
				 *
				 * @param array $vat          Treatment.
				 * @param array $snapshot     Partner tax data snapshot.
				 * @param int   $affiliate_id Partner.
				 */
				$vat = (array) apply_filters( 'wpac_vat_treatment', $vat, $snapshot, $affiliate_id );
				// Corrections of commissions settled in an earlier credit note keep that credit note's
				// VAT treatment (§17 UStG) and refer to it; everything else uses the current treatment.
				$treat = self::item_treatments( $rows, $vat );
				$vat_minor = 0;
				foreach ( self::vat_groups( $rows, $treat ) as $group ) {
					$vat_minor += Vat::amount( $group['net'], $group['rate'] );
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
						'operator_snapshot' => wp_json_encode( self::operator_snapshot() ),
						'created_by'       => get_current_user_id(),
						'created_at'       => Db::now(),
					)
				);
				if ( ! $ok ) {
					throw new IntegrationException( 'db_payout', $wpdb->last_error );
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
						throw new IntegrationException( 'double_payout', 'Commission ' . $r->id . ' is already part of a payout.' );
					}
					// Compare-and-set: only if still approved and unassigned.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$moved = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET payout_id = %d, updated_at = %s WHERE id = %d AND payout_id = 0 AND status = 'approved'", $payout_id, Db::now(), (int) $r->id ) );
					if ( 1 !== $moved ) {
						throw new IntegrationException( 'commission_changed', 'Commission ' . $r->id . ' changed during the payout run.' );
					}
					Ledger::event( (int) $r->id, 'payout_assigned', 'approved', 'approved', (int) $r->amount_minor, $number );
				}
				\WPAffiliateCaptain\Commissions\Balance::post( (int) $affiliate_id, $currency, 'payout', -$total, 0, $payout_id, $number );
				Audit::log(
					'payout.created',
					'payout',
					$payout_id,
					array(
						'number' => $number,
						'amount' => $total,
					)
				);
				return $payout_id;
			}
		);
		if ( $payout_id ) {
			self::after_commit(
				static function () use ( $payout_id, $affiliate_id, $currency ) {
					Statement::generate( $payout_id );
					// A regular run also settles an open payout request of the partner.
					PayoutRequests::fulfil( (int) $affiliate_id, (int) $payout_id, $currency );
				}
			);
		}
		return $payout_id;
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
			return new \WP_Error( 'wpac_statement', sprintf( __( 'The statement of payout %s could not be stored. Please check that the statement folder is writable and try again.', 'wp-affiliate-captain' ), $p->number ) );
		}
		return null;
	}

	/**
	 * Generate statements that are still missing (hourly cron).
	 *
	 * @return int Number generated.
	 */
	public static function generate_missing_statements() {
		global $wpdb;
		$t   = Db::table( 'payouts' );
		$ids = $wpdb->get_col( "SELECT id FROM {$t} WHERE statement_file = '' AND statement_deleted_at IS NULL AND status <> 'cancelled' ORDER BY id ASC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n   = 0;
		foreach ( $ids as $id ) {
			if ( Statement::waiting( (int) $id ) ) {
				continue; // Failed before (e.g. folder not writable): back-off instead of every hour.
			}
			$n += Statement::generate( (int) $id ) ? 1 : 0;
		}
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
							throw new IntegrationException( 'commission_changed', 'Commission ' . $item->commission_id . ' could not be marked as paid.' );
						}
						$ids[] = (int) $item->commission_id;
					}
					return array( $p, $ids );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_state', __( 'This payout cannot be marked as paid.', 'wp-affiliate-captain' ) );
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
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		try {
			$p = Db::transaction(
				static function () use ( $wpdb, $id, $reason, $confirm_not_sent ) {
					$p = self::lock( $id );
					if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
						throw new IntegrationException( 'state', __( 'Only unpaid payouts can be cancelled.', 'wp-affiliate-captain' ) );
					}
					if ( 'exported' === $p->status && ! $confirm_not_sent ) {
						/* translators: %s: payout number */
						throw new IntegrationException( 'exported', sprintf( __( 'Payout %s was already exported to SEPA. Confirm that the bank did not execute the transfer before cancelling – otherwise the commissions could be paid twice.', 'wp-affiliate-captain' ), $p->number ) );
					}
					$pt = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'cancelled', note = %s WHERE id = %d AND status = %s", substr( sanitize_text_field( $reason ), 0, 255 ), $id, $p->status ) );
					if ( 1 !== $rows ) {
						throw new IntegrationException( 'state', __( 'Only unpaid payouts can be cancelled.', 'wp-affiliate-captain' ) );
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
							throw new IntegrationException( 'reexport', sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'wp-affiliate-captain' ), $p->number ) );
						}
						$profile = ProfileRepository::get( (int) $p->affiliate_id );
						if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'profile_changed', sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'wp-affiliate-captain' ), $p->number ) );
						}
						if ( ProfileRepository::METHOD_MANUAL === ProfileRepository::method( $profile ) ) {
							// Other payment method (e.g. PayPal): the list carries the payment details;
							// the operator pays manually and marks the payout as paid.
							$details = ProfileRepository::payment_details( $profile );
							if ( null === $details || '' === $details ) {
								/* translators: %s: payout number */
								throw new IntegrationException( 'details', sprintf( __( 'Payment details for payout %s are missing or cannot be decrypted.', 'wp-affiliate-captain' ), $p->number ) );
							}
							$holder = '' !== trim( (string) $profile->account_holder ) ? (string) $profile->account_holder : (string) $profile->legal_name;
							$rows[] = array( $p->number, $holder, '', '', Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->currency, trim( $prefix . ' ' . $p->number ), ProfileRepository::METHOD_MANUAL, str_replace( array( "\r\n", "\r", "\n" ), ' / ', $details ) );
						} else {
							$iban = ProfileRepository::iban( $profile );
							if ( ! $iban ) {
								/* translators: %s: payout number */
								throw new IntegrationException( 'iban', sprintf( __( 'Bank details for payout %s are missing or cannot be decrypted.', 'wp-affiliate-captain' ), $p->number ) );
							}
							$rows[] = array( $p->number, (string) $profile->account_holder, $iban, (string) $profile->bic, Money::to_decimal( (int) $p->amount_minor, $p->currency ), $p->currency, trim( $prefix . ' ' . $p->number ), ProfileRepository::METHOD_SEPA, '' );
						}
						$used[ (int) $p->id ] = $p->status;
					}
					if ( ! $rows ) {
						throw new IntegrationException( 'empty', __( 'No exportable payouts selected.', 'wp-affiliate-captain' ) );
					}
					$batch = 'ACCSV' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 4, false ) );
					$pt    = Db::table( 'payouts' );
					foreach ( $used as $id => $status ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = %s", $batch, Db::now(), $id, $status ) ) ) {
							throw new IntegrationException( 'state', 'Payout ' . $id . ' changed during export.' );
						}
					}
					return array( $batch, $rows, array_keys( $used ) );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		Audit::log( 'payout.csv_exported', 'payout', 0, array( 'batch' => $result[0], 'payouts' => $result[2] ) );
		return array( $result[0], $result[1] );
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
	 * @return array|\WP_Error [ filename, xml ]
	 */
	public static function sepa_export( array $ids, $allow_reexport = false ) {
		global $wpdb;
		$debtor = array(
			'name' => (string) Settings::get( 'debtor_name' ),
			'iban' => (string) Settings::get( 'debtor_iban' ),
			'bic'  => (string) Settings::get( 'debtor_bic' ),
		);
		if ( '' === $debtor['name'] || '' === $debtor['iban'] ) {
			return new \WP_Error( 'wpac_debtor', __( 'Please enter the account holder and IBAN of your company account in the settings first.', 'wp-affiliate-captain' ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'wpac_empty', __( 'No exportable payouts selected.', 'wp-affiliate-captain' ) );
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
							throw new IntegrationException( 'reexport', sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'wp-affiliate-captain' ), $p->number ) );
						}
						$profile = ProfileRepository::get( (int) $p->affiliate_id );
						if ( ProfileRepository::METHOD_MANUAL === ProfileRepository::method( $profile ) ) {
							// Paid by another method (transfer list, then "mark as paid"): never in the SEPA file.
							++$manual;
							continue;
						}
						if ( 'EUR' !== $p->currency ) {
							throw new IntegrationException( 'currency', __( 'SEPA transfers are only possible in EUR.', 'wp-affiliate-captain' ) );
						}
						if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'profile_changed', sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'wp-affiliate-captain' ), $p->number ) );
						}
						$iban = ProfileRepository::iban( $profile );
						if ( ! $iban || '' === (string) $p->number ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'iban', sprintf( __( 'Bank details for payout %s are missing or cannot be decrypted.', 'wp-affiliate-captain' ), $p->number ) );
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
								? __( 'The selected payouts use another payment method (e.g. PayPal) and are not part of the SEPA file. Download the transfer list (CSV) for their payment details, pay them manually and mark them as paid.', 'wp-affiliate-captain' )
								: __( 'No exportable payouts selected.', 'wp-affiliate-captain' )
						);
					}
					// Unique message ID, even for two exports in the same second.
					$batch = 'ACSEPA' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 4, false ) );
					try {
						$xml = SepaXml::build( $debtor, $transfers, $batch, SepaXml::next_business_day( time() ) );
					} catch ( \InvalidArgumentException $e ) {
						throw new IntegrationException( 'sepa', $e->getMessage() );
					}
					$pt = Db::table( 'payouts' );
					foreach ( $used as $id => $status ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = %s", $batch, Db::now(), $id, $status ) );
						if ( 1 !== $rows ) {
							throw new IntegrationException( 'state', 'Payout ' . $id . ' changed during export.' );
						}
					}
					return array( $batch, $xml, array_keys( $used ) );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		list( $batch, $xml, $used ) = $result;
		Audit::log(
			'payout.sepa_exported',
			'payout',
			0,
			array(
				'batch'   => $batch,
				'payouts' => $used,
			)
		);
		return array( $batch . '.xml', $xml );
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
