<?php
/**
 * Payout runs.
 *
 * - A run collects approved, not yet assigned commissions (incl. negative
 *   adjustments) up to the period end per affiliate and currency.
 * - Partners below the minimum (or with a negative balance) are carried forward.
 * - payout_items has a UNIQUE commission_id → a commission can never be paid twice.
 * - Commissions become "paid" when the payout is marked as paid; cancelling a
 *   payout releases its commissions for the next run.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts;

use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PayoutService {

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'   => __( 'Created', 'wp-affiliate-captain' ),
			'exported'  => __( 'SEPA exported', 'wp-affiliate-captain' ),
			'paid'      => __( 'Paid', 'wp-affiliate-captain' ),
			'cancelled' => __( 'Cancelled', 'wp-affiliate-captain' ),
		);
	}

	/**
	 * Balances available for a run.
	 *
	 * @param string $period_end Y-m-d.
	 * @param string $currency   Currency.
	 * @return array List of [ affiliate, total_minor, count, eligible, reason ].
	 */
	public static function preview( $period_end, $currency ) {
		global $wpdb;
		$t   = Db::table( 'commissions' );
		$end = $period_end . ' 23:59:59';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id, SUM(amount_minor) total, COUNT(*) c FROM {$t} WHERE status = 'approved' AND payout_id = 0 AND currency = %s AND created_at <= %s GROUP BY affiliate_id ORDER BY total DESC", $currency, $end ) );
		$min  = (int) Settings::get( 'min_payout_minor' );
		$out  = array();
		foreach ( $rows as $r ) {
			$aff     = AffiliateRepository::find( (int) $r->affiliate_id );
			$profile = ProfileRepository::get( (int) $r->affiliate_id );
			$total   = (int) $r->total;
			$reason  = '';
			if ( ! $aff ) {
				$reason = __( 'Affiliate not found', 'wp-affiliate-captain' );
			} elseif ( $total <= 0 ) {
				$reason = __( 'Negative balance – carried forward', 'wp-affiliate-captain' );
			} elseif ( $total < $min ) {
				$reason = __( 'Below minimum – carried forward', 'wp-affiliate-captain' );
			} elseif ( ! ProfileRepository::is_complete( $profile ) ) {
				$reason = __( 'Payout profile incomplete', 'wp-affiliate-captain' );
			}
			$out[] = array(
				'affiliate'   => $aff,
				'total_minor' => $total,
				'count'       => (int) $r->c,
				'eligible'    => '' === $reason,
				'reason'      => $reason,
			);
		}
		return $out;
	}

	/**
	 * Create payouts for all eligible affiliates.
	 *
	 * @param string $period_end Y-m-d.
	 * @param string $currency   Currency.
	 * @return array [ created payout IDs, skipped count ]
	 */
	public static function create_run( $period_end, $currency ) {
		$created = array();
		$skipped = 0;
		foreach ( self::preview( $period_end, $currency ) as $row ) {
			if ( ! $row['eligible'] ) {
				++$skipped;
				continue;
			}
			try {
				$id = self::create_for_affiliate( (int) $row['affiliate']->id, $period_end, $currency );
				if ( $id ) {
					$created[] = $id;
				} else {
					++$skipped;
				}
			} catch ( \Throwable $e ) {
				++$skipped;
				Audit::log( 'payout.failed', 'affiliate', (int) $row['affiliate']->id, array( 'error' => $e->getMessage() ) );
			}
		}
		Audit::log(
			'payout.run',
			'payout',
			0,
			array(
				'period_end' => $period_end,
				'currency'   => $currency,
				'created'    => count( $created ),
				'skipped'    => $skipped,
			)
		);
		return array( $created, $skipped );
	}

	/**
	 * Create one payout (transactional, locks the commission rows).
	 *
	 * @param int    $affiliate_id Affiliate.
	 * @param string $period_end   Y-m-d.
	 * @param string $currency     Currency.
	 * @return int Payout ID or 0 when nothing is payable.
	 */
	public static function create_for_affiliate( $affiliate_id, $period_end, $currency ) {
		global $wpdb;
		return Db::transaction(
			static function () use ( $wpdb, $affiliate_id, $period_end, $currency ) {
				$t = Db::table( 'commissions' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, amount_minor FROM {$t} WHERE affiliate_id = %d AND status = 'approved' AND payout_id = 0 AND currency = %s AND created_at <= %s FOR UPDATE", $affiliate_id, $currency, $period_end . ' 23:59:59' ) );
				$total = 0;
				foreach ( $rows as $r ) {
					$total += (int) $r->amount_minor;
				}
				if ( ! $rows || $total <= 0 || $total < (int) Settings::get( 'min_payout_minor' ) ) {
					return 0;
				}
				$profile = ProfileRepository::get( $affiliate_id );
				$ok      = $wpdb->insert(
					Db::table( 'payouts' ),
					array(
						'affiliate_id'     => (int) $affiliate_id,
						'period_end'       => $period_end,
						'currency'         => $currency,
						'amount_minor'     => $total,
						'status'           => 'pending',
						'profile_snapshot' => wp_json_encode( ProfileRepository::snapshot( $profile ) ),
						'created_by'       => get_current_user_id(),
						'created_at'       => Db::now(),
					)
				);
				if ( ! $ok ) {
					throw new IntegrationException( 'db_payout', $wpdb->last_error );
				}
				$payout_id = (int) $wpdb->insert_id;
				$number    = sprintf( 'AC-%s-%05d', substr( $period_end, 0, 4 ), $payout_id );
				$wpdb->update( Db::table( 'payouts' ), array( 'number' => $number ), array( 'id' => $payout_id ) );

				foreach ( $rows as $r ) {
					$ok = $wpdb->insert(
						Db::table( 'payout_items' ),
						array(
							'payout_id'     => $payout_id,
							'commission_id' => (int) $r->id,
							'amount_minor'  => (int) $r->amount_minor,
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'double_payout', 'Commission ' . $r->id . ' is already part of a payout.' );
					}
					$wpdb->update( $t, array( 'payout_id' => $payout_id ), array( 'id' => (int) $r->id ) );
					Ledger::event( (int) $r->id, 'payout_assigned', 'approved', 'approved', (int) $r->amount_minor, $number );
				}
				Audit::log(
					'payout.created',
					'payout',
					$payout_id,
					array(
						'number' => $number,
						'amount' => $total,
					)
				);
				return $payout_id;
			}
		);
	}

	/**
	 * Mark a payout as paid → commissions become "paid".
	 *
	 * @param int $id Payout.
	 * @return true|\WP_Error
	 */
	public static function mark_paid( $id ) {
		global $wpdb;
		$p = self::find( $id );
		if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
			return new \WP_Error( 'wpac_state', __( 'This payout cannot be marked as paid.', 'wp-affiliate-captain' ) );
		}
		$wpdb->update(
			Db::table( 'payouts' ),
			array(
				'status'  => 'paid',
				'paid_at' => Db::now(),
			),
			array( 'id' => (int) $id )
		);
		foreach ( self::items( $id ) as $item ) {
			Ledger::transition( (int) $item->commission_id, 'paid', $p->number, get_current_user_id(), (int) $id );
		}
		Audit::log( 'payout.paid', 'payout', (int) $id, array( 'number' => $p->number ) );
		do_action( 'wpac_payout_paid', (int) $id );
		return true;
	}

	/**
	 * Cancel a payout that was not paid yet; commissions return to the pool.
	 *
	 * An exported payout (SEPA file created) may already have been sent to the bank;
	 * it can only be cancelled with an explicit confirmation that the transfer was not executed.
	 *
	 * @param int    $id                Payout.
	 * @param string $reason            Reason.
	 * @param bool   $confirm_not_sent  Confirmation for exported payouts.
	 * @return true|\WP_Error
	 */
	public static function cancel( $id, $reason, $confirm_not_sent = false ) {
		global $wpdb;
		$p = self::find( $id );
		if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
			return new \WP_Error( 'wpac_state', __( 'Only unpaid payouts can be cancelled.', 'wp-affiliate-captain' ) );
		}
		if ( 'exported' === $p->status && ! $confirm_not_sent ) {
			/* translators: %s: payout number */
			return new \WP_Error( 'wpac_exported', sprintf( __( 'Payout %s was already exported to SEPA. Confirm that the bank did not execute the transfer before cancelling – otherwise the commissions could be paid twice.', 'wp-affiliate-captain' ), $p->number ) );
		}
		if ( '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		Db::transaction(
			static function () use ( $wpdb, $id, $p, $reason ) {
				foreach ( self::items( $id ) as $item ) {
					$wpdb->update( Db::table( 'commissions' ), array( 'payout_id' => 0 ), array( 'id' => (int) $item->commission_id ) );
					Ledger::event( (int) $item->commission_id, 'payout_released', 'approved', 'approved', (int) $item->amount_minor, $p->number . ': ' . $reason );
				}
				$wpdb->delete( Db::table( 'payout_items' ), array( 'payout_id' => (int) $id ) );
				$wpdb->update(
					Db::table( 'payouts' ),
					array(
						'status' => 'cancelled',
						'note'   => substr( sanitize_text_field( $reason ), 0, 255 ),
					),
					array( 'id' => (int) $id )
				);
			}
		);
		Audit::log(
			'payout.cancelled',
			'payout',
			(int) $id,
			array(
				'reason'        => $reason,
				'was_exported'  => 'exported' === $p->status,
			)
		);
		return true;
	}

	/**
	 * Build a SEPA file for payouts and mark them exported.
	 *
	 * Already exported payouts are only included again with $allow_reexport
	 * (e.g. the bank rejected the file) to avoid sending the same transfers twice.
	 * Payouts whose partner changed bank details after the payout was created are refused.
	 *
	 * @param int[] $ids            Payout IDs.
	 * @param bool  $allow_reexport Include payouts that were already exported.
	 * @return array|\WP_Error [ filename, xml ]
	 */
	public static function sepa_export( array $ids, $allow_reexport = false ) {
		global $wpdb;
		$debtor = array(
			'name' => (string) Settings::get( 'debtor_name' ),
			'iban' => (string) Settings::get( 'debtor_iban' ),
			'bic'  => (string) Settings::get( 'debtor_bic' ),
		);
		if ( '' === $debtor['name'] || '' === $debtor['iban'] ) {
			return new \WP_Error( 'wpac_debtor', __( 'Please enter the account holder and IBAN of your company account in the settings first.', 'wp-affiliate-captain' ) );
		}
		$transfers = array();
		$prefix    = (string) Settings::get( 'remittance_prefix' );
		$used      = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$p = self::find( $id );
			if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
				continue;
			}
			if ( 'exported' === $p->status && ! $allow_reexport ) {
				/* translators: %s: payout number */
				return new \WP_Error( 'wpac_reexport', sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'wp-affiliate-captain' ), $p->number ) );
			}
			if ( 'EUR' !== $p->currency ) {
				return new \WP_Error( 'wpac_currency', __( 'SEPA transfers are only possible in EUR.', 'wp-affiliate-captain' ) );
			}
			$profile = ProfileRepository::get( (int) $p->affiliate_id );
			$iban    = ProfileRepository::iban( $profile );
			if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
				/* translators: %s: payout number */
				return new \WP_Error( 'wpac_profile_changed', sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'wp-affiliate-captain' ), $p->number ) );
			}
			if ( ! $iban ) {
				/* translators: %s: payout number */
				return new \WP_Error( 'wpac_iban', sprintf( __( 'Bank details for payout %s are missing or cannot be decrypted.', 'wp-affiliate-captain' ), $p->number ) );
			}
			$transfers[] = array(
				'end_to_end_id' => $p->number,
				'name'          => $profile->account_holder,
				'iban'          => $iban,
				'bic'           => $profile->bic,
				'amount_minor'  => (int) $p->amount_minor,
				'remittance'    => trim( $prefix . ' ' . $p->number ),
			);
			$used[] = (int) $p->id;
		}
		if ( ! $transfers ) {
			return new \WP_Error( 'wpac_empty', __( 'No exportable payouts selected.', 'wp-affiliate-captain' ) );
		}
		$batch = 'ACSEPA' . gmdate( 'YmdHis' );
		try {
			$xml = SepaXml::build( $debtor, $transfers, $batch, gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wpac_sepa', $e->getMessage() );
		}
		foreach ( $used as $id ) {
			$wpdb->update(
				Db::table( 'payouts' ),
				array(
					'status'      => 'exported',
					'sepa_batch'  => $batch,
					'exported_at' => Db::now(),
				),
				array( 'id' => $id )
			);
		}
		Audit::log(
			'payout.sepa_exported',
			'payout',
			0,
			array(
				'batch'   => $batch,
				'payouts' => $used,
			)
		);
		return array( $batch . '.xml', $xml );
	}

	/**
	 * Find.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Items with commission data.
	 *
	 * @param int $id Payout.
	 * @return array
	 */
	public static function items( $id ) {
		global $wpdb;
		$i = Db::table( 'payout_items' );
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT i.*, c.source, c.source_order_id, c.description, c.kind, c.created_at AS commission_date FROM {$i} i LEFT JOIN {$c} c ON c.id = i.commission_id WHERE i.payout_id = %d ORDER BY i.id ASC", $id ) );
	}

	/**
	 * List payouts.
	 *
	 * @param array $args affiliate_id, status, limit, offset.
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t      = Db::table( 'payouts' );
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['affiliate_id'] ) ) {
			$where   .= ' AND affiliate_id = %d';
			$params[] = (int) $args['affiliate_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total    = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) );
		return array( $rows, $total );
	}
}
