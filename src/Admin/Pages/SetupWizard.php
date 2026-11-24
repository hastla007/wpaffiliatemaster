<?php
/**
 * Admin: setup wizard (SET-AC-24). Guided steps country/profile → company details → commission →
 * pages → consent/cookie → test order. Every field comes from the settings register
 * (SettingsSchema: label, help text, legal mark) and is checked by Settings::sanitize() like on the
 * settings page; the country step uses the country profile preview/apply (nothing is changed
 * before the operator confirms the shown changes). Every step can be skipped, the whole wizard too;
 * the progress is stored in an option and the wizard can be opened again at any time.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\CountryProfiles;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class SetupWizard {

	/** Admin page slug. */
	const SLUG = 'wpac-setup';

	/** Option with the progress: { steps: step => done|skipped, started, finished, skipped_all, dismissed }. */
	const OPTION = 'wpac_setup_wizard';

	/** Form field names of amounts that are entered as decimals and stored in minor units. */
	const AMOUNT_FIELDS = array(
		'min_payout_minor'      => 'min_payout',
		'max_payout_minor'      => 'max_payout',
		'fraud_max_order_minor' => 'fraud_max_order',
	);

	/** @var array Rejected input of the last save: values and errors per form field. */
	private static $kept = array(
		'values' => array(),
		'errors' => array(),
	);

	/**
	 * Hooks (the menu entry and the admin-post actions are registered by Admin).
	 */
	public static function register_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'first_run_notice' ) );
	}

	/**
	 * Menu entry (capability of the settings page).
	 */
	public static function menu() {
		add_submenu_page( 'wpac', __( 'Setup wizard', 'affiliate-captain' ), __( 'Setup wizard', 'affiliate-captain' ), Capabilities::MANAGE_PROGRAM, self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * The steps in order: key => { title, intro, fields (setting keys from the register) }.
	 *
	 * @return array
	 */
	public static function steps() {
		return array(
			'country'    => array(
				'title'  => __( 'Country & profile', 'affiliate-captain' ),
				'intro'  => __( 'A country profile proposes VAT rate, currency, your country, retention periods, cookie and hold periods, document number prefixes and notes on credit notes. You see every change before it is applied – nothing is changed before you confirm.', 'affiliate-captain' ),
				'fields' => array(),
			),
			'company'    => array(
				'title'  => __( 'Company details', 'affiliate-captain' ),
				'intro'  => __( 'Your details as printed on credit notes. Needed when you settle commissions by credit note (self-billing).', 'affiliate-captain' ),
				'fields' => array( 'operator_country', 'operator_address', 'operator_vat_id', 'operator_tax_number', 'self_billing', 'vat_rate' ),
			),
			'commission' => array(
				'title'  => __( 'Commission', 'affiliate-captain' ),
				'intro'  => __( 'The default commission for partners without a matching rule, the hold period and the minimum payout. Rules for partners, groups and products can be added later under Rules & groups.', 'affiliate-captain' ),
				'fields' => array( 'default_rate_type', 'default_rate', 'commission_basis', 'hold_days', 'approval_mode', 'min_payout_minor' ),
			),
			'pages'      => array(
				'title'  => __( 'Pages', 'affiliate-captain' ),
				'intro'  => __( 'The application page, the partner area and the partner program terms. Missing pages can be created automatically; the terms are created as a draft from a template – review them with your legal advisor, then publish them.', 'affiliate-captain' ),
				'fields' => array( 'registration_page_id', 'portal_page_id', 'terms_page_id', 'terms_version' ),
			),
			'consent'    => array(
				'title'  => __( 'Consent & cookie', 'affiliate-captain' ),
				'intro'  => __( 'Whether the referral is kept in a browser cookie, for how long, and the texts applicants and readers see.', 'affiliate-captain' ),
				'fields' => array( 'cookie_mode', 'cookie_days', 'consent_terms_label', 'default_disclosure' ),
			),
			'test'       => array(
				'title'  => __( 'Test order', 'affiliate-captain' ),
				'intro'  => __( 'Check once that a sale through a partner link is recorded. The wizard creates no orders; you place the test order yourself in your shop.', 'affiliate-captain' ),
				'fields' => array(),
			),
		);
	}

	/**
	 * Valid step key or ''.
	 *
	 * @param mixed $step Requested step.
	 * @return string
	 */
	public static function clean_step( $step ) {
		$step = sanitize_key( (string) $step );
		return isset( self::steps()[ $step ] ) ? $step : '';
	}

	/**
	 * Step after $step ('' after the last one).
	 *
	 * @param string $step Step.
	 * @return string
	 */
	public static function next_step( $step ) {
		$keys = array_keys( self::steps() );
		$pos  = array_search( $step, $keys, true );
		return false !== $pos && isset( $keys[ $pos + 1 ] ) ? $keys[ $pos + 1 ] : '';
	}

	/**
	 * Step before $step ('' before the first one).
	 *
	 * @param string $step Step.
	 * @return string
	 */
	public static function previous_step( $step ) {
		$keys = array_keys( self::steps() );
		$pos  = array_search( $step, $keys, true );
		return false !== $pos && $pos > 0 ? $keys[ $pos - 1 ] : '';
	}

	/**
	 * Stored progress.
	 *
	 * @return array { steps, started, finished, skipped_all, dismissed }
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$steps = array();
		foreach ( array_keys( self::steps() ) as $step ) {
			// In step order; only known steps and states.
			if ( isset( $state['steps'][ $step ] ) && in_array( $state['steps'][ $step ], array( 'done', 'skipped' ), true ) ) {
				$steps[ $step ] = $state['steps'][ $step ];
			}
		}
		return array(
			'steps'       => $steps,
			'started'     => isset( $state['started'] ) ? (string) $state['started'] : '',
			'finished'    => isset( $state['finished'] ) ? (string) $state['finished'] : '',
			'skipped_all' => ! empty( $state['skipped_all'] ),
			'dismissed'   => ! empty( $state['dismissed'] ),
		);
	}

	/**
	 * Store changed parts of the progress.
	 *
	 * @param array $changes Parts to change.
	 */
	private static function save_state( array $changes ) {
		update_option( self::OPTION, array_merge( self::state(), $changes ), false );
	}

	/**
	 * Mark a step as done or skipped (a later "done" replaces "skipped").
	 *
	 * @param string $step   Step.
	 * @param string $status done|skipped.
	 */
	public static function mark( $step, $status ) {
		if ( '' === self::clean_step( $step ) || ! in_array( $status, array( 'done', 'skipped' ), true ) ) {
			return;
		}
		$state                   = self::state();
		$state['steps'][ $step ] = $status;
		$changes                 = array( 'steps' => $state['steps'] );
		if ( '' === $state['started'] ) {
			$changes['started'] = gmdate( 'Y-m-d H:i:s' );
		}
		self::save_state( $changes );
	}

	/**
	 * Finish the wizard (all steps went through) or skip it as a whole.
	 *
	 * @param bool $skipped Whether the whole wizard was skipped.
	 */
	public static function finish( $skipped = false ) {
		$changes = array( 'finished' => gmdate( 'Y-m-d H:i:s' ) );
		if ( $skipped ) {
			$changes['skipped_all'] = true;
		}
		self::save_state( $changes );
	}

	/**
	 * Start again: the progress is cleared (settings stay as they are).
	 */
	public static function restart() {
		$state = self::state();
		self::save_state(
			array(
				'steps'       => array(),
				'finished'    => '',
				'skipped_all' => false,
				'started'     => '' !== $state['started'] ? $state['started'] : gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Step shown when none is requested: the first one not done or skipped, else the first one.
	 *
	 * @return string
	 */
	public static function current_step() {
		$state = self::state();
		foreach ( array_keys( self::steps() ) as $step ) {
			if ( ! isset( $state['steps'][ $step ] ) ) {
				return $step;
			}
		}
		return 'country';
	}

	/**
	 * Form field name of a setting (amounts are entered as decimals under their own name).
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function form_name( $key ) {
		return isset( self::AMOUNT_FIELDS[ $key ] ) ? self::AMOUNT_FIELDS[ $key ] : (string) $key;
	}

	/**
	 * Save the fields of one step: only its setting keys, checked by Settings::sanitize() like the
	 * settings page; rejected fields keep their stored value. Logged like the settings page.
	 *
	 * @param string $step Step.
	 * @param array  $raw  Posted values (form field name => value), unslashed.
	 * @return array { ok, errors, notices, changed }
	 */
	public static function save_step( $step, array $raw ) {
		$steps  = self::steps();
		$result = array(
			'ok'      => true,
			'errors'  => array(),
			'notices' => array(),
			'changed' => array(),
		);
		$step   = self::clean_step( $step );
		if ( '' === $step || ! $steps[ $step ]['fields'] ) {
			return $result;
		}
		$keys  = $steps[ $step ]['fields'];
		$names = array();
		$in    = array();
		foreach ( $keys as $key ) {
			$name           = self::form_name( $key );
			$names[ $name ] = true;
			// Unchecked checkboxes are absent: sanitize() reads them as off.
			if ( isset( $raw[ $name ] ) && is_scalar( $raw[ $name ] ) ) {
				$in[ $name ] = (string) $raw[ $name ];
			}
		}
		$errors  = array();
		$notices = array();
		$clean   = Settings::sanitize( $in, $errors, $notices );
		$new     = array_intersect_key( $clean, array_flip( $keys ) );
		$old     = Settings::all();
		if ( ! Settings::update( $new ) ) {
			Audit::log(
				'settings.save_failed',
				'settings',
				0,
				array(
					'source' => 'wizard',
					'step'   => $step,
				)
			);
			$result['ok'] = false;
			return $result;
		}
		$changed = array();
		foreach ( $new as $k => $v ) {
			if ( ! isset( $old[ $k ] ) || (string) $old[ $k ] !== (string) $v ) {
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			Audit::log(
				'settings.updated',
				'settings',
				0,
				array(
					'changed' => $changed,
					'values'  => SettingsPage::audit_values( $changed, $old, $new ),
					'source'  => 'wizard',
					'step'    => $step,
				)
			);
		}
		$result['errors']  = array_intersect_key( $errors, $names );
		$result['notices'] = array_intersect_key( $notices, $names + array_flip( $keys ) );
		$result['changed'] = $changed;
		if ( ! $result['errors'] ) {
			self::mark( $step, 'done' );
		}
		return $result;
	}

	/**
	 * Key of the pending country profile of the current user (shown before it is applied).
	 *
	 * @return string
	 */
	private static function pending_key() {
		return 'wpac_setup_profile_' . get_current_user_id();
	}

	/**
	 * Check a profile and keep the result for confirmation (nothing is changed).
	 *
	 * @param string $code Profile code.
	 * @return true|\WP_Error
	 */
	public static function profile_preview( $code ) {
		$code    = CountryProfiles::clean( $code );
		$preview = CountryProfiles::preview( $code );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		set_transient(
			self::pending_key(),
			array(
				'profile' => $code,
				'check'   => $preview,
			),
			HOUR_IN_SECONDS
		);
		return true;
	}

	/**
	 * The pending profile of the current user, or null.
	 *
	 * @return array|null { profile, check }
	 */
	public static function profile_pending() {
		$pending = get_transient( self::pending_key() );
		return is_array( $pending ) && ! empty( $pending['profile'] ) && isset( $pending['check']['changes'] ) ? $pending : null;
	}

	/**
	 * Apply the profile as shown (only the valid values); the step counts as done.
	 *
	 * @return int|\WP_Error Number of changed settings.
	 */
	public static function profile_apply() {
		$pending = self::profile_pending();
		if ( ! $pending ) {
			return new \WP_Error( 'wpac_wizard_expired', __( 'Nothing to apply (the preview expired). Please start again.', 'affiliate-captain' ) );
		}
		delete_transient( self::pending_key() );
		$check           = (array) $pending['check'];
		$check['errors'] = array();
		if ( ! CountryProfiles::apply( (string) $pending['profile'], $check, 'wizard' ) ) {
			return new \WP_Error( 'wpac_wizard_db', __( 'The settings could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ) );
		}
		self::mark( 'country', 'done' );
		return count( (array) $check['values'] );
	}

	/**
	 * Discard the pending profile.
	 */
	public static function profile_discard() {
		delete_transient( self::pending_key() );
	}

	/**
	 * URL of a step.
	 *
	 * @param string $step Step ('' = the current one).
	 * @return string
	 */
	public static function url( $step = '' ) {
		return Admin::url( self::SLUG, '' !== $step ? array( 'step' => $step ) : array() );
	}

	/**
	 * Admin-post actions: wizard_save, wizard_profile, wizard_profile_apply, wizard_pages, wizard_state.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		if ( 'wizard_profile' === $do ) {
			if ( 'discard' === sanitize_key( (string) Admin::post( 'op' ) ) ) {
				self::profile_discard();
				Admin::back( __( 'Discarded. Nothing was changed.', 'affiliate-captain' ), 'success', self::url( 'country' ) );
			}
			$ok = self::profile_preview( (string) Admin::post( 'profile' ) );
			if ( is_wp_error( $ok ) ) {
				Admin::back( $ok->get_error_message(), 'error', self::url( 'country' ) );
			}
			wp_safe_redirect( self::url( 'country' ) . '#wpac-wizard-pending' );
			exit;
		}
		if ( 'wizard_profile_apply' === $do ) {
			$pending = self::profile_pending();
			$count   = self::profile_apply();
			if ( is_wp_error( $count ) ) {
				Admin::back( $count->get_error_message(), 'error', self::url( 'country' ) );
			}
			/* translators: 1: profile name, 2: number of changed settings */
			Admin::back( sprintf( _n( 'Country profile %1$s applied: %2$d setting changed. Everything can still be changed below.', 'Country profile %1$s applied: %2$d settings changed. Everything can still be changed below.', $count, 'affiliate-captain' ), CountryProfiles::name( (string) $pending['profile'] ), $count ), 'success', self::url( 'company' ) );
		}
		if ( 'wizard_pages' === $do ) {
			list( $created, $failed ) = DashboardPage::create_pages();
			if ( $failed ) {
				Admin::back( __( 'Some pages could not be created. Please create them yourself with the blocks "Partner application form" and "Partner area" and select them in the settings.', 'affiliate-captain' ), 'error', self::url( 'pages' ), $failed );
			}
			if ( ! $created ) {
				Admin::back( __( 'All pages already exist – nothing was created.', 'affiliate-captain' ), 'info', self::url( 'pages' ) );
			}
			Admin::back( __( 'Pages created and selected in the settings.', 'affiliate-captain' ), 'success', self::url( 'pages' ), $created );
		}
		if ( 'wizard_state' === $do ) {
			$op = sanitize_key( (string) Admin::post( 'op' ) );
			if ( 'dismiss' === $op ) {
				self::save_state( array( 'dismissed' => true ) );
				Admin::back( __( 'The setup wizard notice is hidden. You can open the wizard at any time from the dashboard or the settings page.', 'affiliate-captain' ), 'success' );
			}
			if ( 'restart' === $op ) {
				self::restart();
				wp_safe_redirect( self::url( 'country' ) );
				exit;
			}
			self::finish( true );
			Admin::back( __( 'Setup wizard skipped. You can open it again at any time from the dashboard checklist or the settings page.', 'affiliate-captain' ), 'success', Admin::url( 'wpac' ) );
		}
		if ( 'wizard_save' !== $do ) {
			return;
		}
		$step = self::clean_step( Admin::post( 'step' ) );
		if ( '' === $step ) {
			Admin::back( __( 'Unknown action.', 'affiliate-captain' ), 'error', self::url() );
		}
		$go   = sanitize_key( (string) Admin::post( 'wizard_go' ) );
		$next = self::next_step( $step );
		if ( 'skip' === $go || 'finish' === $go || ! self::steps()[ $step ]['fields'] ) {
			$state = self::state();
			// A step without fields counts as done only by its own action (profile applied, finish).
			$done = 'finish' === $go || ( isset( $state['steps'][ $step ] ) && 'done' === $state['steps'][ $step ] && 'skip' !== $go );
			self::mark( $step, $done ? 'done' : 'skipped' );
			if ( '' === $next ) {
				self::finish();
				Admin::back( __( 'Setup wizard finished. Open points stay in the setup checklist; everything can be changed on the settings page.', 'affiliate-captain' ), 'success', Admin::url( 'wpac' ) );
			}
			wp_safe_redirect( self::url( $next ) );
			exit;
		}
		$raw    = (array) Admin::post( 'wpac', array() );
		$result = self::save_step( $step, $raw );
		if ( ! $result['ok'] ) {
			Admin::back( __( 'The settings could not be saved (database error). Nothing was changed; please try again.', 'affiliate-captain' ), 'error', self::url( $step ) );
		}
		$labels = self::field_labels();
		if ( $result['errors'] ) {
			$values = array();
			foreach ( array_keys( $result['errors'] ) as $field ) {
				$values[ $field ] = isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ? wp_check_invalid_utf8( (string) $raw[ $field ] ) : '';
			}
			Admin::keep_form(
				'setup_wizard',
				array(
					'values' => $values,
					'errors' => $result['errors'],
				)
			);
			$lines = array();
			foreach ( $result['errors'] as $field => $message ) {
				$lines[] = ( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . ': ' . $message;
			}
			Admin::back(
				sprintf(
					/* translators: %d: number of fields */
					_n( '%d field was not saved because its value is not valid – it keeps its previous value. All other changes were saved.', '%d fields were not saved because their values are not valid – they keep their previous values. All other changes were saved.', count( $result['errors'] ), 'affiliate-captain' ),
					count( $result['errors'] )
				),
				'error',
				self::url( $step ),
				$lines
			);
		}
		$target = '' !== $next ? self::url( $next ) : self::url( 'test' );
		if ( $result['notices'] ) {
			$lines = array();
			foreach ( $result['notices'] as $field => $message ) {
				$lines[] = ( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . ': ' . $message;
			}
			Admin::back( __( 'Settings saved with an adjustment:', 'affiliate-captain' ), 'warning', $target, $lines );
		}
		Admin::back( __( 'Settings saved.', 'affiliate-captain' ), 'success', $target );
	}

	/**
	 * Labels per form field name (amount fields under their form name), from the register.
	 *
	 * @return array
	 */
	private static function field_labels() {
		$labels = SettingsPage::setting_labels();
		foreach ( self::AMOUNT_FIELDS as $key => $field ) {
			if ( isset( $labels[ $key ] ) ) {
				$labels[ $field ] = $labels[ $key ];
			}
		}
		return $labels;
	}

	/**
	 * Admin notice after the first activation: once, until the wizard is opened, finished, skipped
	 * or the notice is dismissed, and only while the setup checklist has open required items.
	 */
	public static function first_run_notice() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->id, array( 'toplevel_page_wpac', 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Markup of the first-run notice ('' when it is not due).
	 *
	 * @return string
	 */
	public static function notice_html() {
		$state = self::state();
		if ( '' !== $state['started'] || '' !== $state['finished'] || $state['dismissed'] || ! DashboardPage::setup_open() ) {
			return '';
		}
		ob_start();
		echo '<div class="notice notice-info wpac-wizard-notice"><p><strong>' . esc_html__( 'Welcome to Affiliate Captain.', 'affiliate-captain' ) . '</strong> ' . esc_html__( 'The setup wizard guides you through country, company details, commission, pages, consent and a test order. Every step can be skipped.', 'affiliate-captain' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Start the setup wizard', 'affiliate-captain' ) . '</a> ';
		Admin::form_open( 'wizard_state', 'wpac-inline-form' );
		echo '<input type="hidden" name="op" value="dismiss"><button type="submit" class="button-link">' . esc_html__( 'Dismiss this notice', 'affiliate-captain' ) . '</button></form></p></div>';
		return (string) ob_get_clean();
	}

	/**
	 * Link to the wizard for the dashboard checklist and the settings page.
	 *
	 * @return string HTML.
	 */
	public static function link_html() {
		if ( ! current_user_can( Capabilities::MANAGE_PROGRAM ) ) {
			return '';
		}
		$state = self::state();
		$text  = '' !== $state['finished'] || $state['steps'] ? __( 'Open the setup wizard again', 'affiliate-captain' ) : __( 'Open the setup wizard', 'affiliate-captain' );
		return '<p class="wpac-wizard-link"><a href="' . esc_url( self::url() ) . '">' . esc_html( $text ) . '</a> <span class="description">' . esc_html__( 'Guided steps for country, company details, commission, pages, consent and a test order; every step can be skipped.', 'affiliate-captain' ) . '</span></p>';
	}

	/**
	 * Render the wizard.
	 */
	public static function render() {
		$steps = self::steps();
		$step  = self::clean_step( Admin::get( 'step' ) );
		$step  = '' !== $step ? $step : self::current_step();
		$state = self::state();
		if ( '' === $state['started'] ) {
			self::save_state( array( 'started' => gmdate( 'Y-m-d H:i:s' ) ) ); // Opened: the first-run notice is no longer needed.
		}
		$kept = Admin::kept_form( 'setup_wizard' );
		if ( isset( $kept['values'], $kept['errors'] ) ) {
			self::$kept = $kept;
		}
		$keys = array_keys( $steps );
		$pos  = (int) array_search( $step, $keys, true );
		echo '<div class="wrap wpac-wizard"><h1>' . esc_html__( 'Setup wizard', 'affiliate-captain' ) . '</h1>';
		Admin::notice();
		if ( '' !== $state['finished'] ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( $state['skipped_all'] ? __( 'You skipped the setup wizard. You can still go through any step.', 'affiliate-captain' ) : __( 'You finished the setup wizard. You can go through any step again; everything can also be changed on the settings page.', 'affiliate-captain' ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Six short steps for a first working setup. Every step can be skipped and the wizard can be opened again at any time from the dashboard or the settings page; all values can be changed later on the settings page.', 'affiliate-captain' ) . '</p>';
		echo '<nav aria-label="' . esc_attr__( 'Setup steps', 'affiliate-captain' ) . '"><ol class="wpac-wizard-steps">';
		foreach ( $steps as $key => $def ) {
			$status = isset( $state['steps'][ $key ] ) ? $state['steps'][ $key ] : 'open';
			$label  = 'done' === $status ? __( 'done', 'affiliate-captain' ) : ( 'skipped' === $status ? __( 'skipped', 'affiliate-captain' ) : __( 'open', 'affiliate-captain' ) );
			printf(
				'<li class="wpac-wizard-step is-%1$s%2$s"><a href="%3$s"%4$s>%5$s</a> <span class="wpac-wizard-status">(%6$s)</span></li>',
				esc_attr( $status ),
				$key === $step ? ' is-current' : '',
				esc_url( self::url( $key ) ),
				$key === $step ? ' aria-current="step"' : '',
				esc_html( $def['title'] ),
				esc_html( $label )
			);
		}
		echo '</ol></nav>';
		self::error_summary();
		/* translators: 1: number of the step, 2: number of steps, 3: step title */
		echo '<h2 id="wpac-wizard-title">' . esc_html( sprintf( __( 'Step %1$d of %2$d: %3$s', 'affiliate-captain' ), $pos + 1, count( $steps ), $steps[ $step ]['title'] ) ) . '</h2>';
		echo '<p>' . esc_html( $steps[ $step ]['intro'] ) . '</p>';
		$s = Settings::all();
		if ( 'country' === $step ) {
			self::render_country();
		} elseif ( 'test' === $step ) {
			self::render_test( $s );
		} else {
			if ( 'pages' === $step ) {
				self::render_pages_box();
			}
			if ( 'consent' === $step ) {
				self::render_consent_box( $s );
			}
			Admin::form_open( 'wizard_save', 'wpac-wizard-form' );
			echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
			echo '<table class="form-table" role="presentation">';
			foreach ( $steps[ $step ]['fields'] as $key ) {
				self::field( $key, $s );
			}
			echo '</table>';
			self::buttons( $step, 'save' );
			echo '</form>';
		}
		if ( '' === $state['finished'] ) {
			Admin::form_open( 'wizard_state', 'wpac-wizard-skip-all' );
			echo '<input type="hidden" name="op" value="skip_all"><p><button type="submit" class="button-link">' . esc_html__( 'Skip the setup wizard', 'affiliate-captain' ) . '</button> <span class="description">' . esc_html__( 'Nothing is changed; the setup checklist on the dashboard stays.', 'affiliate-captain' ) . '</span></p></form>';
		} else {
			Admin::form_open( 'wizard_state', 'wpac-wizard-restart' );
			echo '<input type="hidden" name="op" value="restart"><p><button type="submit" class="button">' . esc_html__( 'Start the wizard again', 'affiliate-captain' ) . '</button> <span class="description">' . esc_html__( 'Clears the progress of the wizard; your settings stay as they are.', 'affiliate-captain' ) . '</span></p></form>';
		}
		echo '</div>';
	}

	/**
	 * Buttons of a step: save (or finish) first, so Enter saves; then skip and back.
	 *
	 * @param string $step Step.
	 * @param string $mode save (fields), skip (only skipping, e.g. without a profile) or finish (last step).
	 */
	private static function buttons( $step, $mode ) {
		$prev = self::previous_step( $step );
		echo '<p class="wpac-wizard-buttons">';
		if ( 'finish' === $mode ) {
			echo '<button type="submit" name="wizard_go" value="finish" class="button button-primary">' . esc_html__( 'Finish the setup wizard', 'affiliate-captain' ) . '</button> ';
		} elseif ( 'skip' === $mode ) {
			echo '<button type="submit" name="wizard_go" value="skip" class="button">' . esc_html__( 'Skip this step', 'affiliate-captain' ) . '</button> ';
		} else {
			echo '<button type="submit" name="wizard_go" value="save" class="button button-primary">' . esc_html__( 'Save and continue', 'affiliate-captain' ) . '</button> ';
			echo '<button type="submit" name="wizard_go" value="skip" class="button">' . esc_html__( 'Skip this step', 'affiliate-captain' ) . '</button> ';
		}
		if ( '' !== $prev ) {
			echo '<a href="' . esc_url( self::url( $prev ) ) . '">' . esc_html__( 'Back to the previous step', 'affiliate-captain' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Summary of rejected fields with links to them (announced once; the fields are linked to their
	 * messages with aria-describedby).
	 */
	private static function error_summary() {
		if ( ! self::$kept['errors'] ) {
			return;
		}
		$labels = self::field_labels();
		echo '<div class="notice notice-error inline wpac-wizard-errors" role="alert"><p>' . esc_html__( 'Please check these fields:', 'affiliate-captain' ) . '</p><ul>';
		foreach ( self::$kept['errors'] as $field => $message ) {
			echo '<li><a href="#wpac-wiz-' . esc_attr( $field ) . '">' . esc_html( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . '</a>: ' . esc_html( (string) $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Labels of the choices shown in the wizard (the same texts as on the settings page).
	 *
	 * @param string $key Setting key.
	 * @return array value => label
	 */
	private static function choice_labels( $key ) {
		switch ( $key ) {
			case 'default_rate_type':
				return array(
					'percent' => \WPAffiliateCaptain\Commissions\CommissionBasis::percent_label(),
					'fixed'   => __( 'Fixed amount per unit', 'affiliate-captain' ),
				);
			case 'commission_basis':
				return array(
					'net'   => __( 'Net: after discounts, without VAT (default)', 'affiliate-captain' ),
					'gross' => __( 'Gross: after discounts, including VAT', 'affiliate-captain' ),
				);
			case 'approval_mode':
				return array(
					'auto'   => __( 'Automatically after the hold period', 'affiliate-captain' ),
					'manual' => __( 'Only by hand', 'affiliate-captain' ),
				);
			case 'cookie_mode':
				return array(
					'off'     => __( 'Never set', 'affiliate-captain' ),
					'consent' => __( 'Only with marketing consent (WP Consent API / wpac_has_marketing_consent filter)', 'affiliate-captain' ),
					'always'  => __( 'Always, without consent (not permitted in the EU – §25 TDDDG, ePrivacy)', 'affiliate-captain' ),
				);
		}
		$f   = SettingsSchema::field( $key );
		$out = array();
		foreach ( isset( $f['options'] ) ? (array) $f['options'] : array() as $option ) {
			$out[ (string) $option ] = (string) $option;
		}
		return $out;
	}

	/**
	 * Value shown in a field: the rejected input after a failed save, else the stored value.
	 *
	 * @param string $name  Form field name.
	 * @param mixed  $value Stored value (display form).
	 * @return string
	 */
	private static function shown( $name, $value ) {
		return isset( self::$kept['errors'][ $name ], self::$kept['values'][ $name ] ) ? (string) self::$kept['values'][ $name ] : (string) $value;
	}

	/**
	 * One field row from the register: label, control, error message and help text with legal mark,
	 * linked with aria-describedby.
	 *
	 * @param string $key Setting key.
	 * @param array  $s   Settings.
	 */
	private static function field( $key, array $s ) {
		$f = SettingsSchema::field( $key );
		if ( ! $f ) {
			return;
		}
		$currency = (string) $s['currency'];
		$name     = self::form_name( $key );
		$id       = 'wpac-wiz-' . $name;
		$label    = (string) $f['label'];
		$help     = (string) $f['help'];
		$legal    = ! empty( $f['legal'] );
		$error    = isset( self::$kept['errors'][ $name ] ) ? (string) self::$kept['errors'][ $name ] : '';
		$ids      = array();
		if ( '' !== $error ) {
			$ids[] = $id . '-err';
		}
		if ( '' !== $help || $legal ) {
			$ids[] = $id . '-desc';
		}
		$attrs = ( $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : '' ) . ( '' !== $error ? ' aria-invalid="true"' : '' );
		$value = $s[ $key ];
		if ( isset( self::AMOUNT_FIELDS[ $key ] ) || ( 'default_rate' === $key && 'fixed' === (string) $s['default_rate_type'] ) ) {
			$value = Money::to_decimal( (int) $value, $currency );
		}
		$value     = self::shown( $name, $value );
		$field_tag = 'name="wpac[' . esc_attr( $name ) . ']" id="' . esc_attr( $id ) . '"' . $attrs;
		if ( 'bool' === $f['type'] ) {
			echo '<tr><th scope="row">' . esc_html( self::short_label( $label ) ) . '</th><td><label for="' . esc_attr( $id ) . '"><input type="checkbox" value="1" ' . $field_tag . checked( (int) $value, 1, false ) . '> ' . esc_html( $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_tag is escaped above.
		} else {
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			self::control( $key, $f, $field_tag, $value, $currency );
		}
		if ( '' !== $error ) {
			echo '<p class="wpac-field-error" id="' . esc_attr( $id ) . '-err">' . esc_html( $error ) . '</p>';
		}
		if ( '' !== $help || $legal ) {
			echo '<p class="description" id="' . esc_attr( $id ) . '-desc">' . esc_html( $help ) . ( $legal ? ' <span class="wpac-legal-mark">' . esc_html__( 'Check legally', 'affiliate-captain' ) . '</span>' : '' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Control of a non-checkbox field by its register type.
	 *
	 * @param string $key       Setting key.
	 * @param array  $f         Register entry.
	 * @param string $field_tag Escaped name, id and ARIA attributes.
	 * @param string $value     Value to show.
	 * @param string $currency  Program currency.
	 */
	private static function control( $key, array $f, $field_tag, $value, $currency ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_tag is escaped by field().
		if ( 'operator_country' === $key ) {
			$countries = Settings::countries();
			if ( '' !== $value && ! isset( $countries[ $value ] ) ) {
				$countries[ $value ] = $value; // Value from an older version.
			}
			self::select( $field_tag, $countries, $value );
		} elseif ( 'choice' === $f['type'] ) {
			self::select( $field_tag, self::choice_labels( $key ), $value );
		} elseif ( 'page' === $f['type'] ) {
			self::select( $field_tag, self::page_options( (int) $value ), $value );
		} elseif ( 'textarea' === $f['type'] ) {
			echo '<textarea rows="3" class="large-text" ' . $field_tag . '>' . esc_textarea( $value ) . '</textarea>';
		} elseif ( 'int' === $f['type'] ) {
			$min = SettingsSchema::int_min();
			$max = SettingsSchema::int_max();
			echo '<input type="number" step="1" min="' . (int) ( isset( $min[ $key ] ) ? $min[ $key ] : 0 ) . '"' . ( isset( $max[ $key ] ) ? ' max="' . (int) $max[ $key ] . '"' : '' ) . ' class="small-text" value="' . esc_attr( $value ) . '" ' . $field_tag . '>';
		} elseif ( 'money' === $f['type'] ) {
			echo '<input type="text" inputmode="decimal" class="small-text" value="' . esc_attr( $value ) . '" ' . $field_tag . '> ' . esc_html( $currency );
		} else {
			echo '<input type="' . ( 'email' === $f['type'] ? 'email' : 'text' ) . '" class="regular-text" value="' . esc_attr( $value ) . '" ' . $field_tag . '>';
		}
		// phpcs:enable
	}

	/**
	 * Select element.
	 *
	 * @param string $field_tag Escaped name, id and ARIA attributes.
	 * @param array  $options   value => label.
	 * @param string $value     Selected value.
	 */
	private static function select( $field_tag, array $options, $value ) {
		echo '<select ' . $field_tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by field().
		foreach ( $options as $v => $l ) {
			echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $value, (string) $v, false ) . '>' . esc_html( (string) $l ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Pages to choose from (published, drafts and private pages; drafts are marked), "—" = none.
	 *
	 * @param int $current Selected page.
	 * @return array ID => title
	 */
	private static function page_options( $current ) {
		$out   = array( '0' => '—' );
		$pages = function_exists( 'get_pages' ) ? get_pages(
			array(
				'post_status' => 'publish,draft,private',
				'sort_column' => 'post_title',
			)
		) : array();
		foreach ( (array) $pages as $page ) {
			$out[ (string) $page->ID ] = self::page_title( (int) $page->ID );
		}
		if ( $current > 0 && ! isset( $out[ (string) $current ] ) && get_post( $current ) ) {
			$out[ (string) $current ] = self::page_title( $current );
		}
		return $out;
	}

	/**
	 * Page title with its status when it is not published.
	 *
	 * @param int $id Page ID.
	 * @return string
	 */
	private static function page_title( $id ) {
		$title  = (string) get_the_title( $id );
		$title  = '' !== trim( $title ) ? $title : '#' . $id;
		$status = (string) get_post_status( $id );
		/* translators: %s: page title */
		return 'draft' === $status ? sprintf( __( '%s (draft)', 'affiliate-captain' ), $title ) : $title;
	}

	/**
	 * Short row title for long checkbox labels (text before a colon or the first 6 words).
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function short_label( $label ) {
		if ( false !== strpos( $label, ':' ) ) {
			return trim( (string) strstr( $label, ':', true ) );
		}
		$words = preg_split( '/\s+/', $label );
		return is_array( $words ) && count( $words ) > 6 ? implode( ' ', array_slice( $words, 0, 6 ) ) . ' …' : $label;
	}

	/**
	 * Country step: proposal, choice of a profile, pending changes (old → new) before applying.
	 */
	private static function render_country() {
		$profiles = CountryProfiles::all();
		$pending  = self::profile_pending();
		if ( ! $profiles ) {
			echo '<p>' . esc_html__( 'No country profile is available. Continue with the company details.', 'affiliate-captain' ) . '</p>';
		} else {
			$applied  = CountryProfiles::applied();
			$proposed = CountryProfiles::proposed();
			if ( $pending ) {
				self::pending_table( $pending );
			}
			if ( $applied ) {
				/* translators: 1: profile name, 2: date */
				echo '<p>' . esc_html( sprintf( __( 'Applied last: %1$s on %2$s.', 'affiliate-captain' ), CountryProfiles::name( (string) $applied['code'] ), mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $applied['at'] ) ) ) ) . '</p>';
			} elseif ( '' !== $proposed ) {
				/* translators: %s: profile name */
				echo '<p>' . esc_html( sprintf( __( 'Proposal for your shop country: %s. Show the changes to see what it would set – nothing is changed before you confirm.', 'affiliate-captain' ), CountryProfiles::name( $proposed ) ) ) . '</p>';
			}
			Admin::form_open( 'wizard_profile', 'wpac-wizard-form' );
			echo '<p><label for="wpac-wiz-profile">' . esc_html__( 'Profile', 'affiliate-captain' ) . '</label> <select id="wpac-wiz-profile" name="profile" aria-describedby="wpac-wiz-profile-desc">';
			$current = $pending ? (string) $pending['profile'] : ( $applied ? (string) $applied['code'] : $proposed );
			foreach ( array_keys( $profiles ) as $code ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( CountryProfiles::name( $code ) ) . '</option>';
			}
			echo '</select> <button type="submit" class="button">' . esc_html__( 'Show changes …', 'affiliate-captain' ) . '</button></p>';
			echo '<p class="description" id="wpac-wiz-profile-desc">' . esc_html__( 'The values are not legally or tax reviewed – check them with your adviser.', 'affiliate-captain' ) . ' <span class="wpac-legal-mark">' . esc_html__( 'Check legally', 'affiliate-captain' ) . '</span></p></form>';
		}
		Admin::form_open( 'wizard_save', 'wpac-wizard-form' );
		echo '<input type="hidden" name="step" value="country">';
		self::buttons( 'country', 'skip' );
		echo '</form>';
	}

	/**
	 * Pending profile: table old → new with legal marks, rejected values, Apply and Discard.
	 *
	 * @param array $pending Pending profile { profile, check }.
	 */
	private static function pending_table( array $pending ) {
		$labels = SettingsPage::setting_labels();
		$check  = (array) $pending['check'];
		/* translators: %s: profile name */
		echo '<div id="wpac-wizard-pending" class="notice notice-info inline"><h3>' . esc_html( sprintf( __( 'Country profile %s: please check', 'affiliate-captain' ), CountryProfiles::name( (string) $pending['profile'] ) ) ) . '</h3>';
		if ( $check['changes'] ) {
			echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Changes of the country profile', 'affiliate-captain' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Setting', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'Now', 'affiliate-captain' ) . '</th><th scope="col">' . esc_html__( 'New', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $check['changes'] as $key => $pair ) {
				$f   = SettingsSchema::field( (string) $key );
				$now = (string) $pair[0];
				if ( ! empty( $f['private'] ) && '' !== $now ) {
					$now = '•••' . ( strlen( $now ) > 6 ? substr( $now, -4 ) : '' );
				}
				$legal = ! empty( $f['legal'] ) ? ' <span class="wpac-legal-mark">' . esc_html__( 'Check legally', 'affiliate-captain' ) . '</span>' : '';
				echo '<tr><th scope="row">' . esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key ) . $legal . '</th><td>' . esc_html( $now ) . '</td><td>' . esc_html( (string) $pair[1] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $legal is escaped above.
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Nothing would change.', 'affiliate-captain' ) . '</p>';
		}
		foreach ( (array) $check['errors'] as $key => $message ) {
			echo '<p class="wpac-field-error">' . esc_html( ( isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key ) . ': ' . $message ) . '</p>';
		}
		echo '<p>';
		if ( $check['changes'] ) {
			Admin::form_open( 'wizard_profile_apply', 'wpac-inline-form' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply and continue', 'affiliate-captain' ) . '</button></form> ';
		}
		Admin::form_open( 'wizard_profile', 'wpac-inline-form' );
		echo '<input type="hidden" name="op" value="discard"><button type="submit" class="button">' . esc_html__( 'Discard', 'affiliate-captain' ) . '</button></form></p></div>';
	}

	/**
	 * Pages step: state of the pages and the button that creates the missing ones (own form, before
	 * the fields form).
	 */
	private static function render_pages_box() {
		$terms = (int) Settings::get( 'terms_page_id' );
		if ( Settings::get( 'registration_page_id' ) && Settings::get( 'portal_page_id' ) && $terms && get_post( $terms ) ) {
			if ( 'publish' !== get_post_status( $terms ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The partner program terms are not published yet. Review the draft with your legal advisor, then publish it.', 'affiliate-captain' ) . ( get_edit_post_link( $terms ) ? ' <a href="' . esc_url( (string) get_edit_post_link( $terms, 'url' ) ) . '">' . esc_html__( 'Edit the terms page', 'affiliate-captain' ) . '</a>' : '' ) . '</p></div>';
			}
			return;
		}
		Admin::form_open( 'wizard_pages', 'wpac-wizard-pages' );
		echo '<p><button type="submit" class="button" aria-describedby="wpac-wiz-pages-desc">' . esc_html__( 'Create pages automatically', 'affiliate-captain' ) . '</button> <span class="description" id="wpac-wiz-pages-desc">' . esc_html__( 'Creates "Become a partner", "Partner area" and a landing page with the matching blocks, and a draft of the partner terms from a template (review it with your legal advisor, then publish it).', 'affiliate-captain' ) . '</span></p></form>';
	}

	/**
	 * Consent step: whether a consent banner is connected and warnings for the current values.
	 *
	 * @param array $s Settings.
	 */
	private static function render_consent_box( array $s ) {
		$consent = \WPAffiliateCaptain\Tracking\Consent::integration_status();
		if ( $consent['needed'] && ! $consent['connected'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No consent banner is connected yet (WP Consent API or the filter wpac_has_marketing_consent): with "only with consent" the affiliate cookie is never set, and only partner codes and the shop session count.', 'affiliate-captain' ) . ' <a href="' . esc_url( Admin::url( 'wpac-integrations', array( 'tab' => 'status' ) ) ) . '">' . esc_html__( 'Check the system status', 'affiliate-captain' ) . '</a></p></div>';
		} elseif ( $consent['needed'] ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'A consent banner is connected: the affiliate cookie is set once a visitor gives marketing consent.', 'affiliate-captain' ) . '</p></div>';
		}
		if ( 'always' === $s['cookie_mode'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'An affiliate cookie is not strictly necessary: in the EU it may only be set with consent. Use "Only with marketing consent" unless your shop is outside the EU and you checked the local law.', 'affiliate-captain' ) . '</p></div>';
		}
		if ( (int) $s['cookie_days'] > Settings::COOKIE_DAYS_ADVISED ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( SettingsPage::cookie_days_warning( (int) $s['cookie_days'] ) ) . '</p></div>';
		}
	}

	/**
	 * Test step: how to check the tracking with a test order (the wizard creates no orders), links
	 * to the tools, and the open items of the setup checklist.
	 *
	 * @param array $s Settings.
	 */
	private static function render_test( array $s ) {
		$link = home_url( '/?' . rawurlencode( (string) $s['referral_param'] ) . '=CODE' );
		echo '<ol class="wpac-wizard-test">';
		/* translators: %s: link to the partner list */
		echo '<li>' . sprintf( esc_html__( 'Approve a test partner (e.g. yourself with a second e-mail address) on the %s page.', 'affiliate-captain' ), '<a href="' . esc_url( Admin::url( 'wpac-affiliates' ) ) . '">' . esc_html__( 'Partners', 'affiliate-captain' ) . '</a>' ) . '</li>';
		/* translators: %s: example partner link */
		echo '<li>' . esc_html( sprintf( __( 'Open the partner link in a private browser window, e.g. %s with the code of the test partner, and accept marketing in your consent banner. In the developer tools of the browser the cookie wpac_ref should now exist (with the cookie mode "only with consent").', 'affiliate-captain' ), $link ) ) . '</li>';
		echo '<li>' . esc_html__( 'Place a small order in the same window, with a test payment method of your shop (e.g. cash on delivery, bank transfer or the sandbox mode of your payment provider), or enter the partner code at checkout.', 'affiliate-captain' ) . '</li>';
		/* translators: %s: link to the commission list */
		echo '<li>' . sprintf( esc_html__( 'Check on the %s page that the order shows up with the test partner (status "pending"). Then cancel or refund the test order: the commission is rejected automatically.', 'affiliate-captain' ), '<a href="' . esc_url( Admin::url( 'wpac-commissions' ) ) . '">' . esc_html__( 'Commissions', 'affiliate-captain' ) . '</a>' ) . '</li>';
		/* translators: %s: link to the system status */
		echo '<li>' . sprintf( esc_html__( 'If nothing shows up: the %s lists the sales source, the consent banner, scheduled tasks and failed jobs, and the audit log shows what happened.', 'affiliate-captain' ), '<a href="' . esc_url( Admin::url( 'wpac-integrations', array( 'tab' => 'status' ) ) ) . '">' . esc_html__( 'system status', 'affiliate-captain' ) . '</a>' ) . '</li>';
		echo '</ol>';
		$open = array();
		foreach ( DashboardPage::checklist_items() as $item ) {
			if ( ! $item[0] && $item[3] ) {
				$open[] = '<li><a href="' . esc_url( $item[2] ) . '">' . esc_html( $item[1] ) . '</a></li>';
			}
		}
		if ( $open ) {
			echo '<h3>' . esc_html__( 'Still open in the setup checklist', 'affiliate-captain' ) . '</h3><ul class="ul-disc">' . implode( '', $open ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- items are escaped above.
		} else {
			echo '<p>' . esc_html__( 'Every required item of the setup checklist is done.', 'affiliate-captain' ) . '</p>';
		}
		Admin::form_open( 'wizard_save', 'wpac-wizard-form' );
		echo '<input type="hidden" name="step" value="test">';
		self::buttons( 'test', 'finish' );
		echo '</form>';
	}
}
