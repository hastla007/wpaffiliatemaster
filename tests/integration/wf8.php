<?php
/**
 * AFF-WF-08 (plugin 0.17.0): what the core guarantees to a consumer of its actions (adapter in an
 * add-on, envelope built by the consumer). No workflow symbols in the core; instead:
 *  - the "since" table of docs/CONTRACT.md names every action the code fires and nothing else
 *    (with contract and plugin version and whether it fires after the COMMIT);
 *  - every action marked "after the COMMIT: ja" fires with exactly the documented parameters (count,
 *    order, types), outside a transaction, and not at all after a rollback;
 *  - a throwing callback leaves the booking in place;
 *  - the core never asks whether anybody listens to such an action, and behaves the same with and
 *    without a callback.
 * Included from run.php before r8-render.php (helpers check(), t(), hook_fired_outside_tx()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Promotion\Creatives;

echo "WF8 hook contract for consumers: since table, parameters, after COMMIT (AFF-WF-08)\n";
global $wpdb;

$wf8_root = dirname( __DIR__, 2 );

/* ---------------------------------------------------------------------------------------------
 * The "since" table of docs/CONTRACT.md.
 * ------------------------------------------------------------------------------------------- */
$wf8_doc   = (string) file_get_contents( $wf8_root . '/docs/CONTRACT.md' );
$wf8_table = array();
$wf8_dupes = array();
$wf8_bad   = array();
$wf8_in    = false;
foreach ( preg_split( '/\R/', $wf8_doc ) as $wf8_line ) {
	if ( 0 === strpos( $wf8_line, '## ' ) ) {
		$wf8_in = 0 === strpos( $wf8_line, '## Actions und seit wann es sie gibt' );
		continue;
	}
	if ( ! $wf8_in || 0 !== strpos( $wf8_line, '| `' ) ) {
		continue;
	}
	if ( ! preg_match( '/^\| `([^`]+)` \| `([^`]*)` \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$/u', $wf8_line, $wf8_m ) ) {
		$wf8_bad[] = $wf8_line;
		continue;
	}
	if ( isset( $wf8_table[ $wf8_m[1] ] ) ) {
		$wf8_dupes[] = $wf8_m[1];
	}
	$wf8_table[ $wf8_m[1] ] = array(
		'params'   => '' === $wf8_m[2] ? array() : array_map( 'trim', explode( ',', $wf8_m[2] ) ),
		'contract' => trim( $wf8_m[3] ),
		'plugin'   => trim( $wf8_m[4] ),
		'commit'   => trim( $wf8_m[5] ),
	);
}
$wf8_ja = array_keys( array_filter( $wf8_table, static function ( $r ) { return 'ja' === $r['commit']; } ) );
check( 'WF8 since table found, every row readable, no action twice', array( true, array(), array() ), array( count( $wf8_table ) > 20, $wf8_bad, $wf8_dupes ) );

// Versions: contract SemVer up to the current contract (P-1), plugin version up to the shipped one.
preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', (string) file_get_contents( $wf8_root . '/affiliate-captain.php' ), $wf8_pv );
$wf8_plugin_version = isset( $wf8_pv[1] ) ? $wf8_pv[1] : '0';
$wf8_versions       = array();
foreach ( $wf8_table as $wf8_name => $wf8_row ) {
	$wf8_c = preg_match( '/^(\d+\.\d+\.\d+)\b/', $wf8_row['contract'], $wf8_cm ) ? $wf8_cm[1] : '';
	$wf8_p = preg_match( '/^(\d+\.\d+\.\d+)$/', $wf8_row['plugin'] ) ? $wf8_row['plugin'] : '';
	if ( '' === $wf8_c || version_compare( $wf8_c, WPAC_CONTRACT_VERSION, '>' ) || '' === $wf8_p || version_compare( $wf8_p, $wf8_plugin_version, '>' ) || ! in_array( $wf8_row['commit'], array( 'ja', 'nicht zugesagt', '–' ), true ) ) {
		$wf8_versions[] = $wf8_name;
	}
}
check( 'WF8 P-1 WPAC_CONTRACT_VERSION is SemVer', 1, preg_match( '/^\d+\.\d+\.\d+$/', WPAC_CONTRACT_VERSION ) );
check( 'WF8 since table: contract version (SemVer, at most the current), plugin version (at most the shipped one) and "after the COMMIT" (ja / nicht zugesagt / –) in every row', array(), $wf8_versions );

