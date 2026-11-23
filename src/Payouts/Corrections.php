<?php
/**
 * Correction credit notes for paid payouts (R5-W55).
 *
 * A paid payout cannot be cancelled (the money went out). When its credit note is wrong – e.g.
 * the partner objected (§14 Abs. 2 S. 3 UStG) – the operator issues a correction credit note:
 *
 * - its own gapless number series (setting correction_prefix, default "KG-2026-000001"),
 *   allocated in the same transaction as the booking;
 * - it names the corrected credit note and shows the amounts before (the original credit note
 *   or the latest correction of it), the corrected amounts and the difference – net, VAT and
 *   gross with the VAT treatment frozen on the original credit note (§17 UStG);
 * - the gross difference is booked into the partner account as an approved booking of the kind
 *   "correction" (not taxable on the next credit note: the VAT is shown on this document). A
 *   shortfall is paid with the next payout, an overpayment is offset against later commissions
 *   (negative balance carried forward, payment requests like any negative balance);
 * - not double-bookable: the payout row is locked, the form carries the amount it was based on
 *   and a one-time request key (UNIQUE);
 * - the PDF is stored like a credit note (hash, private folder, rebuilt identically when the file
 *   is missing), downloadable for tax-data users and the partner, and deleted after the
 *   retention period (Statement::purge_expired()).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Sequence;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Corrections {

	/** Table (without prefix). */
	const TABLE = 'payout_corrections';

	/** Commission kind of the booking that settles a correction credit note. */
	const KIND = 'correction';

	/** Option with failed writes (correction ID => [ n, next ]). */
	const RETRY_OPTION = 'wpac_correction_retry';

	/**
	 * Find a correction.
	 *
	 * @param int $id Correction.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( self::TABLE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Corrections of a payout, oldest first.
	 *
	 * @param int $payout_id Payout.
	 * @return array
	 */
	public static function for_payout( $payout_id ) {
		global $wpdb;
		$t = Db::table( self::TABLE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE payout_id = %d ORDER BY id ASC", (int) $payout_id ) );
	}

	/**
	 * Corrections of several payouts (one query), grouped: payout ID => list.
	 *
	 * @param int[] $payout_ids Payouts.
	 * @return array
	 */
	public static function for_payouts( array $payout_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $payout_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t   = Db::table( self::TABLE );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Integers only.
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE payout_id IN (" . implode( ',', $ids ) . ') ORDER BY id ASC' ) as $c ) {
			$out[ (int) $c->payout_id ][] = $c;
		}
		return $out;
	}

	/**
	 * Taxable parts of a paid credit note with the amounts a correction starts from, one per VAT
	 * treatment and rate (key "mode|rate", as the credit note computes its VAT): the net of the
	 * commissions on the credit note with the treatment frozen on each line (time of the
	 * service), or the corrected amounts of the latest correction of that part. Repayments,
	 * write-offs and settled correction credit notes (Vat::NON_TAXABLE_KINDS) are not taxable and
	 * belong to no part.
	 *
	 * @param object     $p    Payout.
	 * @param array|null $list Corrections of the payout (oldest first; null = load).
	 * @return array key => [ mode, rate, net, vat, number ] (number: the document the amounts are from).
	 */
	public static function parts( $p, $list = null ) {
		$list  = null === $list ? self::for_payout( (int) $p->id ) : $list;
		$parts = array();
		foreach ( (array) PayoutService::items( (int) $p->id, true ) as $item ) {
			if ( in_array( (string) $item->kind, Vat::NON_TAXABLE_KINDS, true ) ) {
				continue;
			}
			// Lines of early credit notes carry no treatment: the credit note's own.
			$mode = '' !== (string) $item->vat_mode ? (string) $item->vat_mode : (string) $p->vat_mode;
			if ( Vat::NONE === $mode ) {
				continue;
			}
			$rate = '' !== (string) $item->vat_rate ? (string) $item->vat_rate : (string) $p->vat_rate;
			$rate = Vat::STANDARD === $mode ? $rate : '0';
			$key  = $mode . '|' . Vat::rate_key( $rate ); // "19" and "19.0" are one part (R13-N8).
			if ( ! isset( $parts[ $key ] ) ) {
				$parts[ $key ] = array(
					'mode'   => $mode,
					'rate'   => $rate,
					'net'    => 0,
					'vat'    => 0,
					'number' => (string) $p->number,
				);
			}
			$parts[ $key ]['net'] += (int) $item->amount_minor;
		}
		foreach ( array_keys( $parts ) as $key ) {
			$parts[ $key ]['vat'] = Vat::amount( $parts[ $key ]['net'], $parts[ $key ]['rate'] );
		}
		// Each correction corrects one part (its treatment and rate): its corrected amounts are
		// what the next correction of that part starts from. An older correction whose treatment
		// matches no line keeps a part of its own, so its amounts are not lost.
		foreach ( $list as $c ) {
			if ( count( $parts ) > 1 && self::legacy( $c ) ) {
				// R13-N8: a correction of 0.12 corrected the whole credit note (all parts, with the
				// main treatment): its corrected total replaces the parts – summing it with the other
				// parts would count them twice. Flagged for the form.
				$parts = array();
			}
			$key           = (string) $c->vat_mode . '|' . Vat::rate_key( (string) $c->vat_rate );
			$parts[ $key ] = array(
				'mode'   => (string) $c->vat_mode,
				'rate'   => (string) $c->vat_rate,
				'net'    => (int) $c->net_minor,
				'vat'    => (int) $c->vat_minor,
				'number' => (string) $c->number,
			);
			if ( self::legacy( $c ) ) {
				$parts[ $key ]['legacy'] = true;
			}
		}
		return $parts;
	}

	/**
	 * Whether a correction was issued before corrections were made per part (0.12): it corrected
	 * the whole credit note. Newer corrections store the number of parts with the document data.
	 *
	 * @param object $c Correction.
	 * @return bool
	 */
	public static function legacy( $c ) {
		$snap = json_decode( (string) $c->operator_snapshot, true );
		return ! is_array( $snap ) || ! array_key_exists( 'parts', $snap );
	}

	/**
	 * Taxable amounts of a payout as they stand now, summed over its parts (see parts(); a
	 * correction always corrects one part).
	 *
	 * @param object     $p    Payout.
	 * @param array|null $list Corrections of the payout (oldest first; null = load).
	 * @return array [ net, vat, gross, number of the latest document ]
	 */
	public static function base( $p, $list = null ) {
		$list = null === $list ? self::for_payout( (int) $p->id ) : $list;
		$net  = 0;
		$vat  = 0;
		foreach ( self::parts( $p, $list ) as $part ) {
			$net += $part['net'];
			$vat += $part['vat'];
		}
		$last = $list ? end( $list ) : null;
		return array( $net, $vat, $net + $vat, $last ? (string) $last->number : (string) $p->number );
	}

	/**
	 * Label of a taxable part (VAT treatment and rate) for forms and documents.
	 *
	 * @param array $part Part (parts()).
	 * @return string
	 */
	public static function part_label( array $part ) {
		$labels = array(
			Vat::REVERSE_CHARGE => __( 'reverse charge', 'affiliate-captain' ),
			Vat::SMALL_BUSINESS => __( 'small business, no VAT', 'affiliate-captain' ),
			Vat::PRIVATE_PERSON => __( 'private individual, no VAT', 'affiliate-captain' ),
		);
		if ( isset( $labels[ $part['mode'] ] ) ) {
			return $labels[ $part['mode'] ];
		}
		/* translators: %s: VAT rate */
		return sprintf( __( 'VAT %s %%', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( (string) $part['rate'] ) );
	}

	/**
	 * Amounts of a correction of one taxable part to a corrected net amount. The VAT is always
	 * computed on the corrected net of the part (never as the VAT before plus the VAT of the
	 * difference, which would add up rounding over several corrections).
	 *
	 * @param object     $p    Payout.
	 * @param int        $net  Corrected taxable net amount of the part.
	 * @param string     $part Part key ("mode|rate"); '' = the only part of the credit note.
	 * @param array|null $list Corrections of the payout (null = load).
	 * @return array|null part, mode, rate, parts, prev_net, prev_vat, net, vat, diff_net, diff_vat,
	 *                    diff_gross, base_number; null when the part is unknown or ambiguous.
	 */
	public static function amounts( $p, $net, $part = '', $list = null ) {
		$parts = self::parts( $p, $list );
		$key   = (string) $part;
		if ( '' === $key && 1 === count( $parts ) ) {
			$key = (string) key( $parts );
		}
		if ( '' === $key || ! isset( $parts[ $key ] ) ) {
			return null;
		}
		$g   = $parts[ $key ];
		$vat = Vat::amount( (int) $net, (string) $g['rate'] );
		return array(
			'part'        => $key,
			'mode'        => (string) $g['mode'],
			'rate'        => (string) $g['rate'],
			'parts'       => count( $parts ),
			'prev_net'    => (int) $g['net'],
			'prev_vat'    => (int) $g['vat'],
			'net'         => (int) $net,
			'vat'         => $vat,
			'diff_net'    => (int) $net - (int) $g['net'],
			'diff_vat'    => $vat - (int) $g['vat'],
			'diff_gross'  => (int) $net + $vat - (int) $g['net'] - (int) $g['vat'],
			'base_number' => (string) $g['number'],
		);
	}

	/**
	 * Amounts on a credit note that are not taxable (repayments, write-offs, settled correction
	 * credit notes): no correction changes them.
	 *
	 * @param object $p Payout.
	 * @return int
	 */
	public static function untaxed( $p ) {
		$sum = 0;
		foreach ( (array) PayoutService::items( (int) $p->id, true ) as $item ) {
			if ( in_array( (string) $item->kind, Vat::NON_TAXABLE_KINDS, true ) || Vat::NONE === (string) $item->vat_mode ) {
				$sum += (int) $item->amount_minor;
			}
		}
		return $sum;
	}

	/**
	 * Orders whose commissions are on a credit note (source order IDs). A later refund of these
	 * orders is deducted again automatically – the correction form says so (R12-M2).
	 *
	 * @param object $p Payout.
	 * @return string[]
	 */
	public static function orders( $p ) {
		$out = array();
		foreach ( (array) PayoutService::items( (int) $p->id, true ) as $item ) {
			if ( 'wpac' !== (string) $item->source && '' !== (string) $item->source_order_id && ! in_array( (string) $item->kind, Vat::NON_TAXABLE_KINDS, true ) ) {
				$out[ (string) $item->source_order_id ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Why a payout cannot get a correction credit note ('' = it can).
	 *
	 * @param object|null $p Payout.
	 * @return string
	 */
	public static function blocked( $p ) {
		if ( ! $p || 'paid' !== (string) $p->status ) {
			return __( 'Only paid payouts get a correction credit note; cancel an unpaid payout instead.', 'affiliate-captain' );
		}
		if ( '' === (string) $p->statement_hash || $p->statement_deleted_at ) {
			return __( 'This payout has no stored credit note (not issued yet or deleted after the retention period).', 'affiliate-captain' );
		}
		if ( ! AffiliateRepository::find( (int) $p->affiliate_id ) ) {
			return __( 'Affiliate not found', 'affiliate-captain' );
		}
		if ( ! self::parts( $p ) ) {
			return __( 'This credit note contains no taxable commissions (only settled correction credit notes, repayments or write-offs), so there is nothing to correct on it. Correct the credit note the amounts came from instead.', 'affiliate-captain' );
		}
		return PayoutService::operator_missing();
	}

	/**
	 * Issue a correction credit note for a paid payout and book the difference.
	 *
	 * @param int    $payout_id   Payout (paid).
	 * @param int    $net_minor   Corrected taxable net amount of the part (minor units, not negative).
	 * @param string $reason      Reason (required; printed on the document, shown to the partner).
	 * @param string $request_key One-time key of the form (a repeated submission returns the
	 *                            correction issued with it instead of booking again).
	 * @param int    $base_net    Net amount of the part the form showed as "before" (-1 = do not
	 *                            check): if another correction was issued meanwhile, nothing is booked.
	 * @param string $part        Taxable part to correct (key of parts(), "mode|rate"); '' = the
	 *                            only part. A credit note with several VAT treatments or rates is
	 *                            corrected part by part (one correction credit note each).
	 * @return int|\WP_Error Correction ID.
	 */
	public static function create( $payout_id, $net_minor, $reason, $request_key = '', $base_net = -1, $part = '' ) {
		global $wpdb;
		$reason      = trim( sanitize_textarea_field( (string) $reason ) );
		$net_minor   = (int) $net_minor;
		$request_key = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request_key ), 0, 64 );
		$request_key = '' !== $request_key ? $request_key : wp_generate_password( 32, false );
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'affiliate-captain' ) );
		}
		if ( abs( $net_minor ) > \WPAffiliateCaptain\Sales\ContractApi::MAX_MINOR ) {
			return new \WP_Error( 'wpac_amount', __( 'Please enter the correct net amount (0 or more).', 'affiliate-captain' ) );
		}
		$done = self::by_key( $request_key );
		if ( $done ) {
			// Submitted twice: already issued. With another amount (R13-M9: back in the browser,
			// changed, sent again) that is said instead of reporting the old document as new.
			return (int) $done->net_minor === $net_minor ? (int) $done->id : self::already( $done );
		}
		$blocked = self::blocked( PayoutService::find( (int) $payout_id ) );
		if ( '' !== $blocked ) {
			return new \WP_Error( 'wpac_state', $blocked );
		}
		try {
			$id = Db::transaction(
				static function () use ( $wpdb, $payout_id, $net_minor, $reason, $request_key, $base_net, $part ) {
					$pt = Db::table( 'payouts' );
					// The payout row serializes corrections of the same credit note.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE id = %d FOR UPDATE", (int) $payout_id ) );
					if ( ! $p || 'paid' !== (string) $p->status ) {
						throw new IntegrationException( 'state', esc_html__( 'Only paid payouts get a correction credit note; cancel an unpaid payout instead.', 'affiliate-captain' ) );
					}
					$done = self::by_key( $request_key );
					if ( $done ) {
						if ( (int) $done->net_minor !== $net_minor ) {
							throw new IntegrationException( 'already', esc_html( self::already( $done )->get_error_message() ) );
						}
						return (int) $done->id;
					}
					$a = self::amounts( $p, $net_minor, (string) $part );
					if ( null === $a ) {
						// Never fall back to the credit note's main treatment: each part keeps its own.
						throw new IntegrationException( 'part', esc_html__( 'This credit note has parts with different VAT treatments or rates. Please correct each part separately (one correction credit note per part, with the corrected net amount of that part).', 'affiliate-captain' ) );
					}
					if ( $net_minor < 0 && $a['prev_net'] >= 0 ) {
						// Negative only where the part is negative (R13-N8: e.g. corrections of an
						// earlier credit note on this one).
						throw new IntegrationException( 'amount', esc_html__( 'Please enter the correct net amount (0 or more).', 'affiliate-captain' ) );
					}
					if ( $base_net >= 0 && (int) $base_net !== $a['prev_net'] ) {
						throw new IntegrationException( 'changed', esc_html__( 'The credit note was corrected in the meantime. Please check the current amounts and try again; nothing was booked.', 'affiliate-captain' ) );
					}
					if ( 0 === $a['diff_net'] && 0 === $a['diff_vat'] ) {
						throw new IntegrationException( 'same', esc_html__( 'The corrected net amount equals the current one: there is nothing to correct. If the credit note is correct, explain this to the partner instead.', 'affiliate-captain' ) );
					}
					$snapshot = self::operator_snapshot();
					// What the document says about the rest of the credit note (only printed on
					// corrections that store it: older documents are rebuilt byte for byte).
					$snapshot['parts']   = (int) $a['parts'];
					$snapshot['untaxed'] = self::untaxed( $p );
					$number   = Sequence::number( (string) Settings::get( 'correction_prefix' ), 'correction', (int) wp_date( 'Y' ) );
					$now      = Db::now();
					$ct       = Db::table( 'commissions' );
					$key      = Ledger::key( 'correction:' . $number );
					$ok       = $wpdb->insert(
						$ct,
						array(
							'affiliate_id'    => (int) $p->affiliate_id,
							'source'          => 'wpac',
							'source_order_id' => Db::cut( $number, 64 ),
							'source_item_id'  => (string) (int) $p->id,
							'kind'            => self::KIND,
							'parent_id'       => 0,
							'product_ref'     => '',
							'description'     => Db::cut( $number . ' – ' . $reason, 255 ),
							'currency'        => (string) $p->currency,
							'base_minor'      => 0,
							'amount_minor'    => $a['diff_gross'],
							'rate_type'       => 'fixed',
							'rate'            => '0',
							'rule_id'         => 0,
							'rule_snapshot'   => null,
							'status'          => 'approved',
							'hold_until'      => $now,
							'idempotency_key' => $key,
							'created_at'      => $now,
							'updated_at'      => $now,
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_correction', esc_html( $wpdb->last_error ) );
					}
					$cid = (int) $wpdb->insert_id;
					Balance::post( (int) $p->affiliate_id, (string) $p->currency, 'correction', $a['diff_gross'], $cid, (int) $p->id, $number );
					Ledger::event( $cid, 'created', '', 'approved', $a['diff_gross'], 'correction: ' . $number . ' (' . $p->number . ')', $key, get_current_user_id() );
					// After COMMIT (dropped on rollback), like the other ledger bookings.
					Db::after_commit(
						static function () use ( $cid ) {
							do_action( 'wpac_commission_created', $cid );
						}
					);
					$ok = $wpdb->insert(
						Db::table( self::TABLE ),
						array(
							'payout_id'         => (int) $p->id,
							'number'            => $number,
							'affiliate_id'      => (int) $p->affiliate_id,
							'currency'          => (string) $p->currency,
							'base_number'       => $a['base_number'],
							'prev_net_minor'    => $a['prev_net'],
							'prev_vat_minor'    => $a['prev_vat'],
							'net_minor'         => $a['net'],
							'vat_minor'         => $a['vat'],
							'vat_mode'          => $a['mode'],
							'vat_rate'          => $a['rate'],
							'reason'            => Db::cut( $reason, 1000 ),
							'commission_id'     => $cid,
							'request_key'       => $request_key,
							'profile_snapshot'  => (string) $p->profile_snapshot,
							'operator_snapshot' => wp_json_encode( $snapshot ),
							'created_by'        => get_current_user_id(),
							'created_at'        => $now,
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_correction', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					Audit::log(
						'payout.correction_issued',
						'payout',
						(int) $p->id,
						array(
							'number'     => $number,
							'correction' => $id,
							'net'        => $a['diff_net'],
							'vat'        => $a['diff_vat'],
							'gross'      => $a['diff_gross'],
							'vat_mode'   => $a['mode'],
							'vat_rate'   => $a['rate'],
							'reason'     => Db::cut( $reason, 300 ),
						)
					);
					return $id;
				}
			);
		} catch ( IntegrationException $e ) {
			if ( in_array( $e->error_code, array( 'state', 'changed', 'same', 'part', 'amount', 'already' ), true ) ) {
				return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
			}
			$done = self::by_key( $request_key ); // A parallel submission with the same key won.
			if ( $done ) {
				return (int) $done->id;
			}
			\WPAffiliateCaptain\Core\Log::exception( 'Correction credit note could not be issued', $e, array( 'payout' => (int) $payout_id ) );
			return new \WP_Error( 'wpac_db_error', __( 'The correction credit note could not be saved (database error). Nothing was booked; please try again.', 'affiliate-captain' ) );
		} catch ( \Throwable $e ) {
			\WPAffiliateCaptain\Core\Log::exception( 'Correction credit note could not be issued', $e, array( 'payout' => (int) $payout_id ) );
			return new \WP_Error( 'wpac_db_error', __( 'The correction credit note could not be saved (database error). Nothing was booked; please try again.', 'affiliate-captain' ) );
		}
		// The booking is committed: from here on nothing may report the correction as failed (a
		// missing document is written again by the hourly cron).
		try {
			$c = self::find( (int) $id );
			if ( $c && '' === (string) $c->file ) {
				$written = self::generate( (int) $id );
				// The objection (if any) is handled by this document; the partner is told where to
				// find it – or, while the file could not be written yet, that it follows by e-mail.
				$p = PayoutService::find( (int) $c->payout_id );
				$o = Objections::for_payout( (int) $c->payout_id );
				if ( $p && $o && 'open' === $o['status'] ) {
					Objections::resolve(
						(int) $c->payout_id,
						$written
							/* translators: 1: correction credit note number, 2: credit note number, 3: reason */
							? sprintf( __( 'We have issued correction credit note %1$s for credit note %2$s (in your partner area under Payouts): %3$s', 'affiliate-captain' ), $c->number, $p->number, $reason )
							/* translators: 1: correction credit note number, 2: credit note number, 3: reason */
							: sprintf( __( 'We have issued correction credit note %1$s for credit note %2$s: %3$s. The document is still being created; we will send it to you by e-mail as soon as it is ready.', 'affiliate-captain' ), $c->number, $p->number, $reason ),
						(int) $c->id
					);
				}
			}
		} catch ( \Throwable $e ) {
			\WPAffiliateCaptain\Core\Log::exception( 'Correction credit note issued, follow-up step failed', $e, array( 'correction' => (int) $id ) );
		}
		return (int) $id;
	}

	/**
	 * Correction issued with a request key.
	 *
	 * @param string $key Key.
	 * @return object|null
	 */
	private static function by_key( $key ) {
		global $wpdb;
		$t = Db::table( self::TABLE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return '' === (string) $key ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE request_key = %s", (string) $key ) );
	}

	/**
	 * Error for a form submitted again with another amount: the correction issued with it (R13-M9).
	 *
	 * @param object $done Correction issued with the request key.
	 * @return \WP_Error
	 */
	private static function already( $done ) {
		return new \WP_Error(
			'wpac_already',
			sprintf(
				/* translators: 1: correction credit note number, 2: corrected net amount */
				__( 'This form was already sent: correction credit note %1$s was issued with the corrected net amount %2$s. The new amount was not booked; open the correction form again to correct the credit note further.', 'affiliate-captain' ),
				(string) $done->number,
				Money::format( (int) $done->net_minor, (string) $done->currency )
			)
		);
	}

	/**
	 * Operator data as printed now, frozen with the correction.
	 *
	 * @return array
	 */
	private static function operator_snapshot() {
		// Logo and footer are frozen with the document (SET-AC-16).
		$op             = array_merge( PayoutService::operator_snapshot(), DocumentBrand::snapshot() );
		$op['document'] = 'correction_note';
		return $op;
	}

	/**
	 * Build the PDF of a correction credit note (only from stored data: the same document can be
	 * built again byte for byte).
	 *
	 * @param object $c         Correction.
	 * @param bool   $duplicate Mark as a duplicate.
	 * @return string
	 */
	public static function build( $c, $duplicate = false ) {
		$op       = json_decode( (string) $c->operator_snapshot, true );
		$op       = is_array( $op ) ? $op : PayoutService::operator_snapshot();
		$switched = ! empty( $op['locale'] ) ? \WPAffiliateCaptain\Core\Locale::switch_to( (string) $op['locale'] ) : false;
		try {
			return self::render( $c, $op, $duplicate );
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $switched );
		}
	}

	/**
	 * Lay out the document (in the document language).
	 *
	 * @param object $c         Correction.
	 * @param array  $op        Operator snapshot.
	 * @param bool   $duplicate Duplicate.
	 * @return string
	 */
	private static function render( $c, array $op, $duplicate ) {
		$p       = PayoutService::find( (int) $c->payout_id );
		$aff     = AffiliateRepository::find( (int) $c->affiliate_id );
		$snap    = json_decode( (string) $c->profile_snapshot, true );
		$snap    = is_array( $snap ) ? $snap : array();
		$labels  = ProfileRepository::tax_status_labels();
		$self    = ! empty( $op['self_billing'] );
		$country = strtoupper( isset( $op['country'] ) && '' !== (string) $op['country'] ? (string) $op['country'] : 'DE' );
		$german  = in_array( $country, array( 'DE', 'AT', 'CH' ), true );
		$format  = ! empty( $op['date_format'] ) ? (string) $op['date_format'] : get_option( 'date_format' );
		$date    = static function ( $mysql ) use ( $format ) {
			return mysql2date( $format, $mysql );
		};
		$cur      = (string) $c->currency;
		$orig_no  = $p ? (string) $p->number : (string) $c->base_number;
		if ( $self ) {
			$title = $german ? __( 'Correction credit note (Gutschrift)', 'affiliate-captain' ) : __( 'Correction credit note (self-billing)', 'affiliate-captain' );
		} else {
			$title = __( 'Correction of commission statement', 'affiliate-captain' );
		}
		$pdf = new Pdf(
			array(
				'title'   => $title . ' ' . $c->number,
				'author'  => (string) $op['name'],
				'created' => strtotime( $c->created_at . ' UTC' ),
			)
		);
		// Logo and footer frozen with the document (SET-AC-16); without them the layout is unchanged.
		$y = DocumentBrand::apply( $pdf, $op );
		$pdf->text( 50, $y, $title, 15, true );
		if ( $duplicate ) {
			$pdf->text( 545, $y, __( 'DUPLICATE', 'affiliate-captain' ), 11, true, 'right' );
		}
		$y   += 30;
		$pdf->text( 50, $y, $self ? __( 'Issued by (recipient of the service)', 'affiliate-captain' ) : __( 'Issued by', 'affiliate-captain' ), 9, true );
		$pdf->text( 310, $y, $self ? __( 'Partner (provider of the service)', 'affiliate-captain' ) : __( 'Partner', 'affiliate-captain' ), 9, true );
		$y   += 14;
		$left = array_merge(
			array( (string) $op['name'] ),
			preg_split( '/\r\n|\r|\n/', isset( $op['address'] ) ? (string) $op['address'] : '' ),
			array_filter(
				array(
					! empty( $op['vat_id'] ) ? __( 'VAT ID', 'affiliate-captain' ) . ': ' . $op['vat_id'] : '',
					! empty( $op['tax_number'] ) ? __( 'Tax number', 'affiliate-captain' ) . ': ' . $op['tax_number'] : '',
				)
			)
		);
		$partner = array_merge(
			array( ! empty( $snap['legal_name'] ) ? $snap['legal_name'] : ( $aff ? $aff->name : '' ) ),
			preg_split( '/\r\n|\r|\n/', isset( $snap['address'] ) ? (string) $snap['address'] : '' ),
			array( isset( $snap['country'] ) ? $snap['country'] : '' )
		);
		$column  = static function ( array $lines ) {
			$out = array();
			foreach ( array_filter( array_map( 'trim', $lines ), 'strlen' ) as $line ) {
				$out = array_merge( $out, Pdf::wrap( $line, 245, 10 ) );
			}
			return $out;
		};
		$left    = $column( $left );
		$partner = $column( $partner );
		for ( $i = 0, $n = max( count( $left ), count( $partner ) ); $i < $n; $i++ ) {
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
			__( 'Correction number', 'affiliate-captain' ) => (string) $c->number,
			__( 'Date', 'affiliate-captain' )              => $date( get_date_from_gmt( (string) $c->created_at ) ),
			__( 'Corrects credit note', 'affiliate-captain' ) => $orig_no . ( $p ? ' (' . $date( get_date_from_gmt( (string) $p->created_at ) ) . ')' : '' ),
		);
		if ( (string) $c->base_number !== $orig_no ) {
			$facts[ __( 'Replaces correction', 'affiliate-captain' ) ] = (string) $c->base_number;
		}
		$facts += array(
			__( 'Service', 'affiliate-captain' )              => __( 'Referral of customers (affiliate commission)', 'affiliate-captain' ),
			__( 'Service period until', 'affiliate-captain' ) => $p ? $date( (string) $p->period_end ) : '—',
			__( 'Tax status', 'affiliate-captain' )           => isset( $snap['tax_status'], $labels[ $snap['tax_status'] ] ) ? $labels[ $snap['tax_status'] ] : '—',
			__( 'VAT ID of the partner', 'affiliate-captain' ) => ! empty( $snap['vat_id'] ) ? $snap['vat_id'] : '—',
		);
		if ( ! empty( $snap['tax_number'] ) ) {
			$facts[ __( 'Tax number of the partner', 'affiliate-captain' ) ] = $snap['tax_number'];
		}
		$facts[ __( 'Reason for the correction', 'affiliate-captain' ) ] = (string) $c->reason;
		foreach ( $facts as $k => $v ) {
			$pdf->text( 50, $y, $k . ':', 9, true );
			foreach ( Pdf::wrap( (string) $v, 355, 9 ) as $line ) {
				if ( $y > $pdf->limit( 780 ) ) {
					$pdf->add_page();
					$y = 60;
				}
				$pdf->text( 190, $y, $line, 9 );
				$y += 13;
			}
		}
		$y += 14;
		$pdf->text( 395, $y, __( 'Net', 'affiliate-captain' ), 9, true, 'right' );
		$pdf->text( 470, $y, __( 'VAT', 'affiliate-captain' ), 9, true, 'right' );
		$pdf->text( 545, $y, __( 'Gross', 'affiliate-captain' ), 9, true, 'right' );
		$pdf->line( 50, $y + 4, 545, $y + 4 );
		$y   += 18;
		$prev_gross = (int) $c->prev_net_minor + (int) $c->prev_vat_minor;
		$gross      = (int) $c->net_minor + (int) $c->vat_minor;
		$rows       = array(
			/* translators: %s: document number */
			array( sprintf( __( 'Previously (%s)', 'affiliate-captain' ), (string) $c->base_number ), (int) $c->prev_net_minor, (int) $c->prev_vat_minor, $prev_gross, false ),
			array( __( 'Corrected', 'affiliate-captain' ), (int) $c->net_minor, (int) $c->vat_minor, $gross, false ),
			array( __( 'Difference', 'affiliate-captain' ), (int) $c->net_minor - (int) $c->prev_net_minor, (int) $c->vat_minor - (int) $c->prev_vat_minor, $gross - $prev_gross, true ),
		);
		if ( $pdf->limit( 800 ) < 800 && $y + 16 * count( $rows ) + 24 > $pdf->limit( 800 ) ) {
			// With a footer (SET-AC-16) the amounts never run into it.
			$pdf->add_page();
			$y = 60;
		}
		foreach ( $rows as $r ) {
			if ( $r[4] ) {
				$pdf->line( 50, $y - 9, 545, $y - 9 );
			}
			$pdf->text( 50, $y, $r[0], 10, $r[4] );
			$pdf->text( 395, $y, Money::format( $r[1], $cur ), 10, $r[4], 'right' );
			$pdf->text( 470, $y, Money::format( $r[2], $cur ), 10, $r[4], 'right' );
			$pdf->text( 545, $y, Money::format( $r[3], $cur ), 10, $r[4], 'right' );
			$y += 16;
		}
		if ( Vat::STANDARD === (string) $c->vat_mode ) {
			/* translators: %s: VAT rate */
			$pdf->text( 50, $y, sprintf( __( 'VAT rate of the corrected credit note: %s %%', 'affiliate-captain' ), \WPAffiliateCaptain\Core\Locale::number( (string) $c->vat_rate ) ), 9 );
			$y += 14;
		}
		$diff = $gross - $prev_gross;
		$y   += 10;
		$settle = $diff > 0
			/* translators: %s: amount */
			? sprintf( __( 'The difference of %s is credited to the partner account and paid with the next payout.', 'affiliate-captain' ), Money::format( $diff, $cur ) )
			/* translators: %s: amount */
			: sprintf( __( 'The overpayment of %s is debited to the partner account and offset against the next commissions.', 'affiliate-captain' ), Money::format( -$diff, $cur ) );
		// Scope of the correction (stored with corrections since R12-K3; older documents unchanged).
		$scope = array();
		if ( isset( $op['parts'] ) && (int) $op['parts'] > 1 ) {
			$part    = array(
				'mode' => (string) $c->vat_mode,
				'rate' => (string) $c->vat_rate,
			);
			/* translators: 1: credit note number, 2: VAT treatment or rate */
			$scope[] = sprintf( __( 'This correction concerns only the part of credit note %1$s with %2$s; the other parts of the credit note are unchanged.', 'affiliate-captain' ), $orig_no, self::part_label( $part ) );
		}
		if ( ! empty( $op['untaxed'] ) ) {
			/* translators: 1: credit note number, 2: amount */
			$scope[] = sprintf( __( 'Repayments, write-offs and settled correction credit notes on credit note %1$s (%2$s) are not taxable and not part of the amounts above.', 'affiliate-captain' ), $orig_no, Money::format( (int) $op['untaxed'], $cur ) );
		}
		$notes = array_filter(
			array(
				$settle,
				implode( "\n", $scope ),
				Statement::vat_note( (string) $c->vat_mode, $country ),
				$self ? ( 'DE' === $country
					? __( 'This document corrects the credit note named above (self-billing, §14 Abs. 2 UStG); the change of the amounts is a correction under §17 UStG. The partner may object to it as to the credit note.', 'affiliate-captain' )
					: __( 'This document corrects the self-billing invoice named above (Art. 224 VAT Directive). The partner may object to it as to the original document.', 'affiliate-captain' ) ) : '',
				$duplicate ? __( 'Duplicate: built again from the stored data because the original file was not available on this server.', 'affiliate-captain' ) : '',
			)
		);
		$lines = Pdf::wrap( implode( "\n", $notes ), 495, 8 );
		$orig  = $pdf->transliterated();
		if ( $orig ) {
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
	 * Write and store the document of a correction (only once).
	 *
	 * @param int $id Correction.
	 * @return bool
	 */
	public static function generate( $id ) {
		global $wpdb;
		$owner = Db::lock( 'wpac_corr_' . (int) $id, 10 * MINUTE_IN_SECONDS );
		if ( '' === $owner ) {
			return false;
		}
		try {
			$c = self::find( (int) $id );
			if ( ! $c || '' !== (string) $c->file || $c->file_deleted_at ) {
				return false;
			}
			$bytes = self::build( $c );
			$name  = sanitize_file_name( $c->number . '-' . wp_generate_password( 16, false ) . '.pdf' );
			$store = Statement::store();
			if ( ! $store->put( $name, $bytes ) ) {
				Audit::log( 'statement.correction_write_failed', 'payout', (int) $c->payout_id, array( 'number' => $c->number ) );
				Statement::store_failed(
					$store,
					'Correction credit note could not be written',
					array(
						'payout'     => (int) $c->payout_id,
						'correction' => (int) $id,
					)
				);
				self::backoff( (int) $id );
				return false;
			}
			$t = Db::table( self::TABLE );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$done = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET file = %s, hash = %s WHERE id = %d AND file = ''", $name, hash( 'sha256', $bytes ), (int) $id ) );
			if ( 1 !== $done ) {
				$store->delete( $name );
				return false;
			}
			Statement::store_ok();
			$path = Statement::attachment_path( $store, $name, $bytes );
			$all  = get_option( self::RETRY_OPTION, array() );
			if ( is_array( $all ) && isset( $all[ (int) $id ] ) ) {
				unset( $all[ (int) $id ] );
				update_option( self::RETRY_OPTION, $all, false );
			}
			Audit::log(
				'statement.correction_generated',
				'payout',
				(int) $c->payout_id,
				array(
					'number' => $c->number,
					'hash'   => hash( 'sha256', $bytes ),
				)
			);
		} finally {
			Db::unlock( 'wpac_corr_' . (int) $id, $owner );
		}
		/**
		 * A correction credit note was issued and stored (e.g. to send it to the partner).
		 *
		 * @param int    $id   Correction.
		 * @param string $path Stored PDF (object storage: temporary copy, see wpac_statement_generated).
		 */
		Db::after_commit(
			static function () use ( $id, $path ) {
				do_action( 'wpac_correction_generated', (int) $id, $path );
			}
		);
		return true;
	}

	/**
	 * Remember a failed write (1, 2, 4 … 24 hours).
	 *
	 * @param int $id Correction.
	 */
	private static function backoff( $id ) {
		$all        = get_option( self::RETRY_OPTION, array() );
		$all        = is_array( $all ) ? $all : array();
		$n          = isset( $all[ $id ] ) ? (int) $all[ $id ]['n'] + 1 : 1;
		$all[ $id ] = array(
			'n'    => $n,
			'next' => time() + min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** ( $n - 1 ) ) ),
		);
		update_option( self::RETRY_OPTION, $all, false );
	}

	/**
	 * Documents still missing (number allocated, file not written): written again by the hourly
	 * cron with growing pauses.
	 *
	 * @param int $limit Max. documents per run.
	 * @return int Number written.
	 */
	public static function generate_missing( $limit = 20 ) {
		global $wpdb;
		$t   = Db::table( self::TABLE );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE file = '' AND file_deleted_at IS NULL ORDER BY id ASC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all = get_option( self::RETRY_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			if ( isset( $all[ (int) $id ] ) && (int) $all[ (int) $id ]['next'] > time() ) {
				continue;
			}
			$n += self::generate( (int) $id ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Corrections whose document is still missing (admin notice): ID => [ n, next ].
	 *
	 * @return array
	 */
	public static function failures() {
		$all = get_option( self::RETRY_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Bytes of a stored correction document; a missing file is built again from the stored data
	 * (the original when the hash matches, otherwise a copy marked as duplicate).
	 *
	 * @param object $c Correction.
	 * @return string|null|false Bytes, null = not available, false = integrity check failed.
	 */
	public static function stored_bytes( $c ) {
		if ( '' === (string) $c->file ) {
			return null;
		}
		$path = Statement::path( (string) $c->file );
		// Deleted after the retention period (on any server: the database decides): never served or
		// rebuilt, and a copy left on this server is deleted (R12-K5).
		$now = self::find( (int) $c->id );
		if ( $c->file_deleted_at || ! $now || $now->file_deleted_at ) {
			StatementSweep::forget( $path, (string) $c->hash );
			return null;
		}
		$bytes = Statement::read_document( (string) $c->file );
		if ( null !== $bytes ) {
			return hash_equals( (string) $c->hash, hash( 'sha256', $bytes ) ) ? $bytes : false;
		}
		$bytes = self::build( $c );
		if ( hash_equals( (string) $c->hash, hash( 'sha256', $bytes ) ) ) {
			Statement::store()->put( basename( (string) $c->file ), $bytes );
			return $bytes;
		}
		return self::build( $c, true );
	}

	/**
	 * Stream the stored document (caller checks permissions) and exit.
	 *
	 * @param object $c Correction.
	 */
	public static function send( $c ) {
		$bytes = self::stored_bytes( $c );
		if ( null === $bytes ) {
			wp_die( esc_html__( 'The statement is not available (anymore).', 'affiliate-captain' ), 404 );
		}
		if ( false === $bytes ) {
			Audit::log( 'statement.hash_mismatch', 'payout', (int) $c->payout_id, array( 'correction' => (int) $c->id ) );
			wp_die( esc_html__( 'The stored statement failed the integrity check.', 'affiliate-captain' ), 500 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $c->number . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Delete correction documents and their partner snapshot after the retention period (from
	 * Statement::purge_expired(); the period starts with the correction's own date). Amounts stay.
	 *
	 * @param string $cutoff Retention cutoff (UTC).
	 * @return int Number purged.
	 */
	public static function purge_expired( $cutoff ) {
		global $wpdb;
		$t = Db::table( self::TABLE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, payout_id, file, hash FROM {$t} WHERE file_deleted_at IS NULL AND created_at <= %s LIMIT 200", (string) $cutoff ) );
		// Through the store; the local folders too (an earlier folder may be shared: only the file
		// with this document's hash goes, see LocalStore::delete()).
		$store = Statement::store();
		$local = $store instanceof \WPAffiliateCaptain\Payouts\Storage\LocalStore ? null : new \WPAffiliateCaptain\Payouts\Storage\LocalStore();
		$done  = 0;
		foreach ( $rows as $r ) {
			$name = \WPAffiliateCaptain\Payouts\Storage\LocalStore::clean( (string) $r->file );
			if ( '' !== $name ) {
				if ( $local ) {
					$local->delete( $name, (string) $r->hash );
				}
				if ( ! $store->delete( $name, (string) $r->hash ) ) {
					// Not marked as deleted while the document still exists: retried on the next run.
					Statement::store_failed(
						$store,
						'Correction credit note could not be deleted after the retention period',
						array(
							'payout'     => (int) $r->payout_id,
							'correction' => (int) $r->id,
						)
					);
					continue;
				}
			}
			++$done;
			$wpdb->update(
				$t,
				array(
					'file'             => '',
					'profile_snapshot' => null,
					'file_deleted_at'  => Db::now(),
				),
				array( 'id' => (int) $r->id )
			);
			Audit::log( 'statement.correction_retention_deleted', 'payout', (int) $r->payout_id, array( 'correction' => (int) $r->id ) );
		}
		return $done;
	}
}
