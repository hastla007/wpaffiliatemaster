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
				if ( self::send_optional( 'broadcast', $aff, $state['subject'], $body ) ) {
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
			$sent = self::send(
				'credit_note',
				$aff->email,
				/* translators: 1: site name, 2: document number */
				sprintf( __( '[%1$s] Credit note %2$s', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
				/* translators: 1: name, 2: document number, 3: amount, 4: portal URL */
				sprintf( __( "Hello %1\$s,\n\nwe have issued credit note %2\$s for your commissions (total %3\$s). It is attached to this e-mail and can be downloaded in your partner area: %4\$s\n\nPlease check it. If something is wrong, you can object to it in the partner area.", 'affiliate-captain' ), $aff->name, $p->number, html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ), add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) ),
				array(
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
			$sent = self::send(
				'credit_note_cancelled',
				$aff->email,
				/* translators: 1: site name, 2: cancelled credit note number */
				sprintf( __( '[%1$s] Credit note %2$s cancelled', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
				/* translators: 1: name, 2: credit note number, 3: cancellation number, 4: portal URL */
				sprintf( __( "Hello %1\$s,\n\nwe have cancelled credit note %2\$s with the attached cancellation document %3\$s. The commissions remain in your account and are paid out with a new credit note.\n\nPartner area: %4\$s", 'affiliate-captain' ), $aff->name, $p->number, (string) $p->storno_number, add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) ),
				array(
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
					? __( 'The change affects the VAT on credit notes. It only takes effect after you approved it (commissions earned before keep the previous treatment). Please check the details (e.g. the VAT ID with VIES):', 'affiliate-captain' )
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
	 *                                 attachments (array), affiliate_id (int).
	 * @return bool Whether the mail was handed over (also true if a filter skipped it on purpose).
	 */
	public static function send( $type, $to, $subject, $message, array $args = array() ) {
		$args += array(
			'admin'        => false,
			'reply_to'     => null,
			'headers'      => array(),
			'attachments'  => array(),
			'affiliate_id' => 0,
		);
		$headers = (array) $args['headers'];
		$from    = (string) Settings::get( 'mail_from_email' );
		$name    = trim( (string) Settings::get( 'mail_from_name' ) );
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
		);
		/**
		 * Filter an e-mail of Affiliate Captain before it is sent: change recipient, subject,
		 * text, headers (e.g. "Content-Type: text/html; charset=UTF-8" for an HTML version) or
		 * attachments – or return false to not send it.
		 *
		 * @param array|false $mail    to, subject, message, headers, attachments.
		 * @param string      $type    Mail type (payout_paid, application_received, monthly_partner …).
		 * @param array       $context type, admin (mail to the operator), affiliate_id.
		 */
		$mail = apply_filters( 'wpac_mail', $mail, (string) $type, $context );
		if ( ! is_array( $mail ) ) {
			Log::debug( 'E-mail skipped by a filter', $context );
			return true;
		}
		$mail += array(
			'to'          => '',
			'subject'     => '',
			'message'     => '',
			'headers'     => array(),
			'attachments' => array(),
		);
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 20 );
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
			Log::warning( 'E-mail could not be sent', $context + array( 'error' => \WPAffiliateCaptain\Core\Db::cut( (string) $error, 300 ) ) );
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
	 * Sender name from the settings while one of our mails is sent (address unchanged).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function filter_from_name( $name ) {
		return self::$sending && '' !== self::$from_name ? self::$from_name : $name;
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
		$locale = '';
		$user   = function_exists( 'get_user_by' ) ? get_user_by( 'email', self::admin_email() ) : false;
		if ( $user && function_exists( 'get_user_locale' ) ) {
			$locale = (string) get_user_locale( $user );
		}
		if ( '' === $locale ) {
			$locale = (string) get_option( 'WPLANG' );
			$locale = '' !== $locale ? $locale : 'en_US';
		}
		return Locale::switch_to( $locale );
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
	 * Unsubscribe link of a mail (init): checks the signature, stores the choice and shows a
	 * confirmation with a link to undo it.
	 */
	public static function handle_unsubscribe() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link from an e-mail (no session).
		if ( ! isset( $_GET['wpac_unsubscribe'], $_GET['wpac_aff'], $_GET['wpac_sig'] ) ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['wpac_unsubscribe'] ) );
		$id   = absint( $_GET['wpac_aff'] );
		$sig  = sanitize_text_field( wp_unslash( $_GET['wpac_sig'] ) );
		$on   = ! empty( $_GET['wpac_on'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$aff = AffiliateRepository::find( $id );
		if ( ! $aff || ! in_array( $type, self::OPTOUT_TYPES, true ) || ! hash_equals( self::unsubscribe_sig( $id, $type ), $sig ) ) {
			wp_die( esc_html__( 'This link is not valid.', 'affiliate-captain' ), esc_html__( 'E-mail settings', 'affiliate-captain' ), array( 'response' => 400 ) );
		}
		self::set_opt_out( $id, $type, ! $on );
		$switched = Locale::switch_for_affiliate( $aff );
		$text     = $on
			? __( 'You will receive these e-mails again.', 'affiliate-captain' )
			: __( 'You have been unsubscribed. You will no longer receive these e-mails from the partner program.', 'affiliate-captain' );
		$link     = $on ? '' : ' <a href="' . esc_url( self::unsubscribe_url( $aff, $type, false ) ) . '">' . esc_html__( 'Undo', 'affiliate-captain' ) . '</a>';
		$title    = __( 'E-mail settings', 'affiliate-captain' );
		Locale::restore( $switched );
		wp_die( '<p>' . esc_html( $text ) . $link . '</p>', esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
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
	 * @return bool
	 */
	public static function send_optional( $type, $aff, $subject, $message ) {
		return self::send(
			$type,
			$aff->email,
			$subject,
			$message . self::unsubscribe_footer( $aff, $type ),
			array(
				'affiliate_id' => (int) $aff->id,
				'headers'      => array( 'List-Unsubscribe: <' . self::unsubscribe_url( $aff, $type ) . '>' ),
			)
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
		if ( ! self::$commission_events ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_commissions' ) );
		}
		self::$commission_events[ (int) $c->affiliate_id ][ $event ][] = $c;
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
				/* translators: 1: name, 2: list of events, 3: portal URL */
				$body = sprintf( __( "Hello %1\$s,\n\nthere is news about your commissions:\n\n%2\$s\n\nDetails are in your partner area: %3\$s", 'affiliate-captain' ), $aff->name, implode( "\n", $lines ), self::portal_url( $aff ) );
				/* translators: %s: site name */
				if ( self::send_optional( 'commission', $aff, sprintf( __( '[%s] News about your commissions', 'affiliate-captain' ), $site ), $body ) ) {
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
			$text = $manual
				/* translators: 1: name, 2: amount, 3: number, 4: portal URL */
				? __( "Hello %1\$s,\n\nwe have paid out %2\$s to you with the payment method agreed with you (credit note %3\$s).\n\nYou can download the credit note in your partner area: %4\$s", 'affiliate-captain' )
				/* translators: 1: name, 2: amount, 3: number, 4: portal URL */
				: __( "Hello %1\$s,\n\nwe have transferred %2\$s to your bank account (credit note %3\$s).\n\nYou can download the credit note in your partner area: %4\$s", 'affiliate-captain' );
			self::send(
				'payout_paid',
				$aff->email,
				$manual
					/* translators: 1: site name, 2: payout number */
					? sprintf( __( '[%1$s] Payout %2$s paid', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number )
					/* translators: 1: site name, 2: payout number */
					: sprintf( __( '[%1$s] Payout %2$s transferred', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->number ),
				sprintf( $text, $aff->name, html_entity_decode( \WPAffiliateCaptain\Core\Money::format( (int) $p->amount_minor, $p->currency ), ENT_QUOTES, 'UTF-8' ), $p->number, $portal ? add_query_arg( 'wpac_tab', 'payouts', self::portal_url( $aff ) ) : home_url( '/' ) ),
				array( 'affiliate_id' => (int) $aff->id )
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
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$switched = Locale::switch_for_affiliate( $aff );
		try {
			self::send(
				'application_confirmed',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] We have received your partner application', 'affiliate-captain' ), $site ),
				/* translators: %s: site name */
				sprintf( __( "Hello,\n\nthank you for your application to the partner program of %s. We will review it and let you know by e-mail as soon as we have decided.", 'affiliate-captain' ), $site ),
				array( 'affiliate_id' => (int) $aff->id )
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
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		self::send(
			'confirm_application',
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
			self::send(
				'already_registered',
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
			self::send(
				'bank_details_changed',
				$to,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your bank details were changed', 'affiliate-captain' ), $site ),
				/* translators: %s: name */
				sprintf( __( "Hello %s,\n\nthe bank details for your commission payouts were just changed. If you did not make this change, please contact us immediately.", 'affiliate-captain' ), $aff->name ),
				array( 'affiliate_id' => (int) $aff->id )
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
			self::send(
				'public_change_resolved',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your profile change', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				$approved
					? __( "Hello,\n\nyour new name or website for the partner program was approved and is shown from now on.", 'affiliate-captain' )
					: __( "Hello,\n\nyour requested change of name or website for the partner program was not approved. The previous details remain. If you have questions, please contact us.", 'affiliate-captain' ),
				array( 'affiliate_id' => (int) $aff->id )
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
			self::send(
				'application_approved',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your affiliate application was approved', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: name, 2: access instructions */
				sprintf( __( "Hello %1\$s,\n\nwelcome to our partner program!\n\n%2\$s", 'affiliate-captain' ), $aff->name, self::access_text( $aff ) ),
				array( 'affiliate_id' => (int) $aff->id )
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
			self::send(
				'partner_created',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Welcome to our partner program', 'affiliate-captain' ), $site ),
				/* translators: 1: name, 2: site name, 3: access instructions */
				sprintf( __( "Hello %1\$s,\n\n%2\$s has set up a partner account for you.\n\n%3\$s", 'affiliate-captain' ), $aff->name, $site, self::access_text( $aff ) ),
				array( 'affiliate_id' => (int) $aff->id )
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
		self::send( 'status_changed', $aff->email, $subject, $message, array( 'affiliate_id' => (int) $aff->id ) );
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
			self::send(
				'application_rejected',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Your affiliate application', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: name, 2: reason */
				sprintf( __( "Hello %1\$s,\n\nunfortunately we cannot accept your application at this time.\n\n%2\$s", 'affiliate-captain' ), $aff->name, $reason ),
				array( 'affiliate_id' => (int) $aff->id )
			);
		} finally {
			Locale::restore( $switched );
		}
	}
}
