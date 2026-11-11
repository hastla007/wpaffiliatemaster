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
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class Portal {

	const SHORTCODE = 'wpac_affiliate_portal';

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'handle_post' ) );
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
		$redirect = remove_query_arg( array( 'wpac_msg' ), wp_get_referer() ? wp_get_referer() : home_url( '/' ) );

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
		if ( Settings::get( 'terms_page_id' ) && ! Agreements::has_current( (int) $aff->id ) ) {
			self::terms_notice();
		}
		echo '<nav class="wpac-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( 'wpac_tab', $key, remove_query_arg( array( 'wpac_msg', 'target', 'campaign' ) ) ) ),
				$key === $tab ? 'is-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav><div class="wpac-tab">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $aff );
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * Flash messages.
	 */
	private static function messages() {
		$messages = array(
			'saved'     => array( 'success', __( 'Your details have been saved.', 'wp-affiliate-captain' ) ),
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
	 */
	private static function terms_notice() {
		$terms = Agreements::current();
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
		$main  = '';
		foreach ( $codes as $c ) {
			if ( CodeRepository::validate( $c ) && '' === $c->batch ) {
				$main = $c->code;
				break;
			}
		}
		$param = (string) Settings::get( 'referral_param' );

		if ( $main ) {
			$link = add_query_arg( $param, rawurlencode( $main ), home_url( '/' ) );
			echo '<h3>' . esc_html__( 'Your partner link', 'wp-affiliate-captain' ) . '</h3>';
			echo '<p><input type="text" readonly class="wpac-copy" value="' . esc_attr( $link ) . '" onclick="this.select()"></p>';
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
					echo '<p><input type="text" readonly class="wpac-copy" value="' . esc_attr( add_query_arg( $args, $target ) ) . '" onclick="this.select()"></p>';
				}
			}
		}

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
			echo '<p>' . esc_html__( 'No commissions yet.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		$labels = Ledger::status_labels();
		echo '<table class="wpac-table"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Order', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Product', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Available from', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$product = 'sale' === $r->kind ? $r->description : sprintf( '%s (%s)', __( 'Correction', 'wp-affiliate-captain' ), self::kind_label( $r->kind ) );
			printf(
				'<tr><td>%s</td><td>#%s</td><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $r->created_at . ' UTC' ) ) ),
				esc_html( $r->source_order_id ),
				esc_html( $product ),
				(int) $r->amount_minor < 0 ? 'wpac-negative' : '',
				esc_html( Money::format( (int) $r->amount_minor, $r->currency ) ),
				esc_html( isset( $labels[ $r->status ] ) ? $labels[ $r->status ] : $r->status ),
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
		list( $rows ) = PayoutService::query(
			array(
				'affiliate_id' => (int) $aff->id,
				'limit'        => 100,
			)
		);
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No payouts yet.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		$labels = PayoutService::status_labels();
		echo '<table class="wpac-table"><thead><tr><th>' . esc_html__( 'Number', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Period until', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			if ( 'cancelled' === $p->status ) {
				continue;
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $p->number ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->period_end ) ) ),
				esc_html( Money::format( (int) $p->amount_minor, $p->currency ) ),
				esc_html( 'exported' === $p->status ? $labels['pending'] : $labels[ $p->status ] )
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
