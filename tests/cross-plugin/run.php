<?php
/**
 * Gesamttest über Affiliate Captain, WP Voucher Captain, WP Pass Captain und WooCommerce.
 * Läuft gegen eine echte TEST-Installation (legt Partner, Produkte, Events und Bestellungen an).
 *
 *   php tests/cross-plugin/run.php /pfad/zu/wordpress [http-host]
 *
 * Exit-Code 0 = alle Prüfungen bestanden. Siehe README.md.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
if ( empty( $argv[1] ) || ! is_file( rtrim( $argv[1], '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Aufruf: php tests/cross-plugin/run.php /pfad/zu/wordpress [http-host]\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']   = $argv[2] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require rtrim( $argv[1], '/' ) . '/wp-load.php';
wp_set_current_user( 1 );
$missing = array();
foreach ( array( 'WPAffiliateCaptain\\Plugin' => 'Affiliate Captain', 'PassCaptain\\Orders' => 'WP Pass Captain', 'WooCommerce' => 'WooCommerce' ) as $class => $name ) {
	if ( ! class_exists( $class ) ) { $missing[] = $name; }
}
if ( ! function_exists( 'vcap' ) ) { $missing[] = 'WP Voucher Captain'; }
if ( $missing ) {
	fwrite( STDERR, 'Nicht aktiv: ' . implode( ', ', $missing ) . "\n" );
	exit( 2 );
}
global $wpdb;
$p = $wpdb->prefix;
$ok = 0; $fail = 0;
function chk( $name, $cond, $info = '' ) { global $ok, $fail; if ( $cond ) { ++$ok; echo "  ok   $name\n"; } else { ++$fail; echo "  FAIL $name" . ( $info ? "  [$info]" : '' ) . "\n"; } }
$comm = static function ( $source, $order ) use ( $wpdb, $p ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT kind, base_minor b, amount_minor a, status FROM {$p}wpac_commissions WHERE source=%s AND source_order_id=%s ORDER BY id", $source, (string) $order ), ARRAY_A );
};
$sum = static function ( array $rows ) { $s = 0; foreach ( $rows as $r ) { if ( 'rejected' !== $r['status'] ) { $s += (int) $r['a']; } } return $s; };
$mails = array();
add_filter( 'wp_mail', static function ( $args ) use ( &$mails ) { $mails[] = $args; return $args; } );
add_filter( 'pre_wp_mail', '__return_true' ); // Keine echte Zustellung nötig.

$tag  = substr( md5( (string) microtime( true ) ), 0, 6 );
$aff  = WPAffiliateCaptain\Affiliates\AffiliateRepository::create( array( 'name' => 'E2E Segelschule ' . $tag, 'email' => "e2e-partner-$tag@example.de", 'status' => 'active' ) );
$code = WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff );
echo "Partner #$aff, Code $code\n";

echo "1. Gutschein (Geschenkkarte 50 €) über WooCommerce mit Partnercode\n";
$gift = new WC_Product_Simple();
$gift->set_name( 'E2E Geschenkkarte 50 €' ); $gift->set_regular_price( '50' ); $gift->set_virtual( true );
$gift->update_meta_data( '_vcap_enabled', 'yes' );
$gift->save();
$wo = wc_create_order();
$wo->add_product( $gift, 1 );
$wo->set_billing_email( "e2e-kunde-$tag@example.de" ); $wo->set_billing_first_name( 'Kim' ); $wo->set_billing_last_name( 'Kunde' );
$wo->update_meta_data( '_wpac_partner_code', $code );
$wo->set_payment_method( 'bacs' );
$wo->calculate_totals(); $wo->save();
$wo->payment_complete();
$wo->update_status( 'processing' );
$vc = $comm( 'woocommerce', $wo->get_id() );
chk( 'Provision auf den Gutscheinverkauf (Standard: an)', 1 === count( $vc ) && (int) $vc[0]['a'] > 0, json_encode( $vc ) );
$vouchers = vcap()->vouchers->for_order( $wo->get_id() );
chk( 'Voucher Captain hat den Gutschein ausgestellt', 1 === count( $vouchers ), count( $vouchers ) . ' Gutscheine' );
$voucher = $vouchers[0] ?? null;
$vcode   = $voucher ? $voucher->code : '';
$vstate  = static function () use ( $vcode ) { $v = vcap()->vouchers->get_by_code( $vcode ); return $v ? $v->status . ' / ' . $v->balance . ' €' : '-'; };
$balance = static function () use ( $voucher, $wpdb, $p ) { return $voucher ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT balance_after FROM {$p}vcap_events WHERE voucher_id=%d AND balance_after IS NOT NULL ORDER BY id DESC LIMIT 1", $voucher->id ) ) : -1; };
echo "  Gutschein $vcode, Guthaben " . $balance() . " €\n";

echo "2. Ticket in Pass Captain (119 € inkl. USt), 50 € mit dem Gutschein bezahlt, gleicher Partnercode\n";
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => "E2E Törn $tag", 'slug' => "e2e-toern-$tag", 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * HOUR_IN_SECONDS ), 'timezone' => 'Europe/Berlin', 'location' => 'Kiel', 'capacity' => 0, 'status' => 'scheduled' ) );
$occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Erwachsene', 'price_minor' => 11900, 'currency' => 'EUR', 'tax_class' => 'standard', 'tax_rate_bp' => 1900, 'capacity' => 20, 'max_per_order' => 5, 'status' => 'active' ) );
$type = (int) $wpdb->insert_id;
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0, 'auto_refund_cancelled' => 1, 'require_terms' => 0 ) );
$po = PassCaptain\Orders::create( array( 'email' => "e2e-kunde-$tag@example.de", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => "e2e-$tag", 'partner_code' => $code ) );
chk( 'Pass-Captain-Bestellung angelegt', is_array( $po ) && ! empty( $po['order_id'] ), is_wp_error( $po ) ? $po->get_error_message() : '' );
$oid = (int) ( $po['order_id'] ?? 0 );
$applied = PassCaptain\Integrations\Vouchers\Checkout::apply( $oid, $vcode );
chk( 'Gutschein im Pass-Captain-Checkout angewendet', ! is_wp_error( $applied ), is_wp_error( $applied ) ? $applied->get_error_message() : '' );
$order = PassCaptain\Orders::get( $oid );
echo '  Zahlbetrag ' . ( (int) $order->total_minor / 100 ) . " €\n";
$paid = PassCaptain\Orders::confirm_manual_payment( $oid );
chk( 'Restbetrag bezahlt, Tickets ausgestellt', ! is_wp_error( $paid ), is_wp_error( $paid ) ? $paid->get_error_message() : '' );
echo '  Gutschein nach Einlösung (Status / Guthaben): ' . $vstate() . ", Journal " . $balance() . " €\n";
$vv = vcap()->vouchers->get_by_code( $vcode );
chk( 'Gutschein ist verbraucht (Journal 0 €, Status nicht mehr aktiv)', $balance() < 0.01 && $vv && 'active' !== $vv->status, $vstate() . ' / Journal ' . $balance() );
$tc = $comm( 'pass-captain', $oid );
$order = PassCaptain\Orders::get( $oid );
$expect_base = (int) round( (int) $order->total_minor * 10000 / 11900 ); // Netto des bezahlten Teils
echo '  Bestellung: Preis 119 €, USt ' . ( (int) $order->tax_minor / 100 ) . ' €, bezahlt ' . ( (int) $order->total_minor / 100 ) . " €\n";
chk( 'Ticketprovision nur auf den bar bezahlten Teil (Netto), keine doppelte Provision auf den Gutschein', 1 === count( $tc ) && $expect_base === (int) $tc[0]['b'], json_encode( $tc ) . " erwartet Basis $expect_base" );

echo "3. Check-in im gemeinsamen Scanner\n";
$token = '';
foreach ( $mails as $m ) { if ( preg_match( '/wpcap_ticket=([a-f0-9]{64})/', is_array( $m['message'] ) ? implode( ' ', $m['message'] ) : (string) $m['message'], $mm ) ) { $token = $mm[1]; } }
chk( 'Ticketcode aus der Ticket-Mail gelesen', '' !== $token, count( $mails ) . ' Mails' );
$svc = apply_filters( 'wp_pass_captain_checkin_service_v1', null, 1 );
chk( 'Scanner-Dienst von Pass Captain verfügbar', is_object( $svc ) );
if ( $svc && $token ) {
	chk( 'Scanner erkennt das Ticket', $svc->recognizes( $token ) );
	$pv = $svc->preview( $token );
	chk( 'Vorschau: gültig', is_array( $pv ) && 'valid' === ( $pv['status'] ?? '' ), json_encode( is_wp_error( $pv ) ? $pv->get_error_message() : $pv ) );
	$ci  = $svc->check_in( $token, "e2e-ci-$tag" );
	$ci2 = $svc->check_in( $token, "e2e-ci-$tag" );
	chk( 'Check-in erfolgreich', is_array( $ci ) && 'checked_in' === ( $ci['status'] ?? '' ), json_encode( is_wp_error( $ci ) ? $ci->get_error_message() : $ci ) );
	chk( 'gleicher Scan nochmal zählt nicht doppelt', ! is_wp_error( $ci2 ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id IN (SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d)", $oid ) ), json_encode( is_wp_error( $ci2 ) ? $ci2->get_error_message() : $ci2 ) );
}

echo "4. Termin abgesagt → Erstattung, Provision und Gutschein\n";
$r = PassCaptain\Admin\EventsPage::cancel_occurrence( array( 'occurrence_id' => $occ ) );
chk( 'Termin abgesagt', is_array( $r ) && ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : json_encode( $r ) );
if ( class_exists( 'PassCaptain\Jobs' ) && method_exists( 'PassCaptain\Jobs', 'run_due' ) ) { PassCaptain\Jobs::run_due(); }
$order = PassCaptain\Orders::get( $oid );
echo '  Bestellung: ' . $order->status . ' / ' . $order->payment_status . ', erstattet ' . ( (int) $order->refunded_minor / 100 ) . " €\n";
$tc = $comm( 'pass-captain', $oid );
chk( 'Ticketprovision vollständig umgekehrt', 0 === $sum( $tc ), json_encode( $tc ) );
echo '  Gutschein nach Absage (Status / Guthaben): ' . $vstate() . ", Journal " . $balance() . " €\n";
$vv = vcap()->vouchers->get_by_code( $vcode );
chk( 'Gutschein-Anteil (50 €) ist zurück und der Gutschein wieder einlösbar', abs( $balance() - 50.0 ) < 0.01 && $vv && 'active' === $vv->status, $vstate() . ' / Journal ' . $balance() );
chk( 'Gutscheinverkauf behält seine Provision (der Gutschein ist weiter gültig)', $sum( $comm( 'woocommerce', $wo->get_id() ) ) > 0 );
chk( 'Affiliate-Konten konsistent', array() === WPAffiliateCaptain\Commissions\Balance::verify_all(), json_encode( WPAffiliateCaptain\Commissions\Balance::verify_all() ) );

echo "\n$ok ok, $fail Fehler\n";
// Für den App-Test (learnboating-app.sh) den Partnercode weitergeben.
echo json_encode( array( 'partner' => $aff, 'code' => $code, 'woo_order' => $wo->get_id(), 'pass_order' => $oid, 'voucher' => $vcode ) ), "\n";
exit( $fail ? 1 : 0 );
