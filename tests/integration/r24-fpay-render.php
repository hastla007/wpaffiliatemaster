<?php
/**
 * Fix round 24, agent fpay, render check P2-14: with a withholding the partner area shows the
 * amount transferred and the amount before the withholding in brackets (button and balance).
 * Included from run.php after the render files (r_render() and template stubs of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Withholding;
use WPAffiliateCaptain\Portal\Portal;

echo "R24 fpay render: withholding in the partner area (P2-14)\n";
global $wpdb;
$fpr_keep_user = isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 0;
$fpr_keep_get  = $_GET;
$fpr_keep_set  = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $fpr_keep_set, array( 'min_payout_minor' => 5000, 'max_payout_minor' => 0, 'currency' => 'EUR', 'payout_requests' => 1, 'self_billing' => 1, 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'vies_check' => 0, 'maintenance_mode' => 0, 'default_rate_type' => 'percent', 'default_rate' => '10' ) ) );
Settings::flush_cache();
$fpr_uid = 924001;
$fpr_aff = AffiliateRepository::create( array( 'name' => 'FPay Render', 'email' => 'fpay-render@example.de', 'status' => 'active', 'user_id' => $fpr_uid ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => $fpr_uid ), array( 'id' => (int) $fpr_aff ) );
ProfileRepository::save( $fpr_aff, array( 'tax_status' => 'private', 'vat_id' => '', 'tax_number' => '', 'legal_name' => 'FPay Render', 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'FPay Render', 'iban' => 'DE89370400440532013000' ) );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $fpr_aff );
$GLOBALS['test_users'][ $fpr_uid ] = (object) array( 'ID' => $fpr_uid, 'user_email' => 'fpay-render@example.de', 'display_name' => 'FPay', 'user_login' => 'fpay-render', 'roles' => array( 'customer' ) );
$GLOBALS['test_user']              = $fpr_uid;
$fpr_sale = wpac_record_sale( array( 'source' => 'test', 'order_id' => 'FPAY-R-1', 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $fpr_aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 60000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-fpay-r@example.de' ) ) );
Ledger::transition( (int) $fpr_sale['commission_ids'][0], 'approved' );
$_GET = array( 'wpac_tab' => 'payouts' );
$fpr_plain = r_render( array( Portal::class, 'render' ) );
Withholding::save( $fpr_aff, '50', 'Quellensteuer' );
$fpr_wh    = r_render( array( Portal::class, 'render' ) );
$fpr_shown = Money::format( 3000, 'EUR' ) . ' (' . Money::format( 6000, 'EUR' ) . ' before withholding)';
check(
	'P2-14 partner area: without withholding 60 €; with 50 % the amount transferred (30 €) and the amount before it in brackets, on the balance and the button, and a hint that the minimum applies before the withholding',
	array( true, false, true, true, true, array(), '' ),
	array(
		false !== strpos( $fpr_plain[0], esc_html( 'Request payout of ' . Money::format( 6000, 'EUR' ) ) ),
		false !== strpos( $fpr_plain[0], 'before withholding' ),
		false !== strpos( $fpr_wh[0], esc_html( 'Available for payout: ' . $fpr_shown ) ),
		false !== strpos( $fpr_wh[0], esc_html( 'Request payout of ' . $fpr_shown ) ),
		false !== strpos( $fpr_wh[0], 'The minimum payout applies to the amount before the withholding.' ),
		$fpr_wh[1],
		$fpr_wh[2],
	)
);
Withholding::save( $fpr_aff, '0', '' );
$_GET                 = $fpr_keep_get;
$GLOBALS['test_user'] = $fpr_keep_user;
update_option( 'wpac_settings', $fpr_keep_set );
Settings::flush_cache();
