<?php
/**
 * Admin: partners (applications, profiles, codes, agreements, payout details).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Agreements;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Csv;
use WPAffiliateCaptain\Core\Iban;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Tracking\Tracker;

defined( 'ABSPATH' ) || exit;

final class AffiliatesPage {

	/**
	 * Handle actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {
		$id = absint( Admin::post( 'id', 0 ) );
		switch ( $do ) {
			case 'affiliate_create':
				$result = AffiliateRepository::create(
					array(
						'name'     => Admin::post( 'name' ),
						'email'    => Admin::post( 'email' ),
						'website'  => Admin::post( 'website' ),
						'channel'  => Admin::post( 'channel' ),
						'group_id' => absint( Admin::post( 'group_id', 0 ) ),
						'status'   => 'active',
					)
				);
				if ( is_wp_error( $result ) ) {
					Admin::back( $result->get_error_message(), 'error' );
				}
				Admin::back( __( 'Partner created and activated.', 'wp-affiliate-captain' ), 'success', Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $result ) ) );
				break;

			case 'affiliate_update':
				AffiliateRepository::update(
					$id,
					array(
						'name'     => Admin::post( 'name' ),
						'website'  => Admin::post( 'website' ),
						'channel'  => Admin::post( 'channel' ),
						'group_id' => absint( Admin::post( 'group_id', 0 ) ),
						'slug'     => Admin::post( 'slug' ),
						'landing_page_id' => absint( Admin::post( 'landing_page_id', 0 ) ),
						'landing_message' => (string) Admin::post( 'landing_message' ),
					)
				);
				Admin::back( __( 'Partner saved.', 'wp-affiliate-captain' ) );
				break;

			case 'affiliate_status':
				$result = AffiliateRepository::set_status( $id, sanitize_key( Admin::post( 'status' ) ), sanitize_text_field( Admin::post( 'reason' ) ) );
				if ( is_wp_error( $result ) ) {
					Admin::back( $result->get_error_message(), 'error' );
				}
				Admin::back( __( 'Status updated.', 'wp-affiliate-captain' ) );
				break;

			case 'code_create':
				$wish   = sanitize_text_field( Admin::post( 'code' ) );
				$result = CodeRepository::create(
					$id,
					'' !== $wish ? $wish : CodeRepository::random_code(),
					array(
						'coupon_code' => Admin::post( 'coupon_code' ),
						'usage_limit' => absint( Admin::post( 'usage_limit', 0 ) ),
						'expires_at'  => sanitize_text_field( Admin::post( 'expires_at' ) ),
						'strict'      => true,
					)
				);
				if ( is_wp_error( $result ) ) {
					Admin::back( $result->get_error_message(), 'error' );
				}
				Admin::back( __( 'Code created.', 'wp-affiliate-captain' ) );
				break;

			case 'code_batch':
				list( $batch, $ids ) = CodeRepository::create_batch(
					$id,
					absint( Admin::post( 'count', 10 ) ),
					sanitize_text_field( Admin::post( 'prefix' ) ),
					array(
						'usage_limit' => absint( Admin::post( 'usage_limit', 1 ) ),
						'expires_at'  => sanitize_text_field( Admin::post( 'expires_at' ) ),
					)
				);
				/* translators: 1: count, 2: batch */
				Admin::back( sprintf( __( '%1$d codes created (batch %2$s).', 'wp-affiliate-captain' ), count( $ids ), $batch ) );
				break;

			case 'code_status':
				CodeRepository::set_status( absint( Admin::post( 'code_id' ) ), sanitize_key( Admin::post( 'status' ) ) );
				Admin::back( __( 'Code updated.', 'wp-affiliate-captain' ) );
				break;

			case 'code_coupon':
				CodeRepository::set_coupon( absint( Admin::post( 'code_id' ) ), sanitize_text_field( Admin::post( 'coupon_code' ) ) );
				Admin::back( __( 'Coupon link updated.', 'wp-affiliate-captain' ) );
				break;

			case 'code_export':
				list( $rows ) = CodeRepository::query(
					array(
						'affiliate_id' => $id,
						'batch'        => sanitize_key( Admin::post( 'batch' ) ),
						'limit'        => 100000,
					)
				);
				$out = array();
				foreach ( $rows as $r ) {
					$out[] = array( $r->code, $r->coupon_code, $r->batch, $r->usage_count, $r->usage_limit, $r->expires_at, $r->status );
				}
				Audit::log( 'code.exported', 'affiliate', $id, array( 'count' => count( $out ) ) );
				Csv::download( 'partner-codes-' . $id . '.csv', array( 'code', 'coupon', 'batch', 'used', 'limit', 'expires_at', 'status' ), $out );
				break;

			case 'profile_update':
				$in = array();
				foreach ( array( 'tax_status', 'legal_name', 'address', 'country', 'vat_id', 'account_holder', 'iban', 'bic' ) as $f ) {
					$in[ $f ] = (string) Admin::post( $f );
				}
				$result = ProfileRepository::save( $id, $in );
				if ( is_wp_error( $result ) ) {
					Admin::back( $result->get_error_message(), 'error' );
				}
				Admin::back( __( 'Payout details saved.', 'wp-affiliate-captain' ) );
				break;
		}
	}

	/**
	 * Router.
	 */
	public static function render() {
		$action = Admin::get( 'action' );
		if ( 'edit' === $action ) {
			self::render_edit( absint( Admin::get( 'id' ) ) );
			return;
		}
		if ( 'new' === $action ) {
			self::render_new();
			return;
		}
		self::render_list();
	}

	/**
	 * Bulk actions of the list table (load-{page} hook, before output).
	 */
	public static function process_bulk() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ( isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '' );
		if ( 'approve' !== $action || empty( $_REQUEST['ids'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-partners' );
		$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) );
		// phpcs:enable
		$done = 0;
		foreach ( $ids as $id ) {
			$aff = AffiliateRepository::find( $id );
			if ( $aff && 'pending' === $aff->status && true === AffiliateRepository::set_status( $id, 'active' ) ) {
				++$done;
			}
		}
		/* translators: %d: number */
		Admin::back( sprintf( _n( '%d application approved.', '%d applications approved.', $done, 'wp-affiliate-captain' ), $done ), 'success', remove_query_arg( array( 'action', 'action2', 'ids', '_wpnonce', '_wp_http_referer' ) ) );
	}

	/**
	 * List.
	 */
	private static function render_list() {
		$table = new \WPAffiliateCaptain\Admin\Tables\AffiliatesTable();
		$table->prepare_items();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Partners', 'wp-affiliate-captain' ) . '</h1> ';
		echo '<a href="' . esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Add partner', 'wp-affiliate-captain' ) . '</a><hr class="wp-header-end">';
		Admin::notice();
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="wpac-affiliates">';
		if ( Admin::get( 'status' ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( Admin::get( 'status' ) ) . '">';
		}
		$table->search_box( __( 'Search partners', 'wp-affiliate-captain' ), 'wpac-partner' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * New partner form.
	 */
	private static function render_new() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Add partner', 'wp-affiliate-captain' ) . '</h1>';
		Admin::notice();
		Admin::form_open( 'affiliate_create' );
		echo '<table class="form-table">';
		self::row_input( 'name', __( 'Name / company', 'wp-affiliate-captain' ), '', 'required' );
		self::row_input( 'email', __( 'E-mail', 'wp-affiliate-captain' ), '', 'required type="email"' );
		self::row_input( 'website', __( 'Website', 'wp-affiliate-captain' ), '', 'type="url"' );
		self::row_input( 'channel', __( 'Channel', 'wp-affiliate-captain' ), '' );
		self::row_group( 0 );
		echo '</table><p class="description">' . esc_html__( 'The partner is activated immediately. A WordPress account is linked by e-mail or created, and a partner code is generated.', 'wp-affiliate-captain' ) . '</p>';
		submit_button( __( 'Create partner', 'wp-affiliate-captain' ) );
		echo '</form></div>';
	}

	/**
	 * Detail view.
	 *
	 * @param int $id Affiliate.
	 */
	private static function render_edit( $id ) {
		$a = AffiliateRepository::find( $id );
		if ( ! $a ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Partner not found.', 'wp-affiliate-captain' ) . '</p></div>';
			return;
		}
		$labels   = AffiliateRepository::status_labels();
		$currency = (string) Settings::get( 'currency' );

		echo '<div class="wrap wpac-wrap"><h1>' . esc_html( $a->name ) . ' ' . Admin::badge( $a->status, $labels[ $a->status ] ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><a href="' . esc_url( Admin::url( 'wpac-affiliates' ) ) . '">&larr; ' . esc_html__( 'All partners', 'wp-affiliate-captain' ) . '</a></p>';
		Admin::notice();

		// KPIs.
		$totals = Ledger::totals( array( 'affiliate_id' => $id ) );
		$sum    = static function ( $s ) use ( $totals, $currency ) {
			return isset( $totals[ $s ][ $currency ] ) ? (int) $totals[ $s ][ $currency ] : 0;
		};
		echo '<div class="wpac-kpis">';
		foreach (
			array(
				__( 'Visits (30 days)', 'wp-affiliate-captain' ) => number_format_i18n( Tracker::count_visits( $id, 30 ) ),
				__( 'Pending', 'wp-affiliate-captain' )          => Money::format( $sum( 'pending' ) + $sum( 'review' ), $currency ),
				__( 'Approved', 'wp-affiliate-captain' )         => Money::format( $sum( 'approved' ), $currency ),
				__( 'Paid', 'wp-affiliate-captain' )             => Money::format( $sum( 'paid' ), $currency ),
			) as $label => $value
		) {
			echo '<div class="wpac-kpi"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
		}
		echo '</div>';
		echo '<p><a class="button" href="' . esc_url( Admin::url( 'wpac-commissions', array( 'affiliate_id' => $id ) ) ) . '">' . esc_html__( 'Commissions of this partner', 'wp-affiliate-captain' ) . '</a></p>';

		$parts = array();

		ob_start();
		// Status actions.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</h2><div class="inside">';
		if ( $a->status_reason ) {
			echo '<p>' . esc_html__( 'Last reason', 'wp-affiliate-captain' ) . ': ' . esc_html( $a->status_reason ) . '</p>';
		}
		if ( $a->application_note ) {
			echo '<p><strong>' . esc_html__( 'Application', 'wp-affiliate-captain' ) . ':</strong><br>' . nl2br( esc_html( $a->application_note ) ) . '</p>';
		}
		$app_data = json_decode( (string) $a->application_data, true );
		if ( is_array( $app_data ) && $app_data ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $app_data as $field ) {
				echo '<tr><th>' . esc_html( isset( $field['label'] ) ? $field['label'] : '' ) . '</th><td>' . nl2br( esc_html( isset( $field['value'] ) ? ( '1' === $field['value'] ? '✔' : $field['value'] ) : '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		Admin::form_open( 'affiliate_status' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		$transitions = array(
			'pending'    => array( 'active' => __( 'Approve', 'wp-affiliate-captain' ), 'rejected' => __( 'Reject', 'wp-affiliate-captain' ) ),
			'active'     => array( 'paused' => __( 'Pause', 'wp-affiliate-captain' ), 'terminated' => __( 'Terminate', 'wp-affiliate-captain' ) ),
			'paused'     => array( 'active' => __( 'Reactivate', 'wp-affiliate-captain' ), 'terminated' => __( 'Terminate', 'wp-affiliate-captain' ) ),
			'rejected'   => array( 'active' => __( 'Approve', 'wp-affiliate-captain' ) ),
			'terminated' => array( 'active' => __( 'Reactivate', 'wp-affiliate-captain' ) ),
		);
		echo '<p><input type="text" name="reason" class="regular-text" placeholder="' . esc_attr__( 'Reason (required for reject/pause/terminate)', 'wp-affiliate-captain' ) . '"></p><p>';
		foreach ( $transitions[ $a->status ] as $to => $label ) {
			$confirm = in_array( $to, array( 'terminated', 'rejected', 'paused' ), true )
				/* translators: %s: action */
				? ' data-wpac-confirm="' . esc_attr( sprintf( __( 'Really "%s"? The partner will be notified by e-mail.', 'wp-affiliate-captain' ), $label ) ) . '"'
				: '';
			echo '<button type="submit" name="status" value="' . esc_attr( $to ) . '" class="button ' . ( 'active' === $to ? 'button-primary' : '' ) . '"' . $confirm . '>' . esc_html( $label ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</p>';
		if ( isset( $transitions[ $a->status ]['terminated'] ) ) {
			echo '<p class="description">' . esc_html(
				'reject' === Settings::get( 'terminated_open' )
					? __( 'On termination, open commissions are rejected (see settings).', 'wp-affiliate-captain' )
					: __( 'On termination, open commissions remain payable (see settings).', 'wp-affiliate-captain' )
			) . '</p>';
		}
		echo '</form></div></div>';

		$parts['overview'][] = ob_get_clean();
		ob_start();
		// Profile.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Profile', 'wp-affiliate-captain' ) . '</h2><div class="inside">';
		Admin::form_open( 'affiliate_update' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '"><table class="form-table">';
		self::row_input( 'name', __( 'Name / company', 'wp-affiliate-captain' ), $a->name );
		echo '<tr><th>' . esc_html__( 'E-mail', 'wp-affiliate-captain' ) . '</th><td>' . esc_html( $a->email ) . ( $a->user_id ? ' · <a href="' . esc_url( get_edit_user_link( (int) $a->user_id ) ) . '">' . esc_html__( 'WordPress user', 'wp-affiliate-captain' ) . '</a>' : '' ) . '</td></tr>';
		self::row_input( 'slug', __( 'Vanity slug', 'wp-affiliate-captain' ), $a->slug );
		self::row_input( 'website', __( 'Website', 'wp-affiliate-captain' ), $a->website, 'type="url"' );
		self::row_input( 'channel', __( 'Channel', 'wp-affiliate-captain' ), $a->channel );
		self::row_group( (int) $a->group_id );
		echo '<tr><th>' . esc_html__( 'Landing page', 'wp-affiliate-captain' ) . '</th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'landing_page_id',
				'selected'          => (int) $a->landing_page_id,
				'show_option_none'  => esc_html__( '— default —', 'wp-affiliate-captain' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html( \WPAffiliateCaptain\Promotion\Landing::url( $a ) ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Welcome message on landing page', 'wp-affiliate-captain' ) . '</th><td><textarea name="landing_message" rows="3" class="large-text">' . esc_textarea( (string) $a->landing_message ) . '</textarea><p class="description">' . esc_html__( 'Shown via [wpac_partner_welcome]. Maintained by the operator only.', 'wp-affiliate-captain' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save', 'wp-affiliate-captain' ), 'secondary' );
		echo '</form></div></div>';

		$parts['profile'][] = ob_get_clean();
		ob_start();
		// Agreements.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Terms acceptance', 'wp-affiliate-captain' ) . '</h2><div class="inside">';
		$agreements = Agreements::for_affiliate( $id );
		if ( ! $agreements ) {
			echo '<p>' . esc_html__( 'No acceptance recorded.', 'wp-affiliate-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Version', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Document hash', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Result', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $agreements as $ag ) {
				printf( '<tr><td>%s</td><td>%s</td><td><code title="%s">%s…</code></td><td>%s</td></tr>', esc_html( Admin::date( $ag->created_at, true ) ), esc_html( $ag->version ), esc_attr( $ag->doc_hash ), esc_html( substr( $ag->doc_hash, 0, 12 ) ), esc_html( $ag->result ) );
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';



		$parts['overview'][] = ob_get_clean();
		ob_start();
		// Codes.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Partner codes', 'wp-affiliate-captain' ) . '</h2><div class="inside">';
		$codes = CodeRepository::for_affiliate( $id );
		$param = (string) Settings::get( 'referral_param' );
		if ( $codes ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Coupon', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Used', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Status', 'wp-affiliate-captain' ) . '</th><th></th></tr></thead><tbody>';
			$shown   = 0;
			$batches = array();
			foreach ( $codes as $c ) {
				if ( $c->batch ) {
					$batches[ $c->batch ] = isset( $batches[ $c->batch ] ) ? $batches[ $c->batch ] + 1 : 1;
					continue;
				}
				++$shown;
				echo '<tr><td><code>' . esc_html( $c->code ) . '</code><br><small>' . esc_html( add_query_arg( $param, $c->code, home_url( '/' ) ) ) . '</small></td><td>';
				Admin::form_open( 'code_coupon', 'wpac-inline' );
				echo '<input type="hidden" name="code_id" value="' . (int) $c->id . '"><input type="text" name="coupon_code" value="' . esc_attr( $c->coupon_code ) . '" size="10"> <button class="button button-small">' . esc_html__( 'Link', 'wp-affiliate-captain' ) . '</button></form>';
				echo '</td><td>' . (int) $c->usage_count . ( (int) $c->usage_limit ? ' / ' . (int) $c->usage_limit : '' ) . '</td><td>' . esc_html( $c->status ) . ( $c->expires_at ? '<br><small>' . esc_html__( 'until', 'wp-affiliate-captain' ) . ' ' . esc_html( Admin::date( $c->expires_at ) ) . '</small>' : '' ) . '</td><td>';
				Admin::form_open( 'code_status', 'wpac-inline' );
				echo '<input type="hidden" name="code_id" value="' . (int) $c->id . '"><input type="hidden" name="status" value="' . ( 'active' === $c->status ? 'blocked' : 'active' ) . '"><button class="button button-small">' . esc_html( 'active' === $c->status ? __( 'Block', 'wp-affiliate-captain' ) : __( 'Unblock', 'wp-affiliate-captain' ) ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
			foreach ( $batches as $batch => $count ) {
				echo '<p>';
				/* translators: 1: batch, 2: count */
				echo esc_html( sprintf( __( 'Batch %1$s: %2$d codes', 'wp-affiliate-captain' ), $batch, $count ) ) . ' ';
				Admin::form_open( 'code_export', 'wpac-inline' );
				echo '<input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="batch" value="' . esc_attr( $batch ) . '"><button class="button button-small">' . esc_html__( 'Export CSV', 'wp-affiliate-captain' ) . '</button></form></p>';
			}
		}

		echo '<h4>' . esc_html__( 'Add code', 'wp-affiliate-captain' ) . '</h4>';
		Admin::form_open( 'code_create' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<p><label for="wpac-wish-code">' . esc_html__( 'Desired code (optional)', 'wp-affiliate-captain' ) . '</label><br><input type="text" name="code" id="wpac-wish-code"> <label for="wpac-coupon-code" class="screen-reader-text">' . esc_html__( 'Coupon code (optional)', 'wp-affiliate-captain' ) . '</label><input type="text" name="coupon_code" id="wpac-coupon-code" placeholder="' . esc_attr__( 'Coupon code (optional)', 'wp-affiliate-captain' ) . '"></p>';
		echo '<p class="description">' . esc_html__( 'Empty = random code (recommended). Speaking codes like SAILING-NORTH are easy to guess and can be misused for own purchases.', 'wp-affiliate-captain' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Usage limit', 'wp-affiliate-captain' ) . ' <input type="number" name="usage_limit" min="0" value="0" class="small-text"></label> <label>' . esc_html__( 'Valid until', 'wp-affiliate-captain' ) . ' <input type="date" name="expires_at"></label> <button class="button">' . esc_html__( 'Add', 'wp-affiliate-captain' ) . '</button></p></form>';

		echo '<h4>' . esc_html__( 'Generate code batch', 'wp-affiliate-captain' ) . '</h4>';
		Admin::form_open( 'code_batch' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
		echo '<p><label>' . esc_html_x( 'Number', 'count', 'wp-affiliate-captain' ) . ' <input type="number" name="count" min="1" max="1000" value="10" class="small-text"></label> <label>' . esc_html__( 'Prefix', 'wp-affiliate-captain' ) . ' <input type="text" name="prefix" size="8"></label> <label>' . esc_html__( 'Uses per code', 'wp-affiliate-captain' ) . ' <input type="number" name="usage_limit" min="0" value="1" class="small-text"></label> <label>' . esc_html__( 'Valid until', 'wp-affiliate-captain' ) . ' <input type="date" name="expires_at"></label> <button class="button">' . esc_html__( 'Generate', 'wp-affiliate-captain' ) . '</button></p></form>';
		echo '</div></div>';

		$parts['codes'][] = ob_get_clean();
		ob_start();
		// Payout details.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Payout details', 'wp-affiliate-captain' ) . '</h2><div class="inside">';
		$p = ProfileRepository::get( $id );
		if ( ! current_user_can( Capabilities::VIEW_TAX_DATA ) ) {
			echo '<p>' . esc_html( ProfileRepository::is_complete( $p ) ? __( 'Complete.', 'wp-affiliate-captain' ) : __( 'Incomplete.', 'wp-affiliate-captain' ) ) . ' ' . esc_html__( 'You are not allowed to view tax and bank details.', 'wp-affiliate-captain' ) . '</p>';
		} else {
			$v = static function ( $k ) use ( $p ) {
				return $p ? (string) $p->$k : '';
			};
			Admin::form_open( 'profile_update' );
			echo '<input type="hidden" name="id" value="' . (int) $id . '"><table class="form-table">';
			echo '<tr><th>' . esc_html__( 'Tax status', 'wp-affiliate-captain' ) . '</th><td><select name="tax_status"><option value="">—</option>';
			foreach ( ProfileRepository::tax_status_labels() as $k => $l ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $v( 'tax_status' ), $k, false ), esc_html( $l ) );
			}
			echo '</select></td></tr>';
			self::row_input( 'legal_name', __( 'Legal name', 'wp-affiliate-captain' ), $v( 'legal_name' ) );
			echo '<tr><th>' . esc_html__( 'Address', 'wp-affiliate-captain' ) . '</th><td><textarea name="address" rows="3" class="regular-text">' . esc_textarea( $v( 'address' ) ) . '</textarea></td></tr>';
			self::row_input( 'country', __( 'Country', 'wp-affiliate-captain' ), $v( 'country' ), 'maxlength="2"' );
			self::row_input( 'vat_id', __( 'VAT ID', 'wp-affiliate-captain' ), $v( 'vat_id' ) );
			self::row_input( 'account_holder', __( 'Account holder', 'wp-affiliate-captain' ), $v( 'account_holder' ) );
			$masked = $p ? Iban::mask( $p->iban_last4, $p->iban_country ) : '';
			self::row_input( 'iban', __( 'IBAN', 'wp-affiliate-captain' ), '', 'autocomplete="off" placeholder="' . esc_attr( $masked ? $masked . ' – ' . __( 'leave empty to keep', 'wp-affiliate-captain' ) : '' ) . '"' );
			self::row_input( 'bic', __( 'BIC', 'wp-affiliate-captain' ), $v( 'bic' ) );
			echo '</table>';
			submit_button( __( 'Save payout details', 'wp-affiliate-captain' ), 'secondary' );
			echo '</form>';
		}
		echo '</div></div>';

		$parts['payout'][] = ob_get_clean();
		ob_start();
		// Audit.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'History', 'wp-affiliate-captain' ) . '</h2><div class="inside"><ul class="wpac-history">';
		foreach ( Audit::query( array( 'object_type' => 'affiliate', 'object_id' => $id, 'limit' => 20 ) ) as $log ) {
			$user = $log->actor_id ? get_userdata( (int) $log->actor_id ) : null;
			echo '<li><strong>' . esc_html( $log->action ) . '</strong> · ' . esc_html( Admin::date( $log->created_at, true ) ) . ' · ' . esc_html( $user ? $user->display_name : __( 'System', 'wp-affiliate-captain' ) ) . ( $log->details ? '<br><code>' . esc_html( $log->details ) . '</code>' : '' ) . '</li>';
		}
		echo '</ul></div></div>';

		$parts['history'][] = ob_get_clean();
		ob_start();
		self::render_balance( $id );
		$parts['balance'][] = ob_get_clean();
		self::render_tabs( $id, $parts );
		echo '</div>';
	}

	/**
	 * Account statement (balance ledger) with integrity check.
	 *
	 * @param int $id Affiliate.
	 */
	private static function render_balance( $id ) {
		$currencies = \WPAffiliateCaptain\Commissions\Balance::currencies( $id );
		if ( ! $currencies ) {
			echo '<p>' . esc_html__( 'No bookings yet. The account starts with the first approved commission.', 'wp-affiliate-captain' ) . '</p>';
			return;
		}
		$labels = \WPAffiliateCaptain\Commissions\Balance::type_labels();
		foreach ( $currencies as $cur ) {
			$problems = \WPAffiliateCaptain\Commissions\Balance::verify( $id, $cur );
			/* translators: 1: currency, 2: balance */
			echo '<h3>' . esc_html( sprintf( __( 'Account %1$s – balance %2$s', 'wp-affiliate-captain' ), $cur, Money::format( \WPAffiliateCaptain\Commissions\Balance::get( $id, $cur ), $cur ) ) ) . '</h3>';
			if ( $problems ) {
				echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Integrity check failed:', 'wp-affiliate-captain' ) . '</strong></p><ul>';
				foreach ( $problems as $p ) {
					echo '<li>' . esc_html( $p ) . '</li>';
				}
				echo '</ul></div>';
			} else {
				echo '<p class="description">✔ ' . esc_html__( 'Integrity check passed: booking chain intact, balance matches the approved, unpaid commissions.', 'wp-affiliate-captain' ) . '</p>';
			}
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Booking', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Reference', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Amount', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Balance', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
			foreach ( \WPAffiliateCaptain\Commissions\Balance::entries( $id, $cur ) as $e ) {
				$ref = $e->commission_id ? '<a href="' . esc_url( Admin::url( 'wpac-commissions', array( 'action' => 'view', 'id' => $e->commission_id ) ) ) . '">#' . (int) $e->commission_id . '</a> ' : '';
				$ref .= $e->payout_id ? '<a href="' . esc_url( Admin::url( 'wpac-payouts', array( 'action' => 'receipt', 'id' => $e->payout_id ) ) ) . '">' . esc_html( $e->reference ) . '</a>' : esc_html( $e->reference );
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td><td>%s</td></tr>',
					esc_html( Admin::date( $e->created_at, true ) ),
					esc_html( isset( $labels[ $e->type ] ) ? $labels[ $e->type ] : $e->type ),
					$ref, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					(int) $e->amount_minor < 0 ? 'wpac-negative' : '',
					esc_html( Money::format( (int) $e->amount_minor, $cur ) ),
					esc_html( Money::format( (int) $e->balance_minor, $cur ) )
				);
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Tab navigation + content of the partner detail page.
	 *
	 * @param int   $id    Affiliate.
	 * @param array $parts Rendered sections per tab.
	 */
	private static function render_tabs( $id, array $parts ) {
		$tabs = array(
			'overview' => __( 'Overview', 'wp-affiliate-captain' ),
			'profile'  => __( 'Profile', 'wp-affiliate-captain' ),
			'codes'    => __( 'Partner codes', 'wp-affiliate-captain' ),
			'payout'   => __( 'Payout details', 'wp-affiliate-captain' ),
			'balance'  => __( 'Account statement', 'wp-affiliate-captain' ),
			'history'  => __( 'History', 'wp-affiliate-captain' ),
		);
		$tab = Admin::get( 'tab' );
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Partner sections', 'wp-affiliate-captain' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s"%s>%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( Admin::url( 'wpac-affiliates', array( 'action' => 'edit', 'id' => $id, 'tab' => $key ) ) ), $key === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav><div class="wpac-tabs-content">';
		echo implode( '', isset( $parts[ $tab ] ) ? $parts[ $tab ] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sections are escaped while rendering.
		echo '</div>';
	}

	/**
	 * Input row.
	 *
	 * @param string $name  Name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $extra Extra attributes (escaped).
	 */
	private static function row_input( $name, $label, $value, $extra = '' ) {
		$type = false !== strpos( $extra, 'type=' ) ? '' : 'type="text"';
		printf(
			'<tr><th><label for="wpac-%1$s">%2$s</label></th><td><input %5$s id="wpac-%1$s" name="%1$s" value="%3$s" class="regular-text" %4$s></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$type // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Group select row.
	 *
	 * @param int $current Current group.
	 */
	private static function row_group( $current ) {
		echo '<tr><th><label for="wpac-group">' . esc_html__( 'Partner group', 'wp-affiliate-captain' ) . '</label></th><td><select id="wpac-group" name="group_id"><option value="0">—</option>';
		foreach ( Groups::options() as $gid => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $gid, selected( $current, $gid, false ), esc_html( $name ) );
		}
		echo '</select></td></tr>';
	}
}
