<?php
/**
 * Feature agent p15 (0.15.0): contract wishes of the Pro add-on.
 * P15-1 wpac_create_adjustment() with parent_id/reference (information-only link, never the
 * ledger column parent_id), P15-2 wpac_creative_created/_updated, P15-3 wpac_portal_url() and
 * wpac_mail_sender().
 * Included from run.php before r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Promotion\Creatives;

echo "R14 p15 contract wishes of the Pro add-on (P15-1..3)\n";
global $wpdb;

$p15_keep = get_option( 'wpac_settings' );
unset( $GLOBALS['test_caps'] );

$p15_partner = static function ( $slug ) use ( $wpdb ) {
	$id = (int) AffiliateRepository::create( array( 'name' => 'P15 ' . $slug, 'email' => 'p15-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'legal_name' => 'P15 ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'P15 ' . $slug, 'iban' => 'DE89370400440532013000', 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$p15_earn = static function ( $aff, $order, $net, $approve = true ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net, 'quantity' => 1 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'p15-kunde-' . $order . '@example.de' ) ) );
	if ( $approve ) {
		foreach ( (array) $r['commission_ids'] as $cid ) {
			Ledger::transition( (int) $cid, 'approved' );
		}
	}
	return (int) $r['commission_ids'][0];
};
$p15_row = static function ( $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'commissions' ) . ' WHERE id = %d', (int) $id ) );
};
$p15_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};

/* ---------------------------------------------------------------------------------------------
 * P15-1: validation.
 * ------------------------------------------------------------------------------------------- */
$p15_up   = $p15_partner( 'upline' );
$p15_down = $p15_partner( 'downline' );
$p15_sale = $p15_earn( $p15_down, 'p15-team', 100000, false ); // Downline sale, still pending.
$p15_sale_amt = (int) $p15_row( $p15_sale )->amount_minor;
check( 'P15-1 setup: open downline commission', array( true, true ), array( in_array( (string) $p15_row( $p15_sale )->status, array( 'pending', 'review' ), true ), $p15_sale_amt > 0 ) );
$p15_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) );
check(
	'P15-1 validation: unknown/invalid parent_id, non-scalar or too long reference, unknown argument, args not an array',
	array( 'wpac_invalid_parent_id', 'wpac_invalid_parent_id', 'wpac_invalid_parent_id', 'wpac_invalid_parent_id', 'wpac_invalid_reference', 'wpac_too_long_reference', 'wpac_invalid_field', 'wpac_invalid_args' ),
	array(
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'parent_id' => 99999999 ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'parent_id' => -3 ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'parent_id' => 'abc' ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'parent_id' => array( $p15_sale ) ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'reference' => array( 'x' ) ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'reference' => str_repeat( 'ä', 65 ) ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', array( 'parent' => $p15_sale ) ) ),
		$p15_code( wpac_create_adjustment( $p15_up, 500, 'EUR', 'Team', 'parent' ) ),
	)
);
check( 'P15-1 validation errors book nothing', $p15_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) ) );
check( 'P15-1 64 characters (multibyte) are accepted', 'ok', $p15_code( $p15_ok = wpac_create_adjustment( $p15_up, 1, 'EUR', 'Grenze', array( 'reference' => str_repeat( 'ä', 64 ) ) ) ) );
check( 'P15-1 reference of 64 multibyte characters stored in full', str_repeat( 'ä', 64 ), is_array( $p15_ok ) ? $p15_ok['reference'] : '' );
check( 'P15-1 without args: as before (parent_id 0, reference empty)', array( 0, '' ), ( static function ( $a ) { return array( $a['parent_id'], $a['reference'] ); } )( wpac_create_adjustment( $p15_up, 2, 'EUR', 'Ohne' ) ) );

/* ---------------------------------------------------------------------------------------------
 * P15-1: team commission linked to the downline's sale.
 * ------------------------------------------------------------------------------------------- */
