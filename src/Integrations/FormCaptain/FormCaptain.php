<?php
/**
 * Optional Form Captain adapter (TODO AFF-SU-01, owner decision 16.11.2026; docs/CONTRACT.md,
 * "Form Captain: Partnerbewerbung über ein Formular").
 *
 * Registers the connector `affiliate_captain` with its action "Partner application to Affiliate
 * Captain" through Form Captain's public contract v1 only (form_captain_loaded_v1,
 * form_captain_connectors_v1, form_captain_register_action_v1(), the deletion and consent events).
 * Without Form Captain, or with another contract major version, nothing happens.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations\FormCaptain;

defined( 'ABSPATH' ) || exit;

final class FormCaptain {

	/** Connector key in Form Captain. */
	const CONNECTOR = 'affiliate_captain';

	/** Action key. */
	const ACTION = 'affiliate_captain.partner_application';

	/** Form Captain contract version the connector is written for (major 1, minor 0 or later). */
	const CONTRACT = '1.0';

	/** @var bool Whether the connector and the listeners are registered (once per request). */
	private static $booted = false;

	/**
	 * Wait for Form Captain (it loads at plugins_loaded, priority 20; this plugin at priority 5).
	 */
	public static function register_hooks() {
		add_action( 'form_captain_loaded_v1', array( __CLASS__, 'boot' ) );
		if ( function_exists( 'did_action' ) && did_action( 'form_captain_loaded_v1' ) && function_exists( 'form_captain_contract_version' ) ) {
			self::boot( (string) form_captain_contract_version() );
		}
	}

	/**
	 * Whether a Form Captain contract version is compatible (same major version).
	 *
	 * @param string $contract Contract version of Form Captain, e.g. 1.1.0.
	 * @return bool
	 */
	public static function compatible( $contract ) {
		return (bool) preg_match( '/^1(\.\d+){0,2}$/', trim( (string) $contract ) );
	}

	/**
	 * Form Captain is loaded: register the connector and the clean-up listeners.
	 *
	 * @param string $contract Contract version of Form Captain.
	 */
	public static function boot( $contract = '' ) {
		if ( ! self::compatible( $contract ) || ! interface_exists( '\FormCaptain\Integrations\ConnectorInterface' ) || ! function_exists( 'form_captain_register_action_v1' ) ) {
			return;
		}
		if ( ! self::$booted ) {
			self::$booted = true;
			add_filter( 'form_captain_connectors_v1', array( __CLASS__, 'add_connector' ) );
			add_action( 'form_captain_entry_deleted_v1', array( Handover::class, 'entry_deleted' ), 10, 3 );
			add_action( 'form_captain_consent_withdrawn_v1', array( Handover::class, 'consent_withdrawn' ), 10, 3 );
		}
	}

	/**
	 * Filter form_captain_connectors_v1: add the connector without replacing an existing key.
	 *
	 * @param array $connectors Connectors.
	 * @return array
	 */
	public static function add_connector( $connectors ) {
		return ( is_array( $connectors ) ? $connectors : array() ) + array( self::CONNECTOR => new Connector() );
	}
}
