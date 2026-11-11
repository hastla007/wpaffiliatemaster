<?php
/**
 * Admin: audit log.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Admin\Pages;

use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Core\Audit;

defined( 'ABSPATH' ) || exit;

final class AuditPage {

	/**
	 * No actions.
	 *
	 * @param string $do Action.
	 */
	public static function handle( $do ) {}

	/**
	 * Render.
	 */
	public static function render() {
		$per  = 100;
		$rows = Audit::query(
			array(
				'object_type' => sanitize_key( Admin::get( 'object_type' ) ),
				'object_id'   => absint( Admin::get( 'object_id' ) ),
				'limit'       => $per,
				'offset'      => ( Admin::paged() - 1 ) * $per,
			)
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Audit log', 'wp-affiliate-captain' ) . '</h1>';
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac-audit"><input type="text" name="object_type" placeholder="' . esc_attr__( 'Object type', 'wp-affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'object_type' ) ) . '"> <input type="number" name="object_id" placeholder="ID" value="' . esc_attr( Admin::get( 'object_id' ) ) . '" class="small-text"> <button class="button">' . esc_html__( 'Filter', 'wp-affiliate-captain' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'User', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Action', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Object', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Details', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user = $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( Admin::date( $r->created_at, true ) ),
				esc_html( $user ? $user->display_name : __( 'System', 'wp-affiliate-captain' ) ),
				esc_html( $r->action ),
				esc_html( $r->object_type . ( $r->object_id ? ' #' . $r->object_id : '' ) ),
				esc_html( (string) $r->details )
			);
		}
		echo '</tbody></table>';
		if ( count( $rows ) === $per ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( 'paged', Admin::paged() + 1 ) ) . '">' . esc_html__( 'Older entries', 'wp-affiliate-captain' ) . '</a></p>';
		}
		echo '</div>';
	}
}
