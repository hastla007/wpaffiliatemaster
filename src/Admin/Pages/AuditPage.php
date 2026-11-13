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
	 * Readable name of an audit action (the technical key for unknown ones).
	 *
	 * @param string $action Action key, e.g. affiliate.status.
	 * @return string
	 */
	public static function action_label( $action ) {
		static $labels = null;
		if ( null === $labels ) {
			$labels = array(
				'affiliate.created'                 => __( 'Partner created', 'wp-affiliate-captain' ),
				'affiliate.updated'                 => __( 'Partner details changed', 'wp-affiliate-captain' ),
				'affiliate.status'                  => __( 'Status changed', 'wp-affiliate-captain' ),
				'affiliate.email_changed'           => __( 'E-mail address changed', 'wp-affiliate-captain' ),
				'affiliate.public_change_requested' => __( 'Change of public details requested', 'wp-affiliate-captain' ),
				'affiliate.public_change_approved'  => __( 'Change of public details approved', 'wp-affiliate-captain' ),
				'affiliate.public_change_rejected'  => __( 'Change of public details rejected', 'wp-affiliate-captain' ),
				'affiliate.user_linked'             => __( 'WordPress user linked', 'wp-affiliate-captain' ),
				'affiliate.user_link_requested'     => __( 'Link to connect the customer account sent', 'wp-affiliate-captain' ),
				'affiliate.user_link_skipped'       => __( 'Account not linked (account with backend rights)', 'wp-affiliate-captain' ),
				'affiliate.user_failed'             => __( 'WordPress user could not be created', 'wp-affiliate-captain' ),
				'agreement.accepted'                => __( 'Terms accepted', 'wp-affiliate-captain' ),
				'agreement.declined'                => __( 'Terms declined', 'wp-affiliate-captain' ),
				'agreement.reminders_started'       => __( 'Terms reminders started', 'wp-affiliate-captain' ),
				'agreement.reminders_sent'          => __( 'Terms reminders sent', 'wp-affiliate-captain' ),
				'attribution.flagged'               => __( 'Sale flagged for review', 'wp-affiliate-captain' ),
				'attribution.manual'                => __( 'Sale assigned manually', 'wp-affiliate-captain' ),
				'attribution.reassigned'            => __( 'Sale reassigned', 'wp-affiliate-captain' ),
				'attribution.self_referral_blocked' => __( 'Self-referral blocked', 'wp-affiliate-captain' ),
				'balance.mismatch'                  => __( 'Account does not match the bookings', 'wp-affiliate-captain' ),
				'code.created'                      => __( 'Code created', 'wp-affiliate-captain' ),
				'code.batch_created'                => __( 'Code batch created', 'wp-affiliate-captain' ),
				'code.coupon_linked'                => __( 'Coupon linked', 'wp-affiliate-captain' ),
				'code.exported'                     => __( 'Codes exported', 'wp-affiliate-captain' ),
				'commission.dunning'                => __( 'Payment request sent', 'wp-affiliate-captain' ),
				'commission.exported'               => __( 'Commissions exported', 'wp-affiliate-captain' ),
				'commission.reinstated'             => __( 'Commission reinstated', 'wp-affiliate-captain' ),
				'commission.repayment'              => __( 'Repayment booked', 'wp-affiliate-captain' ),
				'commission.written_off'            => __( 'Negative balance written off', 'wp-affiliate-captain' ),
				'creative.approved'                 => __( 'Creative approved', 'wp-affiliate-captain' ),
				'creative.archived'                 => __( 'Creative archived', 'wp-affiliate-captain' ),
				'creative.draft'                    => __( 'Creative saved as draft', 'wp-affiliate-captain' ),
				'cron.step_failed'                  => __( 'Scheduled task step failed', 'wp-affiliate-captain' ),
				'group.created'                     => __( 'Group created', 'wp-affiliate-captain' ),
				'group.updated'                     => __( 'Group changed', 'wp-affiliate-captain' ),
				'group.deleted'                     => __( 'Group deleted', 'wp-affiliate-captain' ),
				'job.retry'                         => __( 'Job retried', 'wp-affiliate-captain' ),
				'job.retry_all'                     => __( 'All failed jobs retried', 'wp-affiliate-captain' ),
				'payout.created'                    => __( 'Payout created', 'wp-affiliate-captain' ),
				'payout.run'                        => __( 'Payout run', 'wp-affiliate-captain' ),
				'payout.failed'                     => __( 'Payout failed', 'wp-affiliate-captain' ),
				'payout.paid'                       => __( 'Payout marked as paid', 'wp-affiliate-captain' ),
				'payout.cancelled'                  => __( 'Payout cancelled', 'wp-affiliate-captain' ),
				'payout.rebuilt'                    => __( 'Payout created again', 'wp-affiliate-captain' ),
				'payout.sepa_exported'              => __( 'SEPA file exported', 'wp-affiliate-captain' ),
				'payout.csv_exported'               => __( 'Transfer list exported', 'wp-affiliate-captain' ),
				'payout_profile.updated'            => __( 'Payout details changed', 'wp-affiliate-captain' ),
				'payout_profile.vies_checked'       => __( 'VAT ID checked with VIES', 'wp-affiliate-captain' ),
				'payout_profile.reencrypted'        => __( 'Bank details re-encrypted', 'wp-affiliate-captain' ),
				'payout_request.created'            => __( 'Payout requested', 'wp-affiliate-captain' ),
				'payout_request.processed'          => __( 'Payout request processed', 'wp-affiliate-captain' ),
				'payout_request.rejected'           => __( 'Payout request rejected', 'wp-affiliate-captain' ),
				'privacy.request'                   => __( 'Privacy request', 'wp-affiliate-captain' ),
				'privacy.erased'                    => __( 'Personal data erased', 'wp-affiliate-captain' ),
				'privacy.applicant_erased'          => __( 'Applicant data erased', 'wp-affiliate-captain' ),
				'privacy.profile_deleted'           => __( 'Payout details deleted', 'wp-affiliate-captain' ),
				'privacy.restricted'                => __( 'Processing restricted', 'wp-affiliate-captain' ),
				'privacy.restriction_lifted'        => __( 'Restriction lifted', 'wp-affiliate-captain' ),
				'privacy.retention_expired'         => __( 'Retention period ended', 'wp-affiliate-captain' ),
				'report.batch_restarted'            => __( 'Report mails restarted', 'wp-affiliate-captain' ),
				'report.mail_failed'                => __( 'Report mail failed', 'wp-affiliate-captain' ),
				'report.monthly_admin_sent'         => __( 'Monthly report sent to the operator', 'wp-affiliate-captain' ),
				'report.monthly_partner_batch'      => __( 'Monthly reports sent to partners', 'wp-affiliate-captain' ),
				'rule.created'                      => __( 'Rule created', 'wp-affiliate-captain' ),
				'rule.updated'                      => __( 'Rule changed', 'wp-affiliate-captain' ),
				'rule.deleted'                      => __( 'Rule deleted', 'wp-affiliate-captain' ),
				'schema.stuck'                      => __( 'Database update stuck', 'wp-affiliate-captain' ),
				'settings.updated'                  => __( 'Settings changed', 'wp-affiliate-captain' ),
				'settings.maintenance'              => __( 'Maintenance mode switched', 'wp-affiliate-captain' ),
				'setup.pages_created'               => __( 'Pages created', 'wp-affiliate-captain' ),
				'statement.generated'               => __( 'Credit note created', 'wp-affiliate-captain' ),
				'statement.storno_generated'        => __( 'Cancellation document created', 'wp-affiliate-captain' ),
				'statement.downloaded'              => __( 'Credit note downloaded', 'wp-affiliate-captain' ),
				'statement.storno_downloaded'       => __( 'Cancellation document downloaded', 'wp-affiliate-captain' ),
				'statement.hash_mismatch'           => __( 'Credit note failed the integrity check', 'wp-affiliate-captain' ),
				'statement.write_failed'            => __( 'Credit note could not be written', 'wp-affiliate-captain' ),
				'statement.storno_write_failed'     => __( 'Cancellation document could not be written', 'wp-affiliate-captain' ),
				'statement.retention_deleted'       => __( 'Credit note deleted after the retention period', 'wp-affiliate-captain' ),
				'statement.objection'               => __( 'Objection to a credit note', 'wp-affiliate-captain' ),
				'statement.objection_resolved'      => __( 'Objection handled', 'wp-affiliate-captain' ),
				'statement.moved'                   => __( 'Credit notes moved to the new folder', 'wp-affiliate-captain' ),
				'tier.created'                      => __( 'Bonus tier created', 'wp-affiliate-captain' ),
				'tier.deactivated'                  => __( 'Bonus tier deactivated', 'wp-affiliate-captain' ),
				'tier.month_closed'                 => __( 'Bonus month closed', 'wp-affiliate-captain' ),
				'tier.month_recalculated'           => __( 'Bonus month recalculated', 'wp-affiliate-captain' ),
			);
		}
		return isset( $labels[ $action ] ) ? $labels[ $action ] : (string) $action;
	}

	/**
	 * Readable details of an audit entry ("Key: value; …") instead of raw JSON.
	 *
	 * @param string $json Stored details (JSON).
	 * @return string
	 */
	public static function details_text( $json ) {
		if ( '' === (string) $json ) {
			return '';
		}
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return (string) $json;
		}
		$keys   = array(
			'from'         => __( 'from', 'wp-affiliate-captain' ),
			'to'           => __( 'to', 'wp-affiliate-captain' ),
			'status'       => __( 'Status', 'wp-affiliate-captain' ),
			'reason'       => __( 'Reason', 'wp-affiliate-captain' ),
			'changed'      => __( 'Changed', 'wp-affiliate-captain' ),
			'count'        => __( 'Number', 'wp-affiliate-captain' ),
			'error'        => __( 'Error', 'wp-affiliate-captain' ),
			'version'      => __( 'Version', 'wp-affiliate-captain' ),
			'amount'       => __( 'Amount', 'wp-affiliate-captain' ),
			'user'         => __( 'User', 'wp-affiliate-captain' ),
			'manual'       => __( 'manually', 'wp-affiliate-captain' ),
			'tax_status'   => __( 'Tax status', 'wp-affiliate-captain' ),
			'iban_changed' => __( 'IBAN changed', 'wp-affiliate-captain' ),
			'partners'     => __( 'Partners', 'wp-affiliate-captain' ),
			'sent'         => __( 'Sent', 'wp-affiliate-captain' ),
			'created'      => __( 'Created', 'wp-affiliate-captain' ),
			'skipped'      => __( 'Skipped', 'wp-affiliate-captain' ),
			'period_end'   => __( 'Period end', 'wp-affiliate-captain' ),
			'currency'     => __( 'Currency', 'wp-affiliate-captain' ),
		);
		$status = \WPAffiliateCaptain\Affiliates\AffiliateRepository::status_labels();
		$parts  = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'hash', 'revision_id', 'page_id' ), true ) ) {
				continue; // Technical evidence; kept in the log, not needed for reading.
			}
			if ( is_bool( $value ) ) {
				$value = $value ? __( 'yes', 'wp-affiliate-captain' ) : __( 'no', 'wp-affiliate-captain' );
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			} elseif ( in_array( $key, array( 'from', 'to', 'status' ), true ) && isset( $status[ (string) $value ] ) ) {
				$value = $status[ (string) $value ];
			}
			if ( '' === (string) $value ) {
				continue;
			}
			$label   = isset( $keys[ $key ] ) ? $keys[ $key ] : str_replace( '_', ' ', (string) $key );
			$parts[] = $label . ': ' . $value;
		}
		return implode( '; ', $parts );
	}

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
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac-audit"><label for="wpac-audit-type" class="screen-reader-text">' . esc_html__( 'Object type', 'wp-affiliate-captain' ) . '</label><input type="text" id="wpac-audit-type" name="object_type" placeholder="' . esc_attr__( 'Object type', 'wp-affiliate-captain' ) . '" value="' . esc_attr( Admin::get( 'object_type' ) ) . '"> <label for="wpac-audit-id" class="screen-reader-text">ID</label><input type="number" id="wpac-audit-id" name="object_id" placeholder="ID" value="' . esc_attr( Admin::get( 'object_id' ) ) . '" class="small-text"> <button class="button">' . esc_html__( 'Filter', 'wp-affiliate-captain' ) . '</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'User', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Action', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Object', 'wp-affiliate-captain' ) . '</th><th>' . esc_html__( 'Details', 'wp-affiliate-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user = $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
			printf(
				'<tr><td>%s</td><td>%s</td><td title="%s">%s</td><td>%s</td><td title="%s">%s</td></tr>',
				esc_html( Admin::date( $r->created_at, true ) ),
				esc_html( $user ? $user->display_name : __( 'System', 'wp-affiliate-captain' ) ),
				esc_attr( $r->action ),
				esc_html( self::action_label( $r->action ) ),
				esc_html( $r->object_type . ( $r->object_id ? ' #' . $r->object_id : '' ) ),
				esc_attr( (string) $r->details ),
				esc_html( self::details_text( (string) $r->details ) )
			);
		}
		echo '</tbody></table>';
		if ( count( $rows ) === $per ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( 'paged', Admin::paged() + 1 ) ) . '">' . esc_html__( 'Older entries', 'wp-affiliate-captain' ) . '</a></p>';
		}
		echo '</div>';
	}
}
