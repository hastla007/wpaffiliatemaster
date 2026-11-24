<?php
/**
 * SET-AC-08a: optional sections of the settings export (partner groups, commission rules, tier
 * plans, e-mail templates per language) with a preview per section and matching by keys, not IDs.
 * Included from run.php before r8-render.php. The three tables and the template option are saved
 * first and restored at the end, so later files see the data of the files before.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Commissions\TierService;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSections;
use WPAffiliateCaptain\Core\SettingsTransfer;

echo "SET-AC-08a settings export: groups, rules, tiers, e-mail templates\n";

// Lookups of pages, terms and SKUs (WordPress/WooCommerce functions the bootstrap does not have).
$GLOBALS['xfer_terms'] = array();
$GLOBALS['xfer_skus']  = array();
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
		foreach ( (array) $GLOBALS['test_posts'] as $p ) {
			if ( isset( $p->post_name, $p->post_type ) && $p->post_name === $path && in_array( $p->post_type, (array) $type, true ) ) {
				return $p;
			}
		}
		return null;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $id, $tax = '' ) {
		return isset( $GLOBALS['xfer_terms'][ $tax ][ (int) $id ] ) ? (object) array( 'term_id' => (int) $id, 'slug' => $GLOBALS['xfer_terms'][ $tax ][ (int) $id ] ) : null;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $tax = '' ) {
		$id = isset( $GLOBALS['xfer_terms'][ $tax ] ) ? array_search( $value, $GLOBALS['xfer_terms'][ $tax ], true ) : false;
		return false === $id ? false : (object) array( 'term_id' => (int) $id, 'slug' => $value );
	}
}
if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	// Only SettingsSections asks for it (get_posts() is left to the template stubs of r8-render.php).
	function wc_get_product_id_by_sku( $sku ) {
		return isset( $GLOBALS['xfer_skus'][ $sku ] ) ? $GLOBALS['xfer_skus'][ $sku ] : 0;
	}
}

global $wpdb;
$xfer_tables = array( 'partner_groups', 'rules', 'tiers' );
$xfer_backup = array();
foreach ( $xfer_tables as $xfer_t ) {
	$xfer_backup[ $xfer_t ] = $wpdb->get_results( 'SELECT * FROM ' . t( $xfer_t ), ARRAY_A );
}
$xfer_tpl_backup = get_option( Notifications::TEMPLATES_OPTION );
$xfer_wipe       = static function () use ( $wpdb, $xfer_tables ) {
	foreach ( $xfer_tables as $t ) {
		$wpdb->query( 'DELETE FROM ' . t( $t ) );
	}
	delete_option( Notifications::TEMPLATES_OPTION );
};
$xfer_count = static function ( $table, $where = '1=1' ) use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( $table ) . ' WHERE ' . $where );
};
$xfer_currency = (string) Settings::get( 'currency' );
$xfer_token    = (string) Settings::get( 'monitor_token' );
Settings::update( array( 'currency' => 'EUR' ) );

/* ---------- Source site ---------- */
$xfer_wipe();
$GLOBALS['test_posts'][91801] = (object) array( 'ID' => 91801, 'post_type' => 'page', 'post_name' => 'agb-trainer', 'post_title' => 'AGB Trainer', 'post_content' => '', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
$GLOBALS['test_posts'][91802] = (object) array( 'ID' => 91802, 'post_type' => 'product', 'post_name' => 'segelkurs', 'post_title' => 'Segelkurs', 'post_content' => '', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
$GLOBALS['test_posts'][91803] = (object) array( 'ID' => 91803, 'post_type' => 'product', 'post_name' => 'sup-kurs', 'post_title' => 'SUP', 'post_content' => '', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
update_post_meta( 91803, '_sku', 'SUP-1' );
$GLOBALS['xfer_skus']['SUP-1']                 = 91803;
$GLOBALS['xfer_terms']['product_cat'][31]      = 'kurse';
$xfer_g1 = Groups::create( 'Trainer', 'Trainer und Schulen', '2' );
Groups::update( $xfer_g1, array( 'name' => 'Trainer', 'description' => 'Trainer und Schulen', 'terms_page_id' => 91801, 'agreement_version' => '2', 'public' => 1, 'fields' => "schule|Name der Schule|text|required\nweb|Website|url" ) );
$xfer_g2 = Groups::create( 'Content', 'Blogs' );
$xfer_rule = static function ( array $in ) {
	$d = RuleRepository::sanitize( $in + array( 'active' => 1 ) );
	return RuleRepository::save( $d, 0, 'test' );
};
$xfer_r_global = $xfer_rule( array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '8' ) );
$xfer_r_cat    = $xfer_rule( array( 'scope' => 'group', 'scope_id' => $xfer_g1, 'target' => 'category', 'target_value' => '31', 'rate_type' => 'percent', 'rate' => '12,5', 'max_amount' => '50', 'note' => 'Kurse' ) );
$xfer_r_prod   = $xfer_rule( array( 'scope' => 'global', 'target' => 'product', 'target_value' => '91802,91803', 'rate_type' => 'fixed', 'rate' => '5', 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-31', 'note' => 'Advent' ) );
$xfer_r_sku    = $xfer_rule( array( 'scope' => 'group', 'scope_id' => $xfer_g2, 'target' => 'sku', 'target_value' => 'kurs-*', 'rate_type' => 'exclude' ) );
$xfer_r_aff    = $xfer_rule( array( 'scope' => 'affiliate', 'scope_id' => 777, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '20' ) );
TierService::create( array( 'scope' => 'global', 'scope_id' => 0, 'basis' => 'revenue', 'mode' => 'marginal', 'steps' => array( array( 'threshold' => 100000, 'rate' => '1' ), array( 'threshold' => 500000, 'rate' => '2' ) ), 'note' => 'Umsatz' ) );
TierService::create( array( 'scope' => 'group', 'scope_id' => $xfer_g1, 'basis' => 'orders', 'mode' => 'retroactive', 'steps' => array( array( 'threshold' => 10, 'rate' => '1,5' ) ) ) );
TierService::create( array( 'scope' => 'affiliate', 'scope_id' => 777, 'basis' => 'orders', 'mode' => 'marginal', 'steps' => array( array( 'threshold' => 5, 'rate' => '1' ) ) ) );
Notifications::save_template( 'application_approved', 'Willkommen an Bord, {name}', "Hallo {name},\nschön, dass du dabei bist." );
Notifications::save_template( 'application_approved', 'Welcome aboard, {name}', 'Hello {name}, welcome.', 'en_US' );
Notifications::set_layout( 'html' );
Settings::update( array( 'monitor_token' => 'h1:geheim-token-xfer-0123456789' ) );

$xfer_plain = SettingsTransfer::export();
$xfer_ex    = SettingsTransfer::export( false, array( 'groups', 'rules', 'tiers', 'mail_templates', 'unknown' ) );
$xfer_json  = (string) wp_json_encode( $xfer_ex );
$xfer_file  = json_decode( $xfer_json, true );
$xfer_rules = $xfer_ex['sections']['rules']['items'];
check(
	'SET-AC-08a export: sections only on request, format stays 1 (older versions still read the settings), sections_format 1',
	array( false, 1, 1, array( 'groups', 'rules', 'tiers', 'mail_templates' ) ),
	array( isset( $xfer_plain['sections'] ), $xfer_ex['format'], $xfer_ex['sections_format'], array_keys( $xfer_ex['sections'] ) )
);
check(
	'SET-AC-08a export: rules for all partners and groups with group names and portable references, partner rules left out (counted)',
	array( 4, 1, array( 'slug:kurse' ), array( 'slug:segelkurs', 'sku:SUP-1' ), 'Trainer', 'EUR', array( 'KURS-*' ) ),
	array( count( $xfer_rules ), $xfer_ex['sections']['rules']['partner_rules'], $xfer_rules[1]['target_refs'], $xfer_rules[2]['target_refs'], $xfer_rules[1]['group'], $xfer_ex['sections']['rules']['currency'], $xfer_rules[3]['target_refs'] )
);
check(
	'SET-AC-08a export: groups with terms page as slug and fields; tier plans in effect without partner plans; templates per language with layout',
	array( 'agb-trainer', 2, 2, array( 'de_DE', 'en_US' ), 'html', array( 'global', 'group' ) ),
	array( $xfer_ex['sections']['groups']['items'][1]['terms_page'], count( $xfer_ex['sections']['groups']['items'][1]['fields'] ), count( $xfer_ex['sections']['tiers']['items'] ), array_column( $xfer_ex['sections']['mail_templates']['items'], 'locale' ), $xfer_ex['sections']['mail_templates']['layout'], array_column( $xfer_ex['sections']['tiers']['items'], 'scope' ) )
);
check( 'SET-AC-08a export: no secret value and no database IDs of groups in the file', array( false, false ), array( false !== strpos( $xfer_json, 'geheim-token-xfer' ), false !== strpos( $xfer_json, '"scope_id"' ) ) );

$xfer_same = SettingsTransfer::preview( $xfer_file );
$xfer_cnt  = array();
foreach ( $xfer_same['sections'] as $xfer_s => $xfer_d ) {
	$xfer_cnt[ $xfer_s ] = $xfer_d['counts'];
}
$xfer_zero = static function ( $n ) {
	return array( 'new' => 0, 'changed' => 0, 'unchanged' => $n, 'error' => 0 );
};
check(
	'SET-AC-08a preview on the same site: every item unchanged (keys, not IDs), nothing to apply',
	array( array( 'groups' => $xfer_zero( 2 ), 'rules' => $xfer_zero( 4 ), 'tiers' => $xfer_zero( 2 ), 'mail_templates' => $xfer_zero( 3 ) ), false ),
	array( $xfer_cnt, SettingsSections::has_changes( $xfer_same['sections'] ) )
);

/* ---------- Target site: empty, other IDs ---------- */
$xfer_wipe();
Notifications::set_layout( 'text' );
$wpdb->query( 'ALTER TABLE ' . t( 'partner_groups' ) . ' AUTO_INCREMENT = 5000' );
$xfer_keep      = Groups::create( 'Bestand', 'stays' );
$xfer_keep_rule = $xfer_rule( array( 'scope' => 'global', 'target' => 'sku', 'target_value' => 'ALT', 'rate_type' => 'percent', 'rate' => '3', 'note' => 'stays' ) );
$xfer_aff_rule  = $xfer_rule( array( 'scope' => 'affiliate', 'scope_id' => 888, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '15' ) );
$xfer_partners  = $xfer_count( 'affiliates' );
$xfer_comms     = $xfer_count( 'commissions' );
$xfer_new       = SettingsTransfer::preview( $xfer_file );
$xfer_st        = array();
foreach ( $xfer_new['sections'] as $xfer_s => $xfer_d ) {
	$xfer_st[ $xfer_s ] = array_column( $xfer_d['items'], 'status' );
}
check(
	'SET-AC-08a preview on another site: all items new, rules of the new groups wait for the group, nothing written yet',
	array( array( 'new', 'new' ), array( 'new', 'new', 'new', 'new' ), array( 'new', 'new' ), array( 'changed', 'new', 'new' ), 1, 0 ),
	array( $xfer_st['groups'], $xfer_st['rules'], $xfer_st['tiers'], $xfer_st['mail_templates'], $xfer_count( 'partner_groups' ), $xfer_count( 'tiers' ) )
);
SettingsTransfer::apply( $xfer_new, 'settings.imported', array( 'source' => 'test' ) );
$xfer_rep  = SettingsSections::last_report();
$xfer_tr   = Groups::find( (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'partner_groups' ) . " WHERE name = 'Trainer'" ) );
$xfer_cat  = $wpdb->get_row( 'SELECT * FROM ' . t( 'rules' ) . " WHERE target = 'category'" );
$xfer_prod = $wpdb->get_row( 'SELECT * FROM ' . t( 'rules' ) . " WHERE target = 'product'" );
check(
	'SET-AC-08a import: groups created with terms page (by slug), fields and visibility; rules point to the new group ID and the local product/category IDs',
	array( 91801, 1, 2, (int) $xfer_tr->id, '31', '91802,91803', '500', 5000, '2026-12-01' ),
	array( (int) $xfer_tr->terms_page_id, (int) $xfer_tr->public, count( Groups::fields( $xfer_tr ) ), (int) $xfer_cat->scope_id, (string) $xfer_cat->target_value, (string) $xfer_prod->target_value, (string) $xfer_prod->rate, (int) $xfer_cat->max_amount_minor, (string) $xfer_prod->valid_from )
);
check(
	'SET-AC-08a import: report per section, templates per language and layout, tier plans created',
	array( 2, 4, 2, 3, array( 'Welcome aboard, {name}', 'Hello {name}, welcome.' ), true, 2 ),
	array( $xfer_rep['groups']['created'], $xfer_rep['rules']['created'], $xfer_rep['tiers']['created'], $xfer_rep['mail_templates']['created'] + $xfer_rep['mail_templates']['updated'], Notifications::stored_template( 'application_approved', 'en_US' ), Notifications::html_layout(), $xfer_count( 'tiers', 'active = 1' ) )
);
check(
	'SET-AC-08a import never deletes and never touches partners or commissions: own group, own rule and partner rule stay',
	array( true, true, true, $xfer_partners, $xfer_comms ),
	array( null !== Groups::find( $xfer_keep ), null !== RuleRepository::find( $xfer_keep_rule ), null !== RuleRepository::find( $xfer_aff_rule ), $xfer_count( 'affiliates' ), $xfer_count( 'commissions' ) )
);
$xfer_audit = $wpdb->get_var( 'SELECT details FROM ' . t( 'audit' ) . " WHERE action = 'settings.sections_imported' ORDER BY id DESC LIMIT 1" );
check( 'SET-AC-08a import: one audit entry with the counts per section (plus the entries of each rule, group and plan)', array( true, true, true ), array( false !== strpos( (string) $xfer_audit, '"rules"' ), false !== strpos( (string) $xfer_audit, '"created":4' ), $xfer_count( 'audit', "action = 'rule.created'" ) > 0 ) );
$xfer_again = SettingsTransfer::preview( $xfer_file );
$xfer_ch    = 0;
foreach ( $xfer_again['sections'] as $xfer_d ) {
	$xfer_ch += $xfer_d['counts']['new'] + $xfer_d['counts']['changed'] + $xfer_d['counts']['error'];
}
check( 'SET-AC-08a second import of the same file: everything unchanged (idempotent)', 0, $xfer_ch );

/* ---------- Changes on the target, partial import ---------- */
RuleRepository::save( array_merge( (array) RuleRepository::find( (int) $xfer_cat->id ), array( 'rate' => '15' ) ), (int) $xfer_cat->id, 'local' );
Notifications::save_template( 'application_approved', 'Lokal', 'Lokaler Text', 'en_US' );
Groups::update( (int) $xfer_tr->id, array( 'name' => 'Trainer', 'description' => 'lokal', 'terms_page_id' => 91801, 'agreement_version' => '2', 'public' => 1, 'fields' => "schule|Name der Schule|text|required\nweb|Website|url" ) );
$xfer_plan_before = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'tiers' ) . " WHERE scope = 'global' AND active = 1" );
$wpdb->update( t( 'tiers' ), array( 'steps' => wp_json_encode( array( array( 'threshold' => 100000, 'rate' => '3' ) ) ) ), array( 'id' => $xfer_plan_before ) );
$xfer_chg   = SettingsTransfer::preview( $xfer_file );
$xfer_items = array();
foreach ( $xfer_chg['sections'] as $xfer_s => $xfer_d ) {
	foreach ( $xfer_d['items'] as $xfer_i ) {
		if ( 'changed' === $xfer_i['status'] ) {
			$xfer_items[ $xfer_s ][] = array_keys( $xfer_i['changes'] );
		}
	}
}
check(
	'SET-AC-08a preview names the changed fields per item (rule rate, group description, template subject/body, tier steps)',
	array( 'groups' => array( array( 'description' ) ), 'rules' => array( array( 'rate' ) ), 'tiers' => array( array( 'steps' ) ), 'mail_templates' => array( array( 'subject', 'body' ) ) ),
	$xfer_items
);
$xfer_chg['sections_selected'] = array( 'rules', 'tiers' );
SettingsTransfer::apply( $xfer_chg, 'settings.imported' );
check(
	'SET-AC-08a only the chosen sections are imported: rule back to 12.5 %, tier plan replaced (old one deactivated and kept), template and group unchanged',
	array( '12.5', 0, 1, array( 'Lokal', 'Lokaler Text' ), 'lokal', array( 'rules', 'tiers' ) ),
	array( (string) RuleRepository::find( (int) $xfer_cat->id )->rate, (int) $wpdb->get_var( 'SELECT active FROM ' . t( 'tiers' ) . ' WHERE id = ' . $xfer_plan_before ), $xfer_count( 'tiers', "scope = 'global' AND active = 1" ), Notifications::stored_template( 'application_approved', 'en_US' ), (string) Groups::find( (int) $xfer_tr->id )->description, array_keys( SettingsSections::last_report() ) )
);

/* ---------- Rejected items ---------- */
$xfer_bad = SettingsSections::preview(
	array(
		'rules'          => array(
			'currency' => 'CHF',
			'items'    => array(
				array( 'scope' => 'group', 'group' => 'Unbekannt', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '5' ),
				array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'fixed', 'rate' => '500' ),
				array( 'scope' => 'affiliate', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '5' ),
				array( 'scope' => 'global', 'target' => 'category', 'target_refs' => array( 'slug:gibt-es-nicht' ), 'rate_type' => 'percent', 'rate' => '5' ),
				array( 'scope' => 'global', 'target' => 'all', 'rate_type' => 'percent', 'rate' => '150' ),
			),
		),
		'tiers'          => array(
			'currency' => 'CHF',
			'items'    => array( array( 'scope' => 'global', 'basis' => 'revenue', 'mode' => 'marginal', 'steps' => array( array( 'threshold' => 1, 'rate' => '1' ) ) ) ),
		),
		'mail_templates' => array(
			'items' => array(
				array( 'key' => 'no_such_mail', 'locale' => 'de_DE', 'subject' => 'x', 'body' => 'y' ),
				array( 'key' => 'application_approved', 'locale' => 'fr_FR', 'subject' => 'Bienvenue', 'body' => 'Bonjour' ),
			),
		),
		'payments'       => array( 'items' => array() ),
	),
	'EUR'
);
$xfer_msgs = array();
foreach ( $xfer_bad['sections'] as $xfer_s => $xfer_d ) {
	foreach ( $xfer_d['items'] as $xfer_i ) {
		$xfer_msgs[] = $xfer_i['status'] . ':' . $xfer_i['message'];
	}
}
check(
	'SET-AC-08a rejected items with reason: unknown group, other currency for amounts, partner rule, unknown category, invalid rate, unknown template, language not installed; unknown section ignored',
	array( 8, 8, true, true, true, true, array( 'sections:payments' ) ),
	array(
		count( $xfer_msgs ),
		count( preg_grep( '/^error:./', $xfer_msgs ) ),
		(bool) preg_grep( '/Unbekannt.*not found/', $xfer_msgs ),
		2 === count( preg_grep( '/CHF.*EUR/', $xfer_msgs ) ),
		(bool) preg_grep( '/slug:gibt-es-nicht/', $xfer_msgs ),
		(bool) preg_grep( '/not installed/', $xfer_msgs ),
		$xfer_bad['ignored'],
	)
);
$xfer_old = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array( 'hold_days' => (int) Settings::get( 'hold_days' ) ) ) );
$xfer_fut = SettingsTransfer::preview( array( 'format' => 1, 'settings' => array(), 'sections_format' => 9, 'sections' => array( 'rules' => array( 'items' => array() ) ) ) );
check( 'SET-AC-08a older files without sections import as before; a newer section format is skipped with a note', array( array(), array(), true, array() ), array( $xfer_old['sections'], $xfer_old['sections_data'], in_array( 'sections', $xfer_fut['ignored'], true ), $xfer_fut['sections'] ) );
check( 'SET-AC-08a section names: "all", aliases and unknown names', array( array( 'groups', 'rules', 'tiers', 'mail_templates' ), array( 'rules', 'mail_templates' ) ), array( SettingsSections::clean( 'all' ), SettingsSections::clean( 'templates, rules,foo' ) ) );
$xfer_cli = (string) file_get_contents( WPAC_DIR . 'src/Cli/SettingsCommand.php' );
check( 'SET-AC-08a WP-CLI: export and import take --sections', 2, substr_count( $xfer_cli, '[--sections=<list>]' ) );

/* ---------- Restore ---------- */
$xfer_wipe();
foreach ( $xfer_backup as $xfer_t => $xfer_rows ) {
	foreach ( $xfer_rows as $xfer_row ) {
		$wpdb->insert( t( $xfer_t ), $xfer_row );
	}
}
if ( false !== $xfer_tpl_backup && null !== $xfer_tpl_backup ) {
	update_option( Notifications::TEMPLATES_OPTION, $xfer_tpl_backup );
}
foreach ( $xfer_tables as $xfer_t ) {
	$wpdb->query( 'ALTER TABLE ' . t( $xfer_t ) . ' AUTO_INCREMENT = 1' );
}
Settings::update( array( 'currency' => $xfer_currency, 'monitor_token' => $xfer_token ) );
unset( $GLOBALS['test_posts'][91801], $GLOBALS['test_posts'][91802], $GLOBALS['test_posts'][91803] );
delete_post_meta( 91803, '_sku' );