$p15_mark = count( $GLOBALS['test_fired'] );
$p15_adj  = wpac_create_adjustment( $p15_up, 1500, 'EUR', 'Team-Provision', array( 'parent_id' => (string) $p15_sale, 'reference' => " <b>team-rule</b>:7 \n" ) );
check( 'P15-1 linked booking created', 'ok', $p15_code( $p15_adj ) );
$p15_adj_id = is_array( $p15_adj ) ? (int) $p15_adj['id'] : 0;
check(
	'P15-1 returned booking: own partner, approved, parent_id = linked commission, reference sanitized',
	array( $p15_up, 'adjustment', 'approved', 1500, 'EUR', $p15_sale, 'team-rule:7' ),
	is_array( $p15_adj ) ? array( $p15_adj['affiliate_id'], $p15_adj['kind'], $p15_adj['status'], $p15_adj['amount_minor'], $p15_adj['currency'], $p15_adj['parent_id'], $p15_adj['reference'] ) : array()
);
check( 'P15-1 the ledger column parent_id stays 0 (no correction of the downline commission)', 0, (int) $p15_row( $p15_adj_id )->parent_id );
check( 'P15-1 the linked commission has no children', array(), Ledger::children( $p15_sale ) );
check( 'P15-1 wpac_get_commission() shows parent_id and reference', array( $p15_sale, 'team-rule:7' ), ( static function ( $c ) { return array( $c['parent_id'], $c['reference'] ); } )( wpac_get_commission( $p15_adj_id ) ) );
$p15_list = wpac_get_commissions( array( 'affiliate_id' => $p15_up, 'kind' => 'adjustment', 'per_page' => 100 ) );
$p15_seen = array();
foreach ( $p15_list['items'] as $p15_it ) {
	$p15_seen[ $p15_it['id'] ] = array( $p15_it['parent_id'], $p15_it['reference'] );
}
check( 'P15-1 wpac_get_commissions() shows parent_id and reference per row', array( $p15_sale, 'team-rule:7' ), $p15_seen[ $p15_adj_id ] ?? null );
check( 'P15-1 other rows: reference empty, parent_id from the column', array( 0, '' ), ( static function ( $c ) { return array( $c['parent_id'], $c['reference'] ); } )( wpac_get_commission( $p15_sale ) ) );
check( 'P15-1 balance of the upline holds the booking at once (not held back by the pending downline sale)', true, Balance::get( $p15_up, 'EUR' ) >= 1500 );
$p15_audit = $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'commission.manual' AND object_id = %d ORDER BY id DESC LIMIT 1", $p15_up ) );
$p15_audit = json_decode( (string) $p15_audit, true );
check( 'P15-1 audit commission.manual names the link and the reference', array( $p15_adj_id, 0, $p15_sale, 'team-rule:7' ), array( (int) ( $p15_audit['commission'] ?? 0 ), (int) ( $p15_audit['parent'] ?? -1 ), (int) ( $p15_audit['linked'] ?? 0 ), (string) ( $p15_audit['reference'] ?? '' ) ) );
$p15_created = 0;
foreach ( array_slice( $GLOBALS['test_fired'], $p15_mark ) as $p15_f ) {
	if ( 'wpac_commission_created' === $p15_f[0] && array( $p15_adj_id ) === $p15_f[1] && ! $p15_f[2] ) {
		++$p15_created;
	}
}
check( 'P15-1 wpac_commission_created once, after the commit', 1, $p15_created );
check( 'P15-1 the link is one event "linked" (information only, amount 0)', array( 'linked', 'parent:' . $p15_sale, 'team-rule:7', 0 ), ( static function ( $e ) { return $e ? array( (string) $e->event, (string) $e->source_event_id, (string) $e->reason, (int) $e->amount_minor ) : array(); } )( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'commission_events' ) . " WHERE commission_id = %d AND event = 'linked'", $p15_adj_id ) ) ) );
check( 'P15-1 reference only (no parent): stored, parent_id 0', array( 0, 'nur-ref' ), ( static function ( $a ) { return array( $a['parent_id'], $a['reference'] ); } )( wpac_create_adjustment( $p15_up, 3, 'EUR', 'Nur Referenz', array( 'reference' => 'nur-ref' ) ) ) );

