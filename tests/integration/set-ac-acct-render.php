<?php
/**
 * SET-AC-30 render checks: the partner area shows the fields of the bank transfer outside SEPA
 * only while the method is offered. Included from run.php after the render files (r_render()),
 * uses the helpers of set-ac-acct.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;

echo "SET-AC-30 render: partner area payout form\n";
$acct_keep_r = get_option( 'wpac_settings' );
acct_set( array( 'payout_method_bank' => 1, 'maintenance_mode' => 0 ) );
ProfileRepository::save( $acct_us, acct_us() + acct_base( 'us' ) );
$acct_tab = new ReflectionMethod( WPAffiliateCaptain\Portal\Portal::class, 'tab_profile' );
$acct_tab->setAccessible( true );
$acct_r    = r_render( static function () use ( $acct_tab, $acct_us ) { $acct_tab->invoke( null, AffiliateRepository::find( $acct_us ) ); } );
$acct_html = (string) $acct_r[0];
check( 'SET-AC-30 partner area renders without notices or errors', array( array(), '' ), array( $acct_r[1], $acct_r[2] ) );
check(
	'SET-AC-30 partner area: method offered, shared holder/BIC section, account number masked and never prefilled, bank code prefilled',
	array( true, true, true, true, false, true, true ),
	array(
		(bool) preg_match( '/<option value="bank"\s+selected/', $acct_html ),
		false !== strpos( $acct_html, '<div class="wpac-method-sepa wpac-method-bank">' ),
		false !== strpos( $acct_html, '<div class="wpac-method-bank">' ),
		false !== strpos( $acct_html, 'US •••• 6789' ),
		false !== strpos( $acct_html, '000123456789' ),
		false !== strpos( $acct_html, 'value="021000021"' ),
		(bool) preg_match( '/<option value="US"\s+selected/', $acct_html ),
	)
);
$acct_get   = $_GET;
$_GET       = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $acct_us, 'tab' => 'payout' );
$acct_ra    = r_render( array( WPAffiliateCaptain\Admin\Pages\AffiliatesPage::class, 'render' ) );
$_GET       = $acct_get;
check(
	'SET-AC-30 admin payout tab: non-SEPA rows depend on the method, holder/BIC on SEPA or bank, account number masked only',
	array( array(), '', true, true, true, false ),
	array( $acct_ra[1], $acct_ra[2], false !== strpos( $acct_ra[0], 'data-wpac-depends="payout_method=bank"><th><label for="wpac-bank_country">' ), false !== strpos( $acct_ra[0], 'data-wpac-depends="payout_method=sepa|bank"><th><label for="wpac-account_holder">' ), false !== strpos( $acct_ra[0], 'US •••• 6789 – leave empty to keep' ), false !== strpos( $acct_ra[0], '000123456789' ) )
);
acct_set( array( 'payout_method_bank' => 0 ) );
list( $acct_plain ) = acct_partner( 'plain', array( 'payout_method' => 'sepa', 'iban' => 'DE89370400440532013000', 'country' => 'DE' ) );
$acct_r2    = r_render( static function () use ( $acct_tab, $acct_plain ) { $acct_tab->invoke( null, AffiliateRepository::find( $acct_plain ) ); } );
$acct_html2 = (string) $acct_r2[0];
check( 'SET-AC-30 partner area with the method off (default): no non-SEPA fields', array( false, false, true ), array( false !== strpos( $acct_html2, 'wpac_account_number' ), false !== strpos( $acct_html2, '<option value="bank"' ), false !== strpos( $acct_html2, '<div class="wpac-method-sepa">' ) ) );

update_option( 'wpac_settings', $acct_keep_r );
Settings::flush_cache();
