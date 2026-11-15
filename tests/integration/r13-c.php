<?php
/**
 * Integration checks of audit panel round 13, part C: unambiguous amount input (K6), the
 * precision of every accepted currency (K7), the payout method without JavaScript (M5, server
 * side), existing period ends (M6), plurals and translator comments (N6) and accessibility
 * details in CSS/JS (N7). Included from tests/integration/run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Sales\ContractApi;

echo "R13 C: amount input, currency precision, payout method, period end, plurals, a11y\n";

global $wpdb;
$r13c_root = dirname( __DIR__, 2 );
$r13c_src  = static function ( $f ) use ( $r13c_root ) {
	return (string) file_get_contents( $r13c_root . '/' . $f );
};

/* R13-K6: ambiguous amounts are refused with a locale formatted example. --------------------- */
$r13c_amb = Admin::amount_error( 'ambiguous', 'EUR' );
$r13c_inv = Admin::amount_error( 'invalid', 'EUR' );
check(
	'R13-K6 admin messages: ambiguous amount names 1000 and the grouped form, examples from number_format_i18n()',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $r13c_amb, 'ambiguous' ),
		false !== strpos( $r13c_amb, '1000' ) && false !== strpos( $r13c_amb, number_format_i18n( 1000, 2 ) ),
		false !== strpos( $r13c_inv, number_format_i18n( 1000.5, 2 ) ) && false !== strpos( $r13c_inv, number_format_i18n( 25 ) ),
		false === strpos( $r13c_src( 'src/Admin/Admin.php' ), "'Please enter a valid amount (e.g. 25 or 1.000,50).'" ),
		false === strpos( $r13c_src( 'src/Core/Settings.php' ), 'e.g. 1.000 or 1000,00' ),
	)
);
check(
	'R13-K6 payout correction and manual booking show the ambiguous message (not a generic one)',
	array( true, true ),
	array(
		false !== strpos( $r13c_src( 'src/Admin/Pages/PayoutsPage.php' ), "'invalid' !== \$problem ? Admin::amount_error( (string) \$problem, (string) \$cp->currency )" ),
		false !== strpos( $r13c_src( 'src/Admin/Pages/CommissionsPage.php' ), 'Admin::back( Admin::amount_error( (string) $problem, $currency ), \'error\' );' ),
	)
);
$r13c_keep = get_option( 'wpac_settings' );
$r13c_s    = Settings::sanitize( array( 'currency' => 'EUR', 'fraud_max_order' => '1.000 €', 'min_payout' => '2,500' ), $r13c_err );
check(
	'R13-K6 settings: "1.000 €" and "2,500" are refused as ambiguous, the stored values stay',
	array( true, true, (int) $r13c_keep['fraud_max_order_minor'], (int) $r13c_keep['min_payout_minor'] ),
	array( isset( $r13c_err['fraud_max_order'] ) && false !== strpos( $r13c_err['fraud_max_order'], 'ambiguous' ), isset( $r13c_err['min_payout'] ) && false !== strpos( $r13c_err['min_payout'], 'ambiguous' ), (int) $r13c_s['fraud_max_order_minor'], (int) $r13c_s['min_payout_minor'] )
);
$r13c_s = Settings::sanitize( array( 'currency' => 'EUR', 'fraud_max_order' => '1.000,00 €', 'min_payout' => '1 000,50' ), $r13c_err );
check( 'R13-K6 settings: "1.000,00 €" and "1 000,50" accepted', array( 100000, 100050, false, false ), array( (int) $r13c_s['fraud_max_order_minor'], (int) $r13c_s['min_payout_minor'], isset( $r13c_err['fraud_max_order'] ), isset( $r13c_err['min_payout'] ) ) );
update_option( 'wpac_settings', $r13c_keep );
Settings::flush_cache();

/* R13-K7: every accepted currency has a known precision. ------------------------------------- */
// get_woocommerce_currencies() is stubbed by r12-d.php: EUR, IRT, BTC, GGP and a malformed code.
$r13c_add = static function ( $codes ) {
	return array_merge( $codes, array( 'QQZ', 'JEP', 'bad' ) );
};
add_filter( 'wpac_contract_currencies', $r13c_add );
$r13c_cur = ContractApi::currencies();
remove_filter( 'wpac_contract_currencies', $r13c_add );
check(
	'R13-K7 currencies: BTC/IRT/GGP (known precision) and ISO codes accepted, codes without known decimals (also from the filter) refused',
	array( true, true, true, true, true, false, false ),
	array( in_array( 'BTC', $r13c_cur, true ), in_array( 'IRT', $r13c_cur, true ), in_array( 'GGP', $r13c_cur, true ), in_array( 'JEP', $r13c_cur, true ), in_array( 'XCG', $r13c_cur, true ), in_array( 'QQZ', $r13c_cur, true ), in_array( 'bad', $r13c_cur, true ) )
);
$r13c_unknown = array();
foreach ( ContractApi::currencies() as $r13c_code ) {
	if ( ! in_array( $r13c_code, explode( ' ', ContractApi::CURRENCIES ), true ) && ! Money::has_decimals( $r13c_code ) ) {
		$r13c_unknown[] = $r13c_code;
	}
}
check( 'R13-K7 no accepted code without ISO or mapped precision', array(), $r13c_unknown );

