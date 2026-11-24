<?php
/**
 * Final review P5-08 … P5-14 (forms of rules and partners, partner area payout details, add-on
 * notes, tables on small screens). Included from run.php after the render files (uses r_render()
 * of r8-render.php and the wp_safe_redirect() stub of r12-c.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\AddonHints;
use WPAffiliateCaptain\Admin\Pages\AffiliatesPage;
use WPAffiliateCaptain\Admin\Pages\RulesPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;

echo "R24 fux: rule and partner forms, payout details, add-on notes, tables\n";

$fx_get  = $_GET;
$fx_post = $_POST;
$fx_caps = $GLOBALS['test_caps'] ?? null;
unset( $GLOBALS['test_caps'] );
$fx_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $fx_keep, array( 'currency' => 'EUR', 'feature_tiers' => 1 ) ) );
Settings::flush_cache();

$fx_post_do = static function ( $class, $do, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	delete_transient( 'wpac_notice_' . get_current_user_id() );
	try {
		$class::handle( $do );
		$halt = 'no halt';
	} catch ( R12C_Halt $e ) {
		$halt = $e->getMessage();
	}
	$_POST    = array();
	$_REQUEST = array();
	$notice   = get_transient( 'wpac_notice_' . get_current_user_id() );
	return array( $halt, is_array( $notice ) ? $notice : array( 'message' => '', 'type' => '', 'details' => array() ) );
};
$fx_xpath = static function ( $html ) {
	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	return new DOMXPath( $doc );
};
// Fields of a form (by XPath) without a visible label: no <label for=id> (screen-reader-text does not
// count) and no wrapping <label>.
$fx_unlabelled = static function ( DOMXPath $x, $form ) {
	$out = array();
	foreach ( $x->query( $form . '//*[self::input or self::select or self::textarea]' ) as $n ) {
		if ( in_array( $n->getAttribute( 'type' ), array( 'hidden', 'submit', 'button' ), true ) ) {
			continue;
		}
		$id = $n->getAttribute( 'id' );
		$ok = '' !== $id && $x->query( '//label[@for="' . $id . '" and not(contains(@class,"screen-reader-text"))]' )->length > 0;
		$ok = $ok || $x->query( 'ancestor::label', $n )->length > 0;
		if ( ! $ok ) {
			$out[] = $n->getAttribute( 'name' );
		}
	}
	return $out;
};
// aria-describedby tokens without an element of that id.
$fx_broken = static function ( DOMXPath $x ) {
	$ids = array();
	foreach ( $x->query( '//*[@id]' ) as $n ) {
		$ids[ $n->getAttribute( 'id' ) ] = true;
	}
	$out = array();
	foreach ( $x->query( '//*[@aria-describedby]' ) as $n ) {
		foreach ( preg_split( '/\s+/', trim( $n->getAttribute( 'aria-describedby' ) ) ) as $t ) {
			if ( '' === $t || ! isset( $ids[ $t ] ) ) {
				$out[] = $n->getAttribute( 'name' ) . '→' . $t;
			}
		}
	}
	return $out;
};
$fx_attr = static function ( DOMXPath $x, $query, $attr ) {
	$n = $x->query( $query )->item( 0 );
	return $n ? $n->getAttribute( $attr ) : null;
};

/* P5-08: rule form keeps the input and shows each error at its field ------------------------- */
$fx_rules_before = count( RuleRepository::all() );
$fx_r            = $fx_post_do(
	RulesPage::class,
	'rule_save',
	array( 'id' => '0', 'scope' => 'global', 'target' => 'sku', 'target_value' => 'KURS-*', 'rate_type' => 'percent', 'rate' => '150', 'max_amount' => '30', 'min_base' => '', 'valid_from' => '2026-12-01', 'valid_until' => '2026-11-01', 'priority' => '3', 'active' => '1', 'note' => 'Wintersale', 'reason' => '' )
);
$_GET    = array( 'page' => 'wpac-rules' );
$fx_page = r_render( array( RulesPage::class, 'render' ) );
$fx_x    = $fx_xpath( $fx_page[0] );
check(
	'P5-08 rule with rate 150 % and end before start: nothing saved, every error listed, the input is kept, each error at its field (id, aria-describedby, aria-invalid)',
	array( $fx_rules_before, 'error', 2, 'KURS-*', '150', '30', '2026-12-01', 'Wintersale', '3', 'sku', 'wpac-rule-rate-err', 'true', 'wpac-rule-valid-hint wpac-rule-valid_until-err', true, true, array(), array(), '' ),
	array(
		count( RuleRepository::all() ),
		$fx_r[1]['type'],
		count( $fx_r[1]['details'] ),
		$fx_attr( $fx_x, '//input[@name="target_value"]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="rate"]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="max_amount"]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="valid_from"]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="note"]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="priority"]', 'value' ),
		$fx_attr( $fx_x, '//select[@name="target"]/option[@selected]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="rate"]', 'aria-describedby' ),
		$fx_attr( $fx_x, '//input[@name="rate"]', 'aria-invalid' ),
		$fx_attr( $fx_x, '//input[@name="valid_until"]', 'aria-describedby' ),
		false !== strpos( (string) $fx_x->query( '//*[@id="wpac-rule-rate-err"]' )->item( 0 )->textContent, 'A percentage cannot exceed 100.' ),
		null === $fx_attr( $fx_x, '//input[@name="min_base"]', 'aria-invalid' ) || '' === $fx_attr( $fx_x, '//input[@name="min_base"]', 'aria-invalid' ),
		$fx_broken( $fx_x ),
		$fx_page[1],
		$fx_page[2],
	)
);
// The kept input is read once: the next page view shows an empty form again.
$fx_page2 = r_render( array( RulesPage::class, 'render' ) );
check( 'P5-08 the kept rule input is shown once', '', $fx_attr( $fx_xpath( $fx_page2[0] ), '//input[@name="note"]', 'value' ) );
check(
	'P5-08 RuleRepository::field_errors() names every field at once; sanitize() keeps its first error',
	array( array( 'rate', 'target_value', 'max_amount', 'valid_until' ), 'wpac_rate' ),
	array(
		array_keys( RuleRepository::field_errors( array( 'scope' => 'global', 'target' => 'sku', 'target_value' => '', 'rate_type' => 'percent', 'rate' => '150', 'max_amount' => '-1', 'valid_from' => '2026-12-02', 'valid_until' => '2026-12-01' ) ) ),
		RuleRepository::sanitize( array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '150', 'max_amount' => '-1' ) )->get_error_code(),
	)
);
// Editing without a reason: the changes are kept, the reason field says why.
$fx_rule = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'global', 'target' => 'sku', 'target_value' => 'FX-1', 'rate_type' => 'percent', 'rate' => '5', 'active' => 1, 'note' => 'vorher' ) ), 0, 'test' );
$fx_e    = $fx_post_do( RulesPage::class, 'rule_save', array( 'id' => (string) $fx_rule, 'scope' => 'global', 'target' => 'sku', 'target_value' => 'FX-1', 'rate_type' => 'percent', 'rate' => '7', 'active' => '1', 'note' => 'nachher', 'reason' => '' ) );
$_GET    = array( 'page' => 'wpac-rules', 'edit' => (string) $fx_rule );
$fx_page = r_render( array( RulesPage::class, 'render' ) );
$fx_x    = $fx_xpath( $fx_page[0] );
check(
	'P5-08 edit without a reason: rule unchanged, changes kept in the form, error at the reason field, reason required with a visible *',
	array( 'vorher', 'nachher', '7', 'wpac-rule-reason-err', 'required', true, true ),
	array(
		RuleRepository::find( $fx_rule )->note,
		$fx_attr( $fx_x, '//input[@name="note"]', 'value' ),
		$fx_attr( $fx_x, '//input[@name="rate"]', 'value' ),
		$fx_attr( $fx_x, '//input[@id="wpac-rule-reason"]', 'aria-describedby' ),
		$fx_x->query( '//input[@id="wpac-rule-reason" and @required]' )->length ? 'required' : '',
		false !== strpos( (string) $fx_x->query( '//label[@for="wpac-rule-reason"]' )->item( 0 )->textContent, '*' ),
		1 === $fx_x->query( '//p[@class="wpac-required-note"]' )->length,
	)
);
$fx_ok = $fx_post_do( RulesPage::class, 'rule_save', array( 'id' => (string) $fx_rule, 'scope' => 'global', 'target' => 'sku', 'target_value' => 'FX-1', 'rate_type' => 'percent', 'rate' => '7', 'active' => '1', 'note' => 'nachher', 'reason' => 'Test' ) );
check( 'P5-08 with a reason the change is saved', array( 'success', 'nachher' ), array( $fx_ok[1]['type'], RuleRepository::find( $fx_rule )->note ) );

