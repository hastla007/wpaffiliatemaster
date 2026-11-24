<?php
/**
 * Claiming back negative balances of partners whose partnership ended (they earn nothing that
 * could offset it): payment request, reminder, final reminder; incoming payments are booked as
 * "repayment"; what cannot be collected is written off.
 *
 * All amounts include VAT: a refund of a commission that was paid out with VAT also takes back
 * that VAT (correction of the original credit note, §17 UStG), so the partner owes the gross
 * amount. Repayments and write-offs are not taxable themselves. When the balance is settled, a
 * correction document is issued (PayoutService::settle()).
 *
 * Number of stages and payment deadlines are settings since plugin 0.21.0 (SET-AC-13; marked
 * "check legally"): dunning_levels (3), dunning_days (14) and dunning_final_days (7) – the
 * defaults are the former fixed values.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Dunning {

	/**
	 * Former fixed number of stages (default of the setting dunning_levels).
	 *
	 * @deprecated 0.21.0 Use self::levels().
	 */
	const LEVELS = 3;

	/**
	 * Seconds after which the lock of a request that died is taken over (payment requests and
	 * repayments of one partner and currency; a second call while it is held is refused at once).
	 */
	const LOCK_TTL = 120;

	/**
	 * Number of stages (setting dunning_levels, 1 to 5).
	 *
	 * @return int
	 */
	public static function levels() {
		return max( 1, min( 5, (int) Settings::get( 'dunning_levels' ) ) );
	}

	/**
	 * Kind of a stage: 'request' (first), 'final' (last, with more than one stage) or 'reminder'.
	 *
	 * With a history, a stage that was already sent keeps the kind stored with it (entries since
	 * plugin 0.22.0; older entries are judged by the current setting), and the next stage is
	 * judged by next_stage(): a change of dunning_levels never turns a sent final reminder into a
	 * reminder or the other way round.
	 *
	 * @param int        $level   Stage (1 = first).
	 * @param array|null $history Sent stages (history()); null = judge by the setting alone.
	 * @return string
	 */
	public static function stage( $level, $history = null ) {
		$level = (int) $level;
		if ( is_array( $history ) ) {
			$history = array_values( $history );
			if ( isset( $history[ $level - 1 ] ) ) {
				return self::entry_stage( (array) $history[ $level - 1 ] );
			}
			$next = self::next_stage( $history );
			if ( $next && $next['level'] === $level ) {
				return $next['stage'];
			}
		}
		if ( 1 === $level ) {
			return 'request';
		}
		return $level >= self::levels() ? 'final' : 'reminder';
	}

	/**
	 * Kind of a sent stage (history entry): the stored kind, for entries of versions before
	 * 0.22.0 judged by the level and the current setting.
	 *
	 * @param array $entry History entry.
	 * @return string
	 */
	public static function entry_stage( array $entry ) {
		if ( isset( $entry['stage'] ) && in_array( $entry['stage'], array( 'request', 'reminder', 'final' ), true ) ) {
			return (string) $entry['stage'];
		}
		return self::stage( isset( $entry['level'] ) ? (int) $entry['level'] : 1 );
	}

	/**
	 * The next stage that may be sent: [ level, stage ], or null when the sequence is complete.
	 *
	 * Nothing follows a final reminder, even when dunning_levels was raised afterwards. When the
	 * setting was lowered to the number of stages already sent or below, nothing follows either
	 * (ended_without_final() tells whether the sequence then ended without a final reminder).
	 *
	 * @param array $history Sent stages (history()).
	 * @return array|null
	 */
	public static function next_stage( array $history ) {
		$sent   = count( $history );
		$levels = self::levels();
		if ( 0 === $sent ) {
			return array(
				'level' => 1,
				'stage' => 'request',
			);
		}
		$last = (array) end( $history );
		if ( 'final' === self::entry_stage( $last ) || $sent >= $levels ) {
			return null;
		}
		return array(
			'level' => $sent + 1,
			'stage' => $sent + 1 >= $levels ? 'final' : 'reminder',
		);
	}

	/**
	 * Whether the sequence is complete although no final reminder was sent (dunning_levels was
	 * lowered after stages had been sent).
	 *
	 * @param array $history Sent stages (history()).
	 * @return bool
	 */
	public static function ended_without_final( array $history ) {
		if ( ! $history || null !== self::next_stage( $history ) ) {
			return false;
		}
		$last = (array) end( $history );
		// A single payment request is complete by itself (dunning_levels = 1).
		return 1 < count( $history ) && 'final' !== self::entry_stage( $last );
	}

	/**
	 * End of the payment deadline of a sent stage (history entry), as a UTC date-time; '' for
	 * entries of versions before 0.21.0 (no deadline stored).
	 *
	 * @param array $entry History entry.
	 * @return string
	 */
	public static function deadline_end( array $entry ) {
		if ( empty( $entry['days'] ) || empty( $entry['at'] ) ) {
			return '';
		}
		$ts = strtotime( (string) $entry['at'] . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts + (int) $entry['days'] * DAY_IN_SECONDS );
	}

	/**
	 * Terminated partners with a negative open balance (VAT included): [ affiliate, currency,
	 * amount (negative, gross), history, vat (VAT part, negative) ].
	 *
	 * @return array
	 */
	public static function candidates() {
		global $wpdb;
		$c = Db::table( 'commissions' );
		$a = Db::table( 'affiliates' );
		// The net sum alone is not enough: VAT corrections can make a balance negative whose net
		// sum is not, so every open balance of an ended partnership is looked at.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT c.affiliate_id, c.currency, SUM(c.amount_minor) total FROM {$c} c JOIN {$a} a ON a.id = c.affiliate_id WHERE a.status = 'terminated' AND c.status = 'approved' AND c.payout_id = 0 GROUP BY c.affiliate_id, c.currency ORDER BY total ASC LIMIT 500" );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$aff = AffiliateRepository::find( (int) $r->affiliate_id );
			if ( ! $aff || self::is_excluded( $aff ) ) {
				continue; // Erased or restricted (Art. 18 GDPR): no payment requests.
			}
			$sum = \WPAffiliateCaptain\Payouts\PayoutService::open_totals( (int) $r->affiliate_id, (string) $r->currency );
			if ( $sum[2] >= 0 ) {
				continue;
			}
			$out[] = array(
				'affiliate' => $aff,
				'currency'  => $r->currency,
				'amount'    => (int) $sum[2],
				'history'   => self::history( (int) $r->affiliate_id, $r->currency ),
				'vat'       => (int) $sum[1],
			);
		}
		usort(
			$out,
			static function ( $x, $y ) {
				return $x['amount'] <=> $y['amount'];
			}
		);
		return array_slice( $out, 0, 200 );
	}

	/**
	 * Whether a partner must not be asked for repayment: data erased (Art. 17 GDPR, the
	 * address is a placeholder) or processing restricted (Art. 18 GDPR).
	 *
	 * @param object $aff Partner row.
	 * @return bool
	 */
	public static function is_excluded( $aff ) {
		return 'privacy_erasure' === (string) $aff->status_reason
			|| '@invalid.invalid' === substr( (string) $aff->email, -16 )
			|| ! is_email( (string) $aff->email )
			|| \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id );
	}

	/**
	 * Why no payment request can be sent right now ('' = it can).
	 *
	 * @param object $aff Partner row.
	 * @return string
	 */
	public static function blocked_reason( $aff ) {
		if ( self::is_excluded( $aff ) ) {
			return __( 'No payment request possible: the partner\'s data was erased or its processing is restricted (GDPR). Write the balance off or claim it back outside the plugin.', 'affiliate-captain' );
		}
		if ( '' === trim( (string) Settings::get( 'debtor_iban' ) ) || '' === trim( (string) Settings::get( 'debtor_name' ) ) ) {
			return __( 'No payment request possible yet: enter your company\'s account holder and IBAN under Settings → Payouts first, so the partner knows where to transfer the amount.', 'affiliate-captain' );
		}
		return '';
	}

	/**
	 * Sent requests: list of [ level, at, amount ].
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return array
	 */
	public static function history( $affiliate_id, $currency ) {
		$h = get_option( 'wpac_dunning_' . (int) $affiliate_id . '_' . strtoupper( (string) $currency ) );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Lock name of the payment requests and repayments of one partner and currency.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return string
	 */
	private static function lock_name( $affiliate_id, $currency ) {
		return 'wpac_lock_dunning_' . (int) $affiliate_id . '_' . strtoupper( (string) $currency );
	}

	/**
	 * Send the next payment request (1 = request, then reminders, the last stage of the setting
	 * dunning_levels = final reminder; nothing after a final reminder, see next_stage()).
	 *
	 * Runs under a lock per partner and currency (a second call while it is held is refused with
	 * wpac_dunning_locked), so two parallel calls cannot both send a stage. $expected_level (the stage the form showed) makes a form submitted twice harmless: the
	 * second call finds the stage already sent and sends nothing.
	 *
	 * @param int    $affiliate_id   Partner.
	 * @param string $currency       Currency.
	 * @param int    $expected_level Stage the caller means to send (0 = the next one, whatever it is).
	 * @return int|\WP_Error Level sent.
	 */
	public static function remind( $affiliate_id, $currency, $expected_level = 0 ) {
		$lock  = self::lock_name( (int) $affiliate_id, (string) $currency );
		$owner = Db::lock( $lock, self::LOCK_TTL );
		if ( '' === $owner ) {
			return new \WP_Error( 'wpac_dunning_locked', __( 'Another payment request for this partner is being sent right now. Please reload the page.', 'affiliate-captain' ) );
		}
		try {
			return self::remind_locked( (int) $affiliate_id, strtoupper( (string) $currency ), (int) $expected_level );
		} finally {
			Db::unlock( $lock, $owner );
		}
	}

	/**
	 * Body of remind(), called under the lock.
	 *
	 * @param int    $affiliate_id   Partner.
	 * @param string $currency       Currency (upper case).
	 * @param int    $expected_level Expected stage (0 = any).
	 * @return int|\WP_Error
	 */
	private static function remind_locked( $affiliate_id, $currency, $expected_level ) {
		$aff  = AffiliateRepository::find( (int) $affiliate_id );
		$open = self::open( (int) $affiliate_id, $currency );
		if ( ! $aff || $open >= 0 ) {
			return new \WP_Error( 'wpac_not_negative', __( 'The balance is not negative.', 'affiliate-captain' ) );
		}
		$blocked = self::blocked_reason( $aff );
		if ( '' !== $blocked ) {
			return new \WP_Error( 'wpac_dunning_blocked', $blocked );
		}
		$history = array_values( self::history( (int) $affiliate_id, $currency ) );
		$next    = self::next_stage( $history );
		if ( $expected_level > 0 && ( ! $next || $next['level'] !== $expected_level ) ) {
			/* translators: %d: level */
			return new \WP_Error( 'wpac_dunning_sent', sprintf( __( 'Payment request level %d was already sent (the form was probably submitted twice). Nothing was sent again.', 'affiliate-captain' ), $expected_level ) );
		}
		if ( ! $next ) {
			return new \WP_Error( 'wpac_dunning_done', __( 'All reminders were sent. Write the balance off or hand it over for collection.', 'affiliate-captain' ) );
		}
		$level = (int) $next['level'];
		$stage = (string) $next['stage'];
		$days  = max( 1, 'final' === $stage ? (int) Settings::get( 'dunning_final_days' ) : (int) Settings::get( 'dunning_days' ) );
		$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff );
		try {
			$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$amount  = html_entity_decode( Money::format( -$open, $currency ), ENT_QUOTES, 'UTF-8' );
			$vat     = self::open_vat( (int) $affiliate_id, $currency );
			$ref     = trim( (string) Settings::get( 'remittance_prefix' ) . ' R-' . (int) $affiliate_id );
			$subject = array(
				/* translators: %s: site name */
				'request'  => __( '[%s] Repayment of commissions', 'affiliate-captain' ),
				/* translators: %s: site name */
				'reminder' => __( '[%s] Reminder: repayment of commissions', 'affiliate-captain' ),
				/* translators: %s: site name */
				'final'    => __( '[%s] Final reminder: repayment of commissions', 'affiliate-captain' ),
			);
			$bank = trim( (string) Settings::get( 'debtor_name' ) . "\n" . 'IBAN ' . (string) Settings::get( 'debtor_iban' ) . ( Settings::get( 'debtor_bic' ) ? "\nBIC " . (string) Settings::get( 'debtor_bic' ) : '' ) );
			// Reply-To: the program's notification address ("please reply to this e-mail").
			$sent = \WPAffiliateCaptain\Affiliates\Notifications::send(
				'dunning',
				$aff->email,
				sprintf( $subject[ $stage ], $site ),
				/* translators: 1: name, 2: amount, 3: bank details, 4: reference, 5: days */
				sprintf( __( "Hello %1\$s,\n\nafter refunds and cancellations of orders you referred, your partner account shows a balance of -%2\$s. As the partnership has ended, it can no longer be offset against new commissions. Please transfer the amount within %5\$d days to:\n\n%3\$s\nReference: %4\$s\n\nThe bookings are listed in the partner area. If you think this is wrong, please reply to this e-mail.", 'affiliate-captain' ), $aff->name, $amount, $bank, $ref, $days )
					. ( $vat < 0
						/* translators: %s: VAT amount */
						? "\n\n" . sprintf( __( 'The amount includes %s VAT that was paid out with earlier credit notes for the refunded commissions (correction of those credit notes). Once the balance is settled you receive a correction document.', 'affiliate-captain' ), html_entity_decode( Money::format( -$vat, $currency ), ENT_QUOTES, 'UTF-8' ) )
						: '' ),
				array( 'affiliate_id' => (int) $affiliate_id )
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		}
		if ( ! $sent ) {
			return new \WP_Error( 'wpac_mail_failed', __( 'The payment request could not be sent (e-mail error). Please check the e-mail delivery of your site and try again.', 'affiliate-captain' ) );
		}
		$history[] = array(
			'level'  => $level,
			'at'     => Db::now(),
			'amount' => $open,
			'days'   => $days,
			'stage'  => $stage,
		);
		update_option( 'wpac_dunning_' . (int) $affiliate_id . '_' . $currency, $history, false );
		Audit::log( 'commission.dunning', 'affiliate', (int) $affiliate_id, array( 'level' => $level, 'amount' => $open, 'currency' => $currency, 'days' => $days, 'stage' => $stage ) );
		return $level;
	}

	/**
	 * Open (approved, unpaid) balance including VAT: what the partner owes (negative) or would
	 * get (positive). Refunds of commissions paid out with VAT take that VAT back.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return int
	 */
	public static function open( $affiliate_id, $currency ) {
		$sum = \WPAffiliateCaptain\Payouts\PayoutService::open_totals( (int) $affiliate_id, strtoupper( (string) $currency ) );
		return (int) $sum[2];
	}

	/**
	 * VAT part of the open balance (negative = VAT to be corrected).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return int
	 */
	public static function open_vat( $affiliate_id, $currency ) {
		$sum = \WPAffiliateCaptain\Payouts\PayoutService::open_totals( (int) $affiliate_id, strtoupper( (string) $currency ) );
		return (int) $sum[1];
	}

	/**
	 * Seconds within which a second repayment of the same partner, currency, amount and note
	 * (bank reference) counts as the same booking entered twice and is refused.
	 */
	const REPAYMENT_REPEAT_WINDOW = 600;

	/**
	 * Book a repayment received from the partner (at most the open negative balance including
	 * VAT). The repayment is not taxable; when it settles the balance, the correction document
	 * is issued.
	 *
	 * Protection against booking one payment twice (since plugin 0.22.0): the booking runs under
	 * the lock of the partner and currency; a form token ($token, one per displayed form) can be
	 * used only once; and a repayment with the same amount and note as one booked within the last
	 * REPAYMENT_REPEAT_WINDOW seconds is refused (error code wpac_repayment_repeat).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param int    $amount_minor Amount received (positive).
	 * @param string $note         Note (e.g. bank reference).
	 * @param string $token        Form token ('' = none).
	 * @return int|\WP_Error Booking ID.
	 */
	public static function record_repayment( $affiliate_id, $currency, $amount_minor, $note, $token = '' ) {
		$lock  = self::lock_name( (int) $affiliate_id, (string) $currency );
		$owner = Db::lock( $lock, self::LOCK_TTL );
		if ( '' === $owner ) {
			return new \WP_Error( 'wpac_dunning_locked', __( 'Another booking for this partner is running right now. Please reload the page and check the account before booking again.', 'affiliate-captain' ) );
		}
		try {
			return self::record_repayment_locked( (int) $affiliate_id, strtoupper( (string) $currency ), (int) $amount_minor, (string) $note, (string) $token );
		} finally {
			Db::unlock( $lock, $owner );
		}
	}

	/**
	 * Body of record_repayment(), called under the lock.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency (upper case).
	 * @param int    $amount_minor Amount.
	 * @param string $note         Note.
	 * @param string $token        Form token.
	 * @return int|\WP_Error
	 */
	private static function record_repayment_locked( $affiliate_id, $currency, $amount_minor, $note, $token ) {
		global $wpdb;
		if ( $amount_minor <= 0 ) {
			return new \WP_Error( 'wpac_amount', __( 'Please enter the amount received.', 'affiliate-captain' ) );
		}
		$t           = Db::table( 'commissions' );
		$description = Db::cut( sanitize_text_field( $note ), 255 );
		$token       = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		$key         = '' !== $token
			? Ledger::key( 'repayment:' . $affiliate_id . ':' . $currency . ':form:' . $token )
			: Ledger::key( 'repayment:' . $affiliate_id . ':' . $currency . ':' . Db::now() . ':' . wp_generate_password( 6, false ) );
		if ( '' !== $token && Ledger::find_by_key( $key ) ) {
			return new \WP_Error( 'wpac_repayment_repeat', __( 'This repayment was already booked (the form was submitted twice). Nothing was booked again.', 'affiliate-captain' ) );
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - self::REPAYMENT_REPEAT_WINDOW );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$repeat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE affiliate_id = %d AND currency = %s AND kind = 'repayment' AND amount_minor = %d AND description = %s AND created_at >= %s", $affiliate_id, $currency, $amount_minor, $description, $since ) );
		if ( $repeat > 0 ) {
			/* translators: %d: minutes */
			return new \WP_Error( 'wpac_repayment_repeat', sprintf( __( 'A repayment with the same amount and bank reference was booked less than %d minutes ago. Check the partner account: if it is really a second payment, enter its own bank reference.', 'affiliate-captain' ), (int) ( self::REPAYMENT_REPEAT_WINDOW / 60 ) ) );
		}
		$t = Db::table( 'commissions' );
		try {
			$id = Db::transaction(
				static function () use ( $wpdb, $t, $affiliate_id, $currency, $amount_minor, $description, $key ) {
					// Lock the open rows, then compute the balance with VAT.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0 FOR UPDATE", $affiliate_id, $currency ) );
					$open = self::open( (int) $affiliate_id, $currency );
					if ( $open >= 0 || $amount_minor > -$open ) {
						throw new IntegrationException( 'amount', esc_html__( 'The amount is larger than the negative balance.', 'affiliate-captain' ) );
					}
					$ok = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $affiliate_id,
							'source'          => 'wpac',
							'source_order_id' => 'repayment',
							'source_item_id'  => '',
							'kind'            => 'repayment',
							'parent_id'       => 0,
							'product_ref'     => '',
							'description'     => $description,
							'currency'        => $currency,
							'base_minor'      => 0,
							'amount_minor'    => $amount_minor,
							'rate_type'       => 'fixed',
							'rate'            => '0',
							'rule_id'         => 0,
							'status'          => 'approved',
							'hold_until'      => Db::now(),
							'idempotency_key' => $key,
							'created_at'      => Db::now(),
							'updated_at'      => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'db_repayment', esc_html( $wpdb->last_error ) );
					}
					$id = (int) $wpdb->insert_id;
					Balance::post( (int) $affiliate_id, $currency, 'approved', $amount_minor, $id, 0, 'repayment' );
					Ledger::event( $id, 'created', '', 'approved', $amount_minor, 'repayment', $key, get_current_user_id() );
					// After COMMIT (dropped on rollback), like the other ledger bookings.
					Db::after_commit(
						static function () use ( $id ) {
							do_action( 'wpac_commission_created', $id );
						}
					);
					return $id;
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		Audit::log( 'commission.repayment', 'affiliate', (int) $affiliate_id, array( 'amount' => $amount_minor, 'currency' => $currency ) );
		if ( self::open( (int) $affiliate_id, $currency ) >= 0 ) {
			self::reset( (int) $affiliate_id, $currency );
			// Settled: the correction document reduces the VAT of the original credit notes.
			\WPAffiliateCaptain\Payouts\PayoutService::settle( (int) $affiliate_id, $currency );
		}
		return $id;
	}

	/**
	 * Forget the sent payment requests once the debt is settled (repaid or written off), so a
	 * later new debt starts again with the first request instead of "all reminders sent".
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 */
	public static function reset( $affiliate_id, $currency ) {
		delete_option( 'wpac_dunning_' . (int) $affiliate_id . '_' . strtoupper( (string) $currency ) );
	}
}
