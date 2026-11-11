<?php
/**
 * E-mail notifications.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	/**
	 * Admin recipient.
	 *
	 * @return string
	 */
	private static function admin_email() {
		$email = (string) Settings::get( 'admin_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * New application received.
	 *
	 * @param int $id Affiliate ID.
	 */
	public static function application_received( $id ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$url = admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id );
		wp_mail(
			self::admin_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] New affiliate application', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: name, 2: website, 3: admin URL */
			sprintf( __( "New affiliate application from %1\$s (%2\$s).\n\nReview: %3\$s", 'wp-affiliate-captain' ), $aff->name, $aff->website, $url )
		);
	}

	/**
	 * Application approved.
	 *
	 * @param int $id Affiliate ID.
	 */
	public static function application_approved( $id ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$portal = (int) Settings::get( 'portal_page_id' );
		$url    = $portal ? get_permalink( $portal ) : home_url( '/' );
		wp_mail(
			$aff->email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Your affiliate application was approved', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: name, 2: portal URL */
			sprintf( __( "Hello %1\$s,\n\nwelcome to our partner program! Your partner area with links, codes and statistics: %2\$s", 'wp-affiliate-captain' ), $aff->name, $url )
		);
	}

	/**
	 * Pause, termination or reactivation.
	 *
	 * @param int    $id     Affiliate ID.
	 * @param string $status New status.
	 * @param string $old    Previous status.
	 * @param string $reason Reason.
	 */
	public static function status_changed( $id, $status, $old, $reason ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		switch ( $status ) {
			case 'paused':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account has been paused', 'wp-affiliate-captain' ), $site );
				$body    = __( 'Your partner account has been paused. During the pause, new sales are not attributed to you. Commissions already earned remain unaffected.', 'wp-affiliate-captain' );
				break;
			case 'terminated':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partnership has been terminated', 'wp-affiliate-captain' ), $site );
				$body    = 'reject' === Settings::get( 'terminated_open' )
					? __( 'Your partnership has been terminated. Open commissions that were not yet paid have been cancelled according to the program terms.', 'wp-affiliate-captain' )
					: __( 'Your partnership has been terminated. Approved commissions will still be paid out according to the program terms.', 'wp-affiliate-captain' );
				break;
			default:
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account is active again', 'wp-affiliate-captain' ), $site );
				$body    = __( 'Your partner account has been reactivated. Your links and codes work again.', 'wp-affiliate-captain' );
		}
		/* translators: 1: name, 2: message, 3: reason */
		$message = sprintf( __( "Hello %1\$s,\n\n%2\$s\n\n%3\$s", 'wp-affiliate-captain' ), $aff->name, $body, $reason ? __( 'Reason', 'wp-affiliate-captain' ) . ': ' . $reason : '' );
		wp_mail( $aff->email, $subject, $message );
	}

	/**
	 * Application rejected.
	 *
	 * @param int    $id     Affiliate ID.
	 * @param string $reason Reason.
	 */
	public static function application_rejected( $id, $reason ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		wp_mail(
			$aff->email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Your affiliate application', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: name, 2: reason */
			sprintf( __( "Hello %1\$s,\n\nunfortunately we cannot accept your application at this time.\n\n%2\$s", 'wp-affiliate-captain' ), $aff->name, $reason )
		);
	}
}