/* P5-10 / P5-12: visible labels in the rule preview, the tier form and the rule form ---------- */
$_GET     = array( 'page' => 'wpac-rules', 'tab' => 'preview' );
$fx_prev  = r_render( array( RulesPage::class, 'render' ) );
$fx_px    = $fx_xpath( $fx_prev[0] );
$_GET     = array( 'page' => 'wpac-rules', 'tab' => 'tiers' );
$fx_tiers = r_render( array( RulesPage::class, 'render' ) );
$fx_tx    = $fx_xpath( $fx_tiers[0] );
$_GET     = array( 'page' => 'wpac-rules' );
$fx_list  = r_render( array( RulesPage::class, 'render' ) );
$fx_lx    = $fx_xpath( $fx_list[0] );
check(
	'P5-10 rule preview and tier form: every field has a visible label; the reasons to delete a rule / deactivate a plan are labelled and required',
	array( 7, array(), array(), true, true, array(), '' ),
	array(
		$fx_px->query( '//form[@method="get"]//*[self::input or self::select][not(@type="hidden")]' )->length,
		$fx_unlabelled( $fx_px, '//form[@method="get"]' ),
		$fx_unlabelled( $fx_tx, '//form[.//input[@name="do" and @value="tier_create"]]' ),
		$fx_lx->query( '//form[.//input[@name="do" and @value="rule_delete"]]//input[@name="reason" and @required]' )->length > 0 && array() === $fx_unlabelled( $fx_lx, '//form[.//input[@name="do" and @value="rule_delete"]]' ),
		false !== strpos( $fx_tiers[0], 'for="wpac-tier-note"' ) && false !== strpos( $fx_tiers[0], 'for="wpac-tier-reason"' ),
		array_merge( $fx_prev[1], $fx_tiers[1], $fx_list[1] ),
		$fx_prev[2] . $fx_tiers[2] . $fx_list[2],
	)
);
check(
	'P5-12 rule form: rate, priority and note with <label for>; priority explained (higher number wins)',
	array( true, true, true, 'wpac-rule-priority-hint', true ),
	array(
		1 === $fx_lx->query( '//label[@for="wpac-rule-rate"]' )->length,
		1 === $fx_lx->query( '//label[@for="wpac-rule-priority"]' )->length,
		1 === $fx_lx->query( '//label[@for="wpac-rule-note"]' )->length,
		$fx_attr( $fx_lx, '//input[@name="priority"]', 'aria-describedby' ),
		false !== strpos( (string) $fx_lx->query( '//*[@id="wpac-rule-priority-hint"]' )->item( 0 )->textContent, 'Higher number wins' ),
	)
);

