<?php
/**
 * Plugin Name:       Affiliate Captain Connector Example
 * Description:       Starter adapter for reporting sales and refunds from a custom app.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * Install alongside Affiliate Captain, then replace the example app hooks
 * with the order and refund hooks exposed by your application.
 */

defined( 'ABSPATH' ) || exit;

final class WPAC_Connector_Example {
	const SOURCE = 'example-app';

	public static function boot() {
		add_action( 'wpac_loaded', array( __CLASS__, 'register_app_hooks' ) );
		add_filter( 'wpac_registered_integrations', array( __CLASS__, 'register_status' ) );
	}

	public static function register_app_hooks() {
		// Replace these example hook names with hooks from your custom app.
		add_action( 'my_app_order_paid', array( __CLASS__, 'on_order_paid' ), 10, 1 );
		add_action( 'my_app_order_refunded', array( __CLASS__, 'on_order_refunded' ), 10, 1 );
	}

	public static function register_status( $integrations ) {
		$integrations[ self::SOURCE ] = array(
			'name'     => 'Example Custom App',
			'version'  => '0.1.0',
			'contract' => defined( 'WPAC_CONTRACT_VERSION' ) ? WPAC_CONTRACT_VERSION : '1.0.0',
			'active'   => function_exists( 'wpac_record_sale' ) && function_exists( 'wpac_record_refund' ),
		);
		return $integrations;
	}

	public static function on_order_paid( $order ) {
		if ( ! is_array( $order ) || ! function_exists( 'wpac_record_sale' ) ) {
			do_action( 'wpac_connector_example_error', 'sale_unavailable' );
			return;
		}

		$payload = array(
			// The contract version THIS connector was written for (never the plugin's own constant,
			// otherwise the compatibility check could not detect a breaking change).
			'contract'     => '1.0.0',
			'source'       => self::SOURCE,
			'order_id'     => isset( $order['order_id'] ) ? (string) $order['order_id'] : '',
			'currency'     => isset( $order['currency'] ) ? strtoupper( (string) $order['currency'] ) : '',
			'paid_at'      => isset( $order['paid_at'] ) ? $order['paid_at'] : '',
			// No hold_until and no touch from the order data: if the app filled them from client
			// input, buyers could skip the hold period or forge the attribution. The plugin sets the
			// hold itself; the touch comes from the server (stored when the order was created).
			'partner_code' => isset( $order['partner_code'] ) ? (string) $order['partner_code'] : '',
			'touch'        => isset( $order['server_touch'] ) && is_array( $order['server_touch'] ) ? $order['server_touch'] : null,
			'customer'     => array(
				'user_id' => isset( $order['customer']['user_id'] ) ? (int) $order['customer']['user_id'] : 0,
				'email'   => isset( $order['customer']['email'] ) ? (string) $order['customer']['email'] : '',
			),
			'items'        => isset( $order['items'] ) && is_array( $order['items'] ) ? $order['items'] : array(),
			'idempotency_key' => self::SOURCE . ':' . ( isset( $order['order_id'] ) ? (string) $order['order_id'] : '' ),
		);

		$result = wpac_record_sale( $payload );
		do_action( 'wpac_connector_example_sale_result', $result, $payload );
	}

	public static function on_order_refunded( $refund ) {
		if ( ! is_array( $refund ) || ! function_exists( 'wpac_record_refund' ) ) {
			do_action( 'wpac_connector_example_error', 'refund_unavailable' );
			return;
		}

		$payload = array(
			'contract'  => defined( 'WPAC_CONTRACT_VERSION' ) ? WPAC_CONTRACT_VERSION : '1.0.0',
			'source'    => self::SOURCE,
			'order_id'  => isset( $refund['order_id'] ) ? (string) $refund['order_id'] : '',
			'refund_id' => isset( $refund['refund_id'] ) ? (string) $refund['refund_id'] : '',
			'type'      => isset( $refund['type'] ) ? (string) $refund['type'] : 'refund',
			'full'      => ! empty( $refund['full'] ),
			'items'     => isset( $refund['items'] ) && is_array( $refund['items'] ) ? $refund['items'] : array(),
			'reason'    => isset( $refund['reason'] ) ? (string) $refund['reason'] : '',
		);

		$result = wpac_record_refund( $payload );
		do_action( 'wpac_connector_example_refund_result', $result, $payload );
	}
}

// Run before Affiliate Captain's plugins_loaded priority 5 boot hook.
add_action( 'plugins_loaded', array( 'WPAC_Connector_Example', 'boot' ), 4 );