$r13c_aff  = AffiliateRepository::create( array( 'name' => 'R13C BTC', 'email' => 'r13c-btc@example.de', 'status' => 'active' ) );
$r13c_rate = (string) Settings::get( 'default_rate' );
$r13c_sale = wpac_record_sale( array( 'source' => 'test', 'order_id' => 'r13c-btc-1', 'currency' => 'btc', 'partner_code' => CodeRepository::main_code( $r13c_aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => Money::to_minor( '0.0045', 'BTC' ) ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-r13c@example.de' ) ) );
$r13c_row  = is_array( $r13c_sale ) && ! empty( $r13c_sale['commission_ids'] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT currency, base_minor, amount_minor FROM ' . t( 'commissions' ) . ' WHERE id = %d', (int) $r13c_sale['commission_ids'][0] ) ) : null;
check(
	'R13-K7 a sale of 0.0045 BTC books 450000 satoshi base and a non-zero commission in BTC',
	array( 'BTC', 450000, true ),
	array( $r13c_row ? $r13c_row->currency : ( is_wp_error( $r13c_sale ) ? $r13c_sale->get_error_code() : 'none' ), $r13c_row ? (int) $r13c_row->base_minor : 0, $r13c_row && (int) $r13c_row->amount_minor > 0 )
);
check( 'R13-K7 BTC amounts are shown with 8 decimals', number_format_i18n( 0.0045, 8 ) . "\u{00A0}BTC", Money::format( 450000, 'BTC' ) );

/* R13-M5: the server checks the fields of the chosen payout method. -------------------------- */
$r13c_p    = AffiliateRepository::create( array( 'name' => 'R13C Profile', 'email' => 'r13c-profile@example.de', 'status' => 'active' ) );
$r13c_base = array( 'tax_status' => 'private', 'legal_name' => 'R13C', 'address' => "Weg 1\n24103 Kiel", 'country' => 'DE' );
$r13c_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
check(
	'R13-M5 bank transfer in the partner area: account holder and (unless stored) IBAN required; another method needs neither',
	array( 'wpac_account_holder', 'wpac_iban_required', 'ok', 'ok', 'ok' ),
	array(
		$r13c_code( ProfileRepository::save( $r13c_p, $r13c_base + array( 'payout_method' => 'sepa', 'account_holder' => '', 'iban' => 'DE89370400440532013000' ), 'partner' ) ),
		$r13c_code( ProfileRepository::save( $r13c_p, $r13c_base + array( 'payout_method' => 'sepa', 'account_holder' => 'R13C', 'iban' => '' ), 'partner' ) ),
		$r13c_code( ProfileRepository::save( $r13c_p, $r13c_base + array( 'payout_method' => 'sepa', 'account_holder' => 'R13C', 'iban' => 'DE89370400440532013000' ), 'partner' ) ),
		$r13c_code( ProfileRepository::save( $r13c_p, $r13c_base + array( 'payout_method' => 'sepa', 'account_holder' => 'R13C', 'iban' => '' ), 'partner' ) ),
		$r13c_code( ProfileRepository::save( AffiliateRepository::create( array( 'name' => 'R13C Admin', 'email' => 'r13c-admin@example.de', 'status' => 'active' ) ), $r13c_base + array( 'payout_method' => 'sepa', 'account_holder' => '', 'iban' => '' ), 'admin' ) ),
	)
);

/* R13-M6: a period end must exist. ------------------------------------------------------------ */
$r13c_err = '';
try {
	PayoutService::preview( '2026-02-30', 'EUR' );
} catch ( IntegrationException $e ) {
	$r13c_err = $e->error_code;
}
check( 'R13-M6 is_date(): checkdate for Y-m-d', array( true, true, false, false, false, false ), array( PayoutService::is_date( '2026-02-28' ), PayoutService::is_date( '2028-02-29' ), PayoutService::is_date( '2026-02-30' ), PayoutService::is_date( '2026-13-01' ), PayoutService::is_date( '2026-2-3' ), PayoutService::is_date( null ) ) );
check( 'R13-M6 no cut-off (and no run, preview or payout) for 2026-02-30', 'invalid_date', $r13c_err );
check(
	'R13-M6 payout run and the payouts page check the date with checkdate (is_date)',
	array( true, true ),
	array(
		false !== strpos( $r13c_src( 'src/Admin/Pages/PayoutsPage.php' ), "if ( ! PayoutService::is_date( \$end ) || 3 !== strlen( \$currency ) ) {" ),
		false !== strpos( $r13c_src( 'src/Admin/Pages/PayoutsPage.php' ), "\$end      = (string) Admin::get( 'period_end' );\n\t\tif ( ! PayoutService::is_date( \$end ) ) {" ),
	)
);

/* R13-N6: plurals and translator comments. ---------------------------------------------------- */
$r13c_pp = $r13c_src( 'src/Admin/Pages/PayoutsPage.php' );
check(
	'R13-N6 _n() for several correction credit notes (admin notice, refund warning, order note)',
	array( true, true, true ),
	array(
		false !== strpos( $r13c_pp, "_n( 'Correction credit note %1\$s for %2\$s issued;" ) && false !== strpos( $r13c_pp, 'count( $issued )' ),
		false !== strpos( $r13c_pp, "after correction credit notes %3\$s." ),
		false !== strpos( $r13c_src( 'src/Integrations/WooCommerce/OrderSync.php' ), "already has correction credit notes %2\$s." ) && false === strpos( $r13c_src( 'src/Integrations/WooCommerce/OrderSync.php' ), 'correction credit note(s)' ),
	)
);
preg_match_all( '#/\* translators: ([^*]+) \*/\s*\n[^\n]*Correct net amount \(%s\)#', $r13c_pp, $r13c_cm );
check( 'R13-N6 "Correct net amount (%s)" has the same translator comment at both places', array( 2, 1 ), array( count( $r13c_cm[1] ), count( array_unique( $r13c_cm[1] ) ) ) );
check( 'R13-N6 Monitor: the %d comment is right before its string', true, (bool) preg_match( "#/\\* translators: %d: number of refused payloads \\*/\\s*\\n\\s*sprintf\\( __\\( 'Payloads sent#", $r13c_src( 'src/Core/Monitor.php' ) ) );
ob_start();
$wpdb->insert( t( 'audit' ), array( 'action' => 'payout.refund_after_correction', 'object_type' => 'payout', 'object_id' => 987654, 'details' => wp_json_encode( array( 'order' => '77', 'corrections' => array( 'KG-1', 'KG-2' ) ) ), 'created_at' => WPAffiliateCaptain\Core\Db::now() ) );
WPAffiliateCaptain\Admin\Pages\PayoutsPage::refund_warnings( (object) array( 'id' => 987654 ) );
$r13c_w = (string) ob_get_clean();
check( 'R13-N6 refund warning with two correction credit notes uses the plural', array( true, true ), array( false !== strpos( $r13c_w, 'after correction credit notes KG-1, KG-2' ), false !== strpos( $r13c_w, 'whether the correction credit notes already covered' ) ) );

/* R13-N7: accessibility details in CSS and JS. ------------------------------------------------- */
$r13c_css = $r13c_src( 'assets/css/portal.css' );
$r13c_pjs = $r13c_src( 'assets/js/portal.js' );
$r13c_ajs = $r13c_src( 'assets/js/admin.js' );
check(
	'R13-N7 copy buttons stay hidden with theme button styles; mobile labels are aria-hidden spans (no attr() content); muted text mixed less',
	array( true, false, true, true, true ),
	array(
		false !== strpos( $r13c_css, '.wpac-copy-btn[hidden]{display:none!important}' ),
		false !== strpos( $r13c_css, 'content: attr(data-label)' ),
		false !== strpos( $r13c_css, '.wpac-table td .wpac-cell-label { display: inline; font-weight: 600; }' ),
		false !== strpos( $r13c_src( 'src/Portal/Portal.php' ), '<span class="wpac-cell-label" aria-hidden="true">' ),
		false !== strpos( $r13c_css, '--wpac-muted:color-mix(in srgb,currentColor 90%,#808080)' ),
	)
);
check(
	'R13-N7 portal.js: no notice focus when the address points to an anchor or a field has autofocus',
	array( true, true ),
	array( false !== strpos( $r13c_pjs, 'document.getElementById( decodeURIComponent( window.location.hash.slice( 1 ) ) )' ), false !== strpos( $r13c_pjs, "! document.querySelector( '[autofocus]' )" ) )
);
check(
	'R13-N7 admin.js: scroll box named after caption/preceding heading, region and tab stop only when it overflows, re-checked on resize',
	array( true, true, true, true ),
	array( false !== strpos( $r13c_ajs, "sib.querySelectorAll( 'h2, h3' )" ), false !== strpos( $r13c_ajs, 'box.scrollWidth > box.clientWidth' ), false !== strpos( $r13c_ajs, "box.removeAttribute( 'tabindex' )" ), false !== strpos( $r13c_ajs, "window.addEventListener( 'resize'" ) )
);