/* P5-09: partner profile all or nothing, input kept, slug adjustment named --------------------- */
$fx_a  = (int) AffiliateRepository::create( array( 'name' => 'Segelschule Nord GmbH', 'email' => 'fx-nord@example.de', 'status' => 'active', 'channel' => 'Blog' ) );
$fx_b  = (int) AffiliateRepository::create( array( 'name' => 'Segelschule Süd', 'email' => 'fx-sued@example.de', 'status' => 'active' ) );
$fx_as = (string) AffiliateRepository::find( $fx_a )->slug;
$fx_bs = (string) AffiliateRepository::find( $fx_b )->slug;
$fx_p  = $fx_post_do( AffiliatesPage::class, 'affiliate_update', array( 'id' => (string) $fx_a, 'name' => 'Neuer Name', 'email' => 'fx-sued@example.de', 'slug' => $fx_as, 'website' => '', 'channel' => 'Podcast', 'group_id' => '0', 'landing_page_id' => '0', 'landing_message' => '' ) );
$fx_af = AffiliateRepository::find( $fx_a );
$_GET  = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $fx_a, 'tab' => 'profile' );
$fx_ed = r_render( array( AffiliatesPage::class, 'render' ) );
$fx_ex = $fx_xpath( $fx_ed[0] );
check(
	'P5-09 repro A: e-mail of another partner → nothing saved (name, channel, e-mail unchanged), the input is shown again with the error at the e-mail field',
	array( 'Segelschule Nord GmbH', 'Blog', 'fx-nord@example.de', 'error', 'Neuer Name', 'Podcast', 'fx-sued@example.de', 'true', true, array(), array(), '' ),
	array(
		$fx_af->name,
		$fx_af->channel,
		$fx_af->email,
		$fx_p[1]['type'],
		$fx_attr( $fx_ex, '//input[@name="name"]', 'value' ),
		$fx_attr( $fx_ex, '//input[@name="channel"]', 'value' ),
		$fx_attr( $fx_ex, '//input[@name="email"]', 'value' ),
		$fx_attr( $fx_ex, '//input[@name="email"]', 'aria-invalid' ),
		false !== strpos( (string) $fx_ex->query( '//*[@id="wpac-email-err"]' )->item( 0 )->textContent, 'already used by another partner' ),
		$fx_broken( $fx_ex ),
		$fx_ed[1],
		$fx_ed[2],
	)
);
$fx_s  = $fx_post_do( AffiliatesPage::class, 'affiliate_update', array( 'id' => (string) $fx_a, 'name' => 'Segelschule Nord GmbH', 'email' => 'fx-nord@example.de', 'slug' => $fx_bs, 'website' => '', 'channel' => 'Blog', 'group_id' => '0', 'landing_page_id' => '0', 'landing_message' => '' ) );
$fx_ns = (string) AffiliateRepository::find( $fx_a )->slug;
check(
	'P5-09 repro B: slug of another partner → saved with a suffix and a warning that names the stored slug',
	array( true, 'warning', true, true ),
	array(
		$fx_ns !== $fx_bs && 0 === strpos( $fx_ns, $fx_bs . '-' ),
		$fx_s[1]['type'],
		false !== strpos( $fx_s[1]['message'], '"' . $fx_ns . '"' ),
		false !== strpos( $fx_s[1]['message'], '"' . $fx_bs . '"' ),
	)
);
$fx_u = $fx_post_do( AffiliatesPage::class, 'affiliate_update', array( 'id' => (string) $fx_a, 'name' => 'Segelschule Nord', 'email' => 'fx-nord@example.de', 'slug' => $fx_ns, 'website' => '', 'channel' => 'Blog', 'group_id' => '0', 'landing_page_id' => '0', 'landing_message' => '' ) );
check( 'P5-09 a normal save still says "Partner saved."', array( 'success', 'Partner saved.', 'Segelschule Nord' ), array( $fx_u[1]['type'], $fx_u[1]['message'], AffiliateRepository::find( $fx_a )->name ) );