/* Refunds of the linked commission leave the booking untouched (R12-N3 behaviour). */
Ledger::transition( $p15_sale, 'approved' );
$p15_r = wpac_record_refund( array( 'source' => 'test', 'order_id' => 'p15-team', 'refund_id' => 'p15-r1', 'type' => 'refund', 'items' => array( array( 'item_id' => '1', 'net_minor' => 50000 ) ), 'reason' => 'test' ) );
check( 'P15-1 partial refund of the linked commission: reduced in proportion (booking not netted)', array( 'adjusted', -(int) round( $p15_sale_amt / 2 ) ), array( (string) $p15_r['status'], Ledger::adjusted_sum( $p15_sale ) ) );
$p15_r = wpac_record_refund( array( 'source' => 'test', 'order_id' => 'p15-team', 'refund_id' => 'p15-r2', 'type' => 'cancellation', 'reason' => 'test' ) );
check( 'P15-1 cancellation of the linked commission: whole commission reversed', -$p15_sale_amt, Ledger::adjusted_sum( $p15_sale ) );
check( 'P15-1 after refund and cancellation: the linked booking is untouched', array( 'approved', 1500, 0, $p15_up ), array( (string) $p15_row( $p15_adj_id )->status, (int) $p15_row( $p15_adj_id )->amount_minor, (int) $p15_row( $p15_adj_id )->parent_id, (int) $p15_row( $p15_adj_id )->affiliate_id ) );
check( 'P15-1 no correction was booked for the upline', 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = %d AND kind IN ('refund','cancellation','chargeback')", $p15_up ) ) );
Ledger::reverse_refund( 'test', 'p15-team', 'p15-r1', 'refund deleted' );
check( 'P15-1 reverse_refund of the linked commission: booking still untouched', array( 'approved', 1500 ), array( (string) $p15_row( $p15_adj_id )->status, (int) $p15_row( $p15_adj_id )->amount_minor ) );

/* ---------------------------------------------------------------------------------------------
 * P15-1: payouts and credit notes are the same as for an unlinked booking.
 * ------------------------------------------------------------------------------------------- */
$p15_s = $p15_partner( 'statement' );
$p15_s1 = $p15_earn( $p15_s, 'p15-st1', 100000 );
$p15_p1 = PayoutService::create_for_affiliate( $p15_s, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $p15_p1 );
check( 'P15-1 setup: first payout with the commission', array( true, $p15_p1 ), array( $p15_p1 > 0, (int) $p15_row( $p15_s1 )->payout_id ) );
$p15_linked   = wpac_create_adjustment( $p15_s, 700, 'EUR', 'Bonus verknüpft', array( 'parent_id' => $p15_s1, 'reference' => 'r-1' ) );
$p15_unlinked = wpac_create_adjustment( $p15_s, 700, 'EUR', 'Bonus frei' );
$p15_p2 = PayoutService::create_for_affiliate( $p15_s, gmdate( 'Y-m-d' ), 'EUR' );
$p15_item = static function ( $payout, $cid ) use ( $wpdb ) {
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT amount_minor, vat_mode, vat_rate, ref_payout_id FROM ' . t( 'payout_items' ) . ' WHERE payout_id = %d AND commission_id = %d', (int) $payout, (int) $cid ) );
	return $r ? array( (int) $r->amount_minor, (string) $r->vat_mode, (string) $r->vat_rate, (int) $r->ref_payout_id ) : null;
};
check( 'P15-1 second payout holds both bookings (1 400 net)', array( true, $p15_p2, $p15_p2 ), array( $p15_p2 > 0, (int) $p15_row( $p15_linked['id'] )->payout_id, (int) $p15_row( $p15_unlinked['id'] )->payout_id ) );
check( 'P15-1 credit note line of the linked booking = line of the unlinked booking (no reference to the earlier credit note)', $p15_item( $p15_p2, $p15_unlinked['id'] ), $p15_item( $p15_p2, $p15_linked['id'] ) );
check( 'P15-1 credit note line: no ref_payout_id', 0, $p15_item( $p15_p2, $p15_linked['id'] )[3] );
$p15_doc = Statement::build( PayoutService::find( $p15_p2 ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . t( 'commission_events' ) . " WHERE commission_id = %d AND event = 'linked'", (int) $p15_linked['id'] ) );
check( 'P15-1 credit note is byte for byte the same without the link', $p15_doc, Statement::build( PayoutService::find( $p15_p2 ) ) );
check( 'P15-1 without the link event: parent_id 0, reference empty', array( 0, '' ), ( static function ( $c ) { return array( $c['parent_id'], $c['reference'] ); } )( wpac_get_commission( $p15_linked['id'] ) ) );

/* ---------------------------------------------------------------------------------------------
 * P15-2: hooks after saving a creative.
 * ------------------------------------------------------------------------------------------- */
$p15_cr = array();
$GLOBALS['test_hooks']['wpac_creative_created'][10][] = array( static function ( $id, $info ) use ( &$p15_cr ) { $p15_cr[] = array( 'created', $id, $info['status'] ?? '', $info['version'] ?? 0 ); }, 2 );
$GLOBALS['test_hooks']['wpac_creative_updated'][10][] = array( static function ( $id, $info ) use ( &$p15_cr ) { $p15_cr[] = array( 'updated', $id, $info['status'] ?? '', $info['version'] ?? 0 ); }, 2 );
$p15_data = array(
	'title'         => 'P15 Banner',
	'type'          => 'text',
	'content'       => 'Jetzt buchen: {link}',
	'attachment_id' => 0,
	'landing_url'   => '',
	'disclosure'    => '',
	'group_id'      => 0,
);
$p15_c1 = Creatives::save_draft( $p15_data, 0, 'neu' );
check( 'P15-2 new creative: wpac_creative_created( id, info ) once, status draft', array( array( 'created', $p15_c1, 'draft', 1 ) ), $p15_cr );
$p15_cr = array();
Creatives::approve( $p15_c1 );
check( 'P15-2 approved: wpac_creative_updated with status approved', array( array( 'updated', $p15_c1, 'approved', 1 ) ), $p15_cr );
$p15_cr = array();
$p15_c2 = Creatives::save_draft( $p15_data, (int) Creatives::find( $p15_c1 )->family_id, 'Text' );
check( 'P15-2 new version of the family: wpac_creative_updated (not created)', array( array( 'updated', $p15_c2, 'draft', 2 ) ), $p15_cr );
$p15_cr = array();
Creatives::archive( $p15_c2 );
Creatives::archive( $p15_c2 ); // Already archived: nothing changes, no second hook.
check( 'P15-2 archived: wpac_creative_updated once', array( array( 'updated', $p15_c2, 'archived', 2 ) ), $p15_cr );
$p15_cr = array();
check( 'P15-2 refused approval (not a draft): no hook', array( true, array() ), array( is_wp_error( Creatives::approve( $p15_c2 ) ), $p15_cr ) );
$wpdb->suppress_errors( true );
$p15_bad = Creatives::save_draft( array_merge( $p15_data, array( 'title' => str_repeat( 'x', 400 ) ) ), 0, 'zu lang' );
$wpdb->suppress_errors( false );
check( 'P15-2 failed save: no hook', array( true, array() ), array( is_wp_error( $p15_bad ), $p15_cr ) );
unset( $GLOBALS['test_hooks']['wpac_creative_created'], $GLOBALS['test_hooks']['wpac_creative_updated'] );

/* ---------------------------------------------------------------------------------------------
 * P15-3: portal URL in the partner's language, mail sender.
 * ------------------------------------------------------------------------------------------- */
update_option( 'wpac_settings', array_merge( (array) $p15_keep, array( 'portal_page_id' => 50, 'mail_from_email' => '', 'mail_from_name' => '', 'admin_email' => 'programm@example.de' ) ) );
Settings::flush_cache();
$p15_users = $GLOBALS['test_users'] ?? null;
$GLOBALS['test_users'][ 815 ] = (object) array( 'ID' => 815, 'user_email' => 'p15-fr@example.de', 'locale' => 'fr_FR' );
$p15_fr    = (int) AffiliateRepository::create( array( 'name' => 'P15 FR', 'email' => 'p15-fr@example.de', 'status' => 'active', 'user_id' => 815 ) );
$p15_de    = (int) AffiliateRepository::create( array( 'name' => 'P15 DE', 'email' => 'p15-de@example.de', 'status' => 'active' ) );
$p15_wpml  = array(
	'wpml_default_language' => static function () { return 'de'; },
	'wpml_active_languages' => static function () { return array( 'de' => array( 'default_locale' => 'de_DE' ), 'fr' => array( 'default_locale' => 'fr_FR' ) ); },
	'wpml_object_id'        => static function ( $id, $type = 'page', $original = true, $lang = null ) { return 50 === (int) $id && 'fr' === $lang ? 51 : (int) $id; },
);
foreach ( $p15_wpml as $p15_tag => $p15_cb ) {
	$GLOBALS['test_hooks'][ $p15_tag ][10][] = array( $p15_cb, 4 );
}
check(
	'P15-3 wpac_portal_url(): partner language (French account → translated page), German partner, no partner, unknown partner',
	array( 'http://example.test/?p=51', 'http://example.test/?p=50', 'http://example.test/?p=50', 'http://example.test/?p=50' ),
	array( wpac_portal_url( $p15_fr ), wpac_portal_url( $p15_de ), wpac_portal_url(), wpac_portal_url( 99999999 ) )
);
check( 'P15-3 wpac_portal_url() = the link of the plugin\'s own mails', Notifications::portal_url( AffiliateRepository::find( $p15_fr ) ), wpac_portal_url( (string) $p15_fr ) );
foreach ( array_keys( $p15_wpml ) as $p15_tag ) {
	unset( $GLOBALS['test_hooks'][ $p15_tag ] );
}
$GLOBALS['test_post_status'][50] = 'draft';
check( 'P15-3 wpac_portal_url(): home page when the portal page is not published', home_url( '/' ), wpac_portal_url( $p15_de ) );
unset( $GLOBALS['test_post_status'][50] );
if ( null === $p15_users ) {
	unset( $GLOBALS['test_users'] );
} else {
	$GLOBALS['test_users'] = $p15_users;
}

check( 'P15-3 wpac_mail_sender() without settings: site name, program notification address, custom false', array( 'name' => 'Test Shop', 'email' => 'programm@example.de', 'custom' => false ), wpac_mail_sender() );
update_option( 'wpac_settings', array_merge( (array) $p15_keep, array( 'mail_from_email' => 'partner@example.de', 'mail_from_name' => 'Segelschule Nord', 'admin_email' => 'programm@example.de' ) ) );
Settings::flush_cache();
check( 'P15-3 wpac_mail_sender() with settings', array( 'name' => 'Segelschule Nord', 'email' => 'partner@example.de', 'custom' => true ), wpac_mail_sender() );
$p15_ctx = array();
$GLOBALS['test_hooks']['wpac_mail_from_name'][10][] = array( static function ( $name, $type, $ctx ) use ( &$p15_ctx ) { $p15_ctx = array( $type, $ctx ); return "Marke\r\nBcc: x@example.de"; }, 3 );
$p15_send = array();
$GLOBALS['test_hooks']['wpac_mail'][99][] = array( static function ( $mail ) use ( &$p15_send ) { $p15_send = $mail['headers']; return false; }, 1 );
$p15_sender = wpac_mail_sender( 'pro_milestone', $p15_de );
check( 'P15-3 wpac_mail_sender(): wpac_mail_from_name applies (header-safe), with type and partner', array( 'Marke Bcc: x@example.de', 'pro_milestone', array( 'type' => 'pro_milestone', 'admin' => false, 'affiliate_id' => $p15_de ) ), array( $p15_sender['name'], $p15_ctx[0] ?? '', $p15_ctx[1] ?? array() ) );
Notifications::send( 'payout_paid', 'p@example.de', 'Betreff', 'Text' );
check( 'P15-3 the plugin\'s own mail uses the same sender', 'From: ' . $p15_sender['name'] . ' <' . $p15_sender['email'] . '>', implode( "\n", array_filter( (array) $p15_send, static function ( $h ) { return 0 === strpos( (string) $h, 'From:' ); } ) ) );
remove_all_filters( 'wpac_mail_from_name' );
unset( $GLOBALS['test_hooks']['wpac_mail'][99] );
update_option( 'wpac_settings', array_merge( (array) $p15_keep, array( 'mail_from_email' => 'kein-email', 'mail_from_name' => '', 'admin_email' => '' ) ) );
Settings::flush_cache();
$p15_site_admin = get_option( 'admin_email' );
update_option( 'admin_email', 'site@example.de' );
check( 'P15-3 wpac_mail_sender(): invalid sender address and no program address → site admin e-mail', array( 'site@example.de', false ), ( static function ( $s ) { return array( $s['email'], $s['custom'] ); } )( wpac_mail_sender() ) );
if ( false === $p15_site_admin ) {
	unset( $GLOBALS['test_options']['admin_email'] );
} else {
	update_option( 'admin_email', $p15_site_admin );
}
check( 'P15-3 contract functions are guarded with function_exists', 2, substr_count( (string) file_get_contents( WPAC_DIR . 'src/functions.php' ), "function_exists( 'wpac_portal_url' )" ) + substr_count( (string) file_get_contents( WPAC_DIR . 'src/functions.php' ), "function_exists( 'wpac_mail_sender' )" ) );

update_option( 'wpac_settings', $p15_keep );
Settings::flush_cache();
