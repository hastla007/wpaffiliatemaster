<?php
/**
 * SET-AC-24 setup wizard render checks: every step without PHP problems, step list with
 * aria-current, labels/help/legal marks from the register, error messages linked with
 * aria-describedby, pending country profile, first-run notice, links from the dashboard checklist
 * and the settings page, menu entry. Included from run.php after the render files (uses the
 * template stubs of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Admin\Pages\DashboardPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Admin\Pages\SetupWizard;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

echo "SET-AC-24 render: setup wizard\n";

$wizr_saved = get_option( 'wpac_settings' );
$wizr_get   = $_GET;
$wizr_caps  = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
delete_option( SetupWizard::OPTION );
SettingsSchema::flush();
Settings::update( array( 'registration_page_id' => 0, 'portal_page_id' => 0, 'terms_page_id' => 0, 'cookie_mode' => 'consent', 'default_rate_type' => 'percent', 'currency' => 'EUR' ) );
Settings::flush_cache();

// Every step renders without PHP problems; exactly one aria-current="step", on the shown step.
foreach ( array_keys( SetupWizard::steps() ) as $wizr_i => $wizr_step ) {
	$_GET   = array( 'page' => SetupWizard::SLUG, 'step' => $wizr_step );
	$wizr_r = r_render( array( SetupWizard::class, 'render' ) );
	preg_match_all( '/<li class="wpac-wizard-step[^"]*"><a href="[^"]*step=([a-z]+)"( aria-current="step")?>/', $wizr_r[0], $wizr_m );
	$wizr_current = array();
	foreach ( $wizr_m[1] as $wizr_n => $wizr_s ) {
		if ( '' !== $wizr_m[2][ $wizr_n ] ) {
			$wizr_current[] = $wizr_s;
		}
	}
	check(
		"SET-AC-24 render step {$wizr_step}: no PHP problems, step list of 6 with aria-current on this step, heading \"Step n of 6\", nonce form",
		array( array(), '', 6, array( $wizr_step ), true, true ),
		array( $wizr_r[1], $wizr_r[2], count( $wizr_m[1] ), $wizr_current, false !== strpos( $wizr_r[0], 'Step ' . ( $wizr_i + 1 ) . ' of 6' ), false !== strpos( $wizr_r[0], 'name="_wpac_nonce"' ) )
	);
	$GLOBALS['wizr_out'][ $wizr_step ] = $wizr_r[0];
}
$wizr_out = $GLOBALS['wizr_out'];

// Fields from the register: label for each control, help text, legal mark, linked description.
$wizr_missing = array();
foreach ( SetupWizard::steps() as $wizr_step => $wizr_def ) {
	foreach ( $wizr_def['fields'] as $wizr_k ) {
		$wizr_id = 'wpac-wiz-' . SetupWizard::form_name( $wizr_k );
		$wizr_ok = false !== strpos( $wizr_out[ $wizr_step ], '<label for="' . $wizr_id . '">' )
			&& false !== strpos( $wizr_out[ $wizr_step ], 'id="' . $wizr_id . '"' )
			&& false !== strpos( $wizr_out[ $wizr_step ], esc_html( SettingsSchema::label( $wizr_k ) ) )
			&& false !== strpos( $wizr_out[ $wizr_step ], esc_html( SettingsSchema::help( $wizr_k ) ) )
			&& false !== strpos( $wizr_out[ $wizr_step ], 'aria-describedby="' . $wizr_id . '-desc"' );
		if ( ! $wizr_ok ) {
			$wizr_missing[] = $wizr_k;
		}
	}
}
check(
	'SET-AC-24 every field: label for the control, label and help text from the register, help linked with aria-describedby; legal marks on legal fields',
	array( array(), true, true, true, false ),
	array(
		$wizr_missing,
		1 === preg_match( '/id="wpac-wiz-vat_rate-desc">[^<]*<span class="wpac-legal-mark">/', $wizr_out['company'] ),
		1 === preg_match( '/id="wpac-wiz-cookie_mode-desc">[^<]*<span class="wpac-legal-mark">/', $wizr_out['consent'] ),
		1 === preg_match( '/id="wpac-wiz-terms_version-desc">[^<]*<span class="wpac-legal-mark">/', $wizr_out['pages'] ),
		1 === preg_match( '/id="wpac-wiz-hold_days-desc">[^<]*<span class="wpac-legal-mark">/', $wizr_out['commission'] ),
	)
);
check(
	'SET-AC-24 keyboard: save button first (Enter saves), then skip, then back link; skip-all form; checkbox labelled; choices with settings page texts',
	array( true, true, true, true, true ),
	array(
		1 === preg_match( '/value="save" class="button button-primary">[^<]+<\/button> <button type="submit" name="wizard_go" value="skip" class="button">[^<]+<\/button> <a href="[^"]*step=company">/', $wizr_out['commission'] ),
		false !== strpos( $wizr_out['commission'], 'name="op" value="skip_all"' ),
		1 === preg_match( '/<label for="wpac-wiz-self_billing"><input type="checkbox" value="1" name="wpac\[self_billing\]" id="wpac-wiz-self_billing"/', $wizr_out['company'] ),
		false !== strpos( $wizr_out['consent'], 'Only with marketing consent (WP Consent API / wpac_has_marketing_consent filter)' ),
		1 === preg_match( '/<input type="text" inputmode="decimal" class="small-text" value="[0-9.,]+" name="wpac\[min_payout\]"/', $wizr_out['commission'] ),
	)
);
check(
	'SET-AC-24 pages step: create-pages form (own form) while pages are missing; country step: profile select and skip only; test step: no fields, links to tools, finish button, no order created',
	array( true, true, true, true, true, true ),
	array(
		false !== strpos( $wizr_out['pages'], 'name="do" value="wizard_pages"' ),
		false !== strpos( $wizr_out['country'], 'name="do" value="wizard_profile"' ) && false !== strpos( $wizr_out['country'], '<label for="wpac-wiz-profile">' ),
		false === strpos( $wizr_out['country'], 'value="save"' ),
		false !== strpos( $wizr_out['test'], 'page=wpac-commissions' ) && false !== strpos( $wizr_out['test'], 'tab=status' ),
		false !== strpos( $wizr_out['test'], 'value="finish"' ),
		false === strpos( $wizr_out['test'], 'name="wpac[' ),
	)
);

// Rejected input: summary with links, message linked to the field with aria-describedby, aria-invalid.
Admin::keep_form( 'setup_wizard', array( 'values' => array( 'hold_days' => 'abc', 'min_payout' => '1.000 €' ), 'errors' => array( 'hold_days' => 'Please enter a whole number (0 or more).', 'min_payout' => 'ambiguous' ) ) );
$_GET   = array( 'page' => SetupWizard::SLUG, 'step' => 'commission' );
$wizr_r = r_render( array( SetupWizard::class, 'render' ) );
check(
	'SET-AC-24 errors: summary (role alert) with links to the fields, message id linked by aria-describedby (with the help), aria-invalid, rejected value shown again',
	array( array(), true, true, true, true, true ),
	array(
		$wizr_r[1],
		false !== strpos( $wizr_r[0], 'role="alert"' ) && false !== strpos( $wizr_r[0], '<a href="#wpac-wiz-hold_days">' ),
		1 === preg_match( '/value="abc" name="wpac\[hold_days\]" id="wpac-wiz-hold_days" aria-describedby="wpac-wiz-hold_days-err wpac-wiz-hold_days-desc" aria-invalid="true">/', $wizr_r[0] ),
		false !== strpos( $wizr_r[0], '<p class="wpac-field-error" id="wpac-wiz-hold_days-err">Please enter a whole number (0 or more).</p>' ),
		false !== strpos( $wizr_r[0], 'aria-describedby="wpac-wiz-min_payout-err wpac-wiz-min_payout-desc"' ),
		false !== strpos( $wizr_r[0], 'value="1.000 €"' ),
	)
);

// Pending profile: table old → new with legal marks, apply and discard.
$wizr_before = Settings::all();
SetupWizard::profile_preview( 'AT' );
$_GET   = array( 'page' => SetupWizard::SLUG, 'step' => 'country' );
$wizr_r = r_render( array( SetupWizard::class, 'render' ) );
SetupWizard::profile_discard();
check(
	'SET-AC-24 pending profile: table Setting/Now/New with legal marks, Apply (wizard_profile_apply) and Discard; nothing applied by rendering',
	array( array(), true, true, true, true ),
	array( $wizr_r[1], false !== strpos( $wizr_r[0], 'id="wpac-wizard-pending"' ) && false !== strpos( $wizr_r[0], '<th scope="col">Now</th>' ), false !== strpos( $wizr_r[0], 'wpac-legal-mark' ), false !== strpos( $wizr_r[0], 'value="wizard_profile_apply"' ) && false !== strpos( $wizr_r[0], 'name="op" value="discard"' ), ( Settings::flush_cache() ?: Settings::all() ) === $wizr_before )
);

// First-run notice: while nothing is started and the checklist is open; gone once opened or dismissed.
delete_option( SetupWizard::OPTION );
$wizr_n1 = SetupWizard::notice_html();
$_GET    = array( 'page' => SetupWizard::SLUG );
r_render( array( SetupWizard::class, 'render' ) );
$wizr_n2 = SetupWizard::notice_html();
update_option( SetupWizard::OPTION, array( 'dismissed' => true ) );
$wizr_n3 = SetupWizard::notice_html();
delete_option( SetupWizard::OPTION );
check(
	'SET-AC-24 first-run notice: start link + dismiss button (POST form with nonce) while setup is open; hidden after opening the wizard or dismissing',
	array( true, true, true, '', '' ),
	array( DashboardPage::setup_open(), false !== strpos( $wizr_n1, 'page=wpac-setup' ), false !== strpos( $wizr_n1, 'name="op" value="dismiss"' ) && false !== strpos( $wizr_n1, 'name="_wpac_nonce"' ), $wizr_n2, $wizr_n3 )
);

// Re-openable: link in the dashboard checklist and on the settings page; menu entry.
$_GET   = array( 'page' => 'wpac' );
$wizr_d = r_render( array( DashboardPage::class, 'render' ) );
$_GET   = array( 'page' => 'wpac-settings', 'tab' => 'program' );
$wizr_s = r_render( array( SettingsPage::class, 'render' ) );
$GLOBALS['swr_menu_slugs'] = array();
Admin::menu();
$wizr_menu = $GLOBALS['swr_menu_slugs'];
check(
	'SET-AC-24 re-openable: link in the dashboard checklist and on the settings page, menu entry before Settings',
	array( array(), true, array(), true, true ),
	array( $wizr_d[1], 1 === preg_match( '/class="wpac-checklist">.*page=wpac-setup/s', $wizr_d[0] ), $wizr_s[1], false !== strpos( $wizr_s[0], 'page=wpac-setup' ), array_search( 'wpac-setup', $wizr_menu, true ) === array_search( 'wpac-settings', $wizr_menu, true ) - 1 )
);

$_GET = $wizr_get;
if ( null !== $wizr_caps ) {
	$GLOBALS['test_caps'] = $wizr_caps;
}
update_option( 'wpac_settings', $wizr_saved );
delete_option( SetupWizard::OPTION );
Settings::flush_cache();