/* P5-12: partner forms: required marks and linked help texts ----------------------------------- */
$_GET  = array( 'page' => 'wpac-affiliates', 'action' => 'new' );
$fx_nw = r_render( array( AffiliatesPage::class, 'render' ) );
$fx_nx = $fx_xpath( $fx_nw[0] );
$_GET  = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $fx_a, 'tab' => 'profile' );
$fx_ed = r_render( array( AffiliatesPage::class, 'render' ) );
$fx_ex = $fx_xpath( $fx_ed[0] );
$fx_desc = static function ( DOMXPath $x, array $names ) {
	$out = array();
	foreach ( $names as $n ) {
		$out[ $n ] = (string) $x->query( '//*[@name="' . $n . '"]' )->item( 0 )->getAttribute( 'aria-describedby' );
	}
	return $out;
};
check(
	'P5-12 "Add partner": required note and visible * at name and e-mail; name, e-mail, website and channel have linked help texts',
	array( 1, 2, array( 'name' => 'wpac-name-desc', 'email' => 'wpac-email-desc', 'website' => 'wpac-website-desc', 'channel' => 'wpac-channel-desc' ), array(), array(), '' ),
	array(
		$fx_nx->query( '//p[@class="wpac-required-note"]' )->length,
		$fx_nx->query( '//label[span[@class="wpac-required"]]' )->length,
		$fx_desc( $fx_nx, array( 'name', 'email', 'website', 'channel' ) ),
		$fx_broken( $fx_nx ),
		$fx_nw[1],
		$fx_nw[2],
	)
);
check(
	'P5-12 profile: slug, landing page, welcome text and the other fields have linked help texts; no broken references',
	array( 'wpac-slug-desc', 'wpac-landing_page_id-desc', 'wpac-landing_message-desc', 'wpac-name-desc', array() ),
	array(
		$fx_attr( $fx_ex, '//input[@name="slug"]', 'aria-describedby' ),
		$fx_attr( $fx_ex, '//select[@name="landing_page_id"]', 'aria-describedby' ),
		$fx_attr( $fx_ex, '//textarea[@name="landing_message"]', 'aria-describedby' ),
		$fx_attr( $fx_ex, '//input[@name="name"]', 'aria-describedby' ),
		$fx_broken( $fx_ex ),
	)
);