/* ---------------------------------------------------------------------------------------------
 * P-2 (R-3.4, R-3.5): the table names every action the shipped code fires and no other.
 * ------------------------------------------------------------------------------------------- */
$wf8_files = array( $wf8_root . '/affiliate-captain.php', $wf8_root . '/uninstall.php' );
foreach ( array( 'src', 'blocks', 'tools' ) as $wf8_dir ) {
	if ( ! is_dir( $wf8_root . '/' . $wf8_dir ) ) {
		continue;
	}
	$wf8_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wf8_root . '/' . $wf8_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $wf8_it as $wf8_f ) {
		if ( 'php' === $wf8_f->getExtension() ) {
			$wf8_files[] = $wf8_f->getPathname();
		}
	}
}
// Code without comments (doc blocks quote do_action() calls of other plugins and of sources).
$wf8_code = static function ( $file ) {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
		if ( is_array( $tok ) ) {
			if ( in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= $tok[1];
		} else {
			$out .= $tok;
		}
	}
	return $out;
};
// Dynamic names: the status actions of Ledger::transition() and the add-on tabs of the portal.
$wf8_dynamic = array(
	'wpac_commission_' => array_map( static function ( $s ) { return 'wpac_commission_' . $s; }, array_keys( Ledger::TRANSITIONS ) ),
	'wpac_portal_tab_' => array( 'wpac_portal_tab_{key}' ),
);
$wf8_fired_in_code = array();
$wf8_unknown_dyn   = array();
$wf8_not_literal   = array();
$wf8_asks          = array();
$wf8_sources       = array();
foreach ( $wf8_files as $wf8_file ) {
	$wf8_src                   = $wf8_code( $wf8_file );
	$wf8_sources[ $wf8_file ] = $wf8_src;
	// First argument up to the first space, comma or bracket ('wpac_commission_' . $to gives the literal).
	preg_match_all( '/\bdo_action(?:_ref_array|_deprecated)?\s*\(\s*([^\s,)]+)/', $wf8_src, $wf8_firsts );
	foreach ( $wf8_firsts[1] as $wf8_first ) {
		if ( ! preg_match( '/^([\'"])wpac_[A-Za-z0-9_]*\1$/', $wf8_first ) ) {
			$wf8_not_literal[] = basename( $wf8_file ) . ': ' . $wf8_first;
		}
	}
	preg_match_all( '/\bdo_action(?:_ref_array|_deprecated)?\s*\(\s*([\'"])(wpac_[A-Za-z0-9_]*)\1\s*([.,)])/', $wf8_src, $wf8_named, PREG_SET_ORDER );
	foreach ( $wf8_named as $wf8_n ) {
		if ( '.' === $wf8_n[3] ) {
			if ( isset( $wf8_dynamic[ $wf8_n[2] ] ) ) {
				foreach ( $wf8_dynamic[ $wf8_n[2] ] as $wf8_x ) {
					$wf8_fired_in_code[ $wf8_x ] = true;
				}
			} else {
				$wf8_unknown_dyn[] = basename( $wf8_file ) . ': ' . $wf8_n[2] . '…';
			}
		} else {
			$wf8_fired_in_code[ $wf8_n[2] ] = true;
		}
	}
}
$wf8_code_names  = array_keys( $wf8_fired_in_code );
sort( $wf8_code_names );
$wf8_doc_names = array_keys( $wf8_table );
sort( $wf8_doc_names );
check( 'WF8 every do_action() of the core names its action literally (or a known dynamic prefix)', array( array(), array() ), array( $wf8_not_literal, $wf8_unknown_dyn ) );
check( 'WF8 P-2 the since table names every action of the code', array(), array_values( array_diff( $wf8_code_names, $wf8_doc_names ) ) );
check( 'WF8 P-2 the since table names no action the code does not fire', array(), array_values( array_diff( $wf8_doc_names, $wf8_code_names ) ) );
check( 'WF8 the actions after the COMMIT include the domain events of the hook contract', array(), array_values( array_diff( array( 'wpac_affiliate_created', 'wpac_affiliate_status_changed', 'wpac_affiliate_recruited', 'wpac_affiliate_erased', 'wpac_affiliate_attributed', 'wpac_commission_created', 'wpac_commission_adjusted', 'wpac_commission_approved', 'wpac_commission_rejected', 'wpac_commission_paid', 'wpac_commission_pending', 'wpac_commission_review', 'wpac_payout_created', 'wpac_payout_paid', 'wpac_payout_cancelled', 'wpac_correction_generated', 'wpac_creative_created', 'wpac_creative_updated', 'wpac_code_requested', 'wpac_code_request_approved', 'wpac_code_request_rejected' ), $wf8_ja ) ) );

