<?php
/**
 * Affiliate portal: [wpac_affiliate_portal].
 *
 * Shows only minimized data: order reference, date, product, amount and status.
 * No customer names, e-mail addresses or other customer data.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Portal;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Iban;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Promotion\Creatives;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Promotion\Qr;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Portal {

	const SHORTCODE = 'wpac_affiliate_portal';

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'handle_post' ) );
		add_action( 'init', array( __CLASS__, 'statement_download' ) );
	}

	/**
	 * PDF statement download for the partner's own payouts.
	 */
	public static function statement_download() {
		if ( empty( $_GET['wpac_statement'] ) ) {
			return;
		}
		$id = absint( $_GET['wpac_statement'] );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wpac_statement_' . $id ) ) {
			wp_die( esc_html__( 'The link has expired.', 'wp-affiliate-captain' ), 403 );
		}
		$aff = self::current();
		$p   = PayoutService::find( $id );
		if ( ! $aff || ! $p || (int) $p->affiliate_id !== (int) $aff->id ) {
			wp_die( esc_html__( 'Not allowed.', 'wp-affiliate-captain' ), 403 );
		}
		if ( ! empty( $_GET['storno'] ) ) {
			Statement::send_storno( $p );
		}
		Statement::send( $p );
	}

	/**
	 * Current affiliate of the logged-in user.
	 *
	 * @return object|null
	 */
	private static function current() {
		return is_user_logged_in() ? AffiliateRepository::find_by_user( get_current_user_id() ) : null;
	}

	/**
	 * Profile and terms forms.
	 */
	public static function handle_post() {
		if ( empty( $_POST['wpac_portal_action'] ) || ! isset( $_POST['_wpac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_nonce'] ) ), 'wpac_portal' ) ) {
			return;
		}
		$aff = self::current();
		if ( ! $aff || ! in_array( $aff->status, array( 'active', 'paused' ), true ) ) {
			return;
		}
		$action   = sanitize_key( wp_unslash( $_POST['wpac_portal_action'] ) );
		$redirect = remove_query_arg( array( 'wpac_msg' ), wpac_return_url() );

		if ( 'profile' === $action ) {
			$fields = array( 'tax_status', 'legal_name', 'address', 'country', 'vat_id', 'account_holder', 'iban', 'bic' );
			$in     = array();
			foreach ( $fields as $f ) {
				$in[ $f ] = isset( $_POST[ 'wpac_' . $f ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ 'wpac_' . $f ] ) ) : '';
			}
			$result = ProfileRepository::save( (int) $aff->id, $in );
			$msg    = is_wp_error( $result ) ? $result->get_error_code() : 'saved';
			wp_safe_redirect( add_query_arg( 'wpac_msg', $msg, $redirect ) );
			exit;
		}
		if ( 'payout_request' === $action ) {
			$result = PayoutRequests::request( $aff );
			wp_safe_redirect( add_query_arg( 'wpac_msg', is_wp_error( $result ) ? 'request_failed' : 'requested', $redirect ) );
			exit;
		}
		if ( 'accept_terms' === $action && ! empty( $_POST['wpac_terms'] ) ) {
			Agreements::record( (int) $aff->id, 'accepted' );
			wp_safe_redirect( add_query_arg( 'wpac_msg', 'terms', $redirect ) );
			exit;
		}
	}

	/**
	 * Shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );
		wp_enqueue_script( 'wpac-portal', WPAC_URL . 'assets/js/portal.js', array(), WPAC_VERSION, true );
		wp_localize_script(
			'wpac-portal',
			'wpacPortal',
			array(
				'copied' => __( 'Copied to the clipboard.', 'wp-affiliate-captain' ),
				'failed' => __( 'Please copy the text manually.', 'wp-affiliate-captain' ),
			)
		);

		if ( ! is_user_logged_in() ) {
			$reg = (int) Settings::get( 'registration_page_id' );
			$out = '<div class="wpac-portal"><p>' . esc_html__( 'Please log in to access your partner area.', 'wp-affiliate-captain' ) . '</p>';
			$out .= wp_login_form( array( 'echo' => false ) );
			if ( $reg ) {
				$out .= '<p><a href="' . esc_url( get_permalink( $reg ) ) . '">' . esc_html__( 'Not a partner yet? Apply now.', 'wp-affiliate-captain' ) . '</a></p>';
			}
			return $out . '</div>';
		}

		$aff = self::current();
		if ( ! $aff ) {
			$reg = (int) Settings::get( 'registration_page_id' );
			return '<div class="wpac-notice">' . esc_html__( 'You do not have a partner account yet.', 'wp-affiliate-captain' ) . ( $reg ? ' <a href="' . esc_url( get_permalink( $reg ) ) . '">' . esc_html__( 'Apply now', 'wp-affiliate-captain' ) . '</a>' : '' ) . '</div>';
		}
		if ( 'pending' === $aff->status ) {
			return '<div class="wpac-notice">' . esc_html__( 'Your application is being reviewed. We will notify you by e-mail.', 'wp-affiliate-captain' ) . '</div>';
		}
		if ( in_array( $aff->status, array( 'rejected', 'terminated' ), true ) ) {
			return '<div class="wpac-notice">' . esc_html__( 'Your partner account is not active.', 'wp-affiliate-captain' ) . '</div>';
		}

		$tabs = array(
			'overview'    => __( 'Overview', 'wp-affiliate-captain' ),
			'links'       => __( 'Links & codes', 'wp-affiliate-captain' ),
			'promotion'   => __( 'Creatives', 'wp-affiliate-captain' ),
			'commissions' => __( 'Commissions', 'wp-affiliate-captain' ),
			'payouts'     => __( 'Payouts', 'wp-affiliate-captain' ),
			'profile'     => __( 'Payout details', 'wp-affiliate-captain' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['wpac_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpac_tab'] ) ) : 'overview';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'overview';

		ob_start();
		echo '<div class="wpac-portal">';
		self::messages();
		if ( 'paused' === $aff->status ) {
			echo '<div class="wpac-notice">' . esc_html__( 'Your partner account is paused. New sales are currently not attributed.', 'wp-affiliate-captain' ) . '</div>';
		}
		$terms = Agreements::current( (int) $aff->group_id );
		if ( $terms['page_id'] && ! Agreements::has_current( (int) $aff->id ) ) {
			self::terms_notice( $terms );
		}
		echo '<nav class="wpac-tabs" aria-label="' . esc_attr__( 'Partner area', 'wp-affiliate-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( self::tab_url( $key ) ),
				$key === $tab ? 'is-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav><div class="wpac-tab"><p class="wpac-sr" aria-live="polite" id="wpac-live"></p>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $aff );
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * URL of a portal tab.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_url( $tab ) {
		return add_query_arg( 'wpac_tab', $tab, remove_query_arg( array( 'wpac_msg', 'target', 'campaign', 'wpac_page' ) ) );
	}

	/**
	 * Read-only text with label and copy button.
	 *
	 * @param string $value Value.
	 * @param string $label Accessible label.
	 * @param bool   $multi Multi-line.
	 * @return string
	 */
	private static function copy_field( $value, $label, $multi = false ) {
		$id = 'wpac-copy-' . substr( md5( $value . $label ), 0, 8 );
		$field = $multi
			? '<textarea readonly class="wpac-copy" rows="4" id="' . esc_attr( $id ) . '">' . esc_textarea( $value ) . '</textarea>'
			: '<input type="text" readonly class="wpac-copy" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '">';
		return '<label class="wpac-sr" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="wpac-copy-row">' . $field . '<button type="button" class="wpac-copy-btn" data-wpac-copy="' . esc_attr( $id ) . '">' . esc_html__( 'Copy', 'wp-affiliate-captain' ) . '</button></div>';
	}

	/**
	 * Empty state with a next step.
	 *
	 * @param string $text   Text.
	 * @param string $action Link text.
	 * @param string $tab    Target tab.
	 * @return string
	 */
	private static function empty_state( $text, $action = '', $tab = '' ) {
		return '<div class="wpac-empty"><p>' . esc_html( $text ) . '</p>' . ( $action ? '<p><a class="wpac-button" href="' . esc_url( self::tab_url( $tab ) ) . '">' . esc_html( $action ) . '</a></p>' : '' ) . '</div>';
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 * @param string $label  Label.
	 * @return string
	 */
	private static function status( $status, $label ) {
		return '<span class="wpac-status wpac-status--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Flash messages.
	 */
	private static function messages() {
		$messages = array(
			'saved'     => array( 'success', __( 'Your details have been saved.', 'wp-affiliate-captain' ) ),
			'requested' => array( 'success', __( 'Your payout request was sent.', 'wp-affiliate-captain' ) ),
			'request_failed' => array( 'error', __( 'The payout request could not be created.', 'wp-affiliate-captain' ) ),
			'terms'     => array( 'success', __( 'Thank you for accepting the terms.', 'wp-affiliate-captain' ) ),
			'wpac_iban' => array( 'error', __( 'The IBAN is not valid.', 'wp-affiliate-captain' ) ),
			'wpac_vat'  => array( 'error', __( 'Please enter your VAT ID.', 'wp-affiliate-captain' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['wpac_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpac_msg'] ) ) : '';
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="wpac-notice wpac-notice--%s" role="status">%s</div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	/**
	 * Re-acceptance of changed terms.
	 *
	 * @param array $terms Current terms of the partner's group.
	 */
	private static function terms_notice( array $terms ) {
		echo '<form method="post" class="wpac-notice">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="accept_terms">';
		echo '<p>' . esc_html__( 'Our partner program terms have been updated.', 'wp-affiliate-captain' ) . '</p>';
		echo '<label><input type="checkbox" name="wpac_terms" value="1" required> ';
		printf(
			/* translators: 1: link, 2: version */
			esc_html__( 'I accept the %1$s (version %2$s).', 'wp-affiliate-captain' ),
			'<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'partner program terms', 'wp-affiliate-captain' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $terms['version'] )
		);
		echo '</label> <button type="submit" class="wpac-button">' . esc_html__( 'Accept', 'wp-affiliate-captain' ) . '</button></form>';
	}

	/**
	 * Overview tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_overview( $aff ) {
		global $wpdb;
		$currency = (string) Settings::get( 'currency' );
		$totals   = Ledger::totals( array( 'affiliate_id' => (int) $aff->id ) );
		$sum      = static function ( $status ) use ( $totals, $currency ) {
			return isset( $totals[ $status ][ $currency ] ) ? (int) $totals[ $status ][ $currency ] : 0;
		};
		$t     = Db::table( 'attributions' );
		$since = Db::now( time() - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conversions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE affiliate_id = %d AND created_at >= %s", $aff->id, $since ) );

		$cards = array(
			__( 'Link visits (30 days)', 'wp-affiliate-captain' )       => number_format_i18n( Tracker::count_visits( (int) $aff->id, 30 ) ),
			__( 'Sales (30 days)', 'wp-affiliate-captain' )             => number_format_i18n( $conversions ),
			__( 'Pending (hold period)', 'wp-affiliate-captain' )       => Money::format( $sum( 'pending' ) + $sum( 'review' ), $currency ),
			__( 'Approved, not yet paid', 'wp-affiliate-captain' )      => Money::format( $sum( 'approved' ), $currency ),
			__( 'Paid in total', 'wp-affiliate-captain' )               => Money::format( $sum( 'paid' ), $currency ),
			__( 'Minimum payout amount', 'wp-affiliate-captain' )       => Money::format( (int) Settings::get( 'min_payout_minor' ), $currency ),
		);
		$code    = CodeRepository::main_code( (int) $aff->id );
		$profile = ProfileRepository::get( (int) $aff->id );
		$terms   = Agreements::current( (int) $aff->group_id );
		$steps   = array(
			array( '' !== $code, __( 'Partner code received', 'wp-affiliate-captain' ), 'links' ),
			array( ! $terms['page_id'] || Agreements::has_current( (int) $aff->id ), __( 'Current terms accepted', 'wp-affiliate-captain' ), 'overview' ),
			array( ProfileRepository::is_complete( $profile ), __( 'Payout details completed', 'wp-affiliate-captain' ), 'profile' ),
			array( Tracker::count_visits( (int) $aff->id, 3650 ) > 0, __( 'First visitor via your link', 'wp-affiliate-captain' ), 'promotion' ),
			array( $conversions > 0 || $sum( 'approved' ) + $sum( 'paid' ) > 0, __( 'First sale', 'wp-affiliate-captain' ), 'links' ),
		);
		echo '<section class="wpac-hero" aria-labelledby="wpac-hero-title"><h3 id="wpac-hero-title">' . esc_html__( 'Share your partner link', 'wp-affiliate-captain' ) . '</h3>';
		if ( $code ) {
			echo self::copy_field( add_query_arg( (string) Settings::get( 'referral_param' ), $code, home_url( '/' ) ), __( 'Your partner link', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* translators: %s: code */
			echo '<p>' . esc_html( sprintf( __( 'Or tell customers your code %s – they enter it at checkout, no cookies needed.', 'wp-affiliate-captain' ), $code ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Your partner code is being prepared. Please contact us if it does not appear soon.', 'wp-affiliate-captain' ) . '</p>';
		}
		$open = array_filter(
			$steps,
			static function ( $st ) {
				return ! $st[0];
			}
		);
		if ( $open ) {
			echo '<h4>' . esc_html__( 'Your next steps', 'wp-affiliate-captain' ) . '</h4><ul class="wpac-steps">';
			foreach ( $steps as $st ) {
				echo '<li class="' . ( $st[0] ? 'done' : 'todo' ) . '">' . ( $st[0] ? esc_html( $st[1] ) : '<a href="' . esc_url( self::tab_url( $st[2] ) ) . '">' . esc_html( $st[1] ) . '</a>' ) . '<span class="wpac-sr"> – ' . esc_html( $st[0] ? __( 'done', 'wp-affiliate-captain' ) : __( 'open', 'wp-affiliate-captain' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<div class="wpac-cards">';
		foreach ( $cards as $label => $value ) {
			printf( '<div class="wpac-card"><span class="wpac-card__label">%s</span><strong class="wpac-card__value">%s</strong></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div>';
	}

	/**
	 * Links & codes tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_links( $aff ) {
		$codes = CodeRepository::for_affiliate( (int) $aff->id );
		$main  = CodeRepository::main_code( (int) $aff->id );
		$param = (string) Settings::get( 'referral_param' );

		if ( $main ) {
			$link = add_query_arg( $param, rawurlencode( $main ), home_url( '/' ) );
			echo '<h3>' . esc_html__( 'Your partner link', 'wp-affiliate-captain' ) . '</h3>';
			echo self::copy_field( $link, __( 'Your partner link', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html__( 'Your partner code', 'wp-affiliate-captain' ) . ': <strong class="wpac-code">' . esc_html( $main ) . '</strong> ';
			echo '<span class="wpac-muted">' . esc_html__( 'Customers can enter it at checkout – it works without cookies.', 'wp-affiliate-captain' ) . '</span></p>';

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$target   = isset( $_GET['target'] ) ? esc_url_raw( wp_unslash( $_GET['target'] ) ) : '';
			$campaign = isset( $_GET['campaign'] ) ? sanitize_title( wp_unslash( $_GET['campaign'] ) ) : '';
			// phpcs:enable
			echo '<h3>' . esc_html__( 'Link generator', 'wp-affiliate-captain' ) . '</h3>';
			echo '<form method="get" class="wpac-form wpac-form--inline">';
			// Keep other query args (e.g. page_id on plain permalinks).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $k => $v ) {
				if ( ! in_array( $k, array( 'target', 'campaign' ), true ) && is_string( $v ) ) {
					echo '<input type="hidden" name="' . esc_attr( sanitize_key( $k ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) . '">';
				}
			}
			echo '<label>' . esc_html__( 'Target page on our website', 'wp-affiliate-captain' ) . ' <input type="url" name="target" value="' . esc_attr( $target ) . '" placeholder="' . esc_attr( home_url( '/' ) ) . '"></label> ';
			echo '<label>' . esc_html__( 'Campaign (optional)', 'wp-affiliate-captain' ) . ' <input type="text" name="campaign" value="' . esc_attr( $campaign ) . '"></label> ';
			echo '<button type="submit" class="wpac-button">' . esc_html__( 'Generate', 'wp-affiliate-captain' ) . '</button></form>';
			if ( $target ) {
				$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( wp_parse_url( $target, PHP_URL_HOST ) !== $home_host ) {
					echo '<div class="wpac-notice wpac-notice--error">' . esc_html__( 'Only pages of this website can be linked.', 'wp-affiliate-captain' ) . '</div>';
				} else {
					$args = array( $param => $main );
					if ( $campaign ) {
						$args['utm_campaign'] = $campaign;
						$args['utm_source']   = 'partner';
						$args['utm_medium']   = 'affiliate';
					}
					echo self::copy_field( add_query_arg( $args, $target ), __( 'Generated link', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		}

		echo \WPAffiliateCaptain\Content\Editor::render_targets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.

		echo '<h3>' . esc_html__( 'Your codes', 'wp-affiliate-captain' ) . '</h3>';
		if ( ! $codes ) {
			echo '<p>' . esc_html__( 'No codes assigned yet.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		echo '<table class="wpac-table"><thead><tr><th>' . esc_html__( 'Code', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Coupon', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Used', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Valid until', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $codes as $c ) {
			$valid = (bool) CodeRepository::validate( $c );
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $c->code ),
				esc_html( $c->coupon_code ? strtoupper( $c->coupon_code ) : '—' ),
				esc_html( (int) $c->usage_count . ( (int) $c->usage_limit ? ' / ' . (int) $c->usage_limit : '' ) ),
				esc_html( $c->expires_at ? date_i18n( get_option( 'date_format' ), strtotime( $c->expires_at . ' UTC' ) ) : '—' ),
				esc_html( $valid ? __( 'Active', 'wp-affiliate-captain' ) : __( 'Inactive', 'wp-affiliate-captain' ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Creatives tab: landing page, QR code, guidelines, approved creatives.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_promotion( $aff ) {
		$code = CodeRepository::main_code( (int) $aff->id );
		if ( '' === $code ) {
			echo '<p>' . esc_html__( 'No active partner code yet.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		$landing = Landing::url( $aff );
		echo '<h3>' . esc_html__( 'Your landing page', 'wp-affiliate-captain' ) . '</h3>';
		echo self::copy_field( $landing, __( 'Your landing page', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h3>' . esc_html__( 'QR code', 'wp-affiliate-captain' ) . '</h3>';
		try {
			$svg = Qr::svg( $landing, 6, 4 );
			echo '<div class="wpac-qr">' . $svg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated SVG, no user input in markup.
			echo '<p><a class="wpac-button" download="qr-' . esc_attr( $aff->slug ) . '.svg" href="data:image/svg+xml;base64,' . esc_attr( base64_encode( $svg ) ) . '">' . esc_html__( 'Download QR code (SVG)', 'wp-affiliate-captain' ) . '</a></p>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \InvalidArgumentException $e ) {
			echo '<p>' . esc_html__( 'The link is too long for a QR code.', 'wp-affiliate-captain' ) . '</p>';
		}

		$guidelines = (string) Settings::get( 'promotion_guidelines' );
		if ( '' !== trim( $guidelines ) ) {
			echo '<h3>' . esc_html__( 'Promotion guidelines', 'wp-affiliate-captain' ) . '</h3><div class="wpac-guidelines">' . wp_kses_post( wpautop( $guidelines ) ) . '</div>';
		}

		$creatives = Creatives::approved_for_group( (int) $aff->group_id );
		echo '<h3>' . esc_html__( 'Approved creatives', 'wp-affiliate-captain' ) . '</h3>';
		if ( ! $creatives ) {
			echo self::empty_state( __( 'No creatives available yet. You can already use your partner link and QR code above.', 'wp-affiliate-captain' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		foreach ( $creatives as $c ) {
			echo '<div class="wpac-creative"><h4>' . esc_html( $c->title ) . ' <small class="wpac-muted">v' . (int) $c->version . '</small></h4>';
			/* translators: %s: disclosure note */
			echo '<p class="wpac-disclosure">' . esc_html( sprintf( __( 'Mandatory label: "%s" – clearly visible at the beginning of your post.', 'wp-affiliate-captain' ), $c->disclosure ) ) . '</p>';
			if ( 'image' === $c->type ) {
				$src = wp_get_attachment_image_url( (int) $c->attachment_id, 'full' );
				echo wp_get_attachment_image( (int) $c->attachment_id, 'medium' );
				if ( $src ) {
					echo '<p><a href="' . esc_url( $src ) . '" download>' . esc_html__( 'Download image', 'wp-affiliate-captain' ) . '</a></p>';
				}
				echo self::copy_field( Creatives::link( $c, $code ), __( 'Link', 'wp-affiliate-captain' ) . ': ' . $c->title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== (string) $c->content ) {
				echo self::copy_field( Creatives::personalize( $c, $code, $aff->name ), __( 'Text', 'wp-affiliate-captain' ) . ': ' . $c->title, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
	}

	/**
	 * Commissions tab (minimized data).
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_commissions( $aff ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page          = isset( $_GET['wpac_page'] ) ? max( 1, (int) $_GET['wpac_page'] ) : 1;
		list( $rows, $total ) = Ledger::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 25,
				'offset'       => ( $page - 1 ) * 25,
			)
		);
		if ( ! $rows ) {
			echo self::empty_state( __( 'No commissions yet. As soon as a customer buys via your link or code, the commission appears here.', 'wp-affiliate-captain' ), __( 'Get your link', 'wp-affiliate-captain' ), 'links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$labels = Ledger::status_labels();
		echo '<table class="wpac-table"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Order', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Product', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Available from', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$product = in_array( $r->kind, array( 'sale', 'bonus' ), true ) ? $r->description : sprintf( '%s (%s)', __( 'Correction', 'wp-affiliate-captain' ), self::kind_label( $r->kind ) );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $r->created_at . ' UTC' ) ) ),
				esc_html( 'wpac' === $r->source ? '—' : '#' . $r->source_order_id ),
				esc_html( $product ),
				(int) $r->amount_minor < 0 ? 'wpac-negative' : '',
				esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ),
				self::status( $r->status, isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( in_array( $r->status, array( 'pending', 'review' ), true ) && $r->hold_until ? date_i18n( get_option( 'date_format' ), strtotime( $r->hold_until . ' UTC' ) ) : '—' )
			);
		}
		echo '</tbody></table>';
		self::pager( $page, $total, 25 );
	}

	/**
	 * Kind label.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function kind_label( $kind ) {
		$map = array(
			'refund'       => __( 'refund', 'wp-affiliate-captain' ),
			'cancellation' => __( 'cancellation', 'wp-affiliate-captain' ),
			'chargeback'   => __( 'chargeback', 'wp-affiliate-captain' ),
		);
		return isset( $map[ $kind ] ) ? $map[ $kind ] : $kind;
	}

	/**
	 * Payouts tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_payouts( $aff ) {
		if ( Settings::get( 'payout_requests' ) ) {
			$open      = PayoutRequests::open_for( (int) $aff->id );
			$available = PayoutRequests::available( (int) $aff->id );
			/* translators: %s: amount */
			echo '<p>' . esc_html( sprintf( __( 'Available for payout: %s', 'wp-affiliate-captain' ), Money::format( $available, Settings::get( 'currency' ) ) ) ) . '</p>';
			if ( $open ) {
				/* translators: %s: date */
				echo '<div class="wpac-notice">' . esc_html( sprintf( __( 'Payout requested on %s – in progress.', 'wp-affiliate-captain' ), date_i18n( get_option( 'date_format' ), strtotime( $open->created_at . ' UTC' ) ) ) ) . '</div>';
			} else {
				$can = PayoutRequests::can_request( $aff );
				if ( true === $can ) {
					echo '<form method="post">';
					wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
					echo '<input type="hidden" name="wpac_portal_action" value="payout_request"><button class="wpac-button">' . esc_html__( 'Request payout', 'wp-affiliate-captain' ) . '</button></form>';
				} else {
					echo '<p class="wpac-muted">' . esc_html( $can ) . '</p>';
				}
			}
		}
		list( $rows ) = PayoutService::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 100,
			)
		);
		if ( ! $rows ) {
			echo self::empty_state( __( 'No payouts yet. Approved commissions are paid out once the minimum amount is reached.', 'wp-affiliate-captain' ), ProfileRepository::is_complete( ProfileRepository::get( (int) $aff->id ) ) ? '' : __( 'Complete payout details', 'wp-affiliate-captain' ), 'profile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$labels = PayoutService::status_labels();
		echo '<table class="wpac-table"><thead><tr><th>' . esc_html_x( 'Number', 'document number', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Period until', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Statement', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			if ( 'cancelled' === $p->status && ! $p->storno_file ) {
				continue;
			}
			$url = static function ( $storno ) use ( $p ) {
				return wp_nonce_url( add_query_arg( array( 'wpac_statement' => (int) $p->id, 'storno' => $storno ? 1 : 0 ), home_url( '/' ) ), 'wpac_statement_' . (int) $p->id );
			};
			$pdf = $p->statement_file ? '<a href="' . esc_url( $url( false ) ) . '">' . esc_html__( 'Credit note', 'wp-affiliate-captain' ) . '</a>' : '—';
			if ( $p->storno_file ) {
				$pdf .= ' · <a href="' . esc_url( $url( true ) ) . '">' . esc_html__( 'Cancellation', 'wp-affiliate-captain' ) . '</a>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $p->number ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->period_end ) ) ),
				esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ),
				self::status( 'exported' === $p->status ? 'pending' : $p->status, 'exported' === $p->status ? $labels['pending'] : $labels[ $p->status ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$pdf // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Payout details tab.
	 *
	 * @param object $aff Affiliate.
	 */
	private static function tab_profile( $aff ) {
		$p      = ProfileRepository::get( (int) $aff->id );
		$v      = static function ( $key ) use ( $p ) {
			return $p && isset( $p->$key ) ? (string) $p->$key : '';
		};
		$labels = ProfileRepository::tax_status_labels();
		echo '<form method="post" class="wpac-form">';
		wp_nonce_field( 'wpac_portal', '_wpac_nonce' );
		echo '<input type="hidden" name="wpac_portal_action" value="profile">';
		echo '<p><label for="wpac_tax_status">' . esc_html__( 'Tax status', 'wp-affiliate-captain' ) . ' *</label><select id="wpac_tax_status" name="wpac_tax_status" required><option value="">—</option>';
		foreach ( $labels as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $v( 'tax_status' ), $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		self::input( 'legal_name', __( 'Name / company for the statement', 'wp-affiliate-captain' ), $v( 'legal_name' ) );
		echo '<p><label for="wpac_address">' . esc_html__( 'Address', 'wp-affiliate-captain' ) . '</label><textarea id="wpac_address" name="wpac_address" rows="3">' . esc_textarea( $v( 'address' ) ) . '</textarea></p>';
		self::input( 'country', __( 'Country (ISO code, e.g. DE)', 'wp-affiliate-captain' ), $v( 'country' ), true, 'maxlength="2"' );
		self::input( 'vat_id', __( 'VAT ID (businesses)', 'wp-affiliate-captain' ), $v( 'vat_id' ) );
		self::input( 'account_holder', __( 'Account holder', 'wp-affiliate-captain' ), $v( 'account_holder' ), true );
		$masked = $p ? Iban::mask( $p->iban_last4, $p->iban_country ) : '';
		self::input( 'iban', __( 'IBAN', 'wp-affiliate-captain' ) . ( $masked ? ' (' . __( 'stored', 'wp-affiliate-captain' ) . ': ' . $masked . ')' : '' ), '', ! $masked, 'autocomplete="off" placeholder="' . esc_attr( $masked ? __( 'Leave empty to keep', 'wp-affiliate-captain' ) : 'DE00 0000 0000 0000 0000 00' ) . '"' );
		self::input( 'bic', __( 'BIC (optional)', 'wp-affiliate-captain' ), $v( 'bic' ) );
		echo '<p class="wpac-muted">' . esc_html__( 'Your bank details are stored encrypted and are only used for commission payouts.', 'wp-affiliate-captain' ) . '</p>';
		echo '<p><button type="submit" class="wpac-button">' . esc_html__( 'Save', 'wp-affiliate-captain' ) . '</button></p></form>';
	}

	/**
	 * Text input helper.
	 *
	 * @param string $name     Name.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param bool   $required Required.
	 * @param string $extra    Extra attributes (already escaped).
	 */
	private static function input( $name, $label, $value, $required = false, $extra = '' ) {
		printf(
			'<p><label for="wpac_%1$s">%2$s%3$s</label><input type="text" id="wpac_%1$s" name="wpac_%1$s" value="%4$s" %5$s %6$s></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$required ? ' *' : '',
			esc_attr( $value ),
			$required ? 'required' : '',
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Simple pager.
	 *
	 * @param int $page  Page.
	 * @param int $total Total.
	 * @param int $per   Per page.
	 */
	private static function pager( $page, $total, $per ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="wpac-pager">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			printf( '<a href="%s" class="%s">%d</a> ', esc_url( add_query_arg( 'wpac_page', $i ) ), $i === $page ? 'is-active' : '', (int) $i );
		}
		echo '</nav>';
	}
}
