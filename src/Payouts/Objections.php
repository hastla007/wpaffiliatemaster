<?php
/**
 * Objections against credit notes (§14 Abs. 2 S. 3 UStG): the partner can object to a
 * self-billed credit note at any time; it then loses its effect as an invoice. The operator
 * cancels the payout (cancellation document) and issues a corrected credit note.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Objections {

	const OPTION = 'wpac_objections';

	/**
	 * All objections: payout ID => [ affiliate_id, at, reason, status, resolution, resolved_at ].
	 *
	 * @return array
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/** Lock of the list (read-modify-write). */
	const LOCK = 'wpac_objections_lock';

	/**
	 * The list as stored right now (not from the per-request cache).
	 *
	 * @return array
	 */
	private static function fresh() {
		wp_cache_delete( self::OPTION, 'options' );
		return self::all();
	}

	/**
	 * Daily: drop objections whose payout no longer exists and resolved ones that are past the
	 * retention period of the credit notes (the list must not grow forever).
	 *
	 * @return int Entries removed.
	 */
	public static function purge() {
		global $wpdb;
		if ( ! self::all() ) {
			return 0;
		}
		$cutoff = \WPAffiliateCaptain\Privacy\Retention::cutoff();
		$t      = Db::table( 'payouts' );
		return (int) Db::with_lock(
			self::LOCK,
			static function () use ( $wpdb, $t, $cutoff ) {
				$all = self::fresh();
				if ( ! $all ) {
					return 0;
				}
				$ids   = array_map( 'intval', array_keys( $all ) );
				$exist = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Integers only.
				$n     = 0;
				foreach ( $all as $id => $o ) {
					$resolved = isset( $o['status'] ) && 'resolved' === $o['status'];
					$date     = $resolved && ! empty( $o['resolved_at'] ) ? (string) $o['resolved_at'] : ( isset( $o['at'] ) ? (string) $o['at'] : '' );
					if ( ! in_array( (int) $id, $exist, true ) || ( $resolved && null !== $cutoff && '' !== $date && $date < $cutoff ) ) {
						unset( $all[ $id ] );
						++$n;
					}
				}
				if ( $n ) {
					update_option( self::OPTION, $all, false );
				}
				return $n;
			}
		);
	}

	/**
	 * Open objections.
	 *
	 * @return array
	 */
	public static function open() {
		return array_filter(
			self::all(),
			static function ( $o ) {
				return 'open' === $o['status'];
			}
		);
	}

	/**
	 * Objection of a payout, or null.
	 *
	 * @param int $payout_id Payout.
	 * @return array|null
	 */
	public static function for_payout( $payout_id ) {
		$all = self::all();
		return isset( $all[ (int) $payout_id ] ) ? $all[ (int) $payout_id ] : null;
	}

	/**
	 * The partner objects to a credit note.
	 *
	 * @param int    $payout_id    Payout.
	 * @param int    $affiliate_id Partner (must own the payout).
	 * @param string $reason       What is wrong.
	 * @return true|\WP_Error
	 */
	public static function file( $payout_id, $affiliate_id, $reason ) {
		$p      = PayoutService::find( (int) $payout_id );
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		if ( ! $p || (int) $p->affiliate_id !== (int) $affiliate_id || 'cancelled' === $p->status || '' === (string) $p->statement_file ) {
			return new \WP_Error( 'wpac_objection', __( 'This credit note cannot be objected to.', 'affiliate-captain' ) );
		}
		if ( '' === $reason ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please describe what is incorrect.', 'affiliate-captain' ) );
		}
		// Check and write under a lock: parallel objections or resolutions must not overwrite each other.
		try {
			$filed = Db::with_lock(
				self::LOCK,
				static function () use ( $payout_id, $affiliate_id, $reason ) {
					$all = self::fresh();
					if ( isset( $all[ (int) $payout_id ] ) && 'open' === $all[ (int) $payout_id ]['status'] ) {
						return 'open';
					}
					$all[ (int) $payout_id ] = array(
						'affiliate_id' => (int) $affiliate_id,
						'at'           => Db::now(),
						'reason'       => self::cut( $reason, 1000 ),
						'status'       => 'open',
						'resolution'   => '',
						'resolved_at'  => '',
					);
					// Success only when the objection was really stored: the partner relies on it.
					return update_option( self::OPTION, $all, false ) ? 'filed' : 'failed';
				}
			);
		} catch ( \Throwable $e ) {
			$filed = 'failed'; // Lock not available.
		}
		if ( 'open' === $filed ) {
			return new \WP_Error( 'wpac_objection_open', __( 'Your objection to this credit note is already being reviewed.', 'affiliate-captain' ) );
		}
		if ( 'filed' !== $filed ) {
			return new \WP_Error( 'wpac_objection_save', __( 'Your objection could not be saved. Please try again or send it to us by e-mail.', 'affiliate-captain' ) );
		}
		Audit::log( 'statement.objection', 'payout', (int) $payout_id );
		$aff   = AffiliateRepository::find( (int) $affiliate_id );
		$admin = (string) Settings::get( 'admin_email' );
		wp_mail(
			is_email( $admin ) ? $admin : (string) get_option( 'admin_email' ),
			/* translators: %s: credit note number */
			sprintf( __( 'Objection to credit note %s', 'affiliate-captain' ), $p->number ),
			/* translators: 1: partner, 2: number, 3: reason, 4: URL */
			sprintf( __( "%1\$s objected to credit note %2\$s:\n\n%3\$s\n\nThe credit note no longer counts as an invoice. Please check it, cancel the payout if needed and issue a corrected credit note:\n%4\$s", 'affiliate-captain' ), $aff ? $aff->name : '#' . (int) $affiliate_id, $p->number, $reason, admin_url( 'admin.php?page=wpac-payouts' ) )
		);
		return true;
	}

	/**
	 * What the operator can do about an objection, depending on the payout's state. A paid
	 * payout cannot be cancelled (the money went out): the correction runs outside the payout.
	 *
	 * @param object|null $payout Payout.
	 * @return string
	 */
	public static function guidance( $payout ) {
		if ( $payout && 'paid' === $payout->status ) {
			return __( 'The credit note no longer counts as an invoice. This payout is already paid, so it cannot be cancelled here. If the objection is justified, agree the correction with the partner – the partner issues their own invoice for the correct amount, or you settle the difference by a separate transfer or repayment request – and keep the documents with the credit note. If the credit note is correct, explain why. Then describe below what was done; the partner receives this text.', 'affiliate-captain' );
		}
		if ( $payout && 'cancelled' === $payout->status ) {
			return __( 'The payout was already cancelled (cancellation document issued); its commissions are paid with the next run and a new credit note. Describe below what was done; the partner receives this text.', 'affiliate-captain' );
		}
		return __( 'The credit note no longer counts as an invoice. Cancel the payout (cancellation document) and create a corrected one, or explain why it is correct.', 'affiliate-captain' );
	}

	/**
	 * Shorten text to a number of characters without cutting a multi-byte character in half
	 * (the database rejects invalid UTF-8, and the text would be lost).
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function cut( $text, $max ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, (int) $max, 'UTF-8' );
		}
		return (string) preg_replace( '/^(.{0,' . (int) $max . '}).*$/us', '$1', $text );
	}

	/**
	 * Mark an objection as handled (e.g. payout cancelled and reissued, or explained).
	 *
	 * @param int    $payout_id  Payout.
	 * @param string $resolution What was done (shown to the partner).
	 * @return bool
	 */
	public static function resolve( $payout_id, $resolution ) {
		$resolution = self::cut( sanitize_textarea_field( (string) $resolution ), 1000 );
		try {
			$all = Db::with_lock(
				self::LOCK,
				static function () use ( $payout_id, $resolution ) {
					$all = self::fresh();
					if ( ! isset( $all[ (int) $payout_id ] ) || 'open' !== $all[ (int) $payout_id ]['status'] ) {
						return null;
					}
					$all[ (int) $payout_id ]['status']      = 'resolved';
					$all[ (int) $payout_id ]['resolution']  = $resolution;
					$all[ (int) $payout_id ]['resolved_at'] = Db::now();
					// Not stored: the objection stays open and the partner is not told it was handled.
					return update_option( self::OPTION, $all, false ) ? $all : null;
				}
			);
		} catch ( \Throwable $e ) {
			$all = null; // Lock not available.
		}
		if ( null === $all ) {
			return false;
		}
		Audit::log( 'statement.objection_resolved', 'payout', (int) $payout_id );
		$aff = AffiliateRepository::find( (int) $all[ (int) $payout_id ]['affiliate_id'] );
		$p   = PayoutService::find( (int) $payout_id );
		if ( $aff && $p ) {
			$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff );
			wp_mail(
				$aff->email,
				/* translators: %s: credit note number */
				sprintf( __( 'Your objection to credit note %s', 'affiliate-captain' ), $p->number ),
				/* translators: %s: resolution */
				sprintf( __( "Hello,\n\nwe have reviewed your objection:\n\n%s", 'affiliate-captain' ), $all[ (int) $payout_id ]['resolution'] )
			);
			\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		}
		return true;
	}
}
