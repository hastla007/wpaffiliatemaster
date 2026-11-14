<?php
/**
 * Commissions that need review ("Needs review"): count for the admin menu bubble and the
 * dashboard, and an optional digest e-mail to the operator (R5-W58).
 *
 * The count is cached in a transient that is dropped whenever a commission is created or
 * changes its status, so the menu does not query the commissions table on every admin page.
 * The e-mail is a digest: commissions entering review only set a marker, the hourly cron sends
 * at most one mail per hour with the number waiting.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Commissions;

use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ReviewAlert {

	/** Transient: number of commissions with status "review". */
	const CACHE = 'wpac_review_count';

	/** Option: commissions entered review since the last digest ( since, count ). */
	const PENDING = 'wpac_review_alert';

	/** Option: time of the last digest e-mail. */
	const SENT = 'wpac_review_alert_sent';

	/** Minimum gap between two digest mails (a few minutes of slack for the hourly cron). */
	const GAP = 3300;

	public static function register_hooks() {
		add_action( 'wpac_commission_created', array( __CLASS__, 'created' ) );
		add_action( 'wpac_commission_review', array( __CLASS__, 'entered' ) );
		foreach ( array( 'pending', 'approved', 'rejected', 'paid' ) as $status ) {
			add_action( 'wpac_commission_' . $status, array( __CLASS__, 'flush' ) );
		}
		add_action( 'wpac_commission_adjusted', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Number of commissions waiting for review (cached).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return int
	 */
	public static function count( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}
		global $wpdb;
		$t = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'review'" );
		// Short lifetime as a safety net for changes that fire no hook (e.g. an erasure).
		set_transient( self::CACHE, $n, 10 * MINUTE_IN_SECONDS );
		return $n;
	}

	/**
	 * Count for the current user: only those who can open the commission list see it.
	 *
	 * @return int
	 */
	public static function count_for_user() {
		return current_user_can( Capabilities::MANAGE_AFFILIATES ) ? self::count() : 0;
	}

	/**
	 * Drop the cached count.
	 */
	public static function flush() {
		delete_transient( self::CACHE );
	}

	/**
	 * A commission was created: if it needs review, remember it for the digest.
	 *
	 * @param int $id Commission.
	 */
	public static function created( $id ) {
		self::flush();
		$c = Ledger::find( (int) $id );
		if ( $c && 'review' === $c->status ) {
			self::mark();
		}
	}

	/**
	 * A commission was moved to review.
	 */
	public static function entered() {
		self::flush();
		self::mark();
	}

	/**
	 * Remember for the digest (only while the e-mail is switched on).
	 */
	private static function mark() {
		if ( ! Settings::get( 'notify_admin_review' ) ) {
			return;
		}
		$p = get_option( self::PENDING );
		$p = is_array( $p ) ? $p : array(
			'since' => time(),
			'count' => 0,
		);
		$p['count'] = (int) $p['count'] + 1;
		update_option( self::PENDING, $p, false );
	}

	/**
	 * Hourly: send one digest e-mail if commissions entered review since the last one
	 * (at most one per hour, however many there were).
	 *
	 * @return bool Whether a mail was sent.
	 */
	public static function digest() {
		$p = get_option( self::PENDING );
		if ( ! is_array( $p ) ) {
			return false;
		}
		if ( ! Settings::get( 'notify_admin_review' ) ) {
			delete_option( self::PENDING );
			return false;
		}
		if ( (int) get_option( self::SENT, 0 ) > time() - self::GAP ) {
			return false; // Collected for the next run.
		}
		// Claim: only the run that removed the marker sends (parallel cron runs).
		if ( ! delete_option( self::PENDING ) ) {
			return false;
		}
		$total = self::count( true );
		if ( ! $total ) {
			return false; // Already handled.
		}
		update_option( self::SENT, time(), false );
		$url      = admin_url( 'admin.php?page=wpac-commissions&status=review' );
		$new      = max( 1, (int) $p['count'] );
		$switched = Notifications::switch_for_admin();
		try {
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			return Notifications::send(
				'review_digest',
				Notifications::admin_email(),
				/* translators: 1: site name, 2: number of commissions */
				sprintf( _n( '[%1$s] %2$d commission needs review', '[%1$s] %2$d commissions need review', $total, 'affiliate-captain' ), $site, $total ),
				/* translators: %d: number of commissions */
				sprintf( _n( '%d commission entered "Needs review" since the last notice.', '%d commissions entered "Needs review" since the last notice.', $new, 'affiliate-captain' ), $new ) . "\n"
				/* translators: %d: number of commissions */
				. sprintf( _n( 'In total %d commission is waiting for your decision. It is not approved automatically.', 'In total %d commissions are waiting for your decision. They are not approved automatically.', $total, 'affiliate-captain' ), $total ) . "\n\n"
				. __( 'Review now:', 'affiliate-captain' ) . "\n" . $url . "\n\n"
				. __( 'You receive at most one such e-mail per hour. Switch it off under Settings > E-mails.', 'affiliate-captain' ),
				array( 'admin' => true )
			);
		} finally {
			Locale::restore( $switched );
		}
	}
}
