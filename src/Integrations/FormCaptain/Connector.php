<?php
/**
 * Form Captain connector "affiliate_captain" (Form Captain contract v1, ConnectorInterface).
 *
 * Loaded only after form_captain_loaded_v1 with a compatible contract (FormCaptain::boot()), so
 * the interface exists. Form Captain lists it under Integrations as "detected" and runs nothing
 * until an administrator activates it (opt-in).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations\FormCaptain;

use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Connector implements \FormCaptain\Integrations\ConnectorInterface {

	/**
	 * Stable connector key.
	 *
	 * @return string
	 */
	public function key(): string {
		return FormCaptain::CONNECTOR;
	}

	/**
	 * Name in Form Captain → Integrations.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Affiliate Captain', 'affiliate-captain' );
	}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public function version(): string {
		return (string) WPAC_VERSION;
	}

	/**
	 * Form Captain contract this connector is written for.
	 *
	 * @return string
	 */
	public function contract(): string {
		return FormCaptain::CONTRACT;
	}

	/**
	 * Action keys.
	 *
	 * @return array
	 */
	public function capabilities(): array {
		return array( FormCaptain::ACTION );
	}

	/**
	 * Requirements: a published registration page (it shows the e-mail confirmation step).
	 *
	 * @return array { ok, messages }
	 */
	public function requirements(): array {
		if ( '' === Handover::confirm_url() ) {
			return array(
				'ok'       => false,
				'messages' => array( __( 'Affiliate Captain needs a published registration page (Affiliate Captain → Settings): applicants confirm their e-mail address there.', 'affiliate-captain' ) ),
			);
		}
		return array(
			'ok'       => true,
			'messages' => array( __( 'Applications are created in Affiliate Captain with status "pending" after the applicant confirmed the e-mail address. Approval stays in Affiliate Captain.', 'affiliate-captain' ) ),
		);
	}

	/**
	 * Global connector settings: none (each form action has its own).
	 *
	 * @return array
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Register the form action.
	 */
	public function register_actions(): void {
		$groups = array();
		foreach ( Groups::public_groups() as $group ) {
			$groups[] = (int) $group->id . ' = ' . $group->name;
		}
		form_captain_register_action_v1(
			FormCaptain::ACTION,
			array(
				'label'     => __( 'Partner application to Affiliate Captain', 'affiliate-captain' ),
				'version'   => '1.0.0',
				'contract'  => '^' . FormCaptain::CONTRACT,
				'connector' => FormCaptain::CONNECTOR,
				'settings'  => array(
					array(
						'key'     => 'group_id',
						'type'    => 'number',
						/* translators: %s: list of public partner groups, e.g. "3 = Influencer" */
						'label'   => sprintf( __( 'Partner group (ID, 0 = none). Public groups: %s', 'affiliate-captain' ), $groups ? implode( ', ', $groups ) : __( 'none', 'affiliate-captain' ) ),
						'default' => '0',
					),
					array(
						'key'   => 'terms_version',
						'type'  => 'text',
						/* translators: %s: current terms version in Affiliate Captain */
						'label' => sprintf( __( 'Version of the partner program terms shown in the form (current version in Affiliate Captain: %s)', 'affiliate-captain' ), '' !== (string) Settings::get( 'terms_version' ) ? (string) Settings::get( 'terms_version' ) : '-' ),
					),
				),
				'mapping'   => array(
					array(
						'key'      => 'name',
						'label'    => __( 'Name or company', 'affiliate-captain' ),
						'types'    => array( 'text' ),
						'required' => true,
					),
					array(
						'key'      => 'email',
						'label'    => __( 'E-mail', 'affiliate-captain' ),
						'types'    => array( 'email' ),
						'required' => true,
					),
					array(
						'key'   => 'website',
						'label' => __( 'Website', 'affiliate-captain' ),
						'types' => array( 'text' ),
					),
					array(
						'key'   => 'channel',
						'label' => __( 'Channel', 'affiliate-captain' ),
						'types' => array( 'text', 'select', 'radio' ),
					),
					array(
						'key'   => 'message',
						'label' => __( 'Message', 'affiliate-captain' ),
						'types' => array( 'textarea', 'text' ),
					),
					array(
						'key'      => 'terms',
						'label'    => __( 'Consent to the partner program terms', 'affiliate-captain' ),
						'types'    => array( 'consent' ),
						'required' => true,
					),
					array(
						'key'   => 'referrer',
						'label' => __( 'Referring partner code', 'affiliate-captain' ),
						'types' => array( 'text', 'hidden' ),
					),
				),
				'events'    => array( 'entry_created' ),
			),
			array( Handover::class, 'handle' )
		);
	}
}
