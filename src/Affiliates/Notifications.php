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
	 * Hooks for partner notifications about money.
	 */
	public static function register_hooks() {
		add_action( 'wpac_payout_paid', array( __CLASS__, 'payout_paid' ) );
	}

	/**
	 * Payout transferred: amount, number and where to find the credit note.
	 *
	 * @param int $payout_id Payout.
	 */
	public static function payout_paid( $payout_id ) {
		/**
		 * Whether partners get an e-mail when a payout was transferred.
		 *
		 * @param bool $send Send.
		 */
		if ( ! apply_filters( 'wpac_notify_payout_paid', true ) ) {
			return;
		}
		$p   = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
		$aff = $p ? AffiliateRepository::find( (int) $p->affiliate_id ) : null;
		if ( ! $p || ! $aff || 0 === strpos( (string) $aff->email, 'deleted-' ) ) {
			return;
		}
		$portal = (int) Settings::get( 'portal_page_id' );
		wp_mail(
			$aff->email,
			/* translators: 1: site name, 2: payout number */
			sprintf( __( '[%1$s] Payout %2$s transferred', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
			/* translators: 1: name, 2: amount, 3: number, 4: portal URL */
			sprintf( __( "Hello %1\$s,\n\nwe have transferred %2\$s to your bank account (credit note %3\$s).\n\nYou can download the credit note in your partner area: %4\$s", 'wp-affiliate-captain' ), $aff->name, html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ), $p->number, $portal ? add_query_arg( 'wpac_tab', 'payouts', get_permalink( $portal ) ) : home_url( '/' ) )
		);
	}

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
	 * Double opt-in e-mail for an application.
	 *
	 * @param string $email E-mail.
	 * @param string $name  Name.
	 * @param string $link  Confirmation link.
	 */
	public static function confirm_application( $email, $name, $link ) {
		wp_mail(
			$email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Please confirm your partner application', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: name, 2: link */
			sprintf( __( "Hello %1\$s,\n\nplease confirm your e-mail address to complete your partner application:\n%2\$s\n\nThe link is valid for 48 hours. If you did not apply, you can ignore this e-mail.", 'wp-affiliate-captain' ), $name, $link )
		);
	}

	/**
	 * Someone applied with an address that already has a partner account.
	 *
	 * @param string $email E-mail.
	 */
	public static function already_registered( $email ) {
		$portal = (int) Settings::get( 'portal_page_id' );
		wp_mail(
			$email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Your partner account', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: %s: portal URL */
			sprintf( __( "Hello,\n\nsomeone (probably you) applied for our partner program with this e-mail address. You already have a partner account: %s\n\nIf this was not you, you can ignore this e-mail.", 'wp-affiliate-captain' ), $portal ? get_permalink( $portal ) : home_url( '/' ) )
		);
	}

	/**
	 * Bank details changed (sent to the partner and the operator).
	 *
	 * @param int $id Affiliate ID.
	 */
	public static function bank_details_changed( $id ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$to   = array_filter( array_unique( array( $aff->email, AffiliateRepository::previous_email( (int) $id ) ) ) );
		wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] Your bank details were changed', 'wp-affiliate-captain' ), $site ),
			/* translators: %s: name */
			sprintf( __( "Hello %s,\n\nthe bank details for your commission payouts were just changed. If you did not make this change, please contact us immediately.", 'wp-affiliate-captain' ), $aff->name )
		);
		wp_mail(
			self::admin_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] Partner changed bank details', 'wp-affiliate-captain' ), $site ),
			/* translators: 1: partner, 2: URL */
			sprintf( __( "%1\$s changed the bank details for payouts. Please check before the next payout run.\n\n%2\$s", 'wp-affiliate-captain' ), $aff->name, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id ) )
		);
	}

	/**
	 * A partner asked to change the public name/website.
	 *
	 * @param int $id Affiliate ID.
	 */
	public static function public_change_requested( $id ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		wp_mail(
			self::admin_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] Partner wants to change name or website', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: partner, 2: URL */
			sprintf( __( "%1\$s changed the public name or website. The change is shown only after you approve it:\n\n%2\$s", 'wp-affiliate-captain' ), $aff->name, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id ) )
		);
	}

	/**
	 * Decision on a name/website change.
	 *
	 * @param int  $id       Affiliate ID.
	 * @param bool $approved Approved.
	 */
	public static function public_change_resolved( $id, $approved ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		wp_mail(
			$aff->email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Your profile change', 'wp-affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$approved
				? __( "Hello,\n\nyour new name or website for the partner program was approved and is shown from now on.", 'wp-affiliate-captain' )
				: __( "Hello,\n\nyour requested change of name or website for the partner program was not approved. The previous details remain. If you have questions, please contact us.", 'wp-affiliate-captain' )
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
			/* translators: 1: name, 2: portal URL, 3: set-password URL */
			sprintf( __( "Hello %1\$s,\n\nwelcome to our partner program! Your partner area with links, codes and statistics: %2\$s\n\nNo password yet? Set one here: %3\$s", 'wp-affiliate-captain' ), $aff->name, $url, wp_lostpassword_url( $url ) )
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
