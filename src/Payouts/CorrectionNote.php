<?php
/**
 * Correction credit note (Korrekturgutschrift) for a paid payout, issued from an objection of
 * the partner (§14 Abs. 2 S. 3, §17 UStG). A paid payout cannot be cancelled (the money went
 * out), so the difference is documented with a correction document of its own:
 *
 * - its own number from the correction number range (setting correction_prefix);
 * - reference to the original credit note (number and date) and the reason;
 * - the corrected lines are correction bookings of the original commissions: they keep the
 *   VAT treatment and rate of the original credit note line (PayoutService::item_treatments()),
 *   the operator data are those frozen with the original credit note;
 * - additional payment (positive): a payout of its own that is transferred like any other;
 * - overpayment (negative): never a negative transfer. The document is settled at once and the
 *   gross amount becomes a claim (non-taxable booking "claim") on the partner's balance: it is
 *   deducted from the next credit notes or requested through the payment requests (Dunning).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Sequence;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class CorrectionNote {

	/** Document type in the operator snapshot of the payout. */
	const DOCUMENT = 'correction_note';

	/** Number range of correction credit notes. */
	const SEQUENCE = 'correction';

	/** Booking kind of the claim from an overpayment (not taxable). */
	const CLAIM_KIND = 'claim';

	/** Additional payment to the partner. */
	const POSITIVE = 'positive';

	/** The partner was paid too much. */
	const NEGATIVE = 'negative';

	/**
	 * Document data of a correction credit note from the operator snapshot, or null.
	 *
	 * @param object|array|null $p Payout or decoded operator snapshot.
	 * @return array|null [ payout_id, number, created_at, reason, direction ]
	 */
	public static function document( $p ) {
		if ( is_object( $p ) ) {
			$p = json_decode( (string) ( isset( $p->operator_snapshot ) ? $p->operator_snapshot : '' ), true );
		}
		if ( ! is_array( $p ) || ! isset( $p['document'] ) || self::DOCUMENT !== $p['document'] || empty( $p['corrects'] ) || ! is_array( $p['corrects'] ) ) {
			return null;
		}
		return $p['corrects'] + array(
			'payout_id'  => 0,
			'number'     => '',
			'created_at' => '',
			'reason'     => '',
			'direction'  => self::POSITIVE,
		);
	}

	/**
	 * Whether a payout is a correction credit note.
	 *
	 * @param object|null $p Payout.
	 * @return bool
	 */
	public static function is_note( $p ) {
		return null !== self::document( $p );
	}

	/**
	 * Lines of a paid credit note that can be corrected: taxable lines with the amount on the
	 * credit note and what remains after later corrections (refunds, earlier correction notes).
	 *
	 * @param int $payout_id Original payout.
	 * @return array commission ID => object (commission_id, kind, description, source_order_id, commission_date, amount, remaining, vat_mode, vat_rate)
	 */
	public static function lines( $payout_id ) {
		$out = array();
		foreach ( PayoutService::items( (int) $payout_id, true ) as $item ) {
			if ( Vat::NONE === (string) $item->vat_mode || in_array( (string) $item->kind, Vat::NON_TAXABLE_KINDS, true ) || (int) $item->amount_minor <= 0 ) {
				continue;
			}
			$item->amount    = (int) $item->amount_minor;
			$item->remaining = (int) $item->amount_minor + Ledger::adjusted_sum( (int) $item->commission_id );
			$out[ (int) $item->commission_id ] = $item;
		}
		return $out;
	}

	/**
	 * Correction credit note already issued for a request token (double click, reload).
	 *
	 * @param string $token Token of the form.
	 * @return int Payout ID (0 = none).
	 */
	public static function for_token( $token ) {
		global $wpdb;
		if ( '' === (string) $token ) {
			return 0;
		}
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT payout_id FROM {$t} WHERE idempotency_key LIKE %s AND kind = 'adjustment' ORDER BY id ASC LIMIT 1", $wpdb->esc_like( self::key_prefix( $token ) ) . '%' ) );
	}

	/**
	 * Idempotency key prefix of the bookings of one request.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function key_prefix( $token ) {
		return 'correction_note:' . $token . ':';
	}

	/**
	 * Issue a correction credit note for a paid payout with an open objection.
	 *
	 * @param int    $payout_id Original (paid) payout.
	 * @param array  $amounts   Commission ID of a line => net correction in minor units (> 0).
	 * @param string $direction self::POSITIVE (additional payment) or self::NEGATIVE (overpayment).
	 * @param string $reason    Reason (printed on the document and sent to the partner).
	 * @param string $token     Request token (idempotency: the same token never issues twice).
	 * @return int|\WP_Error Payout ID of the correction credit note.
	 */
	public static function issue( $payout_id, array $amounts, $direction, $reason, $token ) {
		$payout_id = (int) $payout_id;
		$reason    = Db::cut( trim( sanitize_textarea_field( (string) $reason ) ), 500 );
		$token     = (string) $token;
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return new \WP_Error( 'wpac_forbidden', __( 'You are not allowed to do this.', 'affiliate-captain' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{8,64}$/', $token ) ) {
			return new \WP_Error( 'wpac_token', __( 'The form has expired. Please reload the page and try again.', 'affiliate-captain' ) );
		}
		$done = self::for_token( $token );
		if ( $done ) {
			return $done; // Already issued with this form (double click): the same document.
		}
		$check = self::validate( $payout_id, $amounts, $direction, $reason );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		try {
			$id = Db::with_lock(
				'wpac_correction_note_' . $payout_id,
				static function () use ( $payout_id, $amounts, $direction, $reason, $token ) {
					$done = self::for_token( $token );
					if ( $done ) {
						return array( $done, false );
					}
					// Checked again under the lock with the stored objection (a parallel request may
					// have handled it or used the lines up).
					wp_cache_delete( Objections::OPTION, 'options' );
					$check = self::validate( $payout_id, $amounts, $direction, $reason );
					if ( is_wp_error( $check ) ) {
						return $check;
					}
					$doc = Db::transaction(
						static function () use ( $payout_id, $check, $direction, $reason, $token ) {
							return self::book( $payout_id, $check, $direction, $reason, $token );
						}
					);
					return array( (int) $doc, true );
				}
			);
		} catch ( \Throwable $e ) {
			Audit::log( 'payout.correction_note_failed', 'payout', $payout_id, array( 'error' => Db::cut( $e->getMessage(), 300 ) ) );
			return new \WP_Error( 'wpac_db_error', __( 'The correction credit note could not be saved. Nothing was booked; please try again.', 'affiliate-captain' ) );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		list( $id, $fresh ) = $id;
		if ( $fresh ) {
			self::after_issue( (int) $id, $payout_id, $reason );
		}
		return (int) $id;
	}

	/**
	 * Check a request; returns the lines with their amounts.
	 *
	 * @param int    $payout_id Original payout.
	 * @param array  $amounts   Commission ID => minor units.
	 * @param string $direction Direction.
	 * @param string $reason    Reason.
	 * @return array|\WP_Error [ original payout, lines (commission ID => [ line, amount ]) ]
	 */
	private static function validate( $payout_id, array $amounts, $direction, $reason ) {
		$p = PayoutService::find( $payout_id );
		if ( ! $p || 'paid' !== $p->status || self::is_note( $p ) || null !== self::settle_document( $p ) ) {
			return new \WP_Error( 'wpac_state', __( 'A correction credit note can only be issued for a paid credit note.', 'affiliate-captain' ) );
		}
		$o = Objections::for_payout( $payout_id );
		if ( ! $o || 'open' !== $o['status'] ) {
			return new \WP_Error( 'wpac_objection', __( 'This credit note has no open objection (already handled?).', 'affiliate-captain' ) );
		}
		if ( ! in_array( $direction, array( self::POSITIVE, self::NEGATIVE ), true ) ) {
			return new \WP_Error( 'wpac_direction', __( 'Please choose whether the partner gets an additional payment or was paid too much.', 'affiliate-captain' ) );
		}
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter the reason of the correction (it is printed on the document).', 'affiliate-captain' ) );
		}
		$operator = PayoutService::operator_missing();
		if ( '' !== $operator ) {
			return new \WP_Error( 'wpac_operator', $operator );
		}
		$snap = json_decode( (string) $p->profile_snapshot, true );
		if ( ! is_array( $snap ) || ! $snap || ! AffiliateRepository::find( (int) $p->affiliate_id ) ) {
			return new \WP_Error( 'wpac_partner', __( 'The partner data of this credit note are no longer available.', 'affiliate-captain' ) );
		}
		$lines = self::lines( $payout_id );
		$out   = array();
		$total = 0;
		foreach ( $amounts as $cid => $minor ) {
			$minor = (int) $minor;
			if ( 0 === $minor ) {
				continue;
			}
			if ( ! isset( $lines[ (int) $cid ] ) ) {
				return new \WP_Error( 'wpac_line', __( 'A selected line is not part of this credit note.', 'affiliate-captain' ) );
			}
			if ( $minor < 0 || $minor > \WPAffiliateCaptain\Sales\ContractApi::MAX_MINOR ) {
				return new \WP_Error( 'wpac_amount', __( 'Please enter positive net amounts; the direction is chosen separately.', 'affiliate-captain' ) );
			}
			$line = $lines[ (int) $cid ];
			if ( self::NEGATIVE === $direction && $minor > $line->remaining ) {
				/* translators: 1: line, 2: amount */
				return new \WP_Error( 'wpac_amount', sprintf( __( 'The correction of "%1$s" is larger than what remains of the line on the credit note (%2$s).', 'affiliate-captain' ), (string) $line->description, html_entity_decode( Money::format( max( 0, (int) $line->remaining ), $p->currency ), ENT_QUOTES, 'UTF-8' ) ) );
			}
			$out[ (int) $cid ] = array( $line, $minor );
			$total            += $minor;
		}
		if ( ! $out ) {
			return new \WP_Error( 'wpac_amount', __( 'Please enter the net amount to correct for at least one line.', 'affiliate-captain' ) );
		}
		if ( self::NEGATIVE === $direction && $total > (int) $p->net_minor ) {
			return new \WP_Error( 'wpac_amount', __( 'The correction is larger than the net amount of the credit note.', 'affiliate-captain' ) );
		}
		return array( $p, $out );
	}

	/**
	 * The settlement document of R6-DD3 (document "correction") is no regular credit note.
	 *
	 * @param object $p Payout.
	 * @return string|null
	 */
	private static function settle_document( $p ) {
		$op = json_decode( (string) $p->operator_snapshot, true );
		return is_array( $op ) && isset( $op['document'] ) ? (string) $op['document'] : null;
	}

	/**
	 * Book the corrections and the document (inside the transaction).
	 *
	 * @param int    $payout_id Original payout.
	 * @param array  $check     validate() result.
	 * @param string $direction Direction.
	 * @param string $reason    Reason.
	 * @param string $token     Token.
	 * @return int Payout ID.
	 * @throws IntegrationException On a database error (rolled back).
	 */
	private static function book( $payout_id, array $check, $direction, $reason, $token ) {
		global $wpdb;
		list( $orig, $lines ) = $check;
		$t        = Db::table( 'commissions' );
		$sign     = self::NEGATIVE === $direction ? -1 : 1;
		$currency = (string) $orig->currency;
		$aff_id   = (int) $orig->affiliate_id;
		$now      = Db::now();
		$user     = get_current_user_id();
		// Lock the original lines: parallel refunds must not use up the same amount.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->get_col( "SELECT id FROM {$t} WHERE id IN (" . implode( ',', array_map( 'intval', array_keys( $lines ) ) ) . ') FOR UPDATE' );
		$rows = array();
		foreach ( $lines as $cid => $entry ) {
			list( $line, $minor ) = $entry;
			$parent = Ledger::find( (int) $cid );
			if ( ! $parent || (int) $parent->payout_id !== (int) $payout_id || 'paid' !== $parent->status ) {
				throw new IntegrationException( 'commission_changed', esc_html( 'Commission ' . (int) $cid . ' is no longer part of the paid credit note.' ) );
			}
			if ( $sign < 0 && $minor > (int) $parent->amount_minor + Ledger::adjusted_sum( (int) $cid, true ) ) {
				throw new IntegrationException( 'amount', esc_html( 'Correction of commission ' . (int) $cid . ' exceeds the remaining amount.' ) );
			}
			$key = Ledger::key( self::key_prefix( $token ) . (int) $cid );
			$ok  = $wpdb->insert(
				$t,
				array(
					'affiliate_id'    => $aff_id,
					'source'          => $parent->source,
					'source_order_id' => $parent->source_order_id,
					'source_item_id'  => $parent->source_item_id,
					'kind'            => 'adjustment',
					'parent_id'       => (int) $parent->id,
					'product_ref'     => $parent->product_ref,
					'description'     => Db::cut( $reason, 255 ),
					'currency'        => $currency,
					'base_minor'      => 0,
					'amount_minor'    => $sign * $minor,
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
			$id = (int) $wpdb->insert_id;
			Balance::post( $aff_id, $currency, 'approved', $sign * $minor, $id, 0, 'correction note' );
			Ledger::event( $id, 'created', '', 'approved', $sign * $minor, 'correction note: ' . $reason, $key, $user );
			Ledger::event( (int) $parent->id, 'adjusted', $parent->status, $parent->status, $sign * $minor, 'correction note', $key, $user );
			$rows[] = (object) array(
				'id'                => $id,
				'amount_minor'      => $sign * $minor,
				'kind'              => 'adjustment',
				'parent_id'         => (int) $parent->id,
				'created_at'        => $now,
				'parent_created_at' => $parent->created_at,
				'parent_payout_id'  => (int) $payout_id,
			);
		}
		// VAT treatment and rate of the original credit note line (§17 UStG).
		$treat = PayoutService::item_treatments(
			$rows,
			array(
				'mode' => (string) $orig->vat_mode,
				'rate' => (string) $orig->vat_rate,
			)
		);
		$net = 0;
		foreach ( $rows as $r ) {
			$net += (int) $r->amount_minor;
		}
		$vat = 0;
		foreach ( PayoutService::vat_groups( $rows, $treat ) as $group ) {
			$vat += Vat::amount( $group['net'], $group['rate'] );
		}
		$gross  = $net + $vat;
		$number = Sequence::number( self::prefix(), self::SEQUENCE, (int) wp_date( 'Y' ) );
		// Operator data frozen with the original credit note, plus the reference.
		$op = json_decode( (string) $orig->operator_snapshot, true );
		$op = is_array( $op ) ? $op : PayoutService::operator_snapshot();
		$op['document'] = self::DOCUMENT;
		$op['corrects'] = array(
			'payout_id'  => (int) $orig->id,
			'number'     => (string) $orig->number,
			'created_at' => (string) $orig->created_at,
			'reason'     => $reason,
			'direction'  => $direction,
		);
		$ok = $wpdb->insert(
			Db::table( 'payouts' ),
			array(
				'number'            => $number,
				'affiliate_id'      => $aff_id,
				'period_end'        => $orig->period_end,
				'currency'          => $currency,
				'net_minor'         => $net,
				'vat_mode'          => (string) $orig->vat_mode,
				'vat_rate'          => (string) $orig->vat_rate,
				'vat_minor'         => $vat,
				'amount_minor'      => $gross,
				'status'            => $sign > 0 ? 'pending' : 'paid',
				'paid_at'           => $sign > 0 ? null : $now,
				'profile_snapshot'  => wp_json_encode( self::profile_snapshot( $orig ) ),
				'operator_snapshot' => wp_json_encode( $op ),
				'created_by'        => $user,
				'created_at'        => $now,
			)
		);
		if ( ! $ok ) {
			throw new IntegrationException( 'db_payout', esc_html( $wpdb->last_error ) );
		}
		$doc = (int) $wpdb->insert_id;
		foreach ( $rows as $r ) {
			$ok = $wpdb->insert(
				Db::table( 'payout_items' ),
				array(
					'payout_id'            => $doc,
					'commission_id'        => (int) $r->id,
					'active_commission_id' => (int) $r->id,
					'amount_minor'         => (int) $r->amount_minor,
					'vat_mode'             => $treat[ (int) $r->id ]['mode'],
					'vat_rate'             => $treat[ (int) $r->id ]['rate'],
					'ref_payout_id'        => (int) $orig->id,
				)
			);
			if ( ! $ok ) {
				throw new IntegrationException( 'double_payout', esc_html( 'Commission ' . (int) $r->id . ' is already part of a payout.' ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$moved = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET payout_id = %d, updated_at = %s WHERE id = %d AND payout_id = 0 AND status = 'approved'", $doc, $now, (int) $r->id ) );
			if ( 1 !== $moved ) {
				throw new IntegrationException( 'commission_changed', esc_html( 'Commission ' . (int) $r->id . ' changed.' ) );
			}
			Ledger::event( (int) $r->id, 'payout_assigned', 'approved', 'approved', (int) $r->amount_minor, $number );
		}
		Balance::post( $aff_id, $currency, 'payout', -$net, 0, $doc, $number );
		if ( $sign < 0 ) {
			// Overpayment: nothing is transferred. The document settles the corrections at once and
			// the gross amount (VAT included) is owed by the partner – a non-taxable claim on the
			// balance, deducted from the next credit notes or requested by the payment requests.
			foreach ( $rows as $r ) {
				if ( ! Ledger::transition( (int) $r->id, 'paid', $number, $user, $doc, 'approved', false ) ) {
					throw new IntegrationException( 'commission_changed', esc_html( 'Commission ' . (int) $r->id . ' could not be settled.' ) );
				}
			}
			$key = Ledger::key( 'correction_claim:' . $token );
			/* translators: 1: correction credit note number, 2: original credit note number */
			$desc = sprintf( __( 'Correction credit note %1$s (corrects %2$s)', 'affiliate-captain' ), $number, $orig->number );
			$ok   = $wpdb->insert(
				$t,
				array(
					'affiliate_id'    => $aff_id,
					'source'          => 'wpac',
					'source_order_id' => 'claim',
					'source_item_id'  => '',
					'kind'            => self::CLAIM_KIND,
					'parent_id'       => 0,
					'product_ref'     => '',
					'description'     => Db::cut( $desc, 255 ),
					'currency'        => $currency,
					'base_minor'      => 0,
					'amount_minor'    => $gross,
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
				throw new IntegrationException( 'db_claim', esc_html( $wpdb->last_error ) );
			}
			$claim = (int) $wpdb->insert_id;
			Balance::post( $aff_id, $currency, 'approved', $gross, $claim, $doc, $number );
			Ledger::event( $claim, 'created', '', 'approved', $gross, 'correction note claim ' . $number, $key, $user );
		}
		Audit::log(
			'payout.correction_note_created',
			'payout',
			$doc,
			array(
				'number'    => $number,
				'corrects'  => (string) $orig->number,
				'direction' => $direction,
				'net'       => $net,
				'vat'       => $vat,
				'reason'    => $reason,
			)
		);
		return $doc;
	}

	/**
	 * Partner data of the document: tax data as on the original credit note (the correction
	 * belongs to that service), payment data as they are now (an additional payment is
	 * transferred to the current account).
	 *
	 * @param object $orig Original payout.
	 * @return array
	 */
	private static function profile_snapshot( $orig ) {
		$snap = json_decode( (string) $orig->profile_snapshot, true );
		$snap = is_array( $snap ) ? $snap : array();
		$now  = ProfileRepository::snapshot( ProfileRepository::get( (int) $orig->affiliate_id ) );
		foreach ( array( 'account_holder', 'iban_masked', 'bic', 'payout_method' ) as $k ) {
			if ( isset( $now[ $k ] ) ) {
				$snap[ $k ] = $now[ $k ];
			}
		}
		return $snap;
	}

	/**
	 * Prefix of the correction number range.
	 *
	 * @return string
	 */
	public static function prefix() {
		$prefix = (string) Settings::get( 'correction_prefix' );
		return '' !== $prefix ? $prefix : 'KG';
	}

	/**
	 * After the commit: resolve the objection (with the document), write the PDF (back-off and
	 * job queue on failure) – the partner receives it by e-mail when it is stored.
	 *
	 * @param int    $id        Correction credit note.
	 * @param int    $payout_id Original payout.
	 * @param string $reason    Reason.
	 */
	private static function after_issue( $id, $payout_id, $reason ) {
		$p   = PayoutService::find( $id );
		$aff = $p ? AffiliateRepository::find( (int) $p->affiliate_id ) : null;
		if ( ! $p ) {
			return;
		}
		$switched = $aff ? \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff ) : false;
		try {
			$text = sprintf(
				/* translators: 1: correction credit note number, 2: gross amount, 3: reason */
				self::NEGATIVE === self::direction( $p ) ? __( "We have issued correction credit note %1\$s. You were paid %2\$s too much; the amount is offset against your next credit notes.\n\nReason: %3\$s", 'affiliate-captain' ) : __( "We have issued correction credit note %1\$s. The additional amount of %2\$s is transferred to you.\n\nReason: %3\$s", 'affiliate-captain' ),
				$p->number,
				html_entity_decode( Money::format( abs( (int) $p->amount_minor ), $p->currency ), ENT_QUOTES, 'UTF-8' ),
				$reason
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $switched );
		}
		if ( ! Objections::resolve( $payout_id, $text, $id ) ) {
			Audit::log( 'payout.correction_note_objection_open', 'payout', $payout_id, array( 'document' => $id ) );
		}
		if ( Statement::generate( $id ) ) {
			if ( self::POSITIVE === self::direction( $p ) ) {
				/** This action is documented in PayoutService::create_for_affiliate(). */
				do_action( 'wpac_payout_created', $id, (int) $p->affiliate_id );
			}
			return;
		}
		// Not written (e.g. folder not writable): the hourly run retries with back-off; an
		// additional payment also goes through the job queue (announced once the PDF exists).
		if ( self::POSITIVE === self::direction( $p ) ) {
			Jobs::enqueue( PayoutService::STATEMENT_JOB, (string) $id, array( 'payout_id' => $id, 'affiliate_id' => (int) $p->affiliate_id ), '', '', 0 );
		}
	}

	/**
	 * Direction of a correction credit note.
	 *
	 * @param object $p Payout.
	 * @return string
	 */
	public static function direction( $p ) {
		$doc = self::document( $p );
		return $doc && self::NEGATIVE === $doc['direction'] ? self::NEGATIVE : self::POSITIVE;
	}

	/**
	 * Title of the document.
	 *
	 * @param bool $self   Self-billing.
	 * @param bool $german German-speaking operator country.
	 * @return string
	 */
	public static function title( $self, $german ) {
		if ( ! $self ) {
			return __( 'Corrected commission statement', 'affiliate-captain' );
		}
		return $german ? __( 'Correction credit note (Korrekturgutschrift)', 'affiliate-captain' ) : __( 'Correction credit note (self-billing)', 'affiliate-captain' );
	}

	/**
	 * Reference lines of the document (original credit note and reason).
	 *
	 * @param array    $doc  document().
	 * @param callable $date Date formatter (MySQL local time → text).
	 * @return array Label => value.
	 */
	public static function facts( array $doc, callable $date ) {
		return array(
			__( 'Corrects credit note', 'affiliate-captain' ) => $doc['number'] . ( '' !== (string) $doc['created_at'] ? ' (' . $date( get_date_from_gmt( (string) $doc['created_at'] ) ) . ')' : '' ),
			__( 'Reason of the correction', 'affiliate-captain' ) => '' !== (string) $doc['reason'] ? (string) $doc['reason'] : '—',
		);
	}

	/**
	 * Label of the total.
	 *
	 * @param object $p Payout.
	 * @return string
	 */
	public static function total_label( $p ) {
		return self::NEGATIVE === self::direction( $p ) ? __( 'Amount owed by the partner (offset, no transfer)', 'affiliate-captain' ) : __( 'Additional payment', 'affiliate-captain' );
	}

	/**
	 * Note printed on the document.
	 *
	 * @param object $p Payout.
	 * @return string
	 */
	public static function note( $p ) {
		return self::NEGATIVE === self::direction( $p )
			? __( 'This correction credit note corrects the credit note named above after an objection of the partner; it reduces its net amount and VAT. The partner was paid too much: the amount is offset against future credit notes or is to be repaid. Nothing is transferred.', 'affiliate-captain' )
			: __( 'This correction credit note corrects the credit note named above after an objection of the partner; it increases its net amount and VAT. The additional amount is transferred to the partner.', 'affiliate-captain' );
	}

	/**
	 * E-mail to the partner with the document (Notifications::credit_note_issued()).
	 *
	 * @param object $p   Correction credit note.
	 * @param object $aff Partner.
	 * @param string $url Partner area.
	 * @return array [ subject, body ]
	 */
	public static function mail( $p, $aff, $url ) {
		$doc    = self::document( $p );
		$amount = html_entity_decode( Money::format( abs( (int) $p->amount_minor ), $p->currency ), ENT_QUOTES, 'UTF-8' );
		$what   = self::NEGATIVE === self::direction( $p )
			/* translators: %s: amount */
			? sprintf( __( 'You were paid %s too much; the amount is offset against your next credit notes.', 'affiliate-captain' ), $amount )
			/* translators: %s: amount */
			: sprintf( __( 'The additional amount of %s is transferred to you.', 'affiliate-captain' ), $amount );
		return array(
			/* translators: 1: site name, 2: document number */
			sprintf( __( '[%1$s] Correction credit note %2$s', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
			/* translators: 1: name, 2: correction credit note number, 3: original credit note number, 4: what happens with the amount, 5: portal URL */
			sprintf( __( "Hello %1\$s,\n\nafter your objection we have issued correction credit note %2\$s for credit note %3\$s. %4\$s\n\nThe document is attached to this e-mail and can be downloaded in your partner area: %5\$s", 'affiliate-captain' ), $aff->name, $p->number, $doc ? $doc['number'] : '', $what, $url ),
		);
	}
}
