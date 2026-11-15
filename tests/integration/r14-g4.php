<?php
/**
 * Integration checks of audit panel round 14, fix agent g4 (legal): consent integration status
 * (L2), advertising disclosure (L3), customers' right to object (L4), record of processing (L5),
 * rendered terms hash (L6), terms template clauses (L7) and the cookie lifetime notice (L8).
 * Included from tests/integration/run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\DashboardPage;
use WPAffiliateCaptain\Admin\Pages\IntegrationsPage;
use WPAffiliateCaptain\Admin\Pages\SettingsPage;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Tracking\Consent;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R14 G4: consent status, disclosure, privacy text, record of processing, terms proof\n";

// Stand-ins of WordPress functions the minimal runtime lacks (same semantics for these checks).
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $cb = false ) { return empty( $GLOBALS['test_hooks'][ $tag ] ) ? false : true; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $breaks = false ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
}
if ( ! function_exists( 'do_blocks' ) ) {
	// Synced patterns (core/block) are expanded with the referenced post, other block comments removed.
	function do_blocks( $c ) {
		$c = preg_replace_callback( '/<!-- wp:block \{"ref":(\d+)\} \/-->/', static function ( $m ) { $p = get_post( (int) $m[1] ); return $p ? do_blocks( $p->post_content ) : ''; }, (string) $c );
		return preg_replace( '/<!-- \/?wp:[^>]*-->/', '', $c );
	}
}
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $c ) {
		foreach ( $GLOBALS['g4_shortcodes'] ?? array() as $tag => $cb ) {
			$c = str_replace( '[' . $tag . ']', (string) call_user_func( $cb ), (string) $c );
		}
		return $c;
	}
}
if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
	function wp_add_privacy_policy_content( $plugin, $html ) { $GLOBALS['g4_policy'] = $html; }
}

$g4_root     = dirname( __DIR__, 2 );
$g4_src      = static function ( $f ) use ( $g4_root ) {
	return (string) file_get_contents( $g4_root . '/' . $f );
};
$g4_settings = get_option( 'wpac_settings' );
$g4_set      = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};

// ---------------------------------------------------------------------------------------------
// R14-L2: the WP Consent API alone (no banner registered a consent type) is not "connected".
$g4_set( array( 'cookie_mode' => 'consent' ) );
remove_all_filters( 'wpac_has_marketing_consent' );
$GLOBALS['test_consent_type'] = '';
$g4_api_only   = Consent::integration_status();
$g4_check_api  = IntegrationsPage::consent_check();
$g4_problem    = static function () {
	return (bool) array_filter( Tracker::tracking_problems(), static function ( $p ) { return false !== strpos( $p[1], 'wpac_has_marketing_consent' ); } );
};
$g4_item       = static function () {
	foreach ( DashboardPage::checklist_items() as $i ) {
		if ( false !== strpos( $i[1], 'consent banner' ) ) {
			return $i;
		}
	}
	return null;
};
$g4_prob_api   = $g4_problem();
$g4_item_api   = $g4_item();
check(
	'R14-L2 WP Consent API without a registered banner: not connected, red status, Site Health and checklist warn, no cookie',
	array( true, false, false, false, true, false, false, true ),
	array( $g4_api_only['api'], $g4_api_only['banner'], $g4_api_only['connected'], $g4_check_api[0], false !== strpos( $g4_check_api[1], 'no consent banner has registered' ), Consent::cookie_allowed(), $g4_item_api[0], $g4_prob_api )
);
// A banner registers a consent type: connected.
$GLOBALS['test_consent_type'] = 'optin';
check( 'R14-L2 banner registered with the WP Consent API: connected, no warning', array( true, true, false, true ), array( Consent::integration_status()['connected'], IntegrationsPage::consent_check()[0], $g4_problem(), $g4_item()[0] ) );
$GLOBALS['test_consent_type'] = '';
// The PHP filter of a banner recipe: connected (and then also decides the cookie).
add_filter( 'wpac_has_marketing_consent', static function ( $c ) { return $c || ! empty( $GLOBALS['g4_marker'] ); } );
$GLOBALS['g4_marker'] = true;
check( 'R14-L2 filter wpac_has_marketing_consent hooked: connected, cookie allowed with the marker', array( true, true, false, true ), array( Consent::integration_status()['filter'], IntegrationsPage::consent_check()[0], $g4_problem(), Consent::cookie_allowed() ) );
remove_all_filters( 'wpac_has_marketing_consent' );
unset( $GLOBALS['g4_marker'] );
// Cookie mode "off": nothing to connect.
$g4_set( array( 'cookie_mode' => 'off' ) );
check( 'R14-L2 cookie mode "off": no consent warning', array( true, false, true ), array( IntegrationsPage::consent_check()[0], $g4_problem(), $g4_item()[0] ) );
$g4_set( array( 'cookie_mode' => 'consent' ) );
check(
	'R14-L2 status no longer green on function_exists( wp_has_consent ) alone; handbook recipes and cookie table',
	array( false, true, true, true, true, true, true, true, true ),
	array(
		false !== strpos( $g4_src( 'src/Admin/Pages/IntegrationsPage.php' ), "array( function_exists( 'wp_has_consent' )" ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), '### Consent banners' ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), 'BorlabsCookieHelper()->gaveConsent' ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), '**Usercentrics' ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), '**CCM19:**' ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), '**Complianz:**' ) && false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), '**Real Cookie Banner:**' ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), 'CookiebotOnAccept' ),
		false !== strpos( $g4_src( 'docs/HANDBOOK.md' ), '`wpacConsentGranted()` alone sets no cookie' ),
		(bool) preg_match( '/\| Name \| `wpac_ref`.*\n\| Provider \|.*\n\| Purpose \|.*\n\| Lifetime \|/', $g4_src( 'docs/HANDBOOK.md' ) ),
	)
);

// ---------------------------------------------------------------------------------------------
// R14-L3: disclosure label in the banner embed code; notice in the tab "Links & codes".
$g4_plain = Portal::embed_code( 'http://example.test/?partner=X', 'http://example.test/b.png', 300, 250, 'Banner' );
$g4_label = Portal::embed_code( 'http://example.test/?partner=X', 'http://example.test/b.png', 300, 250, 'Banner', 'Anzeige <b>' );
check(
	'R14-L3 embed code with a visible, escaped disclosure label before the linked banner; without label unchanged',
	array( false, true, true, true ),
	array( false !== strpos( $g4_plain, 'wpac-ad-label' ), 0 === strpos( $g4_label, '<div class="wpac-ad"' ), false !== strpos( $g4_label, '>Anzeige &lt;b&gt;</small><a href="http://example.test/?partner=X" rel="sponsored noopener"' ), false !== strpos( $g4_label, $g4_plain ) )
);
$g4_out = Settings::sanitize( array( 'default_disclosure' => 'Werbung' ) );
$g4_on  = Settings::sanitize( array( 'default_disclosure' => 'Werbung', 'embed_disclosure_label' => '1' ) );
check(
	'R14-L3 setting "embed_disclosure_label": default on, saved as a flag, on the Promotion tab; portal passes the creative\'s disclosure',
	array( 1, 0, 1, true, true ),
	array( Settings::defaults()['embed_disclosure_label'], $g4_out['embed_disclosure_label'], $g4_on['embed_disclosure_label'], in_array( 'embed_disclosure_label', SettingsPage::tab_keys()['promotion'], true ), false !== strpos( $g4_src( 'src/Portal/Portal.php' ), "Settings::get( 'embed_disclosure_label' ) ? (string) \$c->disclosure : ''" ) )
);
// Render the tab "Links & codes" for a partner without codes: the notice comes first.
$g4_tab = new ReflectionMethod( Portal::class, 'tab_links' );
$g4_tab->setAccessible( true );
$g4_set( array( 'default_disclosure' => 'Anzeige' ) );
$g4_html = '';
if ( function_exists( 'wp_kses_post' ) ) {
	ob_start();
	try {
		$g4_tab->invoke( null, (object) array( 'id' => 987654, 'slug' => 'g4', 'name' => 'G4', 'group_id' => 0 ) );
	} catch ( \Throwable $e ) {
		echo 'ERROR ' . $e->getMessage();
	}
	$g4_html = (string) ob_get_clean();
}
check(
	'R14-L3 tab "Links & codes" starts with the labelling duty, the disclosure note and examples per channel',
	array( true, true, true, true ),
	array( 0 === strpos( $g4_html, '<div class="wpac-disclosure" role="note">' ), false !== strpos( $g4_html, 'with &quot;Anzeige&quot; at the beginning' ), false !== strpos( $g4_html, 'Instagram, TikTok' ), false !== strpos( $g4_html, 'Print, talks, flyers' ) )
);

// ---------------------------------------------------------------------------------------------
// R14-L7 (and L3/L1 clauses): terms template.
$g4_terms = DashboardPage::terms_template();
check(
	'R14-L7 terms template: no commercial agent / § 89b HGB, data protection roles, labelling duty, credit note format with PDF consent and e-invoice, small business change, legal review note',
	array( true, true, true, true, true, true, true, true ),
	array(
		false !== strpos( $g4_terms, '§ 89b HGB' ) && false !== strpos( $g4_terms, 'not commercial agents' ),
		false !== strpos( $g4_terms, 'solely responsible for the data it processes in its own channels' ),
		false !== strpos( $g4_terms, 'do not receive personal data of our customers' ),
		false !== strpos( $g4_terms, 'with &quot;Advertisement&quot; or &quot;Ad&quot; at the beginning' ),
		false !== strpos( $g4_terms, 'consents to credit notes in PDF format' ) && false !== strpos( $g4_terms, 'e-invoice' ),
		false !== strpos( $g4_terms, 'small business scheme (§ 19 German VAT Act)' ),
		false !== strpos( $g4_terms, 'checked by a lawyer' ),
		false !== strpos( $g4_terms, 'self-billing' ),
	)
);

// ---------------------------------------------------------------------------------------------
// R14-L4: customers' right to object in the privacy policy text.
$g4_set( array( 'lifetime_enabled' => 1, 'lifetime_days' => 0 ) );
unset( $GLOBALS['g4_policy'] );
Privacy::policy_text();
$g4_policy_life = (string) ( $GLOBALS['g4_policy'] ?? '' );
$g4_set( array( 'lifetime_enabled' => 0 ) );
Privacy::policy_text();
$g4_policy_none = (string) ( $GLOBALS['g4_policy'] ?? '' );
check(
	'R14-L4 privacy text: customers can object (Art. 21) to the attribution and the lifetime link, plus their other rights',
	array( true, true, true, false, true ),
	array( false !== strpos( $g4_policy_life, 'Customers can object at any time' ), false !== strpos( $g4_policy_life, 'link to a partner for later purchases (Art. 21 GDPR)' ), false !== strpos( $g4_policy_life, 'supervisory authority' ), false !== strpos( $g4_policy_none, 'later purchases (Art. 21' ), false !== strpos( $g4_policy_none, 'attribution of their orders to a partner (Art. 21 GDPR)' ) )
);

// ---------------------------------------------------------------------------------------------
// R14-L5: record of processing activities.
$g4_set( array( 'lifetime_enabled' => 1, 'lifetime_days' => 180 ) );
$g4_de = Privacy::ropa( 'de' );
$g4_en = Privacy::ropa( 'en' );
add_filter( 'wpac_ropa_language', static function () { return 'en'; } );
$g4_auto = Privacy::ropa_language();
remove_all_filters( 'wpac_ropa_language' );
check(
	'R14-L5 record of processing: German and English template, days in the template language, language filter',
	array( true, true, false, true, true, 'en', false ),
	array( false !== strpos( $g4_de, 'Kundenverknüpfung 180 Tage' ), false !== strpos( $g4_en, 'customer link 180 days' ), false !== strpos( $g4_en, 'Tage' ), 0 === strpos( $g4_en, '# Record of processing activities' ), false === strpos( $g4_en . $g4_de, '{lifetime_days}' ), $g4_auto, false !== strpos( $g4_en, 'Verantwortlicher' ) )
);
$g4_doc = $g4_src( 'docs/VERARBEITUNGSVERZEICHNIS.md' );
check(
	'R14-L5 German template corrected: pseudonymised hash, what partners see, menu path, beacon, shop session, rate_limits, right to object',
	array( false, true, true, false, true, true, true, true, true, true ),
	array(
		false !== strpos( $g4_doc, 'verschlüsselter Hash' ),
		false !== strpos( $g4_doc, 'pseudonymisierter Hashwert' ),
		false !== strpos( $g4_doc, 'Produktname, Basisbetrag, Provision, Status, Kampagne und Sub-ID' ),
		false !== strpos( $g4_doc, 'Einstellungen → Datenschutz' ),
		false !== strpos( $g4_doc, 'Einstellungen → Daten & Wartung' ),
		false !== strpos( $g4_doc, 'Beacon' ),
		false !== strpos( $g4_doc, 'WooCommerce-Sitzung' ),
		false !== strpos( $g4_doc, '`rate_limits`' ),
		false !== strpos( $g4_doc, 'Art. 21 DSGVO' ),
		false !== strpos( $g4_src( 'bin/build-zip.sh' ), 'docs/RECORD-OF-PROCESSING.md' ),
	)
);
$g4_set( array( 'lifetime_enabled' => 0 ) );
check( 'R14-L5 lifetime off: "nicht aktiv" / "not active"', array( true, true ), array( false !== strpos( Privacy::ropa( 'de' ), 'Kundenverknüpfung nicht aktiv' ), false !== strpos( Privacy::ropa( 'en' ), 'customer link not active' ) ) );

// ---------------------------------------------------------------------------------------------
// R14-L6: the version hash covers the rendered text (synced patterns, shortcodes).
$GLOBALS['test_posts'][ 14401 ] = (object) array( 'ID' => 14401, 'post_title' => 'Muster', 'post_content' => '<!-- wp:paragraph --><p>Provision 10 %.</p><!-- /wp:paragraph -->', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
$GLOBALS['test_posts'][ 14402 ] = (object) array( 'ID' => 14402, 'post_title' => 'G4-AGB', 'post_content' => "<!-- wp:paragraph --><p>Gutschriftverfahren &amp; Bedingungen.</p><!-- /wp:paragraph -->\n<!-- wp:block {\"ref\":14401} /-->\n[g4_clause]", 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
$GLOBALS['g4_shortcodes']['g4_clause'] = static function () { return '<p>' . ( $GLOBALS['g4_clause'] ?? 'Klausel A' ) . '</p>'; };
$g4_set( array( 'terms_page_id' => 14402, 'terms_version' => 'g4-1' ) );
$g4_t1   = Agreements::current();
$g4_text = Agreements::rendered_text( get_post( 14402 ) );
// The synced pattern changes, the terms page itself not.
$GLOBALS['test_posts'][ 14401 ]->post_content = '<!-- wp:paragraph --><p>Provision 5 %.</p><!-- /wp:paragraph -->';
$g4_t2 = Agreements::current();
$GLOBALS['g4_clause'] = 'Klausel B';
$g4_t3 = Agreements::current();
check(
	'R14-L6 hash over the rendered text: a changed synced pattern or shortcode output changes it; text without tags and entities',
	array( "Gutschriftverfahren & Bedingungen.\nProvision 10 %.\nKlausel A", true, true ),
	array( $g4_text, $g4_t1['hash'] !== $g4_t2['hash'], $g4_t2['hash'] !== $g4_t3['hash'] )
);
delete_option( 'wpac_terms_text_' . $g4_t3['hash'] );
$g4_stored = Agreements::store_text( $g4_t3 );
$g4_opt    = get_option( 'wpac_terms_text_' . $g4_t3['hash'] );
check(
	'R14-L6 stored proof holds the rendered text (pattern and shortcode expanded) and the block source',
	array( true, "Gutschriftverfahren & Bedingungen.\nProvision 5 %.\nKlausel B", true, 14402 ),
	array( $g4_stored, is_array( $g4_opt ) ? $g4_opt['content'] : null, is_array( $g4_opt ) && false !== strpos( $g4_opt['source'], 'wp:block {"ref":14401}' ), is_array( $g4_opt ) ? (int) $g4_opt['page_id'] : 0 )
);
// An acceptance under the earlier hash still counts for the same version and page.
update_option( 'wpac_terms_text_' . $g4_t1['hash'], array( 'title' => 'G4-AGB', 'content' => 'x', 'page_id' => 14402 ) );
check( 'R14-L6 earlier acceptance of the same version and page still matches; another version does not', array( true, false ), array( Agreements::matches( 'g4-1', $g4_t1['hash'], $g4_t3 ), Agreements::matches( 'g4-0', $g4_t1['hash'], $g4_t3 ) ) );
// A shortcode on the terms page that asks for the terms again does not recurse.
$GLOBALS['g4_shortcodes']['g4_clause'] = static function () { return '<p>' . Agreements::current()['version'] . '</p>'; };
check( 'R14-L6 shortcode asking for the terms while they render: no recursion', true, 64 === strlen( Agreements::current()['hash'] ) );
unset( $GLOBALS['g4_shortcodes'], $GLOBALS['g4_clause'], $GLOBALS['test_posts'][ 14401 ], $GLOBALS['test_posts'][ 14402 ] );
delete_option( 'wpac_terms_text_' . $g4_t1['hash'] );
delete_option( 'wpac_terms_text_' . $g4_t3['hash'] );

// ---------------------------------------------------------------------------------------------
// R14-L8: cookie lifetime above 395 days is saved with a notice; lifetime in the cookie text.
$g4_notices = array();
$g4_errors  = array();
$g4_long    = Settings::sanitize( array( 'cookie_days' => '3650' ), $g4_errors, $g4_notices );
$g4_n_long  = isset( $g4_notices['cookie_days'] );
$g4_notices = array();
Settings::sanitize( array( 'cookie_days' => '395' ), $g4_errors, $g4_notices );
check(
	'R14-L8 cookie lifetime: 3650 saved with a notice, 395 without; form warning names both values',
	array( 3650, true, false, true, true ),
	array( $g4_long['cookie_days'], $g4_n_long, isset( $g4_notices['cookie_days'] ), false !== strpos( SettingsPage::cookie_days_warning( 3650 ), '3650 days' ), false !== strpos( SettingsPage::cookie_days_warning( 3650 ), '395 days' ) )
);
$g4_set( array( 'cookie_mode' => 'consent', 'cookie_days' => 45 ) );
Privacy::policy_text();
check( 'R14-L8 the privacy text names cookie name and lifetime', true, false !== strpos( (string) ( $GLOBALS['g4_policy'] ?? '' ), 'wpac_ref, 45 days' ) );

update_option( 'wpac_settings', $g4_settings );
Settings::flush_cache();
$GLOBALS['test_consent_type'] = '';
unset( $GLOBALS['g4_policy'] );
