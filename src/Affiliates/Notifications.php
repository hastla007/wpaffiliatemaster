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

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Locale;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	/** Option: per partner the mail types they unsubscribed from (affiliate ID => types). */
	const OPTOUT_OPTION = 'wpac_mail_optout';

	/** Mail types a partner can unsubscribe from (everything else is needed for the partnership). */
	const OPTOUT_TYPES = array( 'monthly_partner', 'commission', 'broadcast' );

	/** Cron hook of the broadcast mail batches. */
	const BROADCAST_HOOK = 'wpac_broadcast_batch';

	/** Option: state of the running broadcast mail. */
	const BROADCAST_OPTION = 'wpac_broadcast';

	/** Partners per broadcast batch. */
	const BROADCAST_BATCH = 50;

	/**
	 * Option (not autoloaded): e-mail templates edited in the admin and the layout,
	 * array( 'layout' => 'text'|'html', 'templates' => array( key => array( subject, body, updated ) ) ).
	 */
	const TEMPLATES_OPTION = 'wpac_mail_templates';

	/** Maximum length of a template subject and body. */
	const TEMPLATE_SUBJECT_MAX = 190;
	const TEMPLATE_BODY_MAX    = 20000;

	/** @var string Plain-text alternative of the HTML mail being sent ('' = none). */
	private static $alt_body = '';

	/** @var bool Whether the wp_mail hooks of send() are registered. */
	private static $hooked = false;

	/** @var bool Whether one of our mails is being handed to wp_mail() right now. */
	private static $sending = false;

	/** @var string Sender name for the mail being sent ('' = WordPress default). */
	private static $from_name = '';

	/** @var \WP_Error|null Error reported by wp_mail_failed for the mail being sent. */
	private static $last_error = null;

	/** @var array Commission events of this request: affiliate ID => event => commission IDs. */
	private static $commission_events = array();

	/** @var int Nesting depth of suppress(true). */
	private static $suppressed = 0;

	/**
	 * Suppress (true) or allow again (false) every mail of the plugin in this request, nestable.
	 * send() returns false meanwhile, so nothing is recorded as sent.
	 *
	 * @param bool $on Suppress.
	 */
	public static function suppress( $on ) {
		self::$suppressed = max( 0, self::$suppressed + ( $on ? 1 : -1 ) );
	}

	/**
	 * Whether mails are suppressed right now.
	 *
	 * @return bool
	 */
	public static function suppressed() {
		return self::$suppressed > 0;
	}

	/**
	 * Hooks for partner notifications about money.
	 */
	public static function register_hooks() {
		add_action( 'wpac_payout_paid', array( __CLASS__, 'payout_paid' ) );
		add_action( 'wpac_commission_created', array( __CLASS__, 'commission_created' ) );
		add_action( 'wpac_commission_approved', array( __CLASS__, 'commission_approved' ) );
		add_action( 'wpac_commission_rejected', array( __CLASS__, 'commission_rejected' ) );
		add_action( 'init', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'wpac_statement_generated', array( __CLASS__, 'credit_note_issued' ), 10, 2 );
		add_action( 'wpac_storno_generated', array( __CLASS__, 'credit_note_cancelled' ), 10, 2 );
		add_action( 'wpac_correction_generated', array( __CLASS__, 'credit_note_corrected' ), 10, 2 );
		add_action( self::BROADCAST_HOOK, array( __CLASS__, 'broadcast_batch' ), 10, 0 );
	}

	/**
	 * Labels of the mail types a partner can switch off (partner area, "My account").
	 *
	 * @return array type => label
	 */
	public static function optout_labels() {
		return array(
			'commission'      => __( 'New, approved and rejected commissions', 'affiliate-captain' ),
			'monthly_partner' => __( 'Monthly summary', 'affiliate-captain' ),
			'broadcast'       => __( 'News and announcements of the partner program', 'affiliate-captain' ),
		);
	}

	/**
	 * E-mail templates the operator can edit in the admin (R14-P1): template key => type (mail
	 * type passed to send() and the wpac_mail filter), label and the placeholders it offers.
	 * Several templates can share one mail type (e.g. the variants of the status mail).
	 * Mails that would carry text entered by a stranger (confirm_application, already_registered)
	 * offer no placeholder with such text.
	 *
	 * @return array key => array( type, label, vars )
	 */
	public static function template_defs() {
		$welcome = array( 'name', 'site', 'link', 'code', 'rate', 'portal_url', 'access' );
		return array(
			'confirm_application'    => array(
				'type'  => 'confirm_application',
				'label' => __( 'Application: confirm e-mail address (double opt-in)', 'affiliate-captain' ),
				'vars'  => array( 'site', 'link' ),
			),
			'application_confirmed'  => array(
				'type'  => 'application_confirmed',
				'label' => __( 'Application received', 'affiliate-captain' ),
				'vars'  => array( 'site' ),
			),
			'application_approved'   => array(
				'type'  => 'application_approved',
				'label' => __( 'Application approved (welcome)', 'affiliate-captain' ),
				'vars'  => $welcome,
			),
			'partner_created'        => array(
				'type'  => 'partner_created',
				'label' => __( 'Partner created in the admin (welcome)', 'affiliate-captain' ),
				'vars'  => $welcome,
			),
			'application_rejected'   => array(
				'type'  => 'application_rejected',
				'label' => __( 'Application rejected', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'reason' ),
			),
			'already_registered'     => array(
				'type'  => 'already_registered',
				'label' => __( 'Application with an address that already has a partner account', 'affiliate-captain' ),
				'vars'  => array( 'site', 'portal_url' ),
			),
			'commission'             => array(
				'type'  => 'commission',
				'label' => __( 'News about commissions', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'events', 'portal_url' ),
			),
			'payout_paid'            => array(
				'type'  => 'payout_paid',
				'label' => __( 'Payout transferred (bank transfer)', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'amount', 'number', 'portal_url' ),
			),
			'payout_paid_manual'     => array(
				'type'  => 'payout_paid',
				'label' => __( 'Payout paid (other payment method)', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'amount', 'number', 'portal_url' ),
			),
			'credit_note'            => array(
				'type'  => 'credit_note',
				'label' => __( 'Credit note issued', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'number', 'amount', 'portal_url' ),
			),
			'credit_note_cancelled'  => array(
				'type'  => 'credit_note_cancelled',
				'label' => __( 'Credit note cancelled (Storno)', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'number', 'storno_number', 'portal_url' ),
			),
			'credit_note_corrected'  => array(
				'type'  => 'credit_note_corrected',
				'label' => __( 'Correction credit note issued', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'number', 'correction_number', 'effect', 'portal_url' ),
			),
			'bank_details_changed'   => array(
				'type'  => 'bank_details_changed',
				'label' => __( 'Bank details changed (to the partner)', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site' ),
			),
			'public_change_approved' => array(
				'type'  => 'public_change_resolved',
				'label' => __( 'Name or website change approved', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site' ),
			),
			'public_change_rejected' => array(
				'type'  => 'public_change_resolved',
				'label' => __( 'Name or website change not approved', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site' ),
			),
			// Code requests from the partner area (since 0.15.0).
			'code_request_admin'     => array(
				'type'  => 'code_requested',
				'label' => __( 'Partner requested an own code (to you)', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'code', 'admin_url' ),
			),
			'code_request_approved'  => array(
				'type'  => 'code_request_resolved',
				'label' => __( 'Requested code approved', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'code', 'link', 'portal_url' ),
			),
			'code_request_rejected'  => array(
				'type'  => 'code_request_resolved',
				'label' => __( 'Requested code not approved', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'code', 'reason', 'portal_url' ),
			),
			'status_paused'          => array(
				'type'  => 'status_changed',
				'label' => __( 'Partner account paused', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'reason' ),
			),
			'status_terminated'      => array(
				'type'  => 'status_changed',
				'label' => __( 'Partnership terminated', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'reason' ),
			),
			'status_active'          => array(
				'type'  => 'status_changed',
				'label' => __( 'Partner account active again', 'affiliate-captain' ),
				'vars'  => array( 'name', 'site', 'reason' ),
			),
		);
	}

	/**
	 * What the placeholders stand for (admin template editor).
	 *
	 * @param string $key Template key (some placeholders mean something else per template).
	 * @return array placeholder => description
	 */
	public static function placeholder_labels( $key = '' ) {
		return array(
			'name'              => __( 'Name of the partner', 'affiliate-captain' ),
			'site'              => __( 'Name of your website', 'affiliate-captain' ),
			'link'              => 'confirm_application' === $key ? __( 'Confirmation link', 'affiliate-captain' ) : ( 'code_request_approved' === $key ? __( 'Partner link with the new code', 'affiliate-captain' ) : __( 'Partner link (start page with the partner code)', 'affiliate-captain' ) ),
			'code'              => 0 === strpos( (string) $key, 'code_request' ) ? __( 'Requested code', 'affiliate-captain' ) : __( 'Partner code', 'affiliate-captain' ),
			'admin_url'         => __( 'Address of the list of open requests in the admin', 'affiliate-captain' ),
			'rate'              => __( 'Commission rate', 'affiliate-captain' ),
			'portal_url'        => __( 'Address of the partner area', 'affiliate-captain' ),
			'access'            => __( 'Access to the partner area (set-password link, hint on the terms)', 'affiliate-captain' ),
			'reason'            => 0 === strpos( (string) $key, 'status_' ) ? __( 'Line "Reason: …" (empty without a reason)', 'affiliate-captain' ) : __( 'Reason entered by your team', 'affiliate-captain' ),
			'amount'            => __( 'Amount', 'affiliate-captain' ),
			'number'            => __( 'Credit note number', 'affiliate-captain' ),
			'storno_number'     => __( 'Number of the cancellation document', 'affiliate-captain' ),
			'correction_number' => __( 'Number of the correction credit note', 'affiliate-captain' ),
			'effect'            => __( 'What happens with the difference', 'affiliate-captain' ),
			'events'            => __( 'List of new, approved and rejected commissions', 'affiliate-captain' ),
		);
	}

	/**
	 * Default subject and body of a template (the built-in texts in the current language, with
	 * placeholders instead of the values).
	 *
	 * @param string $key Template key.
	 * @return array|null array( subject, body ) or null for an unknown key.
	 */
	public static function default_template( $key ) {
		// Partner link, code and rate in the welcome mails: the shortest way to the first share.
		/* translators: 1: partner link, 2: partner code, 3: commission rate */
		$share = sprintf( __( "Your partner link: %1\$s\nYour partner code: %2\$s (customers can also enter it at checkout)\nYour commission: %3\$s", 'affiliate-captain' ), '{link}', '{code}', '{rate}' );
		switch ( (string) $key ) {
			case 'confirm_application':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Please confirm your partner application', 'affiliate-captain' ), '{site}' );
				/* translators: 1: site name, 2: link */
				$body = sprintf( __( "Hello,\n\nsomeone (probably you) applied for the partner program of %1\$s with this e-mail address. Please confirm your e-mail address to complete the application:\n%2\$s\n\nThe link is valid for 48 hours. If you did not apply, you can ignore this e-mail.", 'affiliate-captain' ), '{site}', '{link}' );
				break;
			case 'application_confirmed':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] We have received your partner application', 'affiliate-captain' ), '{site}' );
				/* translators: %s: site name */
				$body = sprintf( __( "Hello,\n\nthank you for your application to the partner program of %s. We will review it and let you know by e-mail as soon as we have decided.", 'affiliate-captain' ), '{site}' );
				break;
			case 'application_approved':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your affiliate application was approved', 'affiliate-captain' ), '{site}' );
				/* translators: 1: name, 2: access instructions */
				$body = sprintf( __( "Hello %1\$s,\n\nwelcome to our partner program!\n\n%2\$s", 'affiliate-captain' ), '{name}', $share . "\n\n{access}" );
				break;
			case 'partner_created':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Welcome to our partner program', 'affiliate-captain' ), '{site}' );
				/* translators: 1: name, 2: site name, 3: access instructions */
				$body = sprintf( __( "Hello %1\$s,\n\n%2\$s has set up a partner account for you.\n\n%3\$s", 'affiliate-captain' ), '{name}', '{site}', $share . "\n\n{access}" );
				break;
			case 'application_rejected':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your affiliate application', 'affiliate-captain' ), '{site}' );
				/* translators: 1: name, 2: reason */
				$body = sprintf( __( "Hello %1\$s,\n\nunfortunately we cannot accept your application at this time.\n\n%2\$s", 'affiliate-captain' ), '{name}', '{reason}' );
				break;
			case 'already_registered':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account', 'affiliate-captain' ), '{site}' );
				/* translators: %s: portal URL */
				$body = sprintf( __( "Hello,\n\nsomeone (probably you) applied for our partner program with this e-mail address. You already have a partner account: %s\n\nIf this was not you, you can ignore this e-mail.", 'affiliate-captain' ), '{portal_url}' );
				break;
			case 'commission':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] News about your commissions', 'affiliate-captain' ), '{site}' );
				/* translators: 1: name, 2: list of events, 3: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nthere is news about your commissions:\n\n%2\$s\n\nDetails are in your partner area: %3\$s", 'affiliate-captain' ), '{name}', '{events}', '{portal_url}' );
				break;
			case 'payout_paid':
				/* translators: 1: site name, 2: payout number */
				$subject = sprintf( __( '[%1$s] Payout %2$s transferred', 'affiliate-captain' ), '{site}', '{number}' );
				/* translators: 1: name, 2: amount, 3: number, 4: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nwe have transferred %2\$s to your bank account (credit note %3\$s).\n\nYou can download the credit note in your partner area: %4\$s", 'affiliate-captain' ), '{name}', '{amount}', '{number}', '{portal_url}' );
				break;
			case 'payout_paid_manual':
				/* translators: 1: site name, 2: payout number */
				$subject = sprintf( __( '[%1$s] Payout %2$s paid', 'affiliate-captain' ), '{site}', '{number}' );
				/* translators: 1: name, 2: amount, 3: number, 4: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nwe have paid out %2\$s to you with the payment method agreed with you (credit note %3\$s).\n\nYou can download the credit note in your partner area: %4\$s", 'affiliate-captain' ), '{name}', '{amount}', '{number}', '{portal_url}' );
				break;
			case 'credit_note':
				/* translators: 1: site name, 2: document number */
				$subject = sprintf( __( '[%1$s] Credit note %2$s', 'affiliate-captain' ), '{site}', '{number}' );
				/* translators: 1: name, 2: document number, 3: amount, 4: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nwe have issued credit note %2\$s for your commissions (total %3\$s). It is attached to this e-mail and can be downloaded in your partner area: %4\$s\n\nPlease check it. If something is wrong, you can object to it in the partner area.", 'affiliate-captain' ), '{name}', '{number}', '{amount}', '{portal_url}' );
				break;
			case 'credit_note_cancelled':
				/* translators: 1: site name, 2: cancelled credit note number */
				$subject = sprintf( __( '[%1$s] Credit note %2$s cancelled', 'affiliate-captain' ), '{site}', '{number}' );
				/* translators: 1: name, 2: credit note number, 3: cancellation number, 4: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nwe have cancelled credit note %2\$s with the attached cancellation document %3\$s. The commissions remain in your account and are paid out with a new credit note.\n\nPartner area: %4\$s", 'affiliate-captain' ), '{name}', '{number}', '{storno_number}', '{portal_url}' );
				break;
			case 'credit_note_corrected':
				/* translators: 1: site name, 2: correction number, 3: credit note number */
				$subject = sprintf( __( '[%1$s] Correction credit note %2$s for credit note %3$s', 'affiliate-captain' ), '{site}', '{correction_number}', '{number}' );
				/* translators: 1: name, 2: credit note number, 3: correction number, 4: what happens with the difference, 5: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nwe have corrected credit note %2\$s with the attached correction credit note %3\$s. %4\$s\n\nPartner area: %5\$s", 'affiliate-captain' ), '{name}', '{number}', '{correction_number}', '{effect}', '{portal_url}' );
				break;
			case 'bank_details_changed':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your bank details were changed', 'affiliate-captain' ), '{site}' );
				/* translators: %s: name */
				$body = sprintf( __( "Hello %s,\n\nthe bank details for your commission payouts were just changed. If you did not make this change, please contact us immediately.", 'affiliate-captain' ), '{name}' );
				break;
			case 'public_change_approved':
			case 'public_change_rejected':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your profile change', 'affiliate-captain' ), '{site}' );
				$body    = 'public_change_approved' === $key
					? __( "Hello,\n\nyour new name or website for the partner program was approved and is shown from now on.", 'affiliate-captain' )
					: __( "Hello,\n\nyour requested change of name or website for the partner program was not approved. The previous details remain. If you have questions, please contact us.", 'affiliate-captain' );
				break;
			case 'code_request_admin':
				/* translators: 1: site name, 2: requested code */
				$subject = sprintf( __( '[%1$s] Partner requests the code %2$s', 'affiliate-captain' ), '{site}', '{code}' );
				/* translators: 1: partner name, 2: requested code, 3: admin URL */
				$body = sprintf( __( "%1\$s would like to use the code %2\$s. The code works only after you approve it:\n\n%3\$s", 'affiliate-captain' ), '{name}', '{code}', '{admin_url}' );
				break;
			case 'code_request_approved':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your new partner code', 'affiliate-captain' ), '{site}' );
				/* translators: 1: name, 2: code, 3: partner link, 4: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nyour requested code %2\$s was approved and works from now on – customers can enter it at checkout.\n\nYour partner link with the new code: %3\$s\n\nAll your links and codes: %4\$s", 'affiliate-captain' ), '{name}', '{code}', '{link}', '{portal_url}' );
				break;
			case 'code_request_rejected':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your code request', 'affiliate-captain' ), '{site}' );
				/* translators: 1: name, 2: code, 3: reason, 4: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nunfortunately we could not approve the code %2\$s.\n\nReason: %3\$s\n\nYour previous codes remain valid. You can request another code in your partner area: %4\$s", 'affiliate-captain' ), '{name}', '{code}', '{reason}', '{portal_url}' );
				break;
			case 'status_paused':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account has been paused', 'affiliate-captain' ), '{site}' );
				$text    = __( 'Your partner account has been paused. During the pause, new sales are not attributed to you. Commissions already earned remain unaffected.', 'affiliate-captain' );
				break;
			case 'status_terminated':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partnership has been terminated', 'affiliate-captain' ), '{site}' );
				$text    = 'reject' === Settings::get( 'terminated_open' )
					? __( 'Your partnership has been terminated. Open commissions that were not yet paid have been cancelled according to the program terms.', 'affiliate-captain' )
					: __( 'Your partnership has been terminated. Approved commissions will still be paid out according to the program terms.', 'affiliate-captain' );
				break;
			case 'status_active':
				/* translators: %s: site name */
				$subject = sprintf( __( '[%s] Your partner account is active again', 'affiliate-captain' ), '{site}' );
				$text    = __( 'Your partner account has been reactivated. Your links and codes work again.', 'affiliate-captain' );
				break;
			default:
				return null;
		}
		if ( isset( $text ) ) {
			/* translators: 1: name, 2: message, 3: reason */
			$body = sprintf( __( "Hello %1\$s,\n\n%2\$s\n\n%3\$s", 'affiliate-captain' ), '{name}', $text, '{reason}' );
		}
		return array( $subject, isset( $body ) ? $body : '' );
	}

	/**
	 * Stored template settings (layout and the templates changed in the admin).
	 *
	 * @return array layout, templates
	 */
	public static function template_settings() {
		$opt = get_option( self::TEMPLATES_OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'layout'    => isset( $opt['layout'] ) && 'html' === $opt['layout'] ? 'html' : 'text',
			'templates' => isset( $opt['templates'] ) && is_array( $opt['templates'] ) ? $opt['templates'] : array(),
		);
	}

	/**
	 * Store the template settings (one option, not autoloaded: only read when a mail is sent).
	 *
	 * @param array $settings layout, templates.
	 * @return bool
	 */
	private static function save_template_settings( array $settings ) {
		return (bool) update_option( self::TEMPLATES_OPTION, $settings, false );
	}

	/**
	 * Whether mails with a template are sent as HTML (with a plain-text alternative).
	 *
	 * @return bool
	 */
	public static function html_layout() {
		$s = self::template_settings();
		return 'html' === $s['layout'];
	}

	/**
	 * Switch the layout of the template mails.
	 *
	 * @param string $layout text|html.
	 * @return bool
	 */
	public static function set_layout( $layout ) {
		$s           = self::template_settings();
		$s['layout'] = 'html' === $layout ? 'html' : 'text';
		Audit::log( 'mail.layout', 'settings', 0, array( 'layout' => $s['layout'] ) );
		return self::save_template_settings( $s );
	}

	/**
	 * Template changed in the admin (null = the built-in text is used).
	 *
	 * @param string $key Template key.
	 * @return array|null array( subject, body )
	 */
	public static function custom_template( $key ) {
		$s = self::template_settings();
		if ( empty( $s['templates'][ $key ] ) || ! is_array( $s['templates'][ $key ] ) ) {
			return null;
		}
		$t = $s['templates'][ $key ];
		if ( ! isset( $t['subject'], $t['body'] ) || '' === trim( (string) $t['subject'] ) || '' === trim( (string) $t['body'] ) ) {
			return null;
		}
		return array( (string) $t['subject'], (string) $t['body'] );
	}

	/**
	 * Template in effect: the one changed in the admin, otherwise the built-in text.
	 *
	 * @param string $key Template key.
	 * @return array array( subject, body )
	 */
	public static function template( $key ) {
		$custom = self::custom_template( $key );
		if ( $custom ) {
			return $custom;
		}
		$default = self::default_template( $key );
		return $default ? $default : array( '', '' );
	}

	/**
	 * Save a template edited in the admin. A text equal to the built-in one is not stored (it
	 * then follows the translation and later improvements of the built-in text).
	 *
	 * @param string $key     Template key.
	 * @param string $subject Subject.
	 * @param string $body    Body (plain text with placeholders).
	 * @return true|\WP_Error
	 */
	public static function save_template( $key, $subject, $body ) {
		$defs = self::template_defs();
		if ( ! isset( $defs[ $key ] ) ) {
			return new \WP_Error( 'wpac_template_unknown', __( 'Unknown e-mail template.', 'affiliate-captain' ) );
		}
		$subject = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $subject ) ) );
		$body    = trim( str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( (string) $body ) ) );
		if ( '' === $subject || '' === $body ) {
			return new \WP_Error( 'wpac_template_empty', __( 'Please enter a subject and a text.', 'affiliate-captain' ) );
		}
		$subject = \WPAffiliateCaptain\Core\Db::cut( $subject, self::TEMPLATE_SUBJECT_MAX );
		$body    = \WPAffiliateCaptain\Core\Db::cut( $body, self::TEMPLATE_BODY_MAX );
		$s       = self::template_settings();
		$default = self::default_template( $key );
		if ( $default && $subject === trim( $default[0] ) && $body === trim( str_replace( array( "\r\n", "\r" ), "\n", $default[1] ) ) ) {
			unset( $s['templates'][ $key ] );
		} else {
			$s['templates'][ $key ] = array(
				'subject' => $subject,
				'body'    => $body,
				'updated' => time(),
			);
		}
		self::save_template_settings( $s );
		Audit::log( 'mail.template_saved', 'settings', 0, array( 'template' => $key ) );
		return true;
	}

	/**
	 * Back to the built-in text of a template.
	 *
	 * @param string $key Template key.
	 */
	public static function reset_template( $key ) {
		$s = self::template_settings();
		if ( isset( $s['templates'][ $key ] ) ) {
			unset( $s['templates'][ $key ] );
			self::save_template_settings( $s );
			Audit::log( 'mail.template_reset', 'settings', 0, array( 'template' => (string) $key ) );
		}
	}

	/**
	 * Subject and body of a template with the values filled in (plain text). Values are
	 * inserted once (a value that contains "{link}" is not replaced again); placeholders the
	 * template does not offer stay as typed.
	 *
	 * @param string $key  Template key.
	 * @param array  $vars placeholder => value ("site" is added).
	 * @return array array( subject, body )
	 */
	public static function render_template( $key, array $vars ) {
		$vars += array( 'site' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$map   = array();
		foreach ( $vars as $name => $value ) {
			$map[ '{' . $name . '}' ] = (string) $value;
		}
		list( $subject, $body ) = self::template( $key );
		$subject                = trim( (string) preg_replace( '/[\r\n\t]+/', ' ', strtr( (string) $subject, $map ) ) );
		$body                   = strtr( str_replace( array( "\r\n", "\r" ), "\n", (string) $body ), $map );
		return array( $subject, $body );
	}

	/**
	 * Example values for the test mail of a template.
	 *
	 * @param string $key Template key.
	 * @return array placeholder => value
	 */
	public static function sample_vars( $key ) {
		$code     = 'MAX123';
		$currency = (string) Settings::get( 'currency' );
		$amount   = html_entity_decode( \WPAffiliateCaptain\Core\Money::format( 12345, '' !== $currency ? $currency : 'EUR' ), ENT_QUOTES, 'UTF-8' );
		$portal   = self::portal_url();
		$all      = array(
			'name'              => __( 'Max Mustermann', 'affiliate-captain' ),
			'link'              => 'confirm_application' === $key ? add_query_arg( 'wpac_confirm', 'example', home_url( '/' ) ) : add_query_arg( (string) Settings::get( 'referral_param' ), $code, home_url( '/' ) ),
			'code'              => $code,
			'rate'              => \WPAffiliateCaptain\Commissions\RuleRepository::format_rate( (string) Settings::get( 'default_rate_type' ), (string) Settings::get( 'default_rate' ) ),
			'portal_url'        => $portal,
			/* translators: %s: portal URL */
			'access'            => sprintf( __( 'Your partner area with links, codes and statistics: %s', 'affiliate-captain' ), $portal ),
			/* translators: %s: reason entered by the shop team */
			'reason'            => 0 === strpos( (string) $key, 'status_' ) ? sprintf( __( 'Reason: %s', 'affiliate-captain' ), __( 'Example reason', 'affiliate-captain' ) ) : __( 'Example reason', 'affiliate-captain' ),
			'amount'            => $amount,
			'number'            => 'GS-2026-0001',
			'storno_number'     => 'ST-2026-0001',
			'correction_number' => 'KG-2026-0001',
			/* translators: %s: amount */
			'effect'            => sprintf( __( 'The difference of %s is paid with your next payout.', 'affiliate-captain' ), $amount ),
			'events'            => __( 'New commissions (pending until the end of the hold period)', 'affiliate-captain' ) . ': 2 (' . $amount . ')',
			'admin_url'         => admin_url( 'admin.php?page=wpac-affiliates#wpac-code-requests' ),
		);
		$defs = self::template_defs();
		$vars = isset( $defs[ $key ] ) ? $defs[ $key ]['vars'] : array();
		return array_intersect_key( $all, array_flip( $vars ) );
	}

	/**
	 * Send the template in effect with example values to an address (admin "Send test e-mail").
	 *
	 * @param string $key   Template key.
	 * @param string $email Recipient (the current admin).
	 * @return bool|\WP_Error
	 */
	public static function send_test( $key, $email ) {
		$defs = self::template_defs();
		if ( ! isset( $defs[ $key ] ) ) {
			return new \WP_Error( 'wpac_template_unknown', __( 'Unknown e-mail template.', 'affiliate-captain' ) );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'wpac_template_email', __( 'Your user account has no valid e-mail address.', 'affiliate-captain' ) );
		}
		list( $subject, $body ) = self::render_template( $key, self::sample_vars( $key ) );
		return self::send(
			$defs[ $key ]['type'],
			$email,
			/* translators: %s: subject */
			sprintf( __( '[Test] %s', 'affiliate-captain' ), $subject ),
			$body,
			array(
				'admin'    => true,
				'template' => $key,
				'test'     => true,
			)
		);
	}

	/**
	 * HTML version of a plain-text mail: every text is escaped (values a partner entered, like
	 * the name, cannot inject markup), web addresses become links, line breaks are kept.
	 *
	 * @param string $text    Plain text.
	 * @param string $subject Subject (document title).
	 * @param array  $context type, template, affiliate_id.
	 * @return string
	 */
	public static function html_message( $text, $subject, array $context = array() ) {
		$text  = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		$paras = '';
		foreach ( preg_split( "/\n{2,}/", $text ) as $para ) {
			$paras .= '<p style="margin:0 0 16px;">' . str_replace( "\n", "<br>\n", self::linkify( esc_html( $para ) ) ) . "</p>\n";
		}
		$site = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( (string) $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f5;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:6px;"><tr>'
			. '<td style="padding:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">' . "\n"
			. $paras
			. '</td></tr></table>'
			. '<p style="margin:16px 0 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#6b7280;">' . $site . '</p>'
			. '</td></tr></table></body></html>';
		/**
		 * HTML version of a template mail (layout "HTML" in the e-mail templates).
		 *
		 * @param string $html    HTML document.
		 * @param string $text    Plain text (sent as the alternative part).
		 * @param string $subject Subject.
		 * @param array  $context type, template, affiliate_id.
		 */
		return (string) apply_filters( 'wpac_mail_html', $html, $text, (string) $subject, $context );
	}

	/**
	 * Turn the web addresses in escaped text into links.
	 *
	 * @param string $escaped HTML-escaped text.
	 * @return string
	 */
	private static function linkify( $escaped ) {
		return (string) preg_replace_callback(
			'#https?://[^\s<>"]+#i',
			static function ( $m ) {
				$url  = $m[0];
				$tail = '';
				while ( preg_match( '/(&quot;|&#0?39;|&gt;|&lt;|[.,;:!?)\]])$/', $url, $t ) ) {
					$tail = $t[1] . $tail;
					$url  = substr( $url, 0, -strlen( $t[1] ) );
				}
				return '<a href="' . $url . '" style="color:#2563eb;">' . $url . '</a>' . $tail;
			},
			(string) $escaped
		);
	}

	/**
	 * Start a broadcast mail to all active partners (optionally of one group). The mails are sent
	 * in batches in the background through send(); partners who unsubscribed from "broadcast"
	 * are skipped, every mail carries the unsubscribe link. Only one broadcast runs at a time.
	 *
	 * @param string $subject  Subject.
	 * @param string $message  Plain text.
	 * @param int    $group_id Only partners of this group (0 = all).
	 * @return int|\WP_Error Number of active partners addressed.
	 */
	public static function start_broadcast( $subject, $message, $group_id = 0 ) {
		global $wpdb;
		$subject = trim( sanitize_text_field( (string) $subject ) );
		$message = trim( (string) $message );
		if ( '' === $subject || '' === $message ) {
			return new \WP_Error( 'wpac_broadcast_empty', __( 'Please enter a subject and a text.', 'affiliate-captain' ) );
		}
		$state = get_option( self::BROADCAST_OPTION );
		if ( is_array( $state ) && empty( $state['done'] ) && (int) $state['started'] > time() - DAY_IN_SECONDS ) {
			return new \WP_Error( 'wpac_broadcast_running', __( 'Another broadcast mail is still being sent. Please wait until it is finished.', 'affiliate-captain' ) );
		}
		$a     = \WPAffiliateCaptain\Core\Db::table( 'affiliates' );
		$group = (int) $group_id ? $wpdb->prepare( ' AND group_id = %d', (int) $group_id ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'active'{$group}" );
		if ( ! $total ) {
			return new \WP_Error( 'wpac_none', __( 'There are no active partners to write to.', 'affiliate-captain' ) );
		}
		update_option(
			self::BROADCAST_OPTION,
			array(
				'subject' => \WPAffiliateCaptain\Core\Db::cut( $subject, 190 ),
				'message' => \WPAffiliateCaptain\Core\Db::cut( $message, 20000 ),
				'group'   => (int) $group_id,
				'cursor'  => 0,
				'sent'    => 0,
				'skipped' => 0,
				'total'   => $total,
				'started' => time(),
				'done'    => 0,
			),
			false
		);
		Audit::log( 'mail.broadcast_started', 'settings', 0, array( 'total' => $total, 'group' => (int) $group_id ) );
		\WPAffiliateCaptain\Core\Scheduler::once( self::BROADCAST_HOOK, 0 );
		return $total;
	}

	/**
	 * State of the last broadcast mail.
	 *
	 * @return array|null
	 */
	public static function broadcast_state() {
		$state = get_option( self::BROADCAST_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Send the next batch of the broadcast mail (cron), then schedule the next one.
	 *
	 * @param int $size Batch size.
	 */
	public static function broadcast_batch( $size = self::BROADCAST_BATCH ) {
		global $wpdb;
		$state = get_option( self::BROADCAST_OPTION );
		if ( ! is_array( $state ) || ! empty( $state['done'] ) ) {
			return;
		}
		$a     = \WPAffiliateCaptain\Core\Db::table( 'affiliates' );
		$group = (int) $state['group'] ? $wpdb->prepare( ' AND group_id = %d', (int) $state['group'] ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$a} WHERE status = 'active' AND id > %d{$group} ORDER BY id ASC LIMIT %d", (int) $state['cursor'], max( 1, (int) $size ) ) ) );
		foreach ( $ids as $id ) {
			$state['cursor'] = $id;
			$aff             = AffiliateRepository::find( $id );
			if ( ! $aff || ! is_email( $aff->email ) || self::opted_out( $id, 'broadcast' ) || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( $id ) ) {
				++$state['skipped'];
				continue;
			}
			$switched = Locale::switch_for_affiliate( $aff );
			try {
				$name = trim( (string) $aff->name );
				/* translators: %s: partner name */
				$body = ( '' !== $name ? sprintf( __( 'Hello %s,', 'affiliate-captain' ), $name ) . "\n\n" : '' ) . $state['message'];
				if ( self::send_optional( 'broadcast', $aff, $state['subject'], $body, array( 'template' => 'broadcast' ) ) ) {
					++$state['sent'];
				}
			} finally {
				Locale::restore( $switched );
			}
		}
		if ( count( $ids ) < max( 1, (int) $size ) ) {
			$state['done'] = time();
			Audit::log( 'mail.broadcast_sent', 'settings', 0, array( 'sent' => (int) $state['sent'], 'skipped' => (int) $state['skipped'] ) );
		} else {
			\WPAffiliateCaptain\Core\Scheduler::once( self::BROADCAST_HOOK, 60 );
		}
		update_option( self::BROADCAST_OPTION, $state, false );
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
			list( $subject, $body ) = self::render_template(
				'credit_note',
				array(
					'name'       => $aff->name,
					'number'     => $p->number,
					'amount'     => html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ),
					'portal_url' => add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ),
				)
			);
			$sent = self::send(
				'credit_note',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'credit_note',
					'affiliate_id' => (int) $aff->id,
					'attachments'  => '' !== (string) $path && is_readable( (string) $path ) ? array( (string) $path ) : array(),
				)
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
			list( $subject, $body ) = self::render_template(
				'credit_note_cancelled',
				array(
					'name'          => $aff->name,
					'number'        => $p->number,
					'storno_number' => (string) $p->storno_number,
					'portal_url'    => add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ),
				)
			);
			$sent = self::send(
				'credit_note_cancelled',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'credit_note_cancelled',
					'affiliate_id' => (int) $aff->id,
					'attachments'  => '' !== (string) $path && is_readable( (string) $path ) ? array( (string) $path ) : array(),
				)
			);
		} finally {
			Locale::restore( $switched );
		}
		\WPAffiliateCaptain\Core\Audit::log( $sent ? 'statement.storno_sent' : 'statement.send_failed', 'payout', (int) $payout_id );
	}

	/**
	 * A correction credit note was issued for a paid payout: send it to the partner (like the
	 * credit note, it only takes effect once the partner received it).
	 *
	 * @param int    $correction_id Correction.
	 * @param string $path          Stored PDF.
	 */
	public static function credit_note_corrected( $correction_id, $path = '' ) {
		$c = \WPAffiliateCaptain\Payouts\Corrections::find( (int) $correction_id );
		/** This filter is documented in Notifications::credit_note_issued(). */
		if ( ! $c || ! apply_filters( 'wpac_notify_credit_note', true, (int) $c->payout_id ) ) {
			return;
		}
		$p   = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $c->payout_id );
		$aff = AffiliateRepository::find( (int) $c->affiliate_id );
		if ( ! $p || ! $aff || ! is_email( (string) $aff->email ) || 0 === strpos( (string) $aff->email, 'deleted-' ) ) {
			return;
		}
		$diff     = (int) $c->net_minor + (int) $c->vat_minor - (int) $c->prev_net_minor - (int) $c->prev_vat_minor;
		$amount   = html_entity_decode( \WPAffiliateCaptain\Core\Money::format( abs( $diff ), (string) $c->currency ), ENT_QUOTES, 'UTF-8' );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			$effect = $diff > 0
				/* translators: %s: amount */
				? sprintf( __( 'The difference of %s is paid with your next payout.', 'affiliate-captain' ), $amount )
				/* translators: %s: amount */
				: sprintf( __( 'The overpayment of %s is offset against your next commissions.', 'affiliate-captain' ), $amount );
			list( $subject, $body ) = self::render_template(
				'credit_note_corrected',
				array(
					'name'              => $aff->name,
					'number'            => $p->number,
					'correction_number' => (string) $c->number,
					'effect'            => $effect,
					'portal_url'        => add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ),
				)
			);
			$sent = self::send(
				'credit_note_corrected',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'credit_note_corrected',
					'affiliate_id' => (int) $aff->id,
					'attachments'  => '' !== (string) $path && is_readable( (string) $path ) ? array( (string) $path ) : array(),
				)
			);
		} finally {
			Locale::restore( $switched );
		}
		\WPAffiliateCaptain\Core\Audit::log( $sent ? 'statement.correction_sent' : 'statement.send_failed', 'payout', (int) $c->payout_id, array( 'correction' => (int) $c->id ) );
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
		$switched = self::switch_for_admin();
		try {
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
			self::send(
				$pending ? 'tax_data_pending' : 'tax_data_changed',
				self::admin_email(),
				$pending
					/* translators: 1: site name, 2: partner */
					? sprintf( __( '[%1$s] Tax data change of %2$s waits for your approval', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $aff->name )
					/* translators: 1: site name, 2: partner */
					: sprintf( __( '[%1$s] Tax data of %2$s changed', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $aff->name ),
				implode( "\n", $lines ) . "\n\n" . ( $pending
					? __( 'The change affects the VAT on credit notes. It only takes effect after you have approved it (commissions earned before keep the previous treatment). Please check the details (e.g. the VAT ID with VIES):', 'affiliate-captain' )
					: __( 'The change applies to commissions earned from now on. Details:', 'affiliate-captain' ) ) . "\n" . $url,
				array(
					'admin'        => true,
					'affiliate_id' => (int) $affiliate_id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * Central mail function: every e-mail of the plugin goes through here.
	 *
	 * Sets the sender configured in the settings (name/address), Reply-To with the program's
	 * notification address for mails to partners and applicants (so "please reply" reaches the
	 * operator, not wordpress@…), offers filters and actions, and logs failed mails (with the
	 * reason from wp_mail_failed) instead of ignoring them.
	 *
	 * @param string          $type    Mail type, e.g. payout_paid, application_received, monthly_partner.
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $message Plain text body.
	 * @param array           $args    Optional: admin (bool, mail to the operator: no Reply-To),
	 *                                 reply_to (string; false = none), headers (array),
	 *                                 attachments (array), affiliate_id (int), template (string:
	 *                                 key of the e-mail template the text comes from; with the
	 *                                 HTML layout such a mail is sent as HTML with the plain text
	 *                                 as alternative), test (bool, test mail from the admin).
	 * @return bool Whether the mail was handed over (also true if a filter skipped it on purpose;
	 *              false while mails are suppressed, see suppress()).
	 */
	public static function send( $type, $to, $subject, $message, array $args = array() ) {
		$args += array(
			'admin'        => false,
			'reply_to'     => null,
			'headers'      => array(),
			'attachments'  => array(),
			'affiliate_id' => 0,
			'template'     => '',
			'test'         => false,
		);
		if ( self::$suppressed > 0 ) {
			// E.g. a CSV import without e-mails: nothing leaves the server, and the caller must not
			// record the mail as sent (a link "sent" that nobody got, a 10-minute resend lock).
			Log::debug( 'E-mail suppressed', array( 'type' => (string) $type, 'affiliate_id' => (int) $args['affiliate_id'] ) );
			return false;
		}
		$headers = (array) $args['headers'];
		$from    = (string) Settings::get( 'mail_from_email' );
		$name    = self::from_name( (string) $type, (bool) $args['admin'], (int) $args['affiliate_id'] );
		if ( '' !== $from && is_email( $from ) ) {
			$headers[] = 'From: ' . self::header_text( '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . ' <' . $from . '>';
			$name      = '';
		}
		$reply = $args['reply_to'];
		if ( null === $reply ) {
			$reply = $args['admin'] ? '' : self::admin_email();
		}
		if ( is_string( $reply ) && '' !== $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		$mail = array(
			'to'          => $to,
			'subject'     => (string) $subject,
			'message'     => (string) $message,
			'headers'     => $headers,
			'attachments' => (array) $args['attachments'],
		);
		$context = array(
			'type'         => (string) $type,
			'admin'        => (bool) $args['admin'],
			'affiliate_id' => (int) $args['affiliate_id'],
			'template'     => (string) $args['template'],
			'html'         => '' !== (string) $args['template'] && self::html_layout(),
			'test'         => (bool) $args['test'],
		);
		// Layout "HTML" of the e-mail templates: the filled-in template (plain text) becomes the
		// alternative part, the escaped HTML version the body. wpac_mail runs after the template,
		// so existing filters keep working (and see the Content-Type header).
		$plain = '';
		$built = '';
		if ( $context['html'] ) {
			$plain             = $mail['message'];
			$built             = self::html_message( $plain, $mail['subject'], $context );
			$mail['message']   = $built;
			$mail['headers'][] = 'Content-Type: text/html; charset=UTF-8';
		}
		/**
		 * Filter an e-mail of Affiliate Captain before it is sent: change recipient, subject,
		 * text, headers (e.g. "Content-Type: text/html; charset=UTF-8" for an HTML version) or
		 * attachments – or return false to not send it. Runs after the e-mail template of the
		 * admin was applied (the message is HTML if the template layout is "HTML", see
		 * $context['html']).
		 *
		 * @param array|false $mail    to, subject, message, headers, attachments.
		 * @param string      $type    Mail type (payout_paid, application_received, monthly_partner …).
		 * @param array       $context type, admin (mail to the operator), affiliate_id, template
		 *                             (template key or ''), html, test.
		 */
		$mail = apply_filters( 'wpac_mail', $mail, (string) $type, $context );
		if ( ! is_array( $mail ) ) {
			Log::debug( 'E-mail skipped by a filter', self::log_context( $context ) );
			return true;
		}
		$mail += array(
			'to'          => '',
			'subject'     => '',
			'message'     => '',
			'headers'     => array(),
			'attachments' => array(),
		);
		self::$alt_body = '';
		if ( $context['html'] && preg_grep( '/^\s*content-type:\s*text\/html/i', (array) $mail['headers'] ) ) {
			// A filter that rewrote the HTML gets a plain-text part derived from its version.
			self::$alt_body = $mail['message'] === $built ? $plain : trim( html_entity_decode( wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>|</p>#i', "\n", (string) $mail['message'] ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 20 );
			add_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
		}
		self::$last_error = null;
		self::$from_name  = $name;
		self::$sending    = true;
		try {
			$ok = (bool) wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'], $mail['attachments'] );
		} finally {
			self::$sending   = false;
			self::$from_name = '';
		}
		if ( ! $ok ) {
			$error = self::$last_error instanceof \WP_Error ? self::$last_error->get_error_message() : 'wp_mail() returned false';
			Log::warning( 'E-mail could not be sent', self::log_context( $context ) + array( 'error' => \WPAffiliateCaptain\Core\Db::cut( (string) $error, 300 ) ) );
			/**
			 * Fires when an e-mail of Affiliate Captain could not be sent.
			 *
			 * @param string $type    Mail type.
			 * @param array  $mail    to, subject, message, headers, attachments.
			 * @param string $error   Reason (from wp_mail_failed, if the mailer reported one).
			 * @param array  $context type, admin, affiliate_id.
			 */
			do_action( 'wpac_mail_failed', (string) $type, $mail, (string) $error, $context );
		}
		/**
		 * Fires after an e-mail of Affiliate Captain was handed to wp_mail().
		 *
		 * @param string $type    Mail type.
		 * @param array  $mail    to, subject, message, headers, attachments.
		 * @param bool   $sent    Whether wp_mail() accepted it.
		 * @param array  $context type, admin, affiliate_id.
		 */
		do_action( 'wpac_mail_sent', (string) $type, $mail, $ok, $context );
		return $ok;
	}

	/**
	 * Remember the reason of a failed mail (wp_mail_failed) while one of our mails is sent.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function capture_mail_error( $error ) {
		if ( self::$sending && $error instanceof \WP_Error ) {
			self::$last_error = $error;
		}
	}

	/**
	 * Context of a mail for the log (the template key only if there is one).
	 *
	 * @param array $context Mail context.
	 * @return array
	 */
	private static function log_context( array $context ) {
		$log = array_intersect_key( $context, array_flip( array( 'type', 'admin', 'affiliate_id' ) ) );
		if ( ! empty( $context['template'] ) ) {
			$log['template'] = $context['template'];
		}
		return $log;
	}

	/**
	 * Plain-text alternative of an HTML template mail while it is sent (phpmailer_init): the
	 * mail becomes multipart/alternative, so clients without HTML show the plain text.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 */
	public static function set_alt_body( $phpmailer ) {
		if ( self::$sending && '' !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		}
	}

	/**
	 * Sender name from the settings while one of our mails is sent (address unchanged).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function filter_from_name( $name ) {
		return self::$sending && '' !== self::$from_name ? self::$from_name : $name;
	}

	/**
	 * Sender name of a mail of the plugin: the setting, filtered by wpac_mail_from_name ('' = not
	 * set: the site name with a sender address set, otherwise the WordPress/SMTP default).
	 *
	 * @param string $type         Mail type.
	 * @param bool   $admin        Mail to the operator.
	 * @param int    $affiliate_id Partner.
	 * @return string Header-safe name.
	 */
	private static function from_name( $type, $admin, $affiliate_id ) {
		$name = trim( (string) Settings::get( 'mail_from_name' ) );
		/**
		 * Sender name of an e-mail of the plugin (e.g. the brand of a white-label add-on). Empty:
		 * the site name (with a sender address set) or the WordPress/SMTP default. Since 1.3.0.
		 *
		 * @param string $name    Sender name from the settings ('' = not set).
		 * @param string $type    Mail type.
		 * @param array  $context type, admin (mail to the operator), affiliate_id.
		 */
		$name = apply_filters(
			'wpac_mail_from_name',
			$name,
			(string) $type,
			array(
				'type'         => (string) $type,
				'admin'        => (bool) $admin,
				'affiliate_id' => (int) $affiliate_id,
			)
		);
		return is_string( $name ) ? self::header_text( $name ) : '';
	}

	/**
	 * Sender of the plugin's e-mails for add-ons (wpac_mail_sender(), since 0.15.0): the same
	 * rules as send(). Name: setting, filtered by wpac_mail_from_name, otherwise the site name.
	 * Address: the sender address of the settings, otherwise the program notification address
	 * (or the site admin e-mail).
	 *
	 * @param string $type         Mail type (passed to wpac_mail_from_name).
	 * @param int    $affiliate_id Partner (passed to wpac_mail_from_name).
	 * @return array name, email, custom (true = sender address set in the settings: only then do the
	 *               plugin's mails set a From header; otherwise WordPress or the SMTP plugin decides).
	 */
	public static function sender( $type = '', $affiliate_id = 0 ) {
		$from   = (string) Settings::get( 'mail_from_email' );
		$custom = '' !== $from && is_email( $from );
		$name   = self::from_name( (string) $type, false, (int) $affiliate_id );
		return array(
			'name'   => '' !== $name ? $name : self::header_text( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'email'  => $custom ? $from : self::admin_email(),
			'custom' => $custom,
		);
	}

	/**
	 * Text for a mail header (no line breaks, no angle brackets or quotes).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function header_text( $text ) {
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( array( "\r", "\n", '<', '>', '"' ), ' ', (string) $text ) ) );
	}

	/**
	 * Program notification address (settings), otherwise the site admin e-mail.
	 *
	 * @return string
	 */
	public static function admin_email() {
		$email = (string) Settings::get( 'admin_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Switch to the operator's language for a mail to the operator that is triggered by a
	 * visitor or partner (otherwise it would be written in their language): the user locale of
	 * the account with the notification address, otherwise the site language.
	 *
	 * @return bool Whether switched (pass to Locale::restore()).
	 */
	public static function switch_for_admin() {
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'email', self::admin_email() ) : false;
		// An account without its own language follows the site language (get_user_locale() would
		// return the language of the current request with WPML/Polylang).
		return Locale::switch_to( $user && ! empty( $user->locale ) ? (string) $user->locale : Locale::site() );
	}

	/**
	 * Whether a partner unsubscribed from a mail type.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $type         monthly_partner|commission.
	 * @return bool
	 */
	public static function opted_out( $affiliate_id, $type ) {
		$all = get_option( self::OPTOUT_OPTION, array() );
		return is_array( $all ) && isset( $all[ (int) $affiliate_id ] ) && in_array( $type, (array) $all[ (int) $affiliate_id ], true );
	}

	/**
	 * Unsubscribe from (or subscribe again to) a mail type.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $type         monthly_partner|commission.
	 * @param bool   $out          Unsubscribe.
	 */
	public static function set_opt_out( $affiliate_id, $type, $out ) {
		if ( ! in_array( $type, self::OPTOUT_TYPES, true ) ) {
			return;
		}
		$all = get_option( self::OPTOUT_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$cur = isset( $all[ (int) $affiliate_id ] ) ? (array) $all[ (int) $affiliate_id ] : array();
		$cur = $out ? array_values( array_unique( array_merge( $cur, array( $type ) ) ) ) : array_values( array_diff( $cur, array( $type ) ) );
		if ( $cur ) {
			$all[ (int) $affiliate_id ] = $cur;
		} else {
			unset( $all[ (int) $affiliate_id ] );
		}
		update_option( self::OPTOUT_OPTION, $all, false );
		Audit::log( $out ? 'mail.unsubscribed' : 'mail.subscribed', 'affiliate', (int) $affiliate_id, array( 'type' => $type ) );
	}

	/**
	 * Signed link that unsubscribes a partner from one mail type (works without login).
	 *
	 * @param object $aff  Partner.
	 * @param string $type monthly_partner|commission.
	 * @param bool   $out  Unsubscribe (false: subscribe again).
	 * @return string
	 */
	public static function unsubscribe_url( $aff, $type, $out = true ) {
		return add_query_arg(
			array(
				'wpac_unsubscribe' => $type,
				'wpac_aff'         => (int) $aff->id,
				'wpac_on'          => $out ? 0 : 1,
				'wpac_sig'         => self::unsubscribe_sig( (int) $aff->id, $type ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Signature of an unsubscribe link.
	 *
	 * @param int    $affiliate_id Partner.
	 * @param string $type         Mail type.
	 * @return string
	 */
	public static function unsubscribe_sig( $affiliate_id, $type ) {
		return substr( wp_hash( 'wpac_unsubscribe|' . (int) $affiliate_id . '|' . $type ), 0, 20 );
	}

	/**
	 * Unsubscribe link of a mail (init). A GET only shows a confirmation page with a button
	 * (mail scanners such as Safe Links open links in advance and must not unsubscribe the
	 * partner); the choice is stored on the POST of that button or on the one-click POST of the
	 * mail client (RFC 8058, "List-Unsubscribe=One-Click"). The signed link is the secret: no
	 * nonce (no session, the mail client posts without cookies).
	 */
	public static function handle_unsubscribe() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link from an e-mail (no session).
		if ( ! isset( $_GET['wpac_unsubscribe'], $_GET['wpac_aff'], $_GET['wpac_sig'] ) ) {
			return;
		}
		$query = array(
			'wpac_unsubscribe' => sanitize_key( wp_unslash( $_GET['wpac_unsubscribe'] ) ),
			'wpac_aff'         => absint( $_GET['wpac_aff'] ),
			'wpac_sig'         => sanitize_text_field( wp_unslash( $_GET['wpac_sig'] ) ),
			'wpac_on'          => empty( $_GET['wpac_on'] ) ? 0 : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$result = self::unsubscribe_request( $query, 'POST' === $method );
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		wp_die( $result['html'], esc_html( $result['title'] ), array( 'response' => $result['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in unsubscribe_request().
	}

	/**
	 * Answer to an unsubscribe link: confirmation page (GET) or the stored choice (POST).
	 *
	 * @param array $query wpac_unsubscribe, wpac_aff, wpac_sig, wpac_on (sanitized).
	 * @param bool  $post  POST request (button of the confirmation page or one-click of the mail client).
	 * @return array status, title, html
	 */
	public static function unsubscribe_request( array $query, $post ) {
		$type = isset( $query['wpac_unsubscribe'] ) ? sanitize_key( (string) $query['wpac_unsubscribe'] ) : '';
		$id   = isset( $query['wpac_aff'] ) ? absint( $query['wpac_aff'] ) : 0;
		$sig  = isset( $query['wpac_sig'] ) ? (string) $query['wpac_sig'] : '';
		$on   = ! empty( $query['wpac_on'] );
		$aff  = $id ? AffiliateRepository::find( $id ) : null;
		if ( ! $aff || ! in_array( $type, self::OPTOUT_TYPES, true ) || ! hash_equals( self::unsubscribe_sig( $id, $type ), $sig ) ) {
			return array(
				'status' => 400,
				'title'  => __( 'E-mail settings', 'affiliate-captain' ),
				'html'   => '<p>' . esc_html__( 'This link is not valid.', 'affiliate-captain' ) . '</p>',
			);
		}
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			$labels = self::optout_labels();
			$what   = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
			if ( $post ) {
				self::set_opt_out( $id, $type, ! $on );
				$html = '<p>' . esc_html(
					$on
						? __( 'You will receive these e-mails again.', 'affiliate-captain' )
						: __( 'You have been unsubscribed. You will no longer receive these e-mails from the partner program.', 'affiliate-captain' )
				) . '</p>';
				if ( ! $on ) {
					$html .= self::unsubscribe_form( self::unsubscribe_url( $aff, $type, false ), __( 'Undo', 'affiliate-captain' ) );
				}
			} else {
				$html = '<p>' . esc_html(
					$on
						/* translators: %s: kind of e-mails, e.g. "Monthly summary" */
						? sprintf( __( 'Do you want to receive these e-mails again: %s?', 'affiliate-captain' ), $what )
						/* translators: %s: kind of e-mails, e.g. "Monthly summary" */
						: sprintf( __( 'Do you want to unsubscribe from these e-mails of the partner program: %s?', 'affiliate-captain' ), $what )
				) . '</p>';
				$html .= self::unsubscribe_form( self::unsubscribe_url( $aff, $type, ! $on ), $on ? __( 'Subscribe again', 'affiliate-captain' ) : __( 'Unsubscribe', 'affiliate-captain' ) );
			}
			$title = __( 'E-mail settings', 'affiliate-captain' );
		} finally {
			Locale::restore( $switched );
		}
		return array(
			'status' => 200,
			'title'  => $title,
			'html'   => $html,
		);
	}

	/**
	 * Button that posts to a signed unsubscribe link.
	 *
	 * @param string $url   Signed link.
	 * @param string $label Button label.
	 * @return string HTML.
	 */
	private static function unsubscribe_form( $url, $label ) {
		return '<form method="post" action="' . esc_url( $url ) . '"><input type="hidden" name="wpac_confirm" value="1"><p><button type="submit" class="button">' . esc_html( $label ) . '</button></p></form>';
	}

	/**
	 * Footer of a mail a partner can unsubscribe from.
	 *
	 * @param object $aff  Partner.
	 * @param string $type monthly_partner|commission.
	 * @return string
	 */
	private static function unsubscribe_footer( $aff, $type ) {
		/* translators: %s: URL */
		return "\n\n-- \n" . sprintf( __( 'Do not want these e-mails any more? Unsubscribe: %s', 'affiliate-captain' ), self::unsubscribe_url( $aff, $type ) );
	}

	/**
	 * Partner mail that can be unsubscribed (monthly summary, commission notices): adds the
	 * unsubscribe link and the List-Unsubscribe header.
	 *
	 * @param string $type    monthly_partner|commission.
	 * @param object $aff     Partner.
	 * @param string $subject Subject.
	 * @param string $message Body.
	 * @param array  $args    Further arguments of send() (e.g. template).
	 * @return bool
	 */
	public static function send_optional( $type, $aff, $subject, $message, array $args = array() ) {
		$url = self::unsubscribe_url( $aff, $type );
		return self::send(
			$type,
			$aff->email,
			$subject,
			$message . self::unsubscribe_footer( $aff, $type ),
			array(
				'affiliate_id' => (int) $aff->id,
				// One-click unsubscribe (RFC 8058): the mail client posts to the signed link.
				'headers'      => array( 'List-Unsubscribe: <' . $url . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ),
			) + $args
		);
	}

	/**
	 * Commission recorded (collected per request, sent once per partner: see flush_commissions()).
	 *
	 * @param int $id Commission.
	 */
	public static function commission_created( $id ) {
		self::queue_commission( 'created', (int) $id );
	}

	/**
	 * Commission approved.
	 *
	 * @param int $id Commission.
	 */
	public static function commission_approved( $id ) {
		self::queue_commission( 'approved', (int) $id );
	}

	/**
	 * Commission rejected.
	 *
	 * @param int $id Commission.
	 */
	public static function commission_rejected( $id ) {
		self::queue_commission( 'rejected', (int) $id );
	}

	/**
	 * Collect a commission event (one mail per partner and event at the end of the request, not
	 * one per order line or per approval of the hourly run).
	 *
	 * @param string $event created|approved|rejected.
	 * @param int    $id    Commission.
	 */
	private static function queue_commission( $event, $id ) {
		if ( ! Settings::get( 'notify_commissions' ) ) {
			return;
		}
		$c = \WPAffiliateCaptain\Commissions\Ledger::find( $id );
		if ( ! $c ) {
			return;
		}
		if ( 'created' === $event && ( in_array( (string) $c->kind, array( 'writeoff', 'repayment', 'correction', 'reinstatement' ), true ) || ( 'bonus' === (string) $c->kind && (int) $c->parent_id ) ) ) {
			// Settlements, correction credit notes (own mail), reinstatements and bonus corrections
			// are no "new commission" for the partner (wpac_commission_created fires for them since 1.3.0).
			return;
		}
		if ( ! self::$commission_events ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_on_shutdown' ) );
		}
		self::$commission_events[ (int) $c->affiliate_id ][ $event ][] = $c;
	}

	/**
	 * Hook callback for "shutdown" (actions return nothing): see flush_commissions().
	 */
	public static function flush_on_shutdown() {
		self::flush_commissions();
	}

	/**
	 * Send the collected commission notices (shutdown).
	 *
	 * @return int Mails handed over.
	 */
	public static function flush_commissions() {
		$events                  = self::$commission_events;
		self::$commission_events = array();
		$sent                    = 0;
		foreach ( $events as $affiliate_id => $by_event ) {
			$aff = AffiliateRepository::find( (int) $affiliate_id );
			if ( ! $aff || 'active' !== $aff->status || 0 === strpos( (string) $aff->email, 'deleted-' ) || self::opted_out( (int) $aff->id, 'commission' ) || \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $aff->id ) ) {
				continue;
			}
			$switched = Locale::switch_for_affiliate( $aff );
			try {
				$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$lines = array();
				$heads = array(
					'created'  => __( 'New commissions (pending until the end of the hold period)', 'affiliate-captain' ),
					'approved' => __( 'Approved commissions (count towards your next payout)', 'affiliate-captain' ),
					'rejected' => __( 'Rejected commissions (e.g. cancelled or returned orders)', 'affiliate-captain' ),
				);
				foreach ( $heads as $event => $head ) {
					if ( empty( $by_event[ $event ] ) ) {
						continue;
					}
					$sums = array();
					foreach ( $by_event[ $event ] as $c ) {
						$sums[ $c->currency ] = ( isset( $sums[ $c->currency ] ) ? $sums[ $c->currency ] : 0 ) + (int) $c->amount_minor;
					}
					$amounts = array();
					foreach ( $sums as $cur => $minor ) {
						$amounts[] = html_entity_decode( \WPAffiliateCaptain\Core\Money::format( $minor, $cur ), ENT_QUOTES, 'UTF-8' );
					}
					$lines[] = $head . ': ' . count( $by_event[ $event ] ) . ' (' . implode( ', ', $amounts ) . ')';
				}
				list( $subject, $body ) = self::render_template(
					'commission',
					array(
						'name'       => $aff->name,
						'site'       => $site,
						'events'     => implode( "\n", $lines ),
						'portal_url' => self::portal_url( $aff ),
					)
				);
				if ( self::send_optional( 'commission', $aff, $subject, $body, array( 'template' => 'commission' ) ) ) {
					++$sent;
				}
			} finally {
				Locale::restore( $switched );
			}
		}
		return $sent;
	}

	/**
	 * Payout transferred: amount, number and where to find the credit note. The wording follows
	 * the payment method of the payout (bank transfer or another method such as PayPal).
	 *
	 * @param int $payout_id Payout.
	 */
	public static function payout_paid( $payout_id ) {
		/**
		 * Whether partners get an e-mail when a payout was transferred (setting
		 * "Payout e-mail to partners").
		 *
		 * @param bool $send Send.
		 */
		if ( ! apply_filters( 'wpac_notify_payout_paid', (bool) Settings::get( 'notify_payout_paid' ) ) ) {
			return;
		}
		$p   = \WPAffiliateCaptain\Payouts\PayoutService::find( (int) $payout_id );
		$aff = $p ? AffiliateRepository::find( (int) $p->affiliate_id ) : null;
		if ( ! $p || ! $aff || 0 === strpos( (string) $aff->email, 'deleted-' ) || (int) $p->amount_minor <= 0 ) {
			return; // Nothing transferred (e.g. a correction document settling a balance).
		}
		$snap     = json_decode( (string) ( isset( $p->profile_snapshot ) ? $p->profile_snapshot : '' ), true );
		$manual   = is_array( $snap ) && isset( $snap['payout_method'] ) && \WPAffiliateCaptain\Payouts\ProfileRepository::METHOD_MANUAL === $snap['payout_method'];
		$portal   = (int) Settings::get( 'portal_page_id' );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			$key                    = $manual ? 'payout_paid_manual' : 'payout_paid';
			list( $subject, $body ) = self::render_template(
				$key,
				array(
					'name'       => $aff->name,
					'amount'     => html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ),
					'number'     => $p->number,
					'portal_url' => $portal ? add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) : home_url( '/' ),
				)
			);
			self::send(
				'payout_paid',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => $key,
					'affiliate_id' => (int) $aff->id,
				)
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
	 * New application received.
	 *
	 * @param int $id Affiliate ID.
	 */
	public static function application_received( $id ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$url      = admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id );
		$switched = self::switch_for_admin(); // Triggered by the applicant: the operator's language, not the visitor's.
		try {
			self::send(
				'application_received',
				self::admin_email(),
				/* translators: %s: site name */
				sprintf( __( '[%s] New affiliate application', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: name, 2: website, 3: admin URL */
				sprintf( __( "New affiliate application from %1\$s (%2\$s).\n\nReview: %3\$s", 'affiliate-captain' ), $aff->name, $aff->website, $url ),
				array(
					'admin'        => true,
					'reply_to'     => is_email( $aff->email ) ? (string) $aff->email : '',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
		self::application_confirmed( $aff );
	}

	/**
	 * Confirmation for the applicant after the double opt-in: the application was received and
	 * is reviewed (no text from the form except the name, see confirm_application()).
	 *
	 * @param object $aff Applicant.
	 */
	public static function application_confirmed( $aff ) {
		if ( ! $aff || ! is_email( $aff->email ) ) {
			return;
		}
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			list( $subject, $body ) = self::render_template( 'application_confirmed', array() );
			self::send(
				'application_confirmed',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'application_confirmed',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
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
		list( $subject, $body ) = self::render_template( 'confirm_application', array( 'link' => (string) $link ) );
		self::send( 'confirm_application', $email, $subject, $body, array( 'template' => 'confirm_application' ) );
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
			list( $subject, $body ) = self::render_template( 'already_registered', array( 'portal_url' => self::portal_url( $aff ) ) );
			self::send( 'already_registered', $email, $subject, $body, array( 'template' => 'already_registered' ) );
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
			list( $subject, $body ) = self::render_template(
				'bank_details_changed',
				array(
					'name' => $aff->name,
					'site' => $site,
				)
			);
			self::send(
				'bank_details_changed',
				$to,
				$subject,
				$body,
				array(
					'template'     => 'bank_details_changed',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
		$switched = self::switch_for_admin();
		try {
			self::send(
				'bank_details_changed_admin',
				self::admin_email(),
				/* translators: %s: site name */
				sprintf( __( '[%s] Partner changed bank details', 'affiliate-captain' ), $site ),
				/* translators: 1: partner, 2: URL */
				sprintf( __( "%1\$s changed the bank details for payouts. Please check before the next payout run.\n\n%2\$s", 'affiliate-captain' ), $aff->name, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id ) ),
				array(
					'admin'        => true,
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
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
		$switched = self::switch_for_admin();
		try {
			self::send(
				'public_change_requested',
				self::admin_email(),
				/* translators: %s: site name */
				sprintf( __( '[%s] Partner wants to change name or website', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: partner, 2: URL */
				sprintf( __( "%1\$s changed the public name or website. The change is shown only after you approve it:\n\n%2\$s", 'affiliate-captain' ), $aff->name, admin_url( 'admin.php?page=wpac-affiliates&action=edit&id=' . (int) $id ) ),
				array(
					'admin'        => true,
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
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
			$key                    = $approved ? 'public_change_approved' : 'public_change_rejected';
			list( $subject, $body ) = self::render_template( $key, array( 'name' => $aff->name ) );
			self::send(
				'public_change_resolved',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => $key,
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * A partner requested an own code: mail to the operator (template code_request_admin).
	 *
	 * @param int    $id   Affiliate ID.
	 * @param string $code Requested code.
	 */
	public static function code_requested( $id, $code ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$switched = self::switch_for_admin();
		try {
			list( $subject, $body ) = self::render_template(
				'code_request_admin',
				array(
					'name'      => $aff->name,
					'code'      => (string) $code,
					'admin_url' => admin_url( 'admin.php?page=wpac-affiliates#wpac-code-requests' ),
				)
			);
			self::send(
				'code_requested',
				self::admin_email(),
				$subject,
				$body,
				array(
					'admin'        => true,
					'template'     => 'code_request_admin',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * Decision on a code request: mail to the partner (templates code_request_approved/rejected).
	 *
	 * @param int    $id       Affiliate ID.
	 * @param string $code     Code.
	 * @param bool   $approved Approved.
	 * @param string $reason   Reason of a rejection.
	 */
	public static function code_request_resolved( $id, $code, $approved, $reason = '' ) {
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff ) {
			return;
		}
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			$key  = $approved ? 'code_request_approved' : 'code_request_rejected';
			$vars = array(
				'name'       => $aff->name,
				'code'       => (string) $code,
				'portal_url' => self::portal_url( $aff ),
			);
			if ( $approved ) {
				$vars['link'] = add_query_arg( (string) Settings::get( 'referral_param' ), rawurlencode( (string) $code ), home_url( '/' ) );
			} else {
				$vars['reason'] = (string) $reason;
			}
			list( $subject, $body ) = self::render_template( $key, $vars );
			self::send(
				'code_request_resolved',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => $key,
					'affiliate_id' => (int) $aff->id,
				)
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
			list( $subject, $body ) = self::render_template( 'application_approved', self::welcome_vars( $aff ) );
			self::send(
				'application_approved',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'application_approved',
					'affiliate_id' => (int) $aff->id,
				)
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
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			list( $subject, $body ) = self::render_template( 'partner_created', self::welcome_vars( $aff ) );
			self::send(
				'partner_created',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'partner_created',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
	}

	/**
	 * Placeholder values of the welcome mails: partner link, code and commission rate (the
	 * shortest way to the first share), partner area and access.
	 *
	 * @param object $aff Affiliate.
	 * @return array
	 */
	private static function welcome_vars( $aff ) {
		$code  = \WPAffiliateCaptain\Codes\CodeRepository::main_code( (int) $aff->id );
		$terms = \WPAffiliateCaptain\Portal\Portal::conditions( $aff );
		return array(
			'name'       => $aff->name,
			// Without a code (normally created on activation) the link points to the partner area.
			'link'       => '' !== $code ? add_query_arg( (string) Settings::get( 'referral_param' ), rawurlencode( $code ), home_url( '/' ) ) : self::portal_url( $aff ),
			'code'       => '' !== $code ? $code : '—',
			'rate'       => $terms ? (string) reset( $terms ) : '',
			'portal_url' => self::portal_url( $aff ),
			'access'     => self::access_text( $aff ),
		);
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
		$key                    = in_array( $status, array( 'paused', 'terminated' ), true ) ? 'status_' . $status : 'status_active';
		list( $subject, $body ) = self::render_template(
			$key,
			array(
				'name'   => $aff->name,
				/* translators: %s: reason entered by the shop team */
				'reason' => $reason ? sprintf( __( 'Reason: %s', 'affiliate-captain' ), $reason ) : '',
			)
		);
		self::send(
			'status_changed',
			$aff->email,
			$subject,
			$body,
			array(
				'template'     => $key,
				'affiliate_id' => (int) $aff->id,
			)
		);
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
			list( $subject, $body ) = self::render_template(
				'application_rejected',
				array(
					'name'   => $aff->name,
					'reason' => (string) $reason,
				)
			);
			self::send(
				'application_rejected',
				$aff->email,
				$subject,
				$body,
				array(
					'template'     => 'application_rejected',
					'affiliate_id' => (int) $aff->id,
				)
			);
		} finally {
			Locale::restore( $switched );
		}
	}
}
