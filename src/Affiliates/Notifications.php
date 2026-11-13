<?php
/**
 * E-mail notifications.
 *
 * Mails to partners are written in the partner's language (WordPress user locale of
 * the linked account, otherwise the site language), see Locale.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	/**
	 * Hooks for partner notifications about money.
	 */
	public static function register_hooks() {
		add_action( 'wpac_payout_paid', array( __CLASS__, 'payout_paid' ) );
		add_action( 'wpac_statement_generated', array( __CLASS__, 'credit_note_issued' ), 10, 2 );
		add_action( 'wpac_storno_generated', array( __CLASS__, 'credit_note_cancelled' ), 10, 2 );
	}

	/**
	 * A credit note was issued: send it to the partner. Self-billing only takes effect once the
	 * partner received the credit note (§14 Abs. 2 UStG), and an objection needs it.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $path      Stored PDF.
	 */
	public static function credit_note_issued( $payout_id, $path = '' ) {
		/**
		 * Whether partners get the credit note by e-mail when it is issued.
		 *
		 * @param bool $send      Send.
		 * @param int  $payout_id Payout.
		 */
		if ( ! apply_filters( 'wpac_notify_credit_note', true, (int) $payout_id ) ) {
			return;
		}
		$p   = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
		$aff = $p ? AffiliateRepository::find( (int) $p->affiliate_id ) : null;
		if ( ! $p || ! $aff || ! is_email( (string) $aff->email ) || 0 === strpos( (string) $aff->email, 'deleted-' ) ) {
			return;
		}
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			$sent = wp_mail(
				$aff->email,
				/* translators: 1: site name, 2: document number */
				sprintf( __( '[%1$s] Credit note %2$s', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
				/* translators: 1: name, 2: document number, 3: amount, 4: portal URL */
				sprintf( __( "Hello %1\$s,\n\nwe have issued credit note %2\$s for your commissions (total %3\$s). It is attached to this e-mail and can be downloaded in your partner area: %4\$s\n\nPlease check it. If something is wrong, you can object to it in the partner area.", 'affiliate-captain' ), $aff->name, $p->number, html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ), add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) ),
				'',
				'' !== (string) $path && is_readable( (string) $path ) ? array( (string) $path ) : array()
			);
		} finally {
			Locale::restore( $switched );
		}
		\WPAffiliateCaptain\Core\Audit::log( $sent ? 'statement.sent' : 'statement.send_failed', 'payout', (int) $payout_id );
	}

	/**
	 * A credit note was cancelled: send the cancellation document to the partner.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $path      Stored PDF.
	 */
	public static function credit_note_cancelled( $payout_id, $path = '' ) {
		/** This filter is documented in Notifications::credit_note_issued(). */
		if ( ! apply_filters( 'wpac_notify_credit_note', true, (int) $payout_id ) ) {
			return;
		}
		$p   = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
		$aff = $p ? AffiliateRepository::find( (int) $p->affiliate_id ) : null;
		if ( ! $p || ! $aff || ! is_email( (string) $aff->email ) || 0 === strpos( (string) $aff->email, 'deleted-' ) ) {
			return;
		}
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			$sent = wp_mail(
				$aff->email,
				/* translators: 1: site name, 2: cancelled credit note number */
				sprintf( __( '[%1$s] Credit note %2$s cancelled', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
				/* translators: 1: name, 2: credit note number, 3: cancellation number, 4: portal URL */
				sprintf( __( "Hello %1\$s,\n\nwe have cancelled credit note %2\$s with the attached cancellation document %3\$s. The commissions remain in your account and are paid out with a new credit note.\n\nPartner area: %4\$s", 'affiliate-captain' ), $aff->name, $p->number, (string) $p->storno_number, add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) ),
				'',
				'' !== (string) $path && is_readable( (string) $path ) ? array( (string) $path ) : array()
			);
		} finally {
			Locale::restore( $switched );
		}
		\WPAffiliateCaptain\Core\Audit::log( $sent ? 'statement.storno_sent' : 'statement.send_failed', 'payout', (int) $payout_id );
	}

	/**
	 * The tax data of a partner changed (or a change waits for approval): tell the admin, with
	 * the old and new values (a new VAT status changes the amounts on credit notes).
	 *
	 * @param int   $affiliate_id Partner.
	 * @param array $old          Old tax fields.
	 * @param array $new          New tax fields.
	 * @param bool  $pending      Waits for approval.
	 */
	public static function tax_data_changed( $affiliate_id, array $old, array $new, $pending ) {
		$aff = AffiliateRepository::find( (int) $affiliate_id );
		if ( ! $aff ) {
			return;
		}
		$labels = \WPAffiliateCaptain\Payouts\ProfileRepository::tax_status_labels();
		$names  = array(
			'tax_status' => __( 'Tax status', 'affiliate-captain' ),
			'country'    => __( 'Country', 'affiliate-captain' ),
			'vat_id'     => __( 'VAT ID', 'affiliate-captain' ),
			'tax_number' => __( 'Tax number', 'affiliate-captain' ),
		);
		$lines = array();
		foreach ( $names as $key => $label ) {
			$o = isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
			$n = isset( $new[ $key ] ) ? (string) $new[ $key ] : '';
			if ( $o === $n ) {
				continue;
			}
			if ( 'tax_status' === $key ) {
				$o = isset( $labels[ $o ] ) ? $labels[ $o ] : $o;
				$n = isset( $labels[ $n ] ) ? $labels[ $n ] : $n;
			}
			$lines[] = $label . ': ' . ( '' !== $o ? $o : '—' ) . ' → ' . ( '' !== $n ? $n : '—' );
		}
		$url = admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $affiliate_id . '&tab=payout' );
		wp_mail(
			self::admin_email(),
			$pending
				/* translators: 1: site name, 2: partner */
				? sprintf( __( '[%1$s] Tax data change of %2$s waits for your approval', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $aff->name )
				/* translators: 1: site name, 2: partner */
				: sprintf( __( '[%1$s] Tax data of %2$s changed', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $aff->name ),
			implode( "\n", $lines ) . "\n\n" . ( $pending
				? __( 'The change affects the VAT on credit notes. It only takes effect after you approved it (commissions earned before keep the previous treatment). Please check the details (e.g. the VAT ID with VIES):', 'affiliate-captain' )
				: __( 'The change applies to commissions earned from now on. Details:', 'affiliate-captain' ) ) . "\n" . $url
		);
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
		if ( ! $p || ! $aff || 0 === strpos( (string) $aff->email, 'deleted-' ) || (int) $p->amount_minor <= 0 ) {
			return; // Nothing transferred (e.g. a correction document settling a balance).
		}
		$portal   = (int) Settings::get( 'portal_page_id' );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$aff->email,
				/* translators: 1: site name, 2: payout number */
				sprintf( __( '[%1$s] Payout %2$s transferred', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
				/* translators: 1: name, 2: amount, 3: number, 4: portal URL */
				sprintf( __( "Hello %1\$s,\n\nwe have transferred %2\$s to your bank account (credit note %3\$s).\n\nYou can download the credit note in your partner area: %4\$s", 'affiliate-captain' ), $aff->name, html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ), $p->number, $portal ? add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) : home_url( '/' ) )
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * Partner area URL in the reader's language (translated page with WPML/Polylang).
	 *
	 * @param object|null $aff Affiliate (null = current language).
	 * @return string
	 */
	public static function portal_url( $aff = null ) {
		return Locale::page_url( (int) Settings::get( 'portal_page_id' ), $aff ? Locale::for_affiliate( $aff ) : '' );
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
			sprintf( __( '[%s] New affiliate application', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: name, 2: website, 3: admin URL */
			sprintf( __( "New affiliate application from %1\$s (%2\$s).\n\nReview: %3\$s", 'affiliate-captain' ), $aff->name, $aff->website, $url )
		);
	}

	/**
	 * Double opt-in e-mail for an application. It contains no text entered in the form
	 * (name, note …): whoever fills in the form chooses the recipient, so such text would
	 * turn the shop's mail server into a relay for phishing messages.
	 *
	 * @param string $email E-mail.
	 * @param string $link  Confirmation link.
	 */
	public static function confirm_application( $email, $link ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			$email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Please confirm your partner application', 'affiliate-captain' ), $site ),
			/* translators: 1: site name, 2: link */
			sprintf( __( "Hello,\n\nsomeone (probably you) applied for the partner program of %1\$s with this e-mail address. Please confirm your e-mail address to complete the application:\n%2\$s\n\nThe link is valid for 48 hours. If you did not apply, you can ignore this e-mail.", 'affiliate-captain' ), $site, $link )
		);
	}

	/**
	 * Someone applied with an address that already has a partner account.
	 *
	 * @param string $email E-mail.
	 */
	public static function already_registered( $email ) {
		$aff      = AffiliateRepository::find_by_email( $email );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your partner account', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: %s: portal URL */
				sprintf( __( "Hello,\n\nsomeone (probably you) applied for our partner program with this e-mail address. You already have a partner account: %s\n\nIf this was not you, you can ignore this e-mail.", 'affiliate-captain' ), self::portal_url( $aff ) )
			);
		} finally {
			Locale::restore( $switched );
		}
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
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$to       = array_filter( array_unique( array( $aff->email, AffiliateRepository::previous_email( (int) $id ) ) ) );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$to,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your bank details were changed', 'affiliate-captain' ), $site ),
				/* translators: %s: name */
				sprintf( __( "Hello %s,\n\nthe bank details for your commission payouts were just changed. If you did not make this change, please contact us immediately.", 'affiliate-captain' ), $aff->name )
			);
		} finally {
			Locale::restore( $switched );
		}
		wp_mail(
			self::admin_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] Partner changed bank details', 'affiliate-captain' ), $site ),
			/* translators: 1: partner, 2: URL */
			sprintf( __( "%1\$s changed the bank details for payouts. Please check before the next payout run.\n\n%2\$s", 'affiliate-captain' ), $aff->name, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id ) )
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
			sprintf( __( '[%s] Partner wants to change name or website', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			/* translators: 1: partner, 2: URL */
			sprintf( __( "%1\$s changed the public name or website. The change is shown only after you approve it:\n\n%2\$s", 'affiliate-captain' ), $aff->name, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id ) )
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
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your profile change', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				$approved
					? __( "Hello,\n\nyour new name or website for the partner program was approved and is shown from now on.", 'affiliate-captain' )
					: __( "Hello,\n\nyour requested change of name or website for the partner program was not approved. The previous details remain. If you have questions, please contact us.", 'affiliate-captain' )
			);
		} finally {
			Locale::restore( $switched );
		}
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
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your affiliate application was approved', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: name, 2: access instructions */
				sprintf( __( "Hello %1\$s,\n\nwelcome to our partner program!\n\n%2\$s", 'affiliate-captain' ), $aff->name, self::access_text( $aff ) )
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * Welcome mail for a partner the operator created in the admin (with the portal link and,
	 * for a new account, the link to set a password – no separate WordPress login mail).
	 *
	 * @param int $id Affiliate ID.
	 */
	public static function partner_created( $id ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff || 'active' !== $aff->status ) {
			return;
		}
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Welcome to our partner program', 'affiliate-captain' ), $site ),
				/* translators: 1: name, 2: site name, 3: access instructions */
				sprintf( __( "Hello %1\$s,\n\n%2\$s has set up a partner account for you.\n\n%3\$s", 'affiliate-captain' ), $aff->name, $site, self::access_text( $aff ) )
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * How the partner gets into the partner area: portal link, set-password link for a new
	 * account, a hint on the separate link mail for an existing customer account, and the terms.
	 *
	 * @param object $aff Affiliate.
	 * @return string
	 */
	private static function access_text( $aff ) {
		$url = self::portal_url( $aff );
		/* translators: %s: portal URL */
		$lines = array( sprintf( __( 'Your partner area with links, codes and statistics: %s', 'affiliate-captain' ), $url ) );
		$set   = AffiliateRepository::set_password_url( (int) $aff->id );
		if ( '' !== $set ) {
			/* translators: %s: URL */
			$lines[] = sprintf( __( 'We have created an account for you. Choose your password here: %s', 'affiliate-captain' ), $set );
		} elseif ( ! (int) $aff->user_id && AffiliateRepository::user_link_pending( (int) $aff->id ) ) {
			$lines[] = __( 'You already have a customer account with this e-mail address: we have sent you a separate e-mail with a link to connect it to the partner program.', 'affiliate-captain' );
		} else {
			/* translators: %s: URL */
			$lines[] = sprintf( __( 'No password yet? Set one here: %s', 'affiliate-captain' ), wp_lostpassword_url( $url ) );
		}
		$terms = Agreements::current( (int) $aff->group_id );
		if ( $terms['page_id'] && ! Agreements::has_current( (int) $aff->id ) ) {
			$lines[] = __( 'On your first visit, please read and accept the partner program terms – we need them to pay out your commissions.', 'affiliate-captain' );
		}
		return implode( "\n\n", $lines );
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
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			self::send_status_changed( $aff, $status, $reason );
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * Status mail in the current locale.
	 *
	 * @param object $aff    Affiliate.
	 * @param string $status New status.
	 * @param string $reason Reason.
	 */
	private static function send_status_changed( $aff, $status, $reason ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		switch ( $status ) {
			case 'paused':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account has been paused', 'affiliate-captain' ), $site );
				$body    = __( 'Your partner account has been paused. During the pause, new sales are not attributed to you. Commissions already earned remain unaffected.', 'affiliate-captain' );
				break;
			case 'terminated':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partnership has been terminated', 'affiliate-captain' ), $site );
				$body    = 'reject' === Settings::get( 'terminated_open' )
					? __( 'Your partnership has been terminated. Open commissions that were not yet paid have been cancelled according to the program terms.', 'affiliate-captain' )
					: __( 'Your partnership has been terminated. Approved commissions will still be paid out according to the program terms.', 'affiliate-captain' );
				break;
			default:
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account is active again', 'affiliate-captain' ), $site );
				$body    = __( 'Your partner account has been reactivated. Your links and codes work again.', 'affiliate-captain' );
		}
		/* translators: 1: name, 2: message, 3: reason */
		$message = sprintf( __( "Hello %1\$s,\n\n%2\$s\n\n%3\$s", 'affiliate-captain' ), $aff->name, $body, $reason ? __( 'Reason', 'affiliate-captain' ) . ': ' . $reason : '' );
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
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			wp_mail(
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your affiliate application', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: name, 2: reason */
				sprintf( __( "Hello %1\$s,\n\nunfortunately we cannot accept your application at this time.\n\n%2\$s", 'affiliate-captain' ), $aff->name, $reason )
			);
		} finally {
			Locale::restore( $switched );
		}
	}
}
