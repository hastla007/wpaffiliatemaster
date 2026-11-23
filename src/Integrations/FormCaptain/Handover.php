<?php
/**
 * Form Captain hand-over of partner applications (TODO AFF-SU-01, owner decision 16.11.2026).
 *
 * The connector action "Partner application to Affiliate Captain" runs in the Form Captain worker
 * (never in the submission request). It feeds the mapped values into the same double opt-in as the
 * built-in form ([wpac_affiliate_registration]): the application waits in Core\Pending, the
 * applicant gets the confirmation mail of Affiliate Captain, and only the click creates the
 * partner – through Registration::finalize() and AffiliateRepository::create(), always in status
 * `pending`, also with "Approve automatically". Approval, rejection, partner codes and commission
 * rules stay with the partner manager. No table is written here.
 *
 * Per Form Captain entry (site + form + entry) one small record is kept in Core\Pending under
 * `fc_<sha256>`: state, token hash, partner ID, form/entry ID – no name, no e-mail address, no
 * field value (no parallel data storage). It makes repeated deliveries idempotent and lets the
 * deletion of the entry or the withdrawal of its consent reach the application.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations\FormCaptain;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Pending;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Handover {

	/** Prefix of the hand-over records in Core\Pending. */
	const RECORD_PREFIX = 'fc_';

	/**
	 * Lifetime of a hand-over record: longer than any pending application is kept (setting
	 * pending_application_days, default 365 days), so a deletion of the entry still finds it.
	 */
	const RECORD_DAYS = 400;

	/** Lifetime of the unconfirmed application (as in the built-in form). */
	const CONFIRM_TTL = 2 * DAY_IN_SECONDS;

	/** Hand-overs per form and hour (filter wpac_rate_limit, bucket form_captain_handover). */
	const PER_FORM_HOUR = 30;

	/** Mapping keys this action reads (allowlist; anything else in $job['values'] is ignored). */
	const FIELDS = array( 'name', 'email', 'website', 'channel', 'message', 'terms', 'referrer' );

	/**
	 * Action callback (Form Captain contract v1): $job with form_id, entry_id, idempotency_key,
	 * settings, values (mapped fields only), test_mode. Unknown keys are ignored.
	 *
	 * @param array $job Job.
	 * @return array { status: success|retry|fail, code, message, remote_id? }
	 */
	public static function handle( array $job ) {
		$settings = isset( $job['settings'] ) && is_array( $job['settings'] ) ? $job['settings'] : array();
		$values   = isset( $job['values'] ) && is_array( $job['values'] ) ? array_intersect_key( $job['values'], array_flip( self::FIELDS ) ) : array();

		if ( self::unavailable() ) {
			return self::result( 'retry', 'affiliate_captain_unavailable', 'Affiliate Captain is in maintenance mode or its database update is not finished.' );
		}
		$group_id = isset( $settings['group_id'] ) && is_scalar( $settings['group_id'] ) ? absint( $settings['group_id'] ) : 0;
		$problem  = self::group_problem( $group_id );
		if ( '' !== $problem ) {
			return self::result( 'fail', $problem, 'The partner group in the action settings cannot be used for hand-overs.' );
		}
		$terms = Agreements::current( $group_id );
		if ( $terms['page_id'] && (string) $terms['version'] !== trim( isset( $settings['terms_version'] ) && is_scalar( $settings['terms_version'] ) ? (string) $settings['terms_version'] : '' ) ) {
			return self::result( 'fail', 'terms_version_mismatch', 'The terms version in the action settings is not the current version in Affiliate Captain.' );
		}
		if ( ! self::confirm_url() ) {
			return self::result( 'retry', 'registration_page_missing', 'Affiliate Captain has no published registration page for the confirmation link.' );
		}
		if ( ! empty( $job['test_mode'] ) ) {
			// Test run: configuration checked, nothing stored, no mail.
			return self::result( 'success', 'test_ok', 'Test mode - no application was handed over.' );
		}

		$form_id  = isset( $job['form_id'] ) ? absint( $job['form_id'] ) : 0;
		$entry_id = isset( $job['entry_id'] ) ? absint( $job['entry_id'] ) : 0;
		if ( ! $form_id || ! $entry_id ) {
			return self::result( 'fail', 'invalid_job', 'Form or entry ID missing.' );
		}
		$ref  = self::ref( $form_id, $entry_id );
		$lock = Db::lock( self::lock_name( $ref ), MINUTE_IN_SECONDS );
		if ( '' === $lock ) {
			return self::result( 'retry', 'locked', 'The same entry is being handed over right now.' );
		}
		try {
			return self::handover( $ref, $form_id, $entry_id, $group_id, $terms, $values );
		} finally {
			Db::unlock( self::lock_name( $ref ), $lock );
		}
	}

	/**
	 * The hand-over itself (under the lock of the entry).
	 *
	 * @param string $ref      Record key.
	 * @param int    $form_id  Form Captain form.
	 * @param int    $entry_id Form Captain entry.
	 * @param int    $group_id Partner group (0 = none).
	 * @param array  $terms    Current terms of the group (Agreements::current()).
	 * @param array  $values   Mapped values.
	 * @return array Result.
	 */
	private static function handover( $ref, $form_id, $entry_id, $group_id, array $terms, array $values ) {
		// Idempotency: the same entry always gives the same result, never a second application.
		$record = self::record( $ref );
		if ( $record ) {
			return self::result( 'success', 'created' === $record['state'] ? 'confirm_sent' : (string) $record['state'], '', $ref );
		}

		$data = self::application( $values );
		if ( is_string( $data ) ) {
			return self::result( 'fail', $data, 'terms_required' === $data ? 'The partner program terms were not accepted.' : 'A required field is missing or invalid.' );
		}
		if ( ! RateLimiter::allow_for( 'form_captain_handover', get_current_blog_id() . ':' . $form_id, self::PER_FORM_HOUR, HOUR_IN_SECONDS ) ) {
			return self::result( 'retry', 'rate_limited', 'Too many hand-overs from this form in the last hour.' );
		}

		// An existing partner (any status) gets no second account. Like the built-in form, the
		// address receives the "already registered" notice; the result says nothing about the partner.
		if ( AffiliateRepository::find_by_email( $data['email'] ) ) {
			self::save( $ref, 'already_exists', $form_id, $entry_id, $group_id );
			if ( Registration::mail_allowed( $data['email'] ) ) {
				Notifications::already_registered( $data['email'] );
			}
			self::audit( 'form_captain.application_handed_over', $form_id, $entry_id, $group_id, 'already_exists' );
			return self::result( 'success', 'already_exists', '', $ref );
		}
		// The per-address mail limit of the built-in form (no inbox flooding through forms).
		if ( ! Registration::mail_allowed( $data['email'] ) ) {
			return self::result( 'retry', 'rate_limited', 'Too many confirmation mails to this address today.' );
		}

		$url = self::confirm_url();
		if ( $terms['page_id'] ) {
			// The text accepted now is kept even if the page changes before the e-mail confirmation.
			Agreements::store_text( $terms );
		}
		$token = wp_generate_password( 32, false );
		$hash  = hash( 'sha256', $token );
		$app   = array(
			'user_id'          => 0, // The worker knows no visitor; a guest application like any other.
			'name'             => $data['name'],
			'email'            => $data['email'],
			'website'          => $data['website'],
			'channel'          => $data['channel'],
			'application_note' => $data['message'],
			'application_data' => array(),
			'group_id'         => $group_id,
			'referred_by'      => self::recruiter( $data['referrer'], $data['email'] ),
			'terms'            => $terms['page_id'] ? $terms + array( 'accepted_at' => Db::now() ) : null,
			'redirect'         => $url,
			'pending_only'     => true, // Registration::finalize(): never "active", whatever auto_approve says.
			'handover'         => $ref,
		);
		if ( ! Pending::set( 'app_' . $hash, $app, \WPAffiliateCaptain\Affiliates\Registration::confirm_ttl() ) ) {
			return self::result( 'retry', 'storage_failed', 'The application could not be stored.' );
		}
		if ( ! self::save( $ref, 'confirm_sent', $form_id, $entry_id, $group_id, $hash ) ) {
			Pending::delete( 'app_' . $hash );
			return self::result( 'retry', 'storage_failed', 'The hand-over record could not be stored.' );
		}
		Notifications::confirm_application( $data['email'], add_query_arg( 'wpac_confirm', rawurlencode( $token ), $url ) );
		self::audit( 'form_captain.application_handed_over', $form_id, $entry_id, $group_id, 'confirm_sent' );
		return self::result( 'success', 'confirm_sent', '', $ref );
	}

	/**
	 * Application fields from the mapped values, cleaned and shortened like the built-in form.
	 *
	 * @param array $values Mapped values (mapping key => value).
	 * @return array|string Fields, or the error code (invalid_field, terms_required).
	 */
	public static function application( array $values ) {
		$text = static function ( $key ) use ( $values ) {
			$value = isset( $values[ $key ] ) ? $values[ $key ] : '';
			if ( is_array( $value ) ) { // Multiple choice (select, checkbox).
				$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
			}
			return is_scalar( $value ) ? (string) $value : '';
		};
		$name  = Registration::cap( sanitize_text_field( $text( 'name' ) ), Registration::MAX_NAME );
		$email = sanitize_email( $text( 'email' ) );
		if ( '' === trim( $name ) || strlen( $email ) > Registration::MAX_EMAIL || ! is_email( $email ) ) {
			return 'invalid_field';
		}
		$terms = isset( $values['terms'] ) ? $values['terms'] : '';
		if ( true !== $terms && 1 !== $terms && '1' !== $terms ) {
			return 'terms_required'; // Consent field of Form Captain: '1' = given.
		}
		$website = Registration::cap( $text( 'website' ), Registration::MAX_URL );
		if ( '' !== $website && ! preg_match( '#^https?://#i', $website ) ) {
			$website = 'https://' . $website;
		}
		return array(
			'name'     => $name,
			'email'    => $email,
			'website'  => '' !== $website ? esc_url_raw( $website, array( 'http', 'https' ) ) : '',
			'channel'  => Registration::cap( sanitize_text_field( $text( 'channel' ) ), Registration::MAX_CHANNEL ),
			'message'  => Registration::cap( sanitize_textarea_field( $text( 'message' ) ), Registration::MAX_NOTE ),
			'referrer' => Db::cut( sanitize_text_field( $text( 'referrer' ) ), 100 ),
		);
	}

	/**
	 * Recruiting partner from the optional referrer code field: resolved on the server as a typed
	 * partner code (no vanity slug, no cookie, no session – no tracking data), and only an active
	 * partner who is not the applicant (Registration::recruiter_valid()). Unknown codes are ignored.
	 *
	 * @param string $code  Code from the form.
	 * @param string $email Applicant e-mail address.
	 * @return int Partner ID, 0 = none.
	 */
	public static function recruiter( $code, $email ) {
		if ( '' === (string) $code ) {
			return 0;
		}
		$resolved  = wpac_resolve_partner_code( (string) $code, array( 'context' => 'form_captain' ) );
		$recruiter = is_array( $resolved ) && ! empty( $resolved['affiliate_id'] ) ? (int) $resolved['affiliate_id'] : 0;
		return $recruiter && Registration::recruiter_valid( $recruiter, (string) $email, 0 ) ? $recruiter : 0;
	}

	/**
	 * Why a group cannot take hand-overs: unknown or inactive, private (its forms need the approval
	 * of a partner manager), or with required extra fields (not part of the mapping).
	 *
	 * @param int $group_id Group (0 = none: always fine).
	 * @return string Error code, '' = fine.
	 */
	public static function group_problem( $group_id ) {
		if ( ! $group_id ) {
			return '';
		}
		$group = Groups::find( (int) $group_id );
		if ( ! $group || 'active' !== (string) $group->status || ! (int) $group->public ) {
			return 'group_invalid';
		}
		foreach ( Groups::fields( $group ) as $field ) {
			if ( ! empty( $field['required'] ) ) {
				return 'group_fields_unsupported';
			}
		}
		return '';
	}

	/**
	 * Called by Registration::finalize() once the applicant confirmed a handed-over application:
	 * the record remembers the new partner (for a later deletion of the entry).
	 *
	 * @param string $ref          Record key.
	 * @param int    $affiliate_id New partner (status pending).
	 */
	public static function confirmed( $ref, $affiliate_id ) {
		$record = self::record( (string) $ref );
		if ( ! $record ) {
			// The entry was deleted while this confirmation ran: the application must not stay.
			\WPAffiliateCaptain\Privacy\Privacy::erase_applicant( (int) $affiliate_id );
			return;
		}
		$record['state']        = 'created';
		$record['affiliate_id'] = (int) $affiliate_id;
		$record['token_hash']   = '';
		Pending::set( self::RECORD_PREFIX . $ref, $record, self::RECORD_DAYS * DAY_IN_SECONDS );
		Audit::log(
			'form_captain.application_confirmed',
			'affiliate',
			(int) $affiliate_id,
			array(
				'form_id'  => (int) $record['form_id'],
				'entry_id' => (int) $record['entry_id'],
			)
		);
	}

	/**
	 * Form Captain deleted or anonymised an entry (form_captain_entry_deleted_v1).
	 *
	 * @param int    $entry_id Entry.
	 * @param int    $form_id  Form.
	 * @param string $reason   manual, retention, erasure, anonymized, form_deleted, consent_withdrawn …
	 */
	public static function entry_deleted( $entry_id, $form_id = 0, $reason = '' ) {
		self::forget( (int) $entry_id, (int) $form_id, 'entry_deleted' );
	}

	/**
	 * The consent of an entry was withdrawn (form_captain_consent_withdrawn_v1): the application
	 * rests on that submission, so it is handled like a deletion.
	 *
	 * @param int    $entry_id Entry.
	 * @param int    $form_id  Form.
	 * @param string $source   admin, api, cli, privacy_request, system.
	 */
	public static function consent_withdrawn( $entry_id, $form_id = 0, $source = '' ) {
		self::forget( (int) $entry_id, (int) $form_id, 'consent_withdrawn' );
	}

	/**
	 * Remove what an entry's hand-over left in Affiliate Captain, by Affiliate Captain's own
	 * privacy rules: an unconfirmed application is deleted; a partner still in status `pending`
	 * is anonymised like a never decided application (Privacy::erase_applicant()). A partner who
	 * was approved, rejected or ended is not touched – the audit log notes it, the partner manager
	 * decides (Art. 17 requests go through the privacy tools of WordPress).
	 *
	 * @param int    $entry_id Entry.
	 * @param int    $form_id  Form.
	 * @param string $why      entry_deleted|consent_withdrawn.
	 * @return string What happened: none, application_deleted, applicant_erased, kept.
	 */
	public static function forget( $entry_id, $form_id, $why ) {
		if ( ! $entry_id || ! $form_id ) {
			return 'none';
		}
		$ref    = self::ref( $form_id, $entry_id );
		$record = self::record( $ref );
		if ( ! $record ) {
			return 'none';
		}
		// The confirmation of the same application must not run in between (Registration uses this
		// lock): wait for a running one (a few seconds at most), it ends in confirmed().
		$lock = '';
		$name = 'wpac_appconf_' . substr( (string) $record['token_hash'], 0, 32 );
		for ( $try = 0; '' !== (string) $record['token_hash'] && '' === $lock && $try < 12; $try++ ) {
			$lock = Db::lock( $name, MINUTE_IN_SECONDS );
			if ( '' === $lock ) {
				usleep( 250000 );
			}
		}
		try {
			$record = self::record( $ref ); // Re-read: the confirmation may just have finished.
			if ( ! $record ) {
				return 'none';
			}
			$done = 'none';
			if ( 'confirm_sent' === $record['state'] && '' !== (string) $record['token_hash'] ) {
				Pending::delete( 'app_' . $record['token_hash'] );
				$done = 'application_deleted';
			} elseif ( 'created' === $record['state'] && $record['affiliate_id'] ) {
				$aff = AffiliateRepository::find( (int) $record['affiliate_id'] );
				if ( $aff && 'pending' === (string) $aff->status ) {
					$done = \WPAffiliateCaptain\Privacy\Privacy::erase_applicant( (int) $aff->id ) ? 'applicant_erased' : 'kept';
				} elseif ( $aff ) {
					$done = 'kept';
				}
			}
			Pending::delete( self::RECORD_PREFIX . $ref );
			Audit::log(
				'form_captain.' . ( 'consent_withdrawn' === $why ? 'consent_withdrawn' : 'entry_deleted' ),
				$record['affiliate_id'] ? 'affiliate' : 'form_captain_entry',
				$record['affiliate_id'] ? (int) $record['affiliate_id'] : (int) $entry_id,
				array(
					'form_id'  => (int) $form_id,
					'entry_id' => (int) $entry_id,
					'result'   => $done,
				)
			);
			return $done;
		} finally {
			Db::unlock( $name, $lock ); // No-op without a lock.
		}
	}

	/**
	 * Hand-over record of an entry.
	 *
	 * @param string $ref Record key.
	 * @return array|null { state, token_hash, affiliate_id, form_id, entry_id, group_id, at }
	 */
	public static function record( $ref ) {
		$record = Pending::get( self::RECORD_PREFIX . $ref );
		return is_array( $record ) && isset( $record['state'] ) ? $record + array(
			'token_hash'   => '',
			'affiliate_id' => 0,
			'form_id'      => 0,
			'entry_id'     => 0,
		) : null;
	}

	/**
	 * Record key of an entry: site + form + entry. Two actions on the same form never create two
	 * applications for one entry.
	 *
	 * @param int $form_id  Form.
	 * @param int $entry_id Entry.
	 * @return string SHA-256.
	 */
	public static function ref( $form_id, $entry_id ) {
		return hash( 'sha256', 'form-captain:' . get_current_blog_id() . ':' . (int) $form_id . ':' . (int) $entry_id );
	}

	/**
	 * Confirmation page: the page with the registration form (it shows the confirmation step).
	 *
	 * @return string URL, '' = none published.
	 */
	public static function confirm_url() {
		$page = (int) Settings::get( 'registration_page_id' );
		if ( ! $page || 'publish' !== get_post_status( $page ) ) {
			return '';
		}
		$url = get_permalink( $page );
		// wpac_review: the success message says "we will review it", also with automatic approval.
		return $url ? add_query_arg( 'wpac_review', '1', $url ) : '';
	}

	/**
	 * Whether Affiliate Captain cannot take applications right now (temporary: retry later).
	 *
	 * @return bool
	 */
	public static function unavailable() {
		return (bool) Settings::get( 'maintenance_mode' ) || ! \WPAffiliateCaptain\Core\Installer::schema_current();
	}

	/**
	 * Store a hand-over record (no personal data).
	 *
	 * @param string $ref        Record key.
	 * @param string $state      confirm_sent|already_exists.
	 * @param int    $form_id    Form.
	 * @param int    $entry_id   Entry.
	 * @param int    $group_id   Group.
	 * @param string $token_hash Hash of the confirmation token.
	 * @return bool
	 */
	private static function save( $ref, $state, $form_id, $entry_id, $group_id, $token_hash = '' ) {
		return Pending::set(
			self::RECORD_PREFIX . $ref,
			array(
				'state'        => $state,
				'token_hash'   => $token_hash,
				'affiliate_id' => 0,
				'form_id'      => (int) $form_id,
				'entry_id'     => (int) $entry_id,
				'group_id'     => (int) $group_id,
				'at'           => Db::now(),
			),
			self::RECORD_DAYS * DAY_IN_SECONDS
		);
	}

	/**
	 * Audit entry of a hand-over (IDs and result only).
	 *
	 * @param string $action   Audit action.
	 * @param int    $form_id  Form.
	 * @param int    $entry_id Entry.
	 * @param int    $group_id Group.
	 * @param string $result   Result code.
	 */
	private static function audit( $action, $form_id, $entry_id, $group_id, $result ) {
		Audit::log(
			$action,
			'form_captain_entry',
			(int) $entry_id,
			array(
				'form_id'  => (int) $form_id,
				'group_id' => (int) $group_id,
				'result'   => $result,
			)
		);
	}

	/**
	 * Lock name of an entry's hand-over.
	 *
	 * @param string $ref Record key.
	 * @return string
	 */
	private static function lock_name( $ref ) {
		return 'wpac_fc_lock_' . substr( (string) $ref, 0, 32 );
	}

	/**
	 * Result array of the contract. Messages never contain personal data; the remote ID is an
	 * opaque reference of the hand-over (never the partner ID).
	 *
	 * @param string $status  success|retry|fail.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param string $ref     Record key ('' = no remote ID).
	 * @return array
	 */
	private static function result( $status, $code, $message = '', $ref = '' ) {
		$out = array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
		if ( '' !== $ref ) {
			$out['remote_id'] = 'wpac-fc-' . substr( (string) $ref, 0, 16 );
		}
		return $out;
	}
}
