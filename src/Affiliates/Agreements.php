<?php
/**
 * Versioned terms acceptance protocol (version + document hash + time).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Scheduler;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Agreements {

	/**
	 * Current terms: page, version and SHA-256 of the page content.
	 * A partner group may define its own terms page and version.
	 *
	 * @param int $group_id Group (0 = program terms).
	 * @return array { page_id, version, hash, url }
	 */
	public static function current( $group_id = 0 ) {
		$page_id = (int) Settings::get( 'terms_page_id' );
		$version = (string) Settings::get( 'terms_version' );
		$group   = $group_id ? Groups::find( (int) $group_id ) : null;
		if ( $group && (int) $group->terms_page_id ) {
			$page_id = (int) $group->terms_page_id;
			$version = '' !== (string) $group->agreement_version ? (string) $group->agreement_version : $version;
		}
		$post = $page_id ? get_post( $page_id ) : null;
		return array(
			'page_id' => $page_id,
			'version' => $version,
			'hash'    => $post ? hash( 'sha256', $post->post_title . "\n" . $post->post_content ) : '',
			'url'     => $post ? get_permalink( $post ) : '',
		);
	}

	/**
	 * Keep the text of the terms as shown (once per version hash), so the proof survives later
	 * edits of the page – also for guests, whose acceptance is recorded only after the e-mail
	 * confirmation.
	 *
	 * @param array $terms Terms as shown (page_id, hash).
	 * @return bool Whether the text was stored now.
	 */
	public static function store_text( array $terms ) {
		if ( empty( $terms['page_id'] ) || '' === (string) $terms['hash'] || false !== get_option( 'wpac_terms_text_' . $terms['hash'] ) ) {
			return false;
		}
		$post = get_post( (int) $terms['page_id'] );
		if ( ! $post || hash( 'sha256', $post->post_title . "\n" . $post->post_content ) !== $terms['hash'] ) {
			return false;
		}
		return (bool) add_option( 'wpac_terms_text_' . $terms['hash'], array( 'title' => $post->post_title, 'content' => $post->post_content, 'page_id' => (int) $post->ID, 'stored_at' => Db::now() ), '', false );
	}

	/**
	 * Record an acceptance or a decline.
	 *
	 * @param int        $affiliate_id Affiliate.
	 * @param string     $result       accepted|declined.
	 * @param array|null $terms        Terms as shown (version, hash); default: current.
	 * @return int Record ID.
	 */
	public static function record( $affiliate_id, $result = 'accepted', $terms = null ) {
		global $wpdb;
		$terms = is_array( $terms ) ? $terms : self::current( self::group_of( $affiliate_id ) );
		// Proof of what was accepted: the text of each version is kept once (by hash),
		// together with page and revision (editing the page later does not change it).
		$post     = ! empty( $terms['page_id'] ) ? get_post( (int) $terms['page_id'] ) : null;
		$revision = 0;
		if ( $post && function_exists( 'wp_get_post_revisions' ) ) {
			$revs     = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1, 'fields' => 'ids' ) );
			$revision = $revs ? (int) reset( $revs ) : 0;
		}
		self::store_text( $terms );
		$wpdb->insert(
			Db::table( 'agreements' ),
			array(
				'affiliate_id' => (int) $affiliate_id,
				'version'      => $terms['version'],
				'doc_hash'     => $terms['hash'],
				'result'       => 'declined' === $result ? 'declined' : 'accepted',
				'user_id'      => get_current_user_id(),
				'created_at'   => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit::log(
			'agreement.' . ( 'declined' === $result ? 'declined' : 'accepted' ),
			'affiliate',
			(int) $affiliate_id,
			array(
				'version'     => $terms['version'],
				'hash'        => $terms['hash'],
				'page_id'     => isset( $terms['page_id'] ) ? (int) $terms['page_id'] : 0,
				'revision_id' => $revision,
				// Time of the tick (for guests the record is created at the e-mail confirmation).
				'accepted_at' => ! empty( $terms['accepted_at'] ) ? (string) $terms['accepted_at'] : Db::now(),
			)
		);
		return $id;
	}

	/**
	 * Latest accepted agreement of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return object|null
	 */
	public static function latest( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'agreements' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d AND result = 'accepted' ORDER BY id DESC LIMIT 1", $affiliate_id ) );
	}

	/**
	 * Whether the affiliate accepted the current version of the current terms page. Editing the
	 * page (typo, layout) does not ask all partners to accept again – increase the version for
	 * material changes. But the version alone is not enough: a group with its own terms page and
	 * no own version shares the program's version number, so the page that was accepted must be
	 * the current one too (otherwise accepting page A would count for page B).
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return bool
	 */
	public static function has_current( $affiliate_id ) {
		$latest = self::latest( $affiliate_id );
		$terms  = self::current( self::group_of( $affiliate_id ) );
		return $latest && self::matches( (string) $latest->version, (string) $latest->doc_hash, $terms );
	}

	/**
	 * Whether an acceptance (version + page hash) covers the given terms.
	 *
	 * @param string $version Accepted version.
	 * @param string $hash    Hash of the accepted page text.
	 * @param array  $terms   Current terms (page_id, version, hash).
	 * @return bool
	 */
	public static function matches( $version, $hash, array $terms ) {
		if ( (string) $version !== (string) $terms['version'] ) {
			return false;
		}
		if ( '' === (string) $hash || (string) $hash === (string) $terms['hash'] ) {
			return true;
		}
		// The text was edited since, or it is another page: the stored text of the acceptance
		// names its page. Acceptances from before the page was stored with it count by version.
		$text = get_option( 'wpac_terms_text_' . $hash );
		if ( is_array( $text ) && ! empty( $text['page_id'] ) ) {
			return (int) $text['page_id'] === (int) $terms['page_id'];
		}
		return true;
	}

	/** Background batches of the "accept the current terms" reminder. */
	const REMIND_HOOK = 'wpac_terms_remind';

	/** State of the reminder run (option). */
	const REMIND_OPTION = 'wpac_terms_remind';

	/**
	 * Active partners who have not accepted the current version of their terms (one query).
	 *
	 * @param int $after_id Only IDs above this one.
	 * @param int $limit    Max. partners to look at (0 = all).
	 * @return array [ list of partner IDs without current acceptance, last ID looked at ]
	 */
	public static function missing_current( $after_id = 0, $limit = 0 ) {
		global $wpdb;
		$a     = Db::table( 'affiliates' );
		$g     = Db::table( 'agreements' );
		$limit = (int) $limit > 0 ? ' LIMIT ' . (int) $limit : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names and an integer only.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT a.id, a.group_id, ( SELECT g.version FROM {$g} g WHERE g.affiliate_id = a.id AND g.result = 'accepted' ORDER BY g.id DESC LIMIT 1 ) AS version, ( SELECT g.doc_hash FROM {$g} g WHERE g.affiliate_id = a.id AND g.result = 'accepted' ORDER BY g.id DESC LIMIT 1 ) AS doc_hash FROM {$a} a WHERE a.status = 'active' AND a.id > %d ORDER BY a.id ASC{$limit}", (int) $after_id ) );
		$current = array();
		$missing = array();
		$last    = (int) $after_id;
		foreach ( (array) $rows as $r ) {
			$last  = (int) $r->id;
			$group = (int) $r->group_id;
			if ( ! isset( $current[ $group ] ) ) {
				$current[ $group ] = self::current( $group );
			}
			if ( ! $current[ $group ]['page_id'] ) {
				continue; // No terms page: nothing to accept.
			}
			if ( null === $r->version || ! self::matches( (string) $r->version, (string) $r->doc_hash, $current[ $group ] ) ) {
				$missing[] = (int) $r->id;
			}
		}
		return array( $missing, $last );
	}

	/**
	 * Start the reminder run: every active partner without the current acceptance gets one
	 * e-mail with the link to the partner area (in batches in the background; at most one run
	 * per day, and each partner at most once a week).
	 *
	 * @return int|\WP_Error Number of partners that will be asked.
	 */
	public static function start_reminders() {
		$state = get_option( self::REMIND_OPTION );
		if ( is_array( $state ) && empty( $state['done'] ) && (int) $state['started'] > time() - HOUR_IN_SECONDS ) {
			return new \WP_Error( 'wpac_running', __( 'The reminders are already being sent.', 'affiliate-captain' ) );
		}
		if ( is_array( $state ) && (int) $state['started'] > time() - DAY_IN_SECONDS ) {
			return new \WP_Error( 'wpac_rate_limited', __( 'Reminders were already sent in the last 24 hours. Please wait a day before asking again.', 'affiliate-captain' ) );
		}
		if ( Settings::get( 'maintenance_mode' ) ) {
			// Would wait silently until maintenance ends (R5-O9).
			return new \WP_Error( 'wpac_maintenance', __( 'Maintenance mode is on: no reminders are sent. Switch it off first (Settings → Data & maintenance).', 'affiliate-captain' ) );
		}
		list( $missing ) = self::missing_current();
		if ( ! $missing ) {
			return new \WP_Error( 'wpac_none', __( 'All active partners have accepted the current terms.', 'affiliate-captain' ) );
		}
		$missing = self::reminder_recipients( $missing );
		if ( ! $missing ) {
			return new \WP_Error( 'wpac_none', __( 'All partners without the current acceptance were reminded in the last 7 days, have restricted processing or no valid e-mail address – nobody is asked now.', 'affiliate-captain' ) );
		}
		update_option(
			self::REMIND_OPTION,
			array(
				'started' => time(),
				'cursor'  => 0,
				'sent'    => 0,
				'done'    => 0,
				'total'   => count( $missing ),
			),
			false
		);
		Audit::log( 'agreement.reminders_started', 'settings', 0, array( 'partners' => count( $missing ) ) );
		self::remind_batch();
		return count( $missing );
	}

	/**
	 * Partners of a list who really get a reminder now: not restricted, not reminded in the last
	 * 7 days and with a valid e-mail address (the count shown before and after sending).
	 *
	 * @param int[] $ids Partners without the current acceptance.
	 * @return int[]
	 */
	public static function reminder_recipients( array $ids ) {
		$out = array();
		AffiliateRepository::find_many( $ids );
		foreach ( $ids as $id ) {
			$aff = AffiliateRepository::find( (int) $id );
			if ( $aff && is_email( $aff->email ) && ! \WPAffiliateCaptain\Privacy\Restriction::is_restricted( (int) $id ) && ! get_transient( 'wpac_terms_reminded_' . (int) $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Send one batch of reminders and schedule the next.
	 *
	 * @param int $size Partners per batch.
	 */
	public static function remind_batch( $size = 50 ) {
		$state = get_option( self::REMIND_OPTION );
		if ( ! is_array( $state ) || ! empty( $state['done'] ) ) {
			return;
		}
		if ( Settings::get( 'maintenance_mode' ) ) {
			Scheduler::once( self::REMIND_HOOK, HOUR_IN_SECONDS ); // Wait until maintenance ends (shown in the partner list).
			$state['paused'] = time();
			update_option( self::REMIND_OPTION, $state, false );
			return;
		}
		unset( $state['paused'] );
		list( $missing, $last ) = self::missing_current( (int) $state['cursor'], $size );
		foreach ( $missing as $id ) {
			if ( \WPAffiliateCaptain\Privacy\Restriction::is_restricted( $id ) || get_transient( 'wpac_terms_reminded_' . $id ) ) {
				continue;
			}
			if ( self::send_reminder( $id ) ) {
				set_transient( 'wpac_terms_reminded_' . $id, 1, 7 * DAY_IN_SECONDS );
				++$state['sent'];
			}
		}
		$moved           = $last > (int) $state['cursor'];
		$state['cursor'] = $last;
		if ( ! $moved || self::no_more_after( $last ) ) {
			$state['done'] = time();
			Audit::log( 'agreement.reminders_sent', 'settings', 0, array( 'sent' => (int) $state['sent'] ) );
		} else {
			Scheduler::once( self::REMIND_HOOK, 60 );
		}
		update_option( self::REMIND_OPTION, $state, false );
	}

	/**
	 * Whether no active partner has an ID above $id.
	 *
	 * @param int $id Last ID.
	 * @return bool
	 */
	private static function no_more_after( $id ) {
		global $wpdb;
		$a = Db::table( 'affiliates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$a} WHERE status = 'active' AND id > %d LIMIT 1", (int) $id ) );
	}

	/**
	 * Reminder e-mail to one partner.
	 *
	 * @param int $id Partner.
	 * @return bool
	 */
	public static function send_reminder( $id ) {
		$aff = AffiliateRepository::find( (int) $id );
		if ( ! $aff || ! is_email( $aff->email ) ) {
			return false;
		}
		$terms       = self::current( (int) $aff->group_id );
		$wpac_locale = \WPAffiliateCaptain\Core\Locale::switch_for_affiliate( $aff );
		try {
			$url  = \WPAffiliateCaptain\Affiliates\Notifications::portal_url( $aff );
			$sent = Notifications::send(
				'terms_reminder',
				$aff->email,
				/* translators: %s: site name */
				sprintf( __( '[%s] Please accept the current partner terms', 'affiliate-captain' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				/* translators: 1: name, 2: version, 3: terms URL, 4: partner area URL */
				sprintf( __( "Hello %1\$s,\n\nplease read and accept the current partner program terms (version %2\$s). We need your acceptance to issue credit notes and pay out your commissions.\n\nTerms: %3\$s\nAccept them in your partner area: %4\$s\n\nThank you!", 'affiliate-captain' ), $aff->name, $terms['version'], $terms['url'] ? $terms['url'] : $url, $url ),
				array( 'affiliate_id' => (int) $aff->id )
			);
		} finally {
			\WPAffiliateCaptain\Core\Locale::restore( $wpac_locale );
		}
		return $sent;
	}

	/**
	 * State of the last reminder run (or null).
	 *
	 * @return array|null
	 */
	public static function reminder_state() {
		$state = get_option( self::REMIND_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Group of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return int
	 */
	public static function group_of( $affiliate_id ) {
		$aff = AffiliateRepository::find( (int) $affiliate_id );
		return $aff ? (int) $aff->group_id : 0;
	}

	/**
	 * All records of an affiliate.
	 *
	 * @param int $affiliate_id Affiliate.
	 * @return array
	 */
	public static function for_affiliate( $affiliate_id ) {
		global $wpdb;
		$t = Db::table( 'agreements' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE affiliate_id = %d ORDER BY id DESC", $affiliate_id ) );
	}
}
