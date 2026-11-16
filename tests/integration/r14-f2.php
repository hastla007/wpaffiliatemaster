<?php
/**
 * Feature f2 (0.15.0): partners request an own code (e.g. MARIA10) in the partner area; the
 * operator approves or rejects it. Validation, uniqueness (also against a parallel request),
 * approval (additional code, main code, coupon), rejection, tracker ignores requested codes,
 * monthly limit, setting, mails (templates), privacy export and erasure.
 * Included from run.php (helpers check(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Privacy\Privacy;

echo "R14 f2 partner code requests\n";

$f2_root = dirname( __DIR__, 2 );
$f2_src  = static function ( $file ) use ( $f2_root ) {
	return (string) file_get_contents( $f2_root . '/' . $file );
};
$f2_set  = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$f2_err  = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : ( is_string( $r ) ? $r : 'ok' );
};
$f2_audits = static function ( $action, $aff ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = %s AND object_type = 'affiliate' AND object_id = %d", $action, $aff ) );
};
$f2_rows = static function ( $code ) use ( $wpdb ) {
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . t( 'partner_codes' ) . ' WHERE code = %s', $code ) );
};
$GLOBALS['f2_sent'] = array();
add_action(
	'wpac_mail_sent',
	static function ( $type, $mail, $ok, $context ) {
		$GLOBALS['f2_sent'][] = array( 'type' => $type, 'mail' => $mail, 'context' => $context );
	},
	10,
	4
);
$f2_last = static function ( $type ) {
	foreach ( array_reverse( $GLOBALS['f2_sent'] ) as $m ) {
		if ( $m['type'] === $type ) {
			return $m;
		}
	}
	return null;
};
$f2_set( array( 'referral_param' => 'partner' ) );
$GLOBALS['test_wc_coupons'] = isset( $GLOBALS['test_wc_coupons'] ) ? $GLOBALS['test_wc_coupons'] : array();

$f2_a = AffiliateRepository::create( array( 'name' => 'Maria Zwei', 'email' => 'maria.f2@example.de', 'status' => 'active', 'slug' => 'maria-f2' ) );
$f2_b = AffiliateRepository::create( array( 'name' => 'Bernd Zwei', 'email' => 'bernd.f2@example.de', 'status' => 'active', 'slug' => 'bernd-f2' ) );
$f2_c = AffiliateRepository::create( array( 'name' => 'Carla Zwei', 'email' => 'carla.f2@example.de', 'status' => 'active' ) );
foreach ( array( $f2_a, $f2_b, $f2_c ) as $f2_id ) {
	if ( ! CodeRepository::main_code( $f2_id ) ) {
		CodeRepository::create( $f2_id, CodeRepository::random_code() );
	}
}
$f2_main_a = CodeRepository::main_code( $f2_a );
$f2_main_b = CodeRepository::main_code( $f2_b );
$f2_aff    = static function ( $id ) {
	AffiliateRepository::clear_cache( (int) $id );
	return AffiliateRepository::find( (int) $id );
};

// ---------------------------------------------------------------------------------------------
// Setting and limits.
check(
	'F2 setting "code_requests": default on, saved as a flag, on the Program tab; limits filterable',
	array( 1, 0, 1, true, 3, 5, 20 ),
	array(
		Settings::defaults()['code_requests'],
		Settings::sanitize( array() )['code_requests'],
		Settings::sanitize( array( 'code_requests' => '1' ) )['code_requests'],
		in_array( 'code_requests', \WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['program'], true ),
		CodeRequests::per_month( $f2_a ),
		( static function () use ( $f2_a ) {
			$f = static function () { return 5; };
			add_filter( 'wpac_code_requests_per_month', $f );
			$n = CodeRequests::per_month( $f2_a );
			remove_filter( 'wpac_code_requests_per_month', $f );
			return $n;
		} )(),
		CodeRequests::max_length(),
	)
);

// ---------------------------------------------------------------------------------------------
// Validation: the rules of CodeRepository::check_new_code().
$GLOBALS['test_wc_coupons']['f2sommer'] = 4711; // A shop-wide WooCommerce coupon.
CodeRepository::create( $f2_b, 'F2LINKED', array( 'coupon_code' => 'f2voucher' ) ); // Linked coupon (e.g. Voucher Captain).
CodeRepository::create( $f2_b, 'F2GE-NER8' ); // Generated form with hyphen.
CodeRepository::create( $f2_b, 'F2PLAIN8' );
$f2_long = str_repeat( 'A', 21 );
check(
	'F2 validation: empty, characters, start/end, length, reserved words, referral parameter names, erasure marker',
	array( 'wpac_code_empty', 'wpac_code_chars', 'wpac_code_chars', 'wpac_code_chars', 'wpac_code_chars', 'wpac_code_length', 'wpac_code_length', 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'MARIA10', 'MARIA_10-X' ),
	array(
		$f2_err( CodeRepository::check_new_code( '  ', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'MA RIA', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'MÜLLER10', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( '-MARIA', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'MARIA<b>', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'AB', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( $f2_long, $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'admin', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'PAGE', $f2_a, 0, 20 ) ),          // WordPress query var (R14-T6).
		$f2_err( CodeRepository::check_new_code( 'utm_x', $f2_a, 0, 20 ) ),         // Campaign parameter prefix.
		$f2_err( CodeRepository::check_new_code( 'Partner', $f2_a, 0, 20 ) ),       // The referral parameter itself.
		$f2_err( CodeRepository::check_new_code( 'DEL-123', $f2_a, 0, 20 ) ),       // Erasure marker.
		$f2_err( CodeRepository::check_new_code( 'maria10', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'Maria_10-x', $f2_a, 0, 20 ) ),
	)
);
check(
	'F2 uniqueness: partner codes (also both hyphen forms), linked coupons, WooCommerce coupons, other partners\' slugs; own slug is fine',
	array( 'wpac_code_reserved', 'wpac_code_taken', 'wpac_code_taken', 'wpac_code_taken', 'wpac_code_taken', 'wpac_code_taken', 'wpac_code_taken', 'MARIA-F2' ),
	array(
		$f2_err( CodeRepository::check_new_code( strtolower( $f2_main_b ), $f2_a, 0, 20 ) ), // Generated form: reserved since R15-S1 (and taken).
		$f2_err( CodeRepository::check_new_code( 'F2GENER8', $f2_a, 0, 20 ) ),   // Would find F2GE-NER8 typed without hyphen.
		$f2_err( CodeRepository::check_new_code( 'F2PL-AIN8', $f2_a, 0, 20 ) ),  // Typed without hyphen it finds F2PLAIN8.
		$f2_err( CodeRepository::check_new_code( 'F2VOUCHER', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'F2SOMMER', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'bernd-f2', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'BERND-F2', $f2_a, 0, 20 ) ),
		$f2_err( CodeRepository::check_new_code( 'maria-f2', $f2_a, 0, 20 ) ),
	)
);

// ---------------------------------------------------------------------------------------------
// Request.
$GLOBALS['f2_sent'] = array();
$f2_r1   = CodeRequests::request( $f2_aff( $f2_a ), 'maria10' );
$f2_row1 = CodeRepository::find( (int) $f2_r1 );
$f2_mail = $f2_last( 'code_requested' );
check(
	'F2 request: reserved row (status requested, own batch), history, audit, mail to the operator from the template',
	array( true, 'MARIA10', 'requested', CodeRequests::BATCH, '', 1, 'open', 1, Notifications::admin_email(), true, true, 'code_request_admin' ),
	array(
		is_int( $f2_r1 ) && $f2_r1 > 0,
		$f2_row1 ? $f2_row1->code : '',
		$f2_row1 ? $f2_row1->status : '',
		$f2_row1 ? $f2_row1->batch : '',
		$f2_row1 ? $f2_row1->coupon_code : 'x',
		count( CodeRequests::history( $f2_a ) ),
		CodeRequests::history( $f2_a )[0]['status'] ?? '',
		$f2_audits( 'code.requested', $f2_a ),
		$f2_mail ? $f2_mail['mail']['to'] : '',
		$f2_mail && false !== strpos( $f2_mail['mail']['subject'], 'MARIA10' ),
		$f2_mail && false !== strpos( $f2_mail['mail']['message'], 'Maria Zwei would like to use the code MARIA10' ) && false !== strpos( $f2_mail['mail']['message'], 'wpac-affiliates#wpac-code-requests' ),
		$f2_mail ? $f2_mail['context']['template'] : '',
	)
);

// Tracker, checkout, coupons and lists ignore the requested code.
$f2_link = apply_filters( 'wpac_resolve_partner_code', null, 'MARIA10', array( 'context' => 'link' ) );
$f2_chk  = apply_filters( 'wpac_resolve_partner_code', null, 'maria10', array( 'context' => 'checkout' ) );
$f2_cpn  = apply_filters( 'wpac_coupon_affiliate', null, 'maria10' );
$f2_ids  = static function ( $rows ) {
	return array_map( static function ( $r ) { return $r->code; }, (array) $rows );
};
list( $f2_q ) = CodeRepository::query( array( 'affiliate_id' => $f2_a ) );
check(
	'F2 requested code is never resolved (link, checkout, coupon) and not listed as a code; main code unchanged',
	array( null, null, null, $f2_main_a, false, true, false, null, 1 ),
	array(
		$f2_link,
		$f2_chk,
		$f2_cpn,
		CodeRepository::main_code( $f2_a ),
		in_array( 'MARIA10', $f2_ids( CodeRepository::for_affiliate( $f2_a ) ), true ),
		in_array( 'MARIA10', $f2_ids( CodeRepository::for_affiliate( $f2_a, true ) ), true ),
		in_array( 'MARIA10', $f2_ids( $f2_q ), true ),
		CodeRepository::find_by_coupon( 'maria10', false, $f2_a ), // Linking a coupon must not activate it.
		CodeRequests::open_count(),
	)
);

check(
	'F2 one open request per partner; the code is reserved for others and for the admin',
	array( 'wpac_code_request_open', 'wpac_code_taken', 'wpac_code_taken' ),
	array(
		$f2_err( CodeRequests::request( $f2_aff( $f2_a ), 'MARIA11' ) ),
		$f2_err( CodeRequests::request( $f2_aff( $f2_b ), 'Maria10' ) ),
		$f2_err( CodeRepository::create( $f2_b, 'MARIA10', array( 'strict' => true ) ) ),
	)
);

// Uniqueness race: another request takes the code between the check and the insert – the
// UNIQUE key decides, nothing is half-done.
$f2_race = static function ( $words ) use ( $wpdb, $f2_c, &$f2_race ) {
	remove_filter( 'wpac_reserved_partner_codes', $f2_race );
	$wpdb->insert( t( 'partner_codes' ), array( 'affiliate_id' => $f2_c, 'code' => 'RACE10', 'coupon_code' => '', 'batch' => CodeRequests::BATCH, 'status' => 'requested', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	return $words;
};
add_filter( 'wpac_reserved_partner_codes', $f2_race );
$f2_hist_b = count( CodeRequests::history( $f2_b ) );
$f2_raced  = CodeRequests::request( $f2_aff( $f2_b ), 'race10' );
check(
	'F2 uniqueness race: the parallel request wins, the second gets "taken", no second row, no history entry, no lock left',
	array( 'wpac_code_taken', 1, (string) $f2_c, $f2_hist_b, false ),
	array( $f2_err( $f2_raced ), count( $f2_rows( 'RACE10' ) ), (string) ( $f2_rows( 'RACE10' )[0]->affiliate_id ?? '' ), count( CodeRequests::history( $f2_b ) ), (bool) get_option( 'wpac_code_request_lock_' . $f2_b ) )
);
$wpdb->query( 'DELETE FROM ' . t( 'partner_codes' ) . " WHERE code = 'RACE10'" );

// ---------------------------------------------------------------------------------------------
// Rejection.
$GLOBALS['f2_sent'] = array();
$f2_rej0 = CodeRequests::reject( (int) $f2_r1, '  ' );
$f2_rej  = CodeRequests::reject( (int) $f2_r1, 'Bitte ohne Zahl' );
$f2_mail = $f2_last( 'code_request_resolved' );
$f2_hist = CodeRequests::history( $f2_a );
check(
	'F2 rejection: reason required; row deleted (code free again); history with reason; audit; mail with reason from the template; a second decision fails',
	array( 'wpac_reason_missing', 'ok', 0, 'MARIA10', 'rejected', 'Bitte ohne Zahl', 1, 'maria.f2@example.de', true, 'code_request_rejected', 'wpac_code_request_gone', 'Bitte ohne Zahl' ),
	array(
		$f2_err( $f2_rej0 ),
		$f2_err( $f2_rej ),
		count( $f2_rows( 'MARIA10' ) ),
		$f2_err( CodeRepository::check_new_code( 'MARIA10', $f2_a ) ),
		end( $f2_hist )['status'],
		end( $f2_hist )['reason'],
		$f2_audits( 'code.request_rejected', $f2_a ),
		$f2_mail ? $f2_mail['mail']['to'] : '',
		$f2_mail && false !== strpos( $f2_mail['mail']['message'], "unfortunately we could not approve the code MARIA10.\n\nReason: Bitte ohne Zahl" ),
		$f2_mail ? $f2_mail['context']['template'] : '',
		$f2_err( CodeRequests::reject( (int) $f2_r1, 'nochmal' ) ),
		CodeRequests::last_decided( $f2_a )['reason'] ?? '',
	)
);

// Withdraw, then request again (counts towards the monthly limit).
$f2_r2 = CodeRequests::request( $f2_aff( $f2_a ), 'MARIA11' );
$f2_wd = CodeRequests::withdraw( $f2_aff( $f2_a ) );
$f2_r3 = CodeRequests::request( $f2_aff( $f2_a ), 'MARIA12' );
check(
	'F2 withdraw: row deleted, history "withdrawn", a new request is possible',
	array( true, 0, 'withdrawn', true, 3 ),
	array( $f2_wd, count( $f2_rows( 'MARIA11' ) ), CodeRequests::history( $f2_a )[1]['status'] ?? '', is_int( $f2_r3 ), CodeRequests::recent_count( $f2_a ) )
);

// ---------------------------------------------------------------------------------------------
// Approval: additional active code, the main code stays.
$GLOBALS['f2_sent'] = array();
$f2_ok   = CodeRequests::approve( (int) $f2_r3 );
$f2_row3 = CodeRepository::find( (int) $f2_r3 );
$f2_res  = apply_filters( 'wpac_resolve_partner_code', null, 'maria12', array( 'context' => 'checkout' ) );
$f2_mail = $f2_last( 'code_request_resolved' );
check(
	'F2 approval: active additional code (resolved at checkout and by link), main code unchanged, history, audit, mail with the new link',
	array( 'MARIA12', 'active', '', $f2_a, $f2_main_a, true, 'approved', 1, true, 'code_request_approved', 'wpac_code_request_gone' ),
	array(
		is_array( $f2_ok ) ? $f2_ok['code'] : $f2_err( $f2_ok ),
		$f2_row3->status,
		$f2_row3->batch,
		$f2_res ? $f2_res['affiliate_id'] : 0,
		CodeRepository::main_code( $f2_a ),
		(bool) CodeRepository::validate( CodeRepository::find_by_code( $f2_main_a ) ),
		( static function ( $h ) { return end( $h )['status']; } )( CodeRequests::history( $f2_a ) ),
		$f2_audits( 'code.request_approved', $f2_a ),
		$f2_mail && false !== strpos( $f2_mail['mail']['message'], 'your requested code MARIA12 was approved' ) && false !== strpos( $f2_mail['mail']['message'], 'partner=MARIA12' ),
		$f2_mail ? $f2_mail['context']['template'] : '',
		$f2_err( CodeRequests::approve( (int) $f2_r3 ) ), // Two admins: only one approves.
	)
);

// Monthly limit (3 within 30 days: rejected, withdrawn and approved requests count).
$f2_lim  = CodeRequests::request( $f2_aff( $f2_a ), 'MARIA13' );
$f2_five = static function () { return 5; };
add_filter( 'wpac_code_requests_per_month', $f2_five );
$f2_more = CodeRequests::request( $f2_aff( $f2_a ), 'MARIA13' );
remove_filter( 'wpac_code_requests_per_month', $f2_five );
// Old entries no longer count.
$f2_h = CodeRequests::history( $f2_a );
foreach ( $f2_h as $f2_i => $f2_e ) {
	$f2_h[ $f2_i ]['requested_at'] = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
}
update_option( CodeRequests::HISTORY_PREFIX . $f2_a, $f2_h, false );
check(
	'F2 rate limit: 3 requests in 30 days (filter raises it), older requests do not count',
	array( 'wpac_code_request_limit', true, 0 ),
	array( $f2_err( $f2_lim ), is_int( $f2_more ), CodeRequests::recent_count( $f2_a ) )
);
CodeRequests::withdraw( $f2_aff( $f2_a ) );

// Approval as main code: the previous main code stays valid (owner decision 2026-11-15).
$f2_rb  = CodeRequests::request( $f2_aff( $f2_b ), 'BERND10' );
$f2_okb = CodeRequests::approve( (int) $f2_rb, array( 'make_main' => true ) );
check(
	'F2 approval "make main code": new main code, the previous one stays active and resolvable',
	array( 'BERND10', array(), true, 'active' ),
	array(
		CodeRepository::main_code( $f2_b ),
		is_array( $f2_okb ) ? $f2_okb['blocked'] : null,
		(bool) CodeRepository::validate( CodeRepository::find_by_code( $f2_main_b ) ),
		CodeRepository::find_by_code( $f2_main_b )->status,
	)
);
CodeRepository::set_status( (int) CodeRepository::find_by_code( 'BERND10' )->id, 'blocked' );
check( 'F2 a blocked preferred main code falls back to the automatic order', $f2_main_b, CodeRepository::main_code( $f2_b ) );
CodeRepository::set_status( (int) CodeRepository::find_by_code( 'BERND10' )->id, 'active' );

// Re-check at approval: a WooCommerce coupon with that code was created meanwhile.
$f2_rl = CodeRequests::request( $f2_aff( $f2_c ), 'CARLA7' );
$GLOBALS['test_wc_coupons']['carla7'] = 4712;
$f2_late = CodeRequests::approve( (int) $f2_rl );
unset( $GLOBALS['test_wc_coupons']['carla7'] );
check(
	'F2 approval re-checks the rules (coupon created meanwhile): refused, request stays open',
	array( 'wpac_code_taken', 'requested' ),
	array( $f2_err( $f2_late ), CodeRepository::find( (int) $f2_rl )->status )
);

// Coupon created on approval: discount checked before anything changes.
$f2_cbad = CodeRequests::approve( (int) $f2_rl, array( 'coupon' => 'create', 'amount' => '' ) );
$f2_cok  = class_exists( 'WC_Coupon' ) ? CodeRequests::approve( (int) $f2_rl, array( 'coupon' => 'create', 'discount_type' => 'percent', 'amount' => '10' ) ) : null;
check(
	'F2 approval with "create coupon": invalid discount refused before the approval; then coupon created and linked',
	array( 'wpac_coupon_amount', true, 'carla7', true ),
	array(
		$f2_err( $f2_cbad ),
		is_array( $f2_cok ) && ! $f2_cok['warnings'],
		CodeRepository::find( (int) $f2_rl )->coupon_code,
		! empty( $GLOBALS['test_wc_coupons']['carla7'] ),
	)
);

// Rename the coupon linked to the main code.
$f2_e  = AffiliateRepository::create( array( 'name' => 'Emil Zwei', 'email' => 'emil.f2@example.de', 'status' => 'active' ) );
$f2_em = CodeRepository::create( $f2_e, 'EMILALT', array( 'coupon_code' => 'emilalt' ) );
$GLOBALS['test_wc_coupons']['emilalt']   = 4800;
$GLOBALS['test_wc_coupon_data'][4800]   = array( 'code' => 'emilalt' );
if ( CodeRepository::main_code( $f2_e ) !== 'EMILALT' ) {
	foreach ( CodeRepository::for_affiliate( $f2_e ) as $f2_x ) {
		if ( 'EMILALT' !== $f2_x->code ) {
			CodeRepository::set_status( (int) $f2_x->id, 'blocked' );
		}
	}
}
$f2_re  = CodeRequests::request( $f2_aff( $f2_e ), 'EMIL10' );
$f2_ren = class_exists( 'WC_Coupon' ) ? CodeRequests::approve( (int) $f2_re, array( 'coupon' => 'rename' ) ) : null;
check(
	'F2 approval with "rename coupon": the coupon of the main code gets the new code, the link moves to the new code',
	array( true, 'emil10', '', true, 1 ),
	array(
		is_array( $f2_ren ) && ! $f2_ren['warnings'],
		CodeRepository::find( (int) $f2_re )->coupon_code,
		CodeRepository::find( (int) $f2_em )->coupon_code,
		! empty( $GLOBALS['test_wc_coupons']['emil10'] ),
		$f2_audits( 'coupon.renamed', $f2_e ),
	)
);

// Inactive partners and the switch.
$f2_rd = CodeRequests::request( $f2_aff( $f2_c ), 'CARLA8' );
AffiliateRepository::set_status( $f2_c, 'terminated', 'Ende' );
$f2_dead = CodeRequests::approve( (int) $f2_rd );
$f2_new  = CodeRequests::request( $f2_aff( $f2_c ), 'CARLA9' );
$f2_set( array( 'code_requests' => 0 ) );
$f2_off = CodeRequests::request( $f2_aff( $f2_b ), 'BERND11' );
$f2_set( array( 'code_requests' => 1 ) );
check(
	'F2 terminated partner: approval refused (reject instead), no new request; switched off: no request',
	array( 'wpac_code_request_inactive', 'wpac_code_request_inactive', 'wpac_code_requests_off', true ),
	array( $f2_err( $f2_dead ), $f2_err( $f2_new ), $f2_err( $f2_off ), true === CodeRequests::reject( (int) $f2_rd, 'Partnerschaft beendet' ) )
);

// ---------------------------------------------------------------------------------------------
// Privacy: Art. 15 export and erasure.
$f2_rp     = CodeRequests::request( $f2_aff( $f2_b ), 'BERND12' );
$f2_export = Privacy::export( 'bernd.f2@example.de' );
$f2_found  = array();
foreach ( (array) $f2_export['data'] as $f2_item ) {
	if ( 'wpac-code-requests' === $f2_item['group_id'] ) {
		$f2_found[] = $f2_item['data'][0]['value'] . ':' . $f2_item['data'][1]['value'];
	}
}
check(
	'F2 privacy export lists the requests with status (open and approved)',
	array( true, true ),
	array( in_array( 'BERND12:Waiting for review', $f2_found, true ), in_array( 'BERND10:Approved', $f2_found, true ) )
);
Privacy::erase_partner( $f2_aff( $f2_b ) );
check(
	'F2 erasure removes the open request and the request history',
	array( 0, array(), false ),
	array( count( $f2_rows( 'BERND12' ) ), CodeRequests::history( $f2_b ), get_option( CodeRequests::HISTORY_PREFIX . $f2_b ) )
);

// ---------------------------------------------------------------------------------------------
// Wiring: templates, admin route, audit labels, docs.
$f2_defs = Notifications::template_defs();
check(
	'F2 mail templates registered with defaults (E-mail templates page), admin action routed with MANAGE_AFFILIATES, audit labels and export fields',
	array( true, true, true, 1, true, true, true, true ),
	array(
		isset( $f2_defs['code_request_admin'], $f2_defs['code_request_approved'], $f2_defs['code_request_rejected'] ),
		in_array( 'reason', $f2_defs['code_request_rejected']['vars'], true ) && in_array( 'admin_url', $f2_defs['code_request_admin']['vars'], true ),
		null !== Notifications::default_template( 'code_request_approved' ),
		preg_match_all( "/'code_request'\s*=> array\( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES \)/", $f2_src( 'src/Admin/Admin.php' ) ),
		'Code request approved' === \WPAffiliateCaptain\Admin\Pages\AuditPage::action_label( 'code.request_approved' ),
		isset( Privacy::audit_export_fields()['code.request_rejected'] ),
		false !== strpos( $f2_src( 'docs/CONTRACT.md' ), 'wpac_code_requests_per_month' ) && false !== strpos( $f2_src( 'docs/HANDBOOK.md' ), 'MARIA10' ),
		false !== strpos( $f2_src( 'uninstall.php' ), "wpac\\\\_code\\\\_request%" ),
	)
);