/* P-7 (static): the core never asks whether somebody listens to an action after the COMMIT. ---- */
foreach ( $wf8_sources as $wf8_file => $wf8_src ) {
	preg_match_all( '/\b(has_action|did_action|has_filter|did_filter|doing_action)\s*\(\s*([\'"])(wpac_[A-Za-z0-9_]*)\2/', $wf8_src, $wf8_q, PREG_SET_ORDER );
	foreach ( $wf8_q as $wf8_m ) {
		foreach ( $wf8_ja as $wf8_name ) {
			if ( $wf8_m[3] === $wf8_name || 0 === strpos( $wf8_name, $wf8_m[3] ) ) {
				$wf8_asks[] = basename( $wf8_file ) . ': ' . $wf8_m[1] . '( ' . $wf8_m[3] . ' )';
			}
		}
	}
}
check( 'WF8 P-7 no has_action()/did_action() on an action after the COMMIT anywhere in the shipped code', array(), array_values( array_unique( $wf8_asks ) ) );

/* ---------------------------------------------------------------------------------------------
 * P-4: every action marked "ja" fires with the documented parameters, in that order, with those
 * types, and outside a transaction. One run through the flows that fire them.
 * ------------------------------------------------------------------------------------------- */
$wf8_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $wf8_keep, array( 'min_payout_minor' => 5000, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop GmbH', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );
$GLOBALS['test_user'] = 1;

$wf8_partner = static function ( $slug, array $extra = array() ) use ( $wpdb ) {
	$id = (int) AffiliateRepository::create( array_merge( array( 'name' => 'WF8 Erika ' . $slug, 'email' => 'wf8-' . $slug . '@example.de', 'status' => 'active' ), $extra ) );
	ProfileRepository::save( $id, array( 'legal_name' => 'WF8 Erika ' . $slug, 'address' => "Seestraße 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'WF8 Erika ' . $slug, 'iban' => 'DE89370400440532013000', 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	if ( ! CodeRepository::main_code( $id ) ) {
		CodeRepository::create( $id, CodeRepository::random_code() );
	}
	return $id;
};
$wf8_sale = static function ( $aff, $order, $net ) {
	return wpac_record_sale( array( 'source' => 'wf8', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'wf8-kaeufer@example.de', 'name' => 'Käufer Kunz' ) ) );
};
$wf8_cid = static function ( $r ) {
	return is_array( $r ) && ! empty( $r['commission_ids'] ) ? (int) $r['commission_ids'][0] : 0;
};
// Does a value match a documented PHP type of the since table?
$wf8_type_ok = static function ( $type, $value ) {
	switch ( $type ) {
		case 'int':
			return is_int( $value );
		case 'string':
			return is_string( $value );
		case 'bool':
			return is_bool( $value );
		case 'array':
			return is_array( $value );
		case 'object':
			return is_object( $value );
		case 'int[]':
			return is_array( $value ) && count( array_filter( $value, 'is_int' ) ) === count( $value );
		case 'string[]':
			return is_array( $value ) && count( array_filter( $value, 'is_string' ) ) === count( $value );
	}
	return is_object( $value ) && is_a( $value, $type );
};

$GLOBALS['test_fired'] = array();
$wf8_a = $wf8_partner( 'a' );                                              // wpac_affiliate_created
$wf8_r = $wf8_partner( 'rec', array( 'referred_by' => $wf8_a ) );          // wpac_affiliate_recruited
AffiliateRepository::set_status( $wf8_r, 'paused', 'Prüfung' );            // wpac_affiliate_status_changed
AffiliateRepository::set_status( $wf8_r, 'active' );
$wf8_c1 = $wf8_cid( $wf8_sale( $wf8_a, 'wf8-1', 100000 ) );                // wpac_affiliate_attributed, wpac_commission_created
$wf8_c2 = $wf8_cid( $wf8_sale( $wf8_a, 'wf8-2', 20000 ) );
$wf8_c3 = $wf8_cid( $wf8_sale( $wf8_a, 'wf8-3', 20000 ) );
Ledger::transition( $wf8_c1, 'approved' );                                 // wpac_commission_approved
Ledger::transition( $wf8_c2, 'rejected', 'Storniert' );                    // wpac_commission_rejected
Ledger::transition( $wf8_c2, 'review' );                                   // wpac_commission_review
Ledger::transition( $wf8_c2, 'pending' );                                  // wpac_commission_pending
wpac_record_refund( array( 'source' => 'wf8', 'order_id' => 'wf8-3', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), 'reason' => 'Teilerstattung' ) ); // wpac_commission_adjusted
$wf8_p1 = PayoutService::create_for_affiliate( $wf8_a, gmdate( 'Y-m-d' ), 'EUR' ); // wpac_payout_created
PayoutService::mark_paid( $wf8_p1 );                                       // wpac_payout_paid, wpac_commission_paid
$wf8_k = Corrections::create( $wf8_p1, 12000, 'Provisionssatz korrigiert', 'wf8-korr', 10000 ); // wpac_correction_generated
wpac_record_refund( array( 'source' => 'wf8', 'order_id' => 'wf8-1', 'refund_id' => 'r2', 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ), 'reason' => 'Teilerstattung nach Korrektur' ) ); // wpac_refund_after_correction
$wf8_c4 = $wf8_cid( $wf8_sale( $wf8_a, 'wf8-4', 100000 ) );
Ledger::transition( $wf8_c4, 'approved' );
$wf8_p2 = PayoutService::create_for_affiliate( $wf8_a, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::cancel( $wf8_p2, 'Test' );                                  // wpac_payout_cancelled
$wf8_cr = Creatives::save_draft( array( 'title' => 'WF8 Banner', 'type' => 'text', 'content' => 'Jetzt buchen: {link}', 'attachment_id' => 0, 'landing_url' => '', 'disclosure' => '', 'group_id' => 0 ), 0, 'neu' ); // wpac_creative_created
Creatives::approve( $wf8_cr );                                             // wpac_creative_updated
$wf8_b  = $wf8_partner( 'b' );
$wf8_q1 = CodeRequests::request( AffiliateRepository::find( $wf8_b ), 'wf8wunsch' ); // wpac_code_requested
CodeRequests::approve( (int) $wf8_q1 );                                    // wpac_code_request_approved
$wf8_q2 = CodeRequests::request( AffiliateRepository::find( $wf8_b ), 'wf8zwei' );
CodeRequests::reject( (int) $wf8_q2, 'Name einer Person' );                // wpac_code_request_rejected
$wf8_e = $wf8_partner( 'erase' );
Privacy::erase_partner( AffiliateRepository::find( $wf8_e ) );            // wpac_affiliate_erased

$wf8_missing  = array();
$wf8_mismatch = array();
$wf8_inside   = array();
foreach ( $wf8_ja as $wf8_name ) {
	$wf8_calls = array_values( array_filter( $GLOBALS['test_fired'], static function ( $f ) use ( $wf8_name ) { return $f[0] === $wf8_name; } ) );
	if ( ! $wf8_calls ) {
		$wf8_missing[] = $wf8_name;
		continue;
	}
	$wf8_types = array_map( static function ( $p ) { return strtok( $p, ' ' ); }, $wf8_table[ $wf8_name ]['params'] );
	foreach ( $wf8_calls as $wf8_call ) {
		$wf8_ok = count( $wf8_call[1] ) === count( $wf8_types );
		foreach ( $wf8_types as $wf8_i => $wf8_type ) {
			$wf8_ok = $wf8_ok && array_key_exists( $wf8_i, $wf8_call[1] ) && $wf8_type_ok( $wf8_type, $wf8_call[1][ $wf8_i ] );
		}
		if ( ! $wf8_ok ) {
			$wf8_mismatch[] = $wf8_name . ' ( ' . implode( ', ', array_map( static function ( $v ) { return is_object( $v ) ? get_class( $v ) : gettype( $v ); }, $wf8_call[1] ) ) . ' )';
		}
	}
	if ( ! hook_fired_outside_tx( $wf8_name ) ) {
		$wf8_inside[] = $wf8_name;
	}
}
check( 'WF8 P-4 every action after the COMMIT fired in the flows (' . count( $wf8_ja ) . ' actions)', array(), $wf8_missing );
check( 'WF8 P-4 every call has exactly the documented parameters, in order, with the documented types', array(), array_values( array_unique( $wf8_mismatch ) ) );
check( 'WF8 every action after the COMMIT fired outside a transaction', array(), $wf8_inside );
// Values in the documented order, for the ones whose arguments are known here.
$wf8_args = static function ( $tag ) {
	$out = array();
	foreach ( $GLOBALS['test_fired'] as $f ) {
		if ( $f[0] === $tag ) {
			$out[] = $f[1];
		}
	}
	return $out;
};
$wf8_rec = $wf8_args( 'wpac_affiliate_recruited' );
$wf8_ap  = $wf8_args( 'wpac_code_request_approved' );
$wf8_rv  = $wf8_args( 'wpac_commission_review' );
$wf8_pe  = $wf8_args( 'wpac_commission_pending' );
check(
	'WF8 P-4 argument order: recruited( partner, recruiter ), code_request_approved( partner, code, row, main ), review( id ), pending( id )',
	array( array( $wf8_r, $wf8_a ), array( $wf8_b, 'wf8wunsch', (int) $wf8_q1 ), array( array( $wf8_c2 ) ), array( array( $wf8_c2 ) ) ),
	array( $wf8_rec ? $wf8_rec[0] : array(), $wf8_ap ? array( $wf8_ap[0][0], strtolower( (string) $wf8_ap[0][1] ), $wf8_ap[0][2] ) : array(), $wf8_rv, $wf8_pe )
);

/* ---------------------------------------------------------------------------------------------
 * P-5: after a rollback none of the actions after the COMMIT fires.
 * ------------------------------------------------------------------------------------------- */
$wf8_c5 = $wf8_cid( $wf8_sale( $wf8_b, 'wf8-5', 20000 ) );
$GLOBALS['test_fired'] = array();
try {
	Db::transaction(
		static function () use ( $wf8_b, $wf8_c5 ) {
			AffiliateRepository::create( array( 'name' => 'WF8 Rollback', 'email' => 'wf8-rollback@example.de', 'status' => 'active' ) );
			AffiliateRepository::set_status( $wf8_b, 'paused', 'Prüfung' );
			Ledger::transition( $wf8_c5, 'approved' );
			wpac_create_adjustment( $wf8_b, 700, 'EUR', 'Rollback-Test' );
			Creatives::save_draft( array( 'title' => 'WF8 Rollback', 'type' => 'text', 'content' => 'x {link}', 'attachment_id' => 0, 'landing_url' => '', 'disclosure' => '', 'group_id' => 0 ), 0, 'neu' );
			throw new \RuntimeException( 'rollback' );
		}
	);
} catch ( \RuntimeException $e ) {
	unset( $e );
}
$wf8_after_rb = array_values( array_unique( array_filter( array_map( static function ( $f ) { return $f[0]; }, $GLOBALS['test_fired'] ), static function ( $t ) use ( $wf8_ja ) { return in_array( $t, $wf8_ja, true ); } ) ) );
AffiliateRepository::clear_cache( $wf8_b );
check( 'WF8 P-5 rollback (partner, status, approval, manual booking, creative): no action after the COMMIT fired, nothing kept', array( array(), 'active', true, 0 ), array( $wf8_after_rb, (string) AffiliateRepository::find( $wf8_b )->status, 'approved' !== (string) Ledger::find( $wf8_c5 )->status, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'affiliates' ) . " WHERE email = 'wf8-rollback@example.de'" ) ) );