/* P5-11: payout details: country from a list, help texts linked, required note ------------------ */
ProfileRepository::save( $fx_a, array( 'tax_status' => 'private', 'legal_name' => 'Segelschule Nord', 'address' => 'Hafen 1, Kiel', 'country' => 'DE', 'account_holder' => 'Segelschule Nord', 'iban' => 'DE89370400440532013000', 'payout_method' => 'sepa' ) );
$fx_tab = new ReflectionMethod( WPAffiliateCaptain\Portal\Portal::class, 'tab_profile' );
$fx_tab->setAccessible( true );
$fx_pr  = r_render( static function () use ( $fx_tab, $fx_a ) { $fx_tab->invoke( null, AffiliateRepository::find( $fx_a ) ); } );
$fx_ppx = $fx_xpath( $fx_pr[0] );
$fx_acc = new ReflectionMethod( WPAffiliateCaptain\Portal\Portal::class, 'tab_account' );
$fx_acc->setAccessible( true );
$fx_ac  = r_render( static function () use ( $fx_acc, $fx_a ) { $fx_acc->invoke( null, AffiliateRepository::find( $fx_a ) ); } );
check(
	'P5-11 partner area payout details: country of residence is a list (ISO code as value, stored one selected); payment details help linked; required note in profile and account form',
	array( 0, 'DE', 'wpac-payment-details-help', 1, 1, array(), array(), '' ),
	array(
		$fx_ppx->query( '//input[@name="wpac_country"]' )->length,
		$fx_attr( $fx_ppx, '//select[@name="wpac_country"]/option[@selected]', 'value' ),
		$fx_attr( $fx_ppx, '//textarea[@name="wpac_payment_details"]', 'aria-describedby' ),
		$fx_ppx->query( '//p[@class="wpac-required-note"]' )->length,
		$fx_xpath( $fx_ac[0] )->query( '//p[@class="wpac-required-note"]' )->length,
		$fx_broken( $fx_ppx ),
		array_merge( $fx_pr[1], $fx_ac[1] ),
		$fx_pr[2] . $fx_ac[2],
	)
);
// Bank code help (bank transfer outside SEPA offered).
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'payout_method_bank' => 1 ) ) );
Settings::flush_cache();
$fx_pb = r_render( static function () use ( $fx_tab, $fx_a ) { $fx_tab->invoke( null, AffiliateRepository::find( $fx_a ) ); } );
$fx_bx = $fx_xpath( $fx_pb[0] );
$_GET  = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $fx_a, 'tab' => 'payout' );
$fx_ap = r_render( array( AffiliatesPage::class, 'render' ) );
$fx_apx = $fx_xpath( $fx_ap[0] );
check(
	'P5-11 bank code help linked in the partner area; admin tab "Payout details": country as a list with the stored code, payout method help linked',
	array( 'wpac-bank-code-help', 0, 'DE', 'wpac-payout-method-desc', array(), array(), '' ),
	array(
		$fx_attr( $fx_bx, '//input[@name="wpac_bank_code"]', 'aria-describedby' ),
		$fx_apx->query( '//input[@name="country"]' )->length,
		$fx_attr( $fx_apx, '//select[@name="country"]/option[@selected]', 'value' ),
		$fx_attr( $fx_apx, '//select[@name="payout_method"]', 'aria-describedby' ),
		$fx_broken( $fx_apx ),
		array_merge( $fx_pb[1], $fx_ap[1] ),
		$fx_pb[2] . $fx_ap[2],
	)
);
// A saved country from the list is stored as its ISO code.
ProfileRepository::save( $fx_a, array( 'tax_status' => 'private', 'legal_name' => 'Segelschule Nord', 'address' => 'Hafen 1, Kiel', 'country' => 'AT', 'account_holder' => 'Segelschule Nord', 'payout_method' => 'sepa' ) );
check( 'P5-11 the country is stored as the ISO code', 'AT', (string) ProfileRepository::get( $fx_a )->country );

