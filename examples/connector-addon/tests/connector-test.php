<?php
/**
 * Standalone checks for the generic connector starter.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPAC_CONTRACT_VERSION', '1.1.0' );

$GLOBALS['wpac_connector_test_hooks'] = array();
$GLOBALS['wpac_connector_test_sales'] = array();
$GLOBALS['wpac_connector_test_refunds'] = array();

function wpac_connector_test_register( $hook, $callback, $priority, $accepted_args ) {
	$GLOBALS['wpac_connector_test_hooks'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	wpac_connector_test_register( $hook, $callback, $priority, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	wpac_connector_test_register( $hook, $callback, $priority, $accepted_args );
}

function do_action( $hook ) {
	$args = func_get_args();
	array_shift( $args );
	$callbacks = isset( $GLOBALS['wpac_connector_test_hooks'][ $hook ] ) ? $GLOBALS['wpac_connector_test_hooks'][ $hook ] : array();
	usort(
		$callbacks,
		static function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		}
	);
	foreach ( $callbacks as $entry ) {
		call_user_func_array( $entry['callback'], array_slice( $args, 0, $entry['accepted_args'] ) );
	}
}

function apply_filters( $hook, $value ) {
	$args      = func_get_args();
	array_shift( $args );
	$callbacks = isset( $GLOBALS['wpac_connector_test_hooks'][ $hook ] ) ? $GLOBALS['wpac_connector_test_hooks'][ $hook ] : array();
	usort(
		$callbacks,
		static function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		}
	);
	foreach ( $callbacks as $entry ) {
		$filter_args = array_slice( $args, 0, max( 0, $entry['accepted_args'] - 1 ) );
		$value       = call_user_func_array( $entry['callback'], array_merge( array( $value ), $filter_args ) );
	}
	return $value;
}

function wpac_record_sale( $payload ) {
	$GLOBALS['wpac_connector_test_sales'][] = $payload;
	return array( 'status' => 'created' );
}

function wpac_record_refund( $payload ) {
	$GLOBALS['wpac_connector_test_refunds'][] = $payload;
	return array( 'status' => 'adjusted' );
}

function wpac_connector_test_check( $label, $condition ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
	echo "PASS: {$label}\n";
}

// Model WP Affiliate Captain's plugins_loaded priority 5 boot sequence.
add_action(
	'plugins_loaded',
	static function () {
		do_action( 'wpac_loaded', WPAC_CONTRACT_VERSION );
		$GLOBALS['wpac_connector_test_hooks_ready_before_core'] = ! empty( $GLOBALS['wpac_connector_test_hooks']['my_app_order_paid'] )
			&& ! empty( $GLOBALS['wpac_connector_test_hooks']['my_app_order_refunded'] );
	},
	5,
	0
);

require dirname( __DIR__ ) . '/wp-affiliate-captain-connector-example.php';
do_action( 'plugins_loaded' );

wpac_connector_test_check( 'app hooks register before the core fires wpac_loaded', ! empty( $GLOBALS['wpac_connector_test_hooks_ready_before_core'] ) );

$order = array(
	'order_id'     => 'order-4711',
	'currency'     => 'eur',
	'paid_at'      => 1790000000,
	'partner_code' => 'PARTNER-1',
	'customer'     => array( 'user_id' => 42, 'email' => 'buyer@example.com' ),
	'items'        => array( array( 'item_id' => 'line-1', 'net_minor' => 49900 ) ),
);
do_action( 'my_app_order_paid', $order );

wpac_connector_test_check( 'paid order is reported with stable source and order ID', isset( $GLOBALS['wpac_connector_test_sales'][0] ) && 'example-app' === $GLOBALS['wpac_connector_test_sales'][0]['source'] && 'order-4711' === $GLOBALS['wpac_connector_test_sales'][0]['order_id'] );
wpac_connector_test_check( 'paid order keeps currency, code, and item amount', 'EUR' === $GLOBALS['wpac_connector_test_sales'][0]['currency'] && 'PARTNER-1' === $GLOBALS['wpac_connector_test_sales'][0]['partner_code'] && 49900 === $GLOBALS['wpac_connector_test_sales'][0]['items'][0]['net_minor'] );
wpac_connector_test_check( 'sale idempotency key is stable', 'example-app:order-4711' === $GLOBALS['wpac_connector_test_sales'][0]['idempotency_key'] );

$refund = array(
	'order_id'  => 'order-4711',
	'refund_id' => 'refund-1',
	'type'      => 'refund',
	'items'     => array( array( 'item_id' => 'line-1', 'net_minor' => 10000 ) ),
);
do_action( 'my_app_order_refunded', $refund );

wpac_connector_test_check( 'refund uses the same source and original order ID', isset( $GLOBALS['wpac_connector_test_refunds'][0] ) && 'example-app' === $GLOBALS['wpac_connector_test_refunds'][0]['source'] && 'order-4711' === $GLOBALS['wpac_connector_test_refunds'][0]['order_id'] );
wpac_connector_test_check( 'refund ID and item amount are passed through', 'refund-1' === $GLOBALS['wpac_connector_test_refunds'][0]['refund_id'] && 10000 === $GLOBALS['wpac_connector_test_refunds'][0]['items'][0]['net_minor'] );

$integrations = apply_filters( 'wpac_registered_integrations', array() );
wpac_connector_test_check( 'integration status is registered as active', ! empty( $integrations['example-app']['active'] ) && '1.1.0' === $integrations['example-app']['contract'] );

echo "7 connector starter checks passed\n";
