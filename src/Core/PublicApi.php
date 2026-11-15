<?php
/**
 * Read and write API for add-ons (contract 1.3.0): thin wrappers over the repositories and
 * services, returning plain arrays with documented, stable fields (docs/CONTRACT.md, "Lese- und
 * Schreib-API (1.3.0)"). Never bank, tax or customer data; of the partner only the e-mail.
 *
 * The functions do not check the current user: they are a PHP API for trusted code. REST or
 * AJAX wrappers of an add-on must check wpac_manage_affiliates (approving before the end of the
 * hold period: wpac_manage_payouts) themselves.
 * Every write is in the audit log with the current user as actor.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Commissions\Ledger;

defined( 'ABSPATH' ) || exit;

final class PublicApi {

	/** Largest page of a list. */
	const MAX_PER_PAGE = 100;

	/** Fields wpac_update_affiliate() accepts ("group" = partner group ID, 0 = none). */
	const AFFILIATE_FIELDS = array( 'name', 'website', 'channel', 'group' );

	/**
	 * One partner.
	 *
	 * @param int $id Partner ID.
	 * @return array|null
	 */
	public static function get_affiliate( $id ) {
		$a = (int) $id > 0 ? AffiliateRepository::find( (int) $id ) : null;
		return $a ? self::affiliate_data( $a ) : null; // Like wpac_get_payout(): null when unknown.
	}

	/**
	 * List partners.
	 *
	 * @param array $args status, group (ID, 0 = without group), search, per_page (≤ 100), page,
	 *                    orderby (id|name|status|created_at), order (asc|desc).
	 * @return array|\WP_Error { items, total, page, per_page }
	 */
	public static function get_affiliates( array $args = array() ) {
		$bad = self::non_scalar( $args, array( 'status', 'group', 'search', 'per_page', 'page', 'orderby', 'order' ) );
		if ( $bad ) {
			return $bad;
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( '' !== $status && ! in_array( $status, AffiliateRepository::STATUSES, true ) ) {
			return self::invalid( 'status' );
		}
		$query = array(
			'status' => $status,
			'search' => isset( $args['search'] ) && is_scalar( $args['search'] ) ? Db::cut( trim( sanitize_text_field( (string) $args['search'] ) ), 100 ) : '',
		);
		if ( isset( $args['group'] ) && '' !== (string) $args['group'] ) {
			$group = self::whole( $args['group'] );
			if ( null === $group ) {
				return self::invalid( 'group' );
			}
			$query['group_id'] = $group;
		}
		$paging = self::paging( $args, array( 'id', 'name', 'status', 'created_at' ) );
		if ( is_wp_error( $paging ) ) {
			return $paging;
		}
		list( $rows, $total ) = AffiliateRepository::query( $query + $paging['query'] );
		return self::page( array_map( array( __CLASS__, 'affiliate_data' ), (array) $rows ), $total, $paging );
	}

	/**
	 * One ledger row (commission, correction, bonus, manual booking …).
	 *
	 * @param int $id Commission ID.
	 * @return array|null
	 */
	public static function get_commission( $id ) {
		$c = (int) $id > 0 ? Ledger::find( (int) $id ) : null;
		return $c ? self::commission_data( $c ) : null; // Like wpac_get_payout(): null when unknown.
	}

	/**
	 * List ledger rows.
	 *
	 * @param array $args affiliate_id, status, kind, from, to (Y-m-d, local days), per_page (≤ 100),
	 *                    page, orderby (id|created_at|amount_minor|status), order.
	 * @return array|\WP_Error { items, total, page, per_page }
	 */
	public static function get_commissions( array $args = array() ) {
		$bad = self::non_scalar( $args, array( 'affiliate_id', 'status', 'kind', 'from', 'to', 'per_page', 'page', 'orderby', 'order' ) );
		if ( $bad ) {
			return $bad;
		}
		$affiliate = 0;
		if ( isset( $args['affiliate_id'] ) && '' !== (string) $args['affiliate_id'] ) {
			// Strict: "-5" or "abc" must not turn into 0 (= all partners).
			$affiliate = self::whole( $args['affiliate_id'] );
			if ( null === $affiliate ) {
				return self::invalid( 'affiliate_id' );
			}
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( '' !== $status && ! in_array( $status, Ledger::STATUSES, true ) ) {
			return self::invalid( 'status' );
		}
		$kind = isset( $args['kind'] ) ? (string) $args['kind'] : '';
		if ( '' !== $kind && ! array_key_exists( $kind, Ledger::kind_labels() ) ) {
			return self::invalid( 'kind' );
		}
		$query = array(
			'affiliate_id' => $affiliate,
			'status'       => $status,
			'kind'         => $kind,
		);
		foreach ( array( 'from', 'to' ) as $key ) {
			$date = isset( $args[ $key ] ) ? (string) $args[ $key ] : '';
			if ( '' !== $date && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ) {
				return self::invalid( $key );
			}
			$query[ $key ] = $date;
		}
		$paging = self::paging( $args, array( 'id', 'created_at', 'amount_minor', 'status' ) );
		if ( is_wp_error( $paging ) ) {
			return $paging;
		}
		// Without a filter the total of the whole table is cached briefly (exact when filtered).
		list( $rows, $total ) = Ledger::query( $query + $paging['query'] + array( 'count' => 'cached' ) );
		return self::page( array_map( array( __CLASS__, 'commission_data' ), (array) $rows ), $total, $paging );
	}

	/**
	 * Change the profile of a partner (same validation as the admin form).
	 *
	 * @param int   $id   Partner ID.
	 * @param array $data name, website, channel, group (see AFFILIATE_FIELDS).
	 * @return array|\WP_Error The partner after the change.
	 */
	public static function update_affiliate( $id, array $data ) {
		if ( ! AffiliateRepository::find( (int) $id ) ) {
			return self::not_found();
		}
		$unknown = array_diff( array_keys( $data ), self::AFFILIATE_FIELDS );
		if ( $unknown ) {
			return new \WP_Error(
				'wpac_invalid_field',
				/* translators: %s: field names */
				sprintf( __( 'These fields cannot be changed: %s', 'affiliate-captain' ), implode( ', ', array_map( 'sanitize_key', array_map( 'strval', $unknown ) ) ) )
			);
		}
		$fields = array();
		foreach ( array( 'name', 'website', 'channel' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				if ( ! is_scalar( $data[ $key ] ) ) {
					return self::invalid( $key );
				}
				$fields[ $key ] = (string) $data[ $key ];
			}
		}
		if ( isset( $fields['name'] ) && '' === trim( sanitize_text_field( $fields['name'] ) ) ) {
			return new \WP_Error( 'wpac_invalid_name', __( 'Please enter a name.', 'affiliate-captain' ) );
		}
		if ( isset( $fields['website'] ) && '' !== trim( $fields['website'] ) && ! preg_match( '#^https?://[^\s/?\#]+#i', esc_url_raw( trim( $fields['website'] ) ) ) ) {
			return self::invalid( 'website' );
		}
		if ( array_key_exists( 'group', $data ) ) {
			$group = $data['group'];
			if ( ! is_numeric( $group ) || (int) $group < 0 || ( (int) $group > 0 && ! Groups::find( (int) $group ) ) ) {
				return self::invalid( 'group' );
			}
			$fields['group_id'] = (int) $group;
		}
		$saved = AffiliateRepository::update( (int) $id, $fields ); // Audited (affiliate.updated).
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return self::get_affiliate( $id );
	}

	/**
	 * Change the status of a partner (approve, pause, terminate, reject, reactivate) with the
	 * same rules, e-mails and follow-up steps as in the admin.
	 *
	 * @param int    $id     Partner ID.
	 * @param string $status pending|active|paused|terminated|rejected.
	 * @param string $reason Reason (required for paused, terminated, rejected).
	 * @return array|\WP_Error The partner after the change.
	 */
	public static function set_affiliate_status( $id, $status, $reason = '' ) {
		if ( ! AffiliateRepository::find( (int) $id ) ) {
			return self::not_found();
		}
		$result = AffiliateRepository::set_status( (int) $id, (string) $status, (string) $reason ); // Audited (affiliate.status).
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::get_affiliate( $id );
	}

	/**
	 * Approve or reject a commission (ledger rules: hold period, review, corrections, payouts).
	 *
	 * Approving a commission under review before the end of its hold period moves it back to
	 * "pending" (released when the hold period ends), as in the admin.
	 *
	 * @param int    $id     Commission ID.
	 * @param string $status approved|rejected.
	 * @param string $reason Reason (required for rejected).
	 * @return array|\WP_Error The commission after the change.
	 */
	public static function set_commission_status( $id, $status, $reason = '' ) {
		$c = (int) $id > 0 ? Ledger::find( (int) $id ) : null;
		if ( ! $c ) {
			return self::not_found();
		}
		$reason = trim( sanitize_text_field( (string) $reason ) );
		if ( 'approved' === $status ) {
			$result = Ledger::approve( (int) $id, '' !== $reason ? $reason : __( 'Approved manually', 'affiliate-captain' ) );
		} elseif ( 'rejected' === $status ) {
			$result = Ledger::reject( (int) $id, $reason );
		} else {
			return self::invalid( 'status' );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$after = Ledger::find( (int) $id );
		Audit::log(
			'commission.status',
			'commission',
			(int) $id,
			array(
				'from'   => (string) $c->status,
				'to'     => $after ? (string) $after->status : (string) $status,
				'reason' => $reason,
				'via'    => 'api',
			)
		);
		return self::get_commission( $id );
	}

	/**
	 * Manual booking (positive or negative), approved at once and payable with the next payout.
	 *
	 * @param int    $affiliate_id Partner ID.
	 * @param int    $amount_minor Amount in minor units (not 0; negative = deduction).
	 * @param string $currency     ISO 4217 code.
	 * @param string $reason       Reason (required, shown to the partner).
	 * @return array|\WP_Error The booking.
	 */
	public static function create_adjustment( $affiliate_id, $amount_minor, $currency, $reason ) {
		if ( ! AffiliateRepository::find( (int) $affiliate_id ) ) {
			return self::not_found();
		}
		if ( ! is_int( $amount_minor ) && ! ( is_string( $amount_minor ) && preg_match( '/^-?\d{1,12}$/', $amount_minor ) ) ) {
			return new \WP_Error( 'wpac_amount', __( 'Please enter an amount other than 0.', 'affiliate-captain' ) );
		}
		if ( ! is_string( $currency ) || ! preg_match( '/^[A-Za-z]{3}$/', $currency ) ) {
			return self::invalid( 'currency' );
		}
		// Audited (commission.manual); wpac_commission_created fires after the COMMIT.
		$id = Ledger::manual_adjustment( (int) $affiliate_id, strtoupper( $currency ), (int) $amount_minor, sanitize_text_field( (string) $reason ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::get_commission( $id );
	}

	/**
	 * Public fields of a partner (never application, bank or tax data).
	 *
	 * @param object $a Partner row.
	 * @return array
	 */
	public static function affiliate_data( $a ) {
		return array(
			'id'            => (int) $a->id,
			'user_id'       => (int) $a->user_id,
			'group_id'      => (int) $a->group_id,
			'status'        => (string) $a->status,
			'status_reason' => 'privacy_erasure' === (string) $a->status_reason ? '' : (string) $a->status_reason,
			'slug'          => (string) $a->slug,
			'name'          => (string) $a->name,
			'email'         => (string) $a->email,
			'website'       => (string) $a->website,
			'channel'       => (string) $a->channel,
			'created_at'    => (string) $a->created_at,
			'updated_at'    => (string) $a->updated_at,
			'approved_at'   => $a->approved_at ? (string) $a->approved_at : null,
		);
	}

	/**
	 * Public fields of a ledger row (no customer data: the ledger stores none).
	 *
	 * @param object $c Commission row.
	 * @return array
	 */
	public static function commission_data( $c ) {
		return array(
			'id'           => (int) $c->id,
			'affiliate_id' => (int) $c->affiliate_id,
			'kind'         => (string) $c->kind,
			'parent_id'    => (int) $c->parent_id,
			'status'       => (string) $c->status,
			'currency'     => (string) $c->currency,
			'amount_minor' => (int) $c->amount_minor,
			'base_minor'   => (int) $c->base_minor,
			'rate_type'    => (string) $c->rate_type,
			'rate'         => (string) $c->rate,
			'rule_id'      => (int) $c->rule_id,
			'source'       => (string) $c->source,
			'order_id'     => (string) $c->source_order_id,
			'item_id'      => (string) $c->source_item_id,
			'product_ref'  => (string) $c->product_ref,
			'description'  => (string) $c->description,
			'hold_until'   => $c->hold_until ? (string) $c->hold_until : null,
			'payout_id'    => (int) $c->payout_id,
			'created_at'   => (string) $c->created_at,
			'updated_at'   => (string) $c->updated_at,
		);
	}

	/**
	 * Paging arguments.
	 *
	 * @param array    $args     Caller arguments.
	 * @param string[] $sortable Allowed orderby columns.
	 * @return array|\WP_Error { page, per_page, query }
	 */
	private static function paging( array $args, array $sortable ) {
		$per_page = isset( $args['per_page'] ) ? self::whole( $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? self::whole( $args['page'] ) : 1;
		if ( null === $per_page || $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return self::invalid( 'per_page' );
		}
		if ( null === $page || $page < 1 ) {
			return self::invalid( 'page' );
		}
		// A page far beyond the end stays beyond it (empty items): (page - 1) * per_page must not
		// overflow into a float that is cut back to page 1.
		$offset = $page - 1 > intdiv( PHP_INT_MAX, $per_page ) - 1 ? PHP_INT_MAX : ( $page - 1 ) * $per_page;
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'id';
		if ( ! in_array( $orderby, $sortable, true ) ) {
			return self::invalid( 'orderby' );
		}
		$order = isset( $args['order'] ) ? strtolower( (string) $args['order'] ) : 'desc';
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			return self::invalid( 'order' );
		}
		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'query'    => array(
				'limit'   => $per_page,
				'offset'  => $offset,
				'orderby' => $orderby,
				'order'   => $order,
			),
		);
	}

	/**
	 * A whole number ≥ 0: integers, or strings of digits only. Everything else (negative
	 * numbers, "abc", "1.5", "1e3", floats, booleans, arrays) is null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function whole( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_string( $value ) && preg_match( '/^\d{1,18}$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}
		return null;
	}

	/**
	 * Error for the first argument that is set but not a scalar (arrays, objects): they would
	 * become "Array" in a query.
	 *
	 * @param array    $args Caller arguments.
	 * @param string[] $keys Arguments to check.
	 * @return \WP_Error|null
	 */
	private static function non_scalar( array $args, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $args[ $key ] ) && ! is_scalar( $args[ $key ] ) ) {
				return self::invalid( $key );
			}
		}
		return null;
	}

	/**
	 * List result.
	 *
	 * @param array $items  Items.
	 * @param int   $total  Total rows.
	 * @param array $paging Paging from paging().
	 * @return array
	 */
	private static function page( array $items, $total, array $paging ) {
		return array(
			'items'    => $items,
			'total'    => (int) $total,
			'page'     => (int) $paging['page'],
			'per_page' => (int) $paging['per_page'],
		);
	}

	/**
	 * Error: object not found.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return new \WP_Error( 'wpac_not_found', __( 'Not found.', 'affiliate-captain' ) );
	}

	/**
	 * Error: invalid argument.
	 *
	 * @param string $field Argument name.
	 * @return \WP_Error
	 */
	private static function invalid( $field ) {
		return new \WP_Error(
			'wpac_invalid_' . $field,
			/* translators: %s: argument name */
			sprintf( __( 'Invalid value for "%s".', 'affiliate-captain' ), $field )
		);
	}
}