/* P5-14: wide tables of rules, tiers and partner codes scroll in their own box ----------------- */
CodeRepository::create( $fx_a, 'FXCODE24' );
$_GET  = array( 'page' => 'wpac-affiliates', 'action' => 'edit', 'id' => (string) $fx_a, 'tab' => 'codes' );
$fx_cd = r_render( array( AffiliatesPage::class, 'render' ) );
$fx_in_box = static function ( $html ) {
	$x = ( function ( $h ) {
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $h . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return new DOMXPath( $doc );
	} )( $html );
	return array( $x->query( '//table[contains(@class,"widefat")]' )->length, $x->query( '//div[contains(@class,"wpac-table-scroll")]/table[contains(@class,"widefat")]' )->length );
};
$fx_lb = $fx_in_box( $fx_list[0] );
$fx_tb = $fx_in_box( $fx_tiers[0] );
$fx_cb = $fx_in_box( $fx_cd[0] );
check(
	'P5-14 rules list, tier list and partner codes tables are wrapped in a scroll box on the server',
	array( true, true, true, array(), '' ),
	array( $fx_lb[0] > 0 && $fx_lb[0] === $fx_lb[1], $fx_tb[0] > 0 && $fx_tb[0] === $fx_tb[1], $fx_cb[1] >= 1, $fx_cd[1], $fx_cd[2] )
);
$fx_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/admin.css' );
check( 'P5-14 admin.css: the scroll box scrolls sideways', true, (bool) preg_match( '/\.wpac-table-scroll\s*\{[^}]*overflow-x:\s*auto/', $fx_css ) );

/* P5-13: add-on notes: a link to the switch, one "Learn more" per address ---------------------- */
$fx_url = static function () { return 'https://example.test/pro'; };
add_filter( 'wpac_addon_hint_url', $fx_url );
$fx_h  = r_render( static function () { AddonHints::render( 'data' ); } );
$fx_hp = r_render( static function () { AddonHints::render( 'payouts' ); } );
$_GET  = array( 'page' => 'wpac-settings', 'tab' => 'data' );
$fx_st = r_render( array( SettingsPage::class, 'render' ) );
remove_filter( 'wpac_addon_hint_url', $fx_url );
check(
	'P5-13 add-on notes: one "Learn more" per address on tab Data (two notes), a "Hide these hints" link to the switch on tab Data, which has that id',
	array( 1, true, true, true, array(), '' ),
	array(
		substr_count( $fx_h[0], '>Learn more<' ),
		false !== strpos( $fx_h[0], 'tab=data#wpac-addon_hints' ) && false !== strpos( $fx_h[0], 'Hide these hints' ),
		false !== strpos( $fx_hp[0], 'tab=data#wpac-addon_hints' ),
		false !== strpos( $fx_st[0], 'id="wpac-addon_hints"' ),
		array_merge( $fx_h[1], $fx_hp[1], $fx_st[1] ),
		$fx_h[2] . $fx_hp[2] . $fx_st[2],
	)
);

$_GET  = $fx_get;
$_POST = $fx_post;
if ( null !== $fx_caps ) {
	$GLOBALS['test_caps'] = $fx_caps;
}
update_option( 'wpac_settings', $fx_keep );
Settings::flush_cache();
