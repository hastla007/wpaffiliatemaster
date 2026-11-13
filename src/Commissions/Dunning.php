<?php
/**
 * Claiming back negative balances of partners whose partnership ended (they earn nothing that
 * could offset it): payment request, reminder, final reminder; incoming payments are booked as
 * "repayment"; what cannot be collected is written off.
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

	const LEVELS = 3;

	/**
	 * Terminated partners with a negative open balance: [ affiliate, currency, amount (negative), history ].
	 *
	 * @return array
	 */
	public static function candidates() {
		global $wpdb;
		$c = Db::table( 'commissions' );
		$a = Db::table( 'affiliates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT c.affiliate_id, c.currency, SUM(c.amount_minor) total FROM {$c} c JOIN {$a} a ON a.id = c.affiliate_id WHERE a.status = 'terminated' AND c.status = 'approved' AND c.payout_id = 0 GROUP BY c.affiliate_id, c.currency HAVING SUM(c.amount_minor) < 0 ORDER BY total ASC LIMIT 200" );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$aff = AffiliateRepository::find( (int) $r->affiliate_id );
			if ( ! $aff || self::is_excluded( $aff ) ) {
				continue; // Erased or restricted (Art. 18 GDPR): no payment requests.
			}
			$out[] = array(
				'affiliate' => $aff,
				'currency'  => $r->currency,
				'amount'    => (int) $r->total,
				'history'   => self::history( (int) $r->affiliate_id, $r->currency ),
			);
		}
		return $out;
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
	 * Send the next payment request (1 = request, 2 = reminder, 3 = final reminder).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return int|\WP_Error Level sent.
	 */
	public static function remind( $affiliate_id, $currency ) {
		$aff      = AffiliateRepository::find( (int) $affiliate_id );
		$currency = strtoupper( (string) $currency );
		$open     = self::open( (int) $affiliate_id, $currency );
		if ( ! $aff || $open >= 0 ) {
			return new \WP_Error( 'wpac_not_negative', __( 'The balance is not negative.', 'affiliate-captain' ) );
		}
		$blocked = self::blocked_reason( $aff );
		if ( '' !== $blocked ) {
			return new \WP_Error( 'wpac_dunning_blocked', $blocked );
		}
		$history = self::history( (int) $affiliate_id, $currency );
		$level   = count( $history ) + 1;
		if ( $level > self::LEVELS ) {
			return new \WP_Error( 'wpac_dunning_done', __( 'All reminders were sent. Write the balance off or hand it over for collection.', 'affiliate-captain' ) );
		}
		$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$amount  = html_entity_decode( Money::format( -$open, $currency ), ENT_QUOTES, 'UTF-8' );
		$ref     = trim( (string) Settings::get( 'remittance_prefix' ) . ' R-' . (int) $affiliate_id );
		$subject = array(
			/* translators: %s: site name */
			1 => __( '[%s] Repayment of commissions', 'affiliate-captain' ),
			/* translators: %s: site name */
			2 => __( '[%s] Reminder: repayment of commissions', 'affiliate-captain' ),
			/* translators: %s: site name */
			3 => __( '[%s] Final reminder: repayment of commissions', 'affiliate-captain' ),
		);
		$bank = trim( (string) Settings::get( 'debtor_name' ) . "\n" . 'IBAN ' . (string) Settings::get( 'debtor_iban' ) . ( Settings::get( 'debtor_bic' ) ? "\nBIC " . (string) Settings::get( 'debtor_bic' ) : '' ) );
		// Reply-To: the program's notification address ("please reply to this e-mail").
		$sent = \WPAffiliateCaptain\Affiliates\Notifications::send(
			'dunning',
			$aff->email,
			sprintf( $subject[ $level ], $site ),
			/* translators: 1: name, 2: amount, 3: bank details, 4: reference, 5: days */
			sprintf( __( "Hello %1\$s,\n\nafter refunds and cancellations of orders you referred, your partner account shows a balance of -%2\$s. As the partnership has ended, it can no longer be offset against new commissions. Please transfer the amount within %5\$d days to:\n\n%3\$s\nReference: %4\$s\n\nThe bookings are listed in the partner area. If you think this is wrong, please reply to this e-mail.", 'affiliate-captain' ), $aff->name, $amount, $bank, $ref, 3 === $level ? 7 : 14 ),
			array( 'affiliate_id' => (int) $affiliate_id )
		);
		\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		if ( ! $sent ) {
			return new \WP_Error( 'wpac_mail_failed', __( 'The payment request could not be sent (e-mail error). Please check the e-mail delivery of your site and try again.', 'affiliate-captain' ) );
		}
		$history[] = array(
			'level'  => $level,
			'at'     => Db::now(),
			'amount' => $open,
		);
		update_option( 'wpac_dunning_' . (int) $affiliate_id . '_' . $currency, $history, false );
		Audit::log( 'commission.dunning', 'affiliate', (int) $affiliate_id, array( 'level' => $level, 'amount' => $open, 'currency' => $currency ) );
		return $level;
	}

	/**
	 * Open (approved, unpaid) balance.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @return int
	 */
	public static function open( $affiliate_id, $currency ) {
		global $wpdb;
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$c} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0", $affiliate_id, $currency ) );
	}

	/**
	 * Book a repayment received from the partner (at most the open negative balance).
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $currency     Currency.
	 * @param int    $amount_minor Amount received (positive).
	 * @param string $note         Note (e.g. bank reference).
	 * @return int|\WP_Error Booking ID.
	 */
	public static function record_repayment( $affiliate_id, $currency, $amount_minor, $note ) {
		global $wpdb;
		$currency     = strtoupper( (string) $currency );
		$amount_minor = (int) $amount_minor;
		if ( $amount_minor <= 0 ) {
			return new \WP_Error( 'wpac_amount', __( 'Please enter the amount received.', 'affiliate-captain' ) );
		}
		$t = Db::table( 'commissions' );
		try {
			$id = Db::transaction(
				static function () use ( $wpdb, $t, $affiliate_id, $currency, $amount_minor, $note ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$open = (int) array_sum( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT amount_minor FROM {$t} WHERE affiliate_id = %d AND currency = %s AND status = 'approved' AND payout_id = 0 FOR UPDATE", $affiliate_id, $currency ) ) ) );
					if ( $open >= 0 || $amount_minor > -$open ) {
						throw new IntegrationException( 'amount', esc_html__( 'The amount is larger than the negative balance.', 'affiliate-captain' ) );
					}
					$key = Ledger::key( 'repayment:' . (int) $affiliate_id . ':' . $currency . ':' . Db::now() . ':' . wp_generate_password( 6, false ) );
					$ok  = $wpdb->insert(
						$t,
						array(
							'affiliate_id'    => (int) $affiliate_id,
							'source'          => 'wpac',
							'source_order_id' => 'repayment',
							'source_item_id'  => '',
							'kind'            => 'repayment',
							'parent_id'       => 0,
							'product_ref'     => '',
							'description'     => substr( sanitize_text_field( (string) $note ), 0, 255 ),
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
					return $id;
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		Audit::log( 'commission.repayment', 'affiliate', (int) $affiliate_id, array( 'amount' => $amount_minor, 'currency' => $currency ) );
		if ( self::open( (int) $affiliate_id, $currency ) >= 0 ) {
			self::reset( (int) $affiliate_id, $currency );
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