/* ---------------------------------------------------------------------------------------------
 * P-6: a callback that throws leaves the booking in place (state checked after the error).
 * ------------------------------------------------------------------------------------------- */
$wf8_throw = static function () {
	throw new \RuntimeException( 'WF8 listener failed' );
};
foreach ( $wf8_ja as $wf8_name ) {
	add_action( $wf8_name, $wf8_throw, 99, 0 );
}
$wf8_try = static function ( callable $fn ) {
	try {
		return $fn();
	} catch ( \RuntimeException $e ) {
		return 'WF8 listener failed' === $e->getMessage() ? 'thrown' : 'other: ' . $e->getMessage();
	}
};
$wf8_s6 = $wf8_try( static function () use ( $wf8_sale, $wf8_b ) { return $wf8_sale( $wf8_b, 'wf8-6', 30000 ); } );
$wf8_s6_row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, amount_minor FROM ' . t( 'commissions' ) . " WHERE source = 'wf8' AND source_order_id = %s AND kind = 'sale'", 'wf8-6' ) );
$wf8_try( static function () use ( $wf8_c5 ) { return Ledger::transition( $wf8_c5, 'approved' ); } );
$wf8_c5_st = (string) Ledger::find( $wf8_c5 )->status;
$wf8_ma = $wf8_try( static function () use ( $wf8_b ) { return wpac_create_adjustment( $wf8_b, 6000, 'EUR', 'Bonus trotz Fehler' ); } );
$wf8_ma_n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = %d AND kind = 'adjustment' AND amount_minor = 6000", $wf8_b ) );
$wf8_p3_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) . ' WHERE affiliate_id = %d', $wf8_b ) );
$wf8_p3 = $wf8_try( static function () use ( $wf8_b ) { return PayoutService::create_for_affiliate( $wf8_b, gmdate( 'Y-m-d' ), 'EUR' ); } );
$wf8_p3_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . t( 'payouts' ) . ' WHERE affiliate_id = %d', $wf8_b ) );
$wf8_p3_new = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) . ' WHERE affiliate_id = %d', $wf8_b ) ) === $wf8_p3_before + 1;
$wf8_try( static function () use ( $wf8_p3_id ) { return PayoutService::mark_paid( $wf8_p3_id ); } );
$wf8_p3_st = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . t( 'payouts' ) . ' WHERE id = %d', $wf8_p3_id ) );
$wf8_try( static function () use ( $wf8_b ) { return AffiliateRepository::set_status( $wf8_b, 'paused', 'Prüfung' ); } );
AffiliateRepository::clear_cache( $wf8_b );
$wf8_st = (string) AffiliateRepository::find( $wf8_b )->status;
foreach ( $wf8_ja as $wf8_name ) {
	unset( $GLOBALS['test_hooks'][ $wf8_name ][99] );
}
check(
	'WF8 P-6 throwing callbacks on every action after the COMMIT: sale booked, status saved, approval saved, manual booking kept, payout created and paid',
	array( true, 'paused', 'approved', 1, true, 'paid' ),
	array( null !== $wf8_s6_row && (int) $wf8_s6_row->amount_minor > 0, $wf8_st, $wf8_c5_st, $wf8_ma_n, $wf8_p3_new, $wf8_p3_st )
);
unset( $wf8_s6, $wf8_ma, $wf8_p3 );
AffiliateRepository::set_status( $wf8_b, 'active' );

