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
		$labels = self::labels();
		return isset( $labels[ $action ] ) ? $labels[ $action ] : (string) $action;
	}

	/**
	 * Readable names of the audit actions.
	 *
	 * @return array action => label
	 */
	private static function labels() {
		static $labels = null;
		if ( null === $labels ) {
			$labels = array(
				'affiliate.created'                 => __( 'Partner created', 'affiliate-captain' ),
				'affiliate.updated'                 => __( 'Partner details changed', 'affiliate-captain' ),
				'affiliate.status'                  => __( 'Status changed', 'affiliate-captain' ),
				'affiliate.email_changed'           => __( 'E-mail address changed', 'affiliate-captain' ),
				'affiliate.public_change_requested' => __( 'Change of public details requested', 'affiliate-captain' ),
				'affiliate.public_change_approved'  => __( 'Change of public details approved', 'affiliate-captain' ),
				'affiliate.public_change_rejected'  => __( 'Change of public details rejected', 'affiliate-captain' ),
				'affiliate.user_linked'             => __( 'WordPress user linked', 'affiliate-captain' ),
				'affiliate.user_link_requested'     => __( 'Link to connect the customer account sent', 'affiliate-captain' ),
				'affiliate.user_link_skipped'       => __( 'Account not linked (account with backend rights)', 'affiliate-captain' ),
				'affiliate.user_link_not_sent'      => __( 'Link to connect the customer account not sent', 'affiliate-captain' ),
				'affiliate.imported'                => __( 'Partners imported', 'affiliate-captain' ),
				'program.exported'                  => __( 'Program exported', 'affiliate-captain' ),
				'privacy.retention_anonymised'      => __( 'Data of an ended partnership deleted after the retention period', 'affiliate-captain' ),
				'refund.unknown_items'              => __( 'Refund with unknown items', 'affiliate-captain' ),
				'commission.reopened'               => __( 'Commission reopened', 'affiliate-captain' ),
				'affiliate.user_failed'             => __( 'WordPress user could not be created', 'affiliate-captain' ),
				'agreement.accepted'                => __( 'Terms accepted', 'affiliate-captain' ),
				'agreement.declined'                => __( 'Terms declined', 'affiliate-captain' ),
				'agreement.reminders_started'       => __( 'Terms reminders started', 'affiliate-captain' ),
				'agreement.reminders_sent'          => __( 'Terms reminders sent', 'affiliate-captain' ),
				'attribution.flagged'               => __( 'Sale flagged for review', 'affiliate-captain' ),
				'attribution.manual'                => __( 'Sale assigned manually', 'affiliate-captain' ),
				'attribution.reassigned'            => __( 'Sale reassigned', 'affiliate-captain' ),
				'attribution.self_referral_blocked' => __( 'Self-referral blocked', 'affiliate-captain' ),
				'balance.mismatch'                  => __( 'Account does not match the bookings', 'affiliate-captain' ),
				'code.created'                      => __( 'Code created', 'affiliate-captain' ),
				'code.batch_created'                => __( 'Code batch created', 'affiliate-captain' ),
				'code.coupon_linked'                => __( 'Coupon linked', 'affiliate-captain' ),
				'code.exported'                     => __( 'Codes exported', 'affiliate-captain' ),
				'commission.dunning'                => __( 'Payment request sent', 'affiliate-captain' ),
				'commission.exported'               => __( 'Commissions exported', 'affiliate-captain' ),
				'commission.reinstated'             => __( 'Commission reinstated', 'affiliate-captain' ),
				'commission.repayment'              => __( 'Repayment booked', 'affiliate-captain' ),
				'commission.written_off'            => __( 'Negative balance written off', 'affiliate-captain' ),
				'creative.approved'                 => __( 'Creative approved', 'affiliate-captain' ),
				'creative.archived'                 => __( 'Creative archived', 'affiliate-captain' ),
				'creative.draft'                    => __( 'Creative saved as draft', 'affiliate-captain' ),
				'cron.step_failed'                  => __( 'Scheduled task step failed', 'affiliate-captain' ),
				'group.created'                     => __( 'Group created', 'affiliate-captain' ),
				'group.updated'                     => __( 'Group changed', 'affiliate-captain' ),
				'group.deleted'                     => __( 'Group deleted', 'affiliate-captain' ),
				'job.retry'                         => __( 'Job retried', 'affiliate-captain' ),
				'job.retry_all'                     => __( 'All failed jobs retried', 'affiliate-captain' ),
				'payout.created'                    => __( 'Payout created', 'affiliate-captain' ),
				'payout.run'                        => __( 'Payout run', 'affiliate-captain' ),
				'payout.failed'                     => __( 'Payout failed', 'affiliate-captain' ),
				'payout.paid'                       => __( 'Payout marked as paid', 'affiliate-captain' ),
				'payout.cancelled'                  => __( 'Payout cancelled', 'affiliate-captain' ),
				'payout.rebuilt'                    => __( 'Payout created again', 'affiliate-captain' ),
				'payout.sepa_exported'              => __( 'SEPA file exported', 'affiliate-captain' ),
				'payout.csv_exported'               => __( 'Transfer list exported', 'affiliate-captain' ),
				'payout.paypal_exported'            => __( 'PayPal payouts file exported', 'affiliate-captain' ),
				'payout.termination_exported'       => __( 'Exported payouts of a terminated partner to check', 'affiliate-captain' ),
				'payout_request.reopened'           => __( 'Payout request open again (payout cancelled)', 'affiliate-captain' ),
				'payout_profile.save_failed'        => __( 'Payout details could not be saved', 'affiliate-captain' ),
				'payout_profile.password_refused'   => __( 'Change of bank details refused (password missing or wrong)', 'affiliate-captain' ),
				'payout_profile.updated'            => __( 'Payout details changed', 'affiliate-captain' ),
				'payout_profile.vies_checked'       => __( 'VAT ID checked with VIES', 'affiliate-captain' ),
				'payout_profile.reencrypted'        => __( 'Bank details re-encrypted', 'affiliate-captain' ),
				'payout_request.created'            => __( 'Payout requested', 'affiliate-captain' ),
				'payout_request.processed'          => __( 'Payout request processed', 'affiliate-captain' ),
				'payout_request.rejected'           => __( 'Payout request rejected', 'affiliate-captain' ),
				'privacy.request'                   => __( 'Privacy request', 'affiliate-captain' ),
				'privacy.erased'                    => __( 'Personal data erased', 'affiliate-captain' ),
				'privacy.applicant_erased'          => __( 'Applicant data erased', 'affiliate-captain' ),
				'privacy.profile_deleted'           => __( 'Payout details deleted', 'affiliate-captain' ),
				'privacy.restricted'                => __( 'Processing restricted', 'affiliate-captain' ),
				'privacy.restriction_lifted'        => __( 'Restriction lifted', 'affiliate-captain' ),
				'privacy.retention_expired'         => __( 'Retention period ended', 'affiliate-captain' ),
				'report.batch_restarted'            => __( 'Report mails restarted', 'affiliate-captain' ),
				'report.mail_failed'                => __( 'Report mail failed', 'affiliate-captain' ),
				'report.monthly_admin_sent'         => __( 'Monthly report sent to the operator', 'affiliate-captain' ),
				'report.monthly_partner_batch'      => __( 'Monthly reports sent to partners', 'affiliate-captain' ),
				'rule.created'                      => __( 'Rule created', 'affiliate-captain' ),
				'rule.updated'                      => __( 'Rule changed', 'affiliate-captain' ),
				'rule.deleted'                      => __( 'Rule deleted', 'affiliate-captain' ),
				'schema.stuck'                      => __( 'Database update stuck', 'affiliate-captain' ),
				'settings.updated'                  => __( 'Settings changed', 'affiliate-captain' ),
				'settings.maintenance'              => __( 'Maintenance mode switched', 'affiliate-captain' ),
				'setup.pages_created'               => __( 'Pages created', 'affiliate-captain' ),
				'statement.generated'               => __( 'Credit note created', 'affiliate-captain' ),
				'statement.storno_generated'        => __( 'Cancellation document created', 'affiliate-captain' ),
				'statement.downloaded'              => __( 'Credit note downloaded', 'affiliate-captain' ),
				'statement.storno_downloaded'       => __( 'Cancellation document downloaded', 'affiliate-captain' ),
				'statement.hash_mismatch'           => __( 'Credit note failed the integrity check', 'affiliate-captain' ),
				'statement.write_failed'            => __( 'Credit note could not be written', 'affiliate-captain' ),
				'statement.storno_write_failed'     => __( 'Cancellation document could not be written', 'affiliate-captain' ),
				'statement.retention_deleted'       => __( 'Credit note deleted after the retention period', 'affiliate-captain' ),
				'statement.objection'               => __( 'Objection to a credit note', 'affiliate-captain' ),
				'statement.objection_resolved'      => __( 'Objection handled', 'affiliate-captain' ),
				'statement.moved'                   => __( 'Credit notes moved to the new folder', 'affiliate-captain' ),
				'tier.created'                      => __( 'Bonus tier created', 'affiliate-captain' ),
				'tier.deactivated'                  => __( 'Bonus tier deactivated', 'affiliate-captain' ),
				'tier.month_closed'                 => __( 'Bonus month closed', 'affiliate-captain' ),
				'tier.month_recalculated'           => __( 'Bonus month recalculated', 'affiliate-captain' ),
			);
		}
		return $labels;
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
			'from'         => _x( 'from', 'audit detail: previous value', 'affiliate-captain' ),
			'to'           => _x( 'to', 'audit detail: new value', 'affiliate-captain' ),
			'status'       => __( 'Status', 'affiliate-captain' ),
			'reason'       => __( 'Reason', 'affiliate-captain' ),
			'changed'      => __( 'Changed', 'affiliate-captain' ),
			'count'        => __( 'Number', 'affiliate-captain' ),
			'error'        => __( 'Error', 'affiliate-captain' ),
			'version'      => __( 'Version', 'affiliate-captain' ),
			'amount'       => __( 'Amount', 'affiliate-captain' ),
			'user'         => __( 'User', 'affiliate-captain' ),
			'manual'       => __( 'manually', 'affiliate-captain' ),
			'tax_status'   => __( 'Tax status', 'affiliate-captain' ),
			'iban_changed' => __( 'IBAN changed', 'affiliate-captain' ),
			'partners'     => __( 'Partners', 'affiliate-captain' ),
			'sent'         => __( 'Sent', 'affiliate-captain' ),
			'created'      => __( 'Created', 'affiliate-captain' ),
			'skipped'      => __( 'Skipped', 'affiliate-captain' ),
			'period_end'   => __( 'Period end', 'affiliate-captain' ),
			'currency'     => __( 'Currency', 'affiliate-captain' ),
			'order'        => __( 'Order', 'affiliate-captain' ),
			'payout_method'   => __( 'Payout method', 'affiliate-captain' ),
			'details_changed' => __( 'Payment details changed', 'affiliate-captain' ),
			'mail'            => __( 'E-mail', 'affiliate-captain' ),
			'type'            => __( 'Type', 'affiliate-captain' ),
			'fields'          => __( 'Fields', 'affiliate-captain' ),
			'failed'          => __( 'Failed', 'affiliate-captain' ),
			'tab'             => __( 'Settings section', 'affiliate-captain' ),
			'month'           => __( 'Month', 'affiliate-captain' ),
			'number'          => _x( 'Number', 'document number', 'affiliate-captain' ),
			'payouts'         => __( 'Payouts', 'affiliate-captain' ),
			'moved'           => __( 'Moved', 'affiliate-captain' ),
		);
		// Codes stored in the details, shown by their name (R5-O8).
		$values = array(
			'payout_method' => \WPAffiliateCaptain\Payouts\ProfileRepository::method_labels(),
			'tax_status'    => \WPAffiliateCaptain\Payouts\ProfileRepository::tax_status_labels(),
			'mail'          => array(
				'sent'   => __( 'sent', 'affiliate-captain' ),
				'failed' => __( 'failed', 'affiliate-captain' ),
			),
			'type'          => array(
				'export'   => __( 'Data export', 'affiliate-captain' ),
				'erase'    => __( 'Erasure', 'affiliate-captain' ),
				'restrict' => __( 'Restriction of processing', 'affiliate-captain' ),
				'object'   => __( 'Objection', 'affiliate-captain' ),
			),
		);
		$settings = SettingsPage::setting_labels();
		$status   = \WPAffiliateCaptain\Affiliates\AffiliateRepository::status_labels();
		$parts  = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'hash', 'revision_id', 'page_id' ), true ) ) {
				continue; // Technical evidence; kept in the log, not needed for reading.
			}
			if ( in_array( $key, array( 'tax_status', 'vat_id', 'vat_mode' ), true ) && ! current_user_can( \WPAffiliateCaptain\Core\Capabilities::VIEW_TAX_DATA ) ) {
				$value = '***'; // Tax data only for users with the tax data permission.
			}
			if ( is_bool( $value ) ) {
				$value = $value ? __( 'yes', 'affiliate-captain' ) : __( 'no', 'affiliate-captain' );
			} elseif ( is_array( $value ) ) {
				$list = array_map( 'strval', array_filter( $value, 'is_scalar' ) );
				if ( in_array( $key, array( 'changed', 'fields' ), true ) ) {
					// Setting keys → field names.
					foreach ( $list as $i => $item ) {
						$list[ $i ] = isset( $settings[ $item ] ) ? $settings[ $item ] : $item;
					}
				}
				$value = implode( ', ', $list );
			} elseif ( in_array( $key, array( 'from', 'to', 'status' ), true ) && isset( $status[ (string) $value ] ) ) {
				$value = $status[ (string) $value ];
			} elseif ( isset( $values[ $key ][ (string) $value ] ) ) {
				$value = $values[ $key ][ (string) $value ];
			}
			if ( 0 === $value || '0' === $value ) {
				$value = in_array( $key, array( 'details_changed', 'iban_changed' ), true ) ? __( 'no', 'affiliate-captain' ) : $value;
			} elseif ( ( 1 === $value || '1' === $value ) && in_array( $key, array( 'details_changed', 'iban_changed' ), true ) ) {
				$value = __( 'yes', 'affiliate-captain' );
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
	 * Filters of the list from the request.
	 *
	 * @return array object_type, object_id, action, from, to, order.
	 */
	public static function filters() {
		$date   = static function ( $v ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
		};
		$action = (string) Admin::get( 'audit_action' );
		return array(
			'object_type' => sanitize_key( Admin::get( 'object_type' ) ),
			'object_id'   => absint( Admin::get( 'object_id' ) ),
			'action'      => preg_match( '/^[a-z_]+\.([a-z_]+)?$/', $action ) ? $action : '',
			'from'        => $date( Admin::get( 'from' ) ),
			'to'          => $date( Admin::get( 'to' ) ),
			'order'       => ltrim( trim( sanitize_text_field( (string) Admin::get( 'order' ) ) ), '#' ),
		);
	}

	/**
	 * Object types of the audit log (filter, display).
	 *
	 * @return array key => label
	 */
	public static function object_types() {
		return array(
			'affiliate'  => __( 'Partner', 'affiliate-captain' ),
			'commission' => __( 'Commission', 'affiliate-captain' ),
			'payout'     => __( 'Payout', 'affiliate-captain' ),
			'balance'    => __( 'Partner account', 'affiliate-captain' ),
			'code'       => __( 'Code', 'affiliate-captain' ),
			'order'      => __( 'Order', 'affiliate-captain' ),
			'rule'       => __( 'Rule', 'affiliate-captain' ),
			'group'      => __( 'Group', 'affiliate-captain' ),
			'tier'       => __( 'Bonus tier', 'affiliate-captain' ),
			'creative'   => __( 'Creative', 'affiliate-captain' ),
			'job'        => __( 'Job', 'affiliate-captain' ),
			'report'     => __( 'Report', 'affiliate-captain' ),
			'settings'   => __( 'Settings', 'affiliate-captain' ),
			'setup'      => __( 'Setup', 'affiliate-captain' ),
			'cron'       => __( 'Scheduled tasks', 'affiliate-captain' ),
		);
	}

	/**
	 * Choices of the action filter: each area ("commission.") and each known action.
	 *
	 * @return array value => label
	 */
	private static function action_choices() {
		$out = array();
		foreach ( self::known_actions() as $action ) {
			$area = strstr( $action, '.', true ) . '.';
			if ( ! isset( $out[ $area ] ) ) {
				/* translators: %s: area key, e.g. commission */
				$out[ $area ] = sprintf( __( 'All of "%s"', 'affiliate-captain' ), rtrim( $area, '.' ) );
			}
			$out[ $action ] = '— ' . self::action_label( $action );
		}
		return $out;
	}

	/**
	 * Known action keys, sorted.
	 *
	 * @return string[]
	 */
	private static function known_actions() {
		$keys = array();
		foreach ( array_keys( self::labels() ) as $k ) {
			$keys[] = (string) $k;
		}
		sort( $keys );
		return $keys;
	}

	/**
	 * Render.
	 */
	public static function render() {
		$per     = 100;
		$filters = self::filters();
		$rows    = Audit::query(
			$filters + array(
				'limit'  => $per,
				'offset' => ( Admin::paged() - 1 ) * $per,
			)
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Audit log', 'affiliate-captain' ) . '</h1>';
		echo '<form method="get" class="wpac-filters"><input type="hidden" name="page" value="wpac-audit">';
		echo '<label for="wpac-audit-order">' . esc_html__( 'Order', 'affiliate-captain' ) . '</label> <input type="text" id="wpac-audit-order" name="order" inputmode="numeric" class="small-text" value="' . esc_attr( $filters['order'] ) . '"> ';
		echo '<label for="wpac-audit-action" class="screen-reader-text">' . esc_html__( 'Action', 'affiliate-captain' ) . '</label><select id="wpac-audit-action" name="audit_action"><option value="">' . esc_html__( 'All actions', 'affiliate-captain' ) . '</option>';
		foreach ( self::action_choices() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $filters['action'], $value, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label for="wpac-audit-from">' . esc_html__( 'From', 'affiliate-captain' ) . '</label> <input type="date" id="wpac-audit-from" name="from" value="' . esc_attr( $filters['from'] ) . '"> ';
		echo '<label for="wpac-audit-to">' . esc_html__( 'To', 'affiliate-captain' ) . '</label> <input type="date" id="wpac-audit-to" name="to" value="' . esc_attr( $filters['to'] ) . '"> ';
		echo '<label for="wpac-audit-type">' . esc_html__( 'Object type', 'affiliate-captain' ) . '</label> <select id="wpac-audit-type" name="object_type"><option value="">' . esc_html__( 'All', 'affiliate-captain' ) . '</option>';
		$types = self::object_types();
		if ( '' !== $filters['object_type'] && ! isset( $types[ $filters['object_type'] ] ) ) {
			$types[ $filters['object_type'] ] = $filters['object_type'];
		}
		foreach ( $types as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $filters['object_type'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <label for="wpac-audit-id">' . esc_html__( 'ID (e.g. partner number)', 'affiliate-captain' ) . '</label> <input type="number" min="1" id="wpac-audit-id" name="object_id" value="' . esc_attr( $filters['object_id'] ? (string) $filters['object_id'] : '' ) . '" class="small-text"> <button class="button">' . esc_html__( 'Filter', 'affiliate-captain' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'Order: everything about one order (attribution, commissions, refunds, manual changes). Dates are UTC.', 'affiliate-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'User', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Action', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Object', 'affiliate-captain' ) . '</th><th>' . esc_html__( 'Details', 'affiliate-captain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html( array_filter( $filters ) ? __( 'No entries match this filter.', 'affiliate-captain' ) : __( 'No entries yet. Changes to partners, commissions, payouts and settings are recorded here.', 'affiliate-captain' ) ) . '</td></tr>';
		}
		$types = self::object_types();
		foreach ( $rows as $r ) {
			$user = $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
			printf(
				'<tr><td>%s</td><td>%s</td><td title="%s">%s</td><td>%s</td><td title="%s">%s</td></tr>',
				esc_html( Admin::date( $r->created_at, true ) ),
				esc_html( $user ? $user->display_name : __( 'System', 'affiliate-captain' ) ),
				esc_attr( $r->action ),
				esc_html( self::action_label( $r->action ) ),
				esc_html( ( isset( $types[ $r->object_type ] ) ? $types[ $r->object_type ] : $r->object_type ) . ( $r->object_id ? ' #' . $r->object_id : '' ) ),
				esc_attr( (string) $r->details ),
				esc_html( self::details_text( (string) $r->details ) )
			);
		}
		echo '</tbody></table>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No entries match the filter.', 'affiliate-captain' ) . '</p>';
		}
		if ( count( $rows ) === $per ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( 'paged', Admin::paged() + 1 ) ) . '">' . esc_html__( 'Older entries', 'affiliate-captain' ) . '</a></p>';
		}
		echo '</div>';
	}
}
