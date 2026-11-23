<?php
/**
 * SET-AC-22: the rule form has the fields for cap, minimum line amount and validity; the rule
 * list shows the conditions; the rule preview takes a payment date and shows the cap (reached or
 * not) and skips a rule outside its validity. Included from run.php after the render files
 * (uses r_render() of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\RulesPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Settings;

echo "SET-AC-22 rules: form, list and preview\n";

$s22r_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $s22r_keep, array( 'currency' => 'EUR' ) ) );
Settings::flush_cache();
$s22r_aff  = (int) AffiliateRepository::create( array( 'name' => 'S22R Vorschau', 'email' => 's22r@example.de', 'status' => 'active' ) );
$s22r_rule = RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $s22r_aff, 'target' => 'all', 'rate_type' => 'percent', 'rate' => '20', 'active' => 1, 'max_amount' => '30', 'min_base' => '50', 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-31' ) ), 0, 'test' );

$_GET  = array( 'page' => 'wpac-rules', 'edit' => (string) $s22r_rule );
$s22r  = r_render( array( RulesPage::class, 'render' ) );
check(
	'SET-AC-22 rule form: labelled fields for cap, minimum line amount and validity with the stored values; list shows the conditions',
	array( true, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $s22r[0], 'for="wpac-rule-max"' ) && false !== strpos( $s22r[0], 'name="max_amount" id="wpac-rule-max" value="30' ),
		false !== strpos( $s22r[0], 'for="wpac-rule-min"' ) && false !== strpos( $s22r[0], 'name="min_base" id="wpac-rule-min" value="50' ),
		false !== strpos( $s22r[0], 'name="valid_from" id="wpac-rule-from" value="2026-12-01"' ) && false !== strpos( $s22r[0], 'name="valid_until" id="wpac-rule-until" value="2026-12-31"' ),
		false !== strpos( $s22r[0], 'aria-describedby="wpac-rule-valid-hint"' ) && false !== strpos( $s22r[0], 'id="wpac-rule-valid-hint"' ),
		false !== strpos( $s22r[0], 'max. ' ) && false !== strpos( $s22r[0], 'per line' ) && false !== strpos( $s22r[0], 'from a line amount of' ),
		$s22r[1],
		$s22r[2],
	)
);

$s22r_preview = static function ( $net, $day ) use ( $s22r_aff ) {
	$_GET = array( 'page' => 'wpac-rules', 'tab' => 'preview', 'preview' => '1', 'p_affiliate' => (string) $s22r_aff, 'p_product' => '922002', 'p_net' => $net, 'p_date' => $day );
	return r_render( array( RulesPage::class, 'render' ) );
};
$s22r_p1 = $s22r_preview( '1000', '2026-12-15' ); // 20 % of 1000 = 200, capped at 30.
$s22r_p2 = $s22r_preview( '100', '2026-12-15' );  // 20, cap not reached.
$s22r_p3 = $s22r_preview( '1000', '2027-01-01' ); // Outside the validity: not this rule.
$s22r_p4 = $s22r_preview( '40', '2026-12-15' );   // Below the minimum: not this rule.
check(
	'SET-AC-22 rule preview: payment date field; shows the cap (reached with the amount without it / not reached); outside the validity or below the minimum the rule is not used',
	array( true, true, true, true, true, true, array(), '' ),
	array(
		false !== strpos( $s22r_p1[0], 'name="p_date"' ) && false !== strpos( $s22r_p1[0], 'value="2026-12-15"' ),
		false !== strpos( $s22r_p1[0], 'rule #' . $s22r_rule ) && false !== strpos( $s22r_p1[0], 'Capped at' ) && false !== strpos( $s22r_p1[0], '(without the cap:' ),
		false !== strpos( $s22r_p2[0], 'rule #' . $s22r_rule ) && false !== strpos( $s22r_p2[0], 'Cap of the rule:' ) && false !== strpos( $s22r_p2[0], 'not reached' ),
		false !== strpos( $s22r_p1[0], 'Conditions of the rule:' ),
		false === strpos( $s22r_p3[0], 'rule #' . $s22r_rule . ')' ) && false === strpos( $s22r_p3[0], 'Capped at' ),
		false === strpos( $s22r_p4[0], 'rule #' . $s22r_rule . ')' ),
		array_merge( $s22r_p1[1], $s22r_p2[1], $s22r_p3[1], $s22r_p4[1] ),
		$s22r_p1[2] . $s22r_p2[2] . $s22r_p3[2] . $s22r_p4[2],
	)
);

RuleRepository::delete( $s22r_rule, 'test' );
$_GET = array();
update_option( 'wpac_settings', $s22r_keep );
Settings::flush_cache();