/* ---------------------------------------------------------------------------------------------
 * P-7 (runtime): the same sale books the same with and without callbacks.
 * ------------------------------------------------------------------------------------------- */
$wf8_c = $wf8_partner( 'ohne' );
$wf8_d = $wf8_partner( 'mit' );
$wf8_shape = static function ( $aff, $order ) use ( $wf8_sale, $wpdb ) {
	$GLOBALS['test_fired'] = array();
	$r    = $wf8_sale( $aff, $order, 40000 );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, status, amount_minor, currency FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = %d ORDER BY id', $aff ), ARRAY_A );
	$tags = array_map( static function ( $f ) { return $f[0]; }, $GLOBALS['test_fired'] );
	return array( is_array( $r ) ? $r['status'] : 'error', $rows, $tags );
};
$wf8_without = $wf8_shape( $wf8_c, 'wf8-7' );
$wf8_noop    = static function () {};
foreach ( $wf8_ja as $wf8_name ) {
	add_action( $wf8_name, $wf8_noop, 98, 0 );
}
$wf8_with = $wf8_shape( $wf8_d, 'wf8-8' );
foreach ( $wf8_ja as $wf8_name ) {
	unset( $GLOBALS['test_hooks'][ $wf8_name ][98] );
}
check( 'WF8 P-7 a sale books the same and fires the same actions with and without callbacks', $wf8_without, $wf8_with );

$GLOBALS['test_fired'] = array();
update_option( 'wpac_settings', $wf8_keep );
Settings::flush_cache();
