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
use WPAffiliateCaptain\Core\Sequence;
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
	 * FROM/WHERE for commissions payable in a run (alias c).
	 *
	 * - approved and not assigned to a payout, in the payout currency;
	 * - booked up to the period end – corrections (refund/chargeback) are always
	 *   included together with their original commission, even if booked later,
	 *   so a chargeback after the cut-off is never left behind;
	 * - original commissions with corrections still in hold/review are held back.
	 *
	 * @param string $currency     Currency.
	 * @param string $end          Period end (Y-m-d H:i:s).
	 * @param int    $affiliate_id Optional partner.
	 * @return array [ sql, params ]
	 */
	private static function payable_sql( $currency, $end, $affiliate_id = 0 ) {
		$t      = Db::table( 'commissions' );
		$sql    = "FROM {$t} c LEFT JOIN {$t} p ON p.id = c.parent_id
			WHERE c.status = 'approved' AND c.payout_id = 0 AND c.currency = %s
			AND ( c.created_at <= %s OR ( c.parent_id > 0 AND p.created_at <= %s ) )
			AND NOT EXISTS ( SELECT 1 FROM {$t} ch WHERE ch.parent_id = c.id AND ch.status IN ('pending','review') )
			AND ( c.parent_id = 0 OR p.status IN ('approved','paid') )";
		$params = array( $currency, $end, $end );
		if ( $affiliate_id ) {
			$sql     .= ' AND c.affiliate_id = %d';
			$params[] = (int) $affiliate_id;
		}
		return array( $sql, $params );
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
		list( $from, $params ) = self::payable_sql( $currency, $period_end . ' 23:59:59' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.affiliate_id, SUM(c.amount_minor) total, COUNT(*) c {$from} GROUP BY c.affiliate_id ORDER BY total DESC", $params ) );
		$min  = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
		$out  = array();
		foreach ( $rows as $r ) {
			$aff     = AffiliateRepository::find( (int) $r->affiliate_id );
			$profile = ProfileRepository::get( (int) $r->affiliate_id );
			$total   = (int) $r->total;
			$reason  = '';
			if ( ! $aff ) {
				$reason = __( 'Affiliate not found', 'wp-affiliate-captain' );
			} elseif ( null === $min ) {
				/* translators: %s: currency */
				$reason = sprintf( __( 'No reference rate for %s – minimum cannot be checked', 'wp-affiliate-captain' ), $currency );
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
		$payout_id = Db::transaction(
			static function () use ( $wpdb, $affiliate_id, $period_end, $currency ) {
				$t                     = Db::table( 'commissions' );
				list( $from, $params ) = self::payable_sql( $currency, $period_end . ' 23:59:59', $affiliate_id );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.amount_minor {$from} FOR UPDATE", $params ) );
				$total = 0;
				foreach ( $rows as $r ) {
					$total += (int) $r->amount_minor;
				}
				$min = \WPAffiliateCaptain\Core\Fx::min_payout( $currency );
				if ( ! $rows || $total <= 0 || null === $min || $total < $min ) {
					return 0;
				}
				$profile  = ProfileRepository::get( $affiliate_id );
				$snapshot = ProfileRepository::snapshot( $profile );
				// Commission rates are net; VAT is added for domestic VAT-registered partners.
				$vat = Vat::treatment(
					isset( $snapshot['tax_status'] ) ? $snapshot['tax_status'] : '',
					isset( $snapshot['country'] ) ? $snapshot['country'] : '',
					(string) Settings::get( 'operator_country' ),
					(string) Settings::get( 'vat_rate' )
				);
				/**
				 * Filter the VAT treatment of a credit note (mode, rate).
				 *
				 * @param array $vat          Treatment.
				 * @param array $snapshot     Partner tax data snapshot.
				 * @param int   $affiliate_id Partner.
				 */
				$vat       = (array) apply_filters( 'wpac_vat_treatment', $vat, $snapshot, $affiliate_id );
				$vat_minor = Vat::amount( $total, $vat['rate'] );
				$year      = (int) wp_date( 'Y' );
				// Gapless credit note number, allocated inside this transaction.
				$number = Sequence::number( (string) Settings::get( 'credit_prefix' ), 'credit', $year );
				$ok     = $wpdb->insert(
					Db::table( 'payouts' ),
					array(
						'number'           => $number,
						'affiliate_id'     => (int) $affiliate_id,
						'period_end'       => $period_end,
						'currency'         => $currency,
						'net_minor'        => $total,
						'vat_mode'         => $vat['mode'],
						'vat_rate'         => (string) $vat['rate'],
						'vat_minor'        => $vat_minor,
						'amount_minor'     => $total + $vat_minor, // Amount paid out (gross).
						'status'           => 'pending',
						'profile_snapshot' => wp_json_encode( $snapshot ),
						'created_by'       => get_current_user_id(),
						'created_at'       => Db::now(),
					)
				);
				if ( ! $ok ) {
					throw new IntegrationException( 'db_payout', $wpdb->last_error );
				}
				$payout_id = (int) $wpdb->insert_id;

				foreach ( $rows as $r ) {
					$ok = $wpdb->insert(
						Db::table( 'payout_items' ),
						array(
							'payout_id'            => $payout_id,
							'commission_id'        => (int) $r->id,
							'active_commission_id' => (int) $r->id, // UNIQUE: one active payout per commission.
							'amount_minor'         => (int) $r->amount_minor,
						)
					);
					if ( ! $ok ) {
						throw new IntegrationException( 'double_payout', 'Commission ' . $r->id . ' is already part of a payout.' );
					}
					// Compare-and-set: only if still approved and unassigned.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$moved = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET payout_id = %d, updated_at = %s WHERE id = %d AND payout_id = 0 AND status = 'approved'", $payout_id, Db::now(), (int) $r->id ) );
					if ( 1 !== $moved ) {
						throw new IntegrationException( 'commission_changed', 'Commission ' . $r->id . ' changed during the payout run.' );
					}
					Ledger::event( (int) $r->id, 'payout_assigned', 'approved', 'approved', (int) $r->amount_minor, $number );
				}
				\WPAffiliateCaptain\Commissions\Balance::post( (int) $affiliate_id, $currency, 'payout', -$total, 0, $payout_id, $number );
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
		if ( $payout_id ) {
			Statement::generate( $payout_id );
			// A regular run also settles an open payout request of the partner.
			PayoutRequests::fulfil( (int) $affiliate_id, (int) $payout_id );
		}
		return $payout_id;
	}

	/**
	 * Lock and read a payout inside a transaction.
	 *
	 * @param int $id Payout.
	 * @return object|null
	 */
	private static function lock( $id ) {
		global $wpdb;
		$t = Db::table( 'payouts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d FOR UPDATE", $id ) );
	}

	/**
	 * Mark a payout as paid → commissions become "paid". Atomic: either the
	 * payout and all its commissions change, or nothing does.
	 *
	 * @param int $id Payout.
	 * @return true|\WP_Error
	 */
	public static function mark_paid( $id ) {
		global $wpdb;
		try {
			$paid = Db::transaction(
				static function () use ( $wpdb, $id ) {
					$p = self::lock( $id );
					if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
						throw new IntegrationException( 'state', 'not payable' );
					}
					$t = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'paid', paid_at = %s WHERE id = %d AND status IN ('pending','exported')", Db::now(), $id ) );
					if ( 1 !== $rows ) {
						throw new IntegrationException( 'state', 'changed' );
					}
					$ids = array();
					foreach ( self::items( $id, true ) as $item ) {
						if ( ! Ledger::transition( (int) $item->commission_id, 'paid', $p->number, get_current_user_id(), (int) $id, 'approved', false ) ) {
							throw new IntegrationException( 'commission_changed', 'Commission ' . $item->commission_id . ' could not be marked as paid.' );
						}
						$ids[] = (int) $item->commission_id;
					}
					return array( $p, $ids );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_state', __( 'This payout cannot be marked as paid.', 'wp-affiliate-captain' ) );
		}
		list( $p, $ids ) = $paid;
		Audit::log( 'payout.paid', 'payout', (int) $id, array( 'number' => $p->number ) );
		foreach ( $ids as $cid ) {
			do_action( 'wpac_commission_paid', $cid );
		}
		do_action( 'wpac_payout_paid', (int) $id );
		return true;
	}

	/**
	 * Cancel a payout that was not paid yet; commissions return to the pool.
	 * Payout items are kept (marked as released) for the audit trail.
	 *
	 * An exported payout (SEPA file created) may already have been sent to the bank;
	 * it can only be cancelled with an explicit confirmation that the transfer was not executed.
	 *
	 * @param int    $id               Payout.
	 * @param string $reason           Reason.
	 * @param bool   $confirm_not_sent Confirmation for exported payouts.
	 * @return true|\WP_Error
	 */
	public static function cancel( $id, $reason, $confirm_not_sent = false ) {
		global $wpdb;
		if ( '' === trim( (string) $reason ) ) {
			return new \WP_Error( 'wpac_reason_required', __( 'Please enter a reason.', 'wp-affiliate-captain' ) );
		}
		try {
			$p = Db::transaction(
				static function () use ( $wpdb, $id, $reason, $confirm_not_sent ) {
					$p = self::lock( $id );
					if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
						throw new IntegrationException( 'state', __( 'Only unpaid payouts can be cancelled.', 'wp-affiliate-captain' ) );
					}
					if ( 'exported' === $p->status && ! $confirm_not_sent ) {
						/* translators: %s: payout number */
						throw new IntegrationException( 'exported', sprintf( __( 'Payout %s was already exported to SEPA. Confirm that the bank did not execute the transfer before cancelling – otherwise the commissions could be paid twice.', 'wp-affiliate-captain' ), $p->number ) );
					}
					$pt = Db::table( 'payouts' );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'cancelled', note = %s WHERE id = %d AND status = %s", substr( sanitize_text_field( $reason ), 0, 255 ), $id, $p->status ) );
					if ( 1 !== $rows ) {
						throw new IntegrationException( 'state', __( 'Only unpaid payouts can be cancelled.', 'wp-affiliate-captain' ) );
					}
					// A credit note that was issued is cancelled by its own document with a new number.
					if ( '' !== (string) $p->statement_file || '' !== (string) $p->statement_hash ) {
						$storno = Sequence::number( (string) Settings::get( 'storno_prefix' ), 'storno', (int) wp_date( 'Y' ) );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET storno_number = %s, storno_at = %s WHERE id = %d", $storno, Db::now(), $id ) );
					}
					foreach ( self::items( $id, true ) as $item ) {
						Ledger::event( (int) $item->commission_id, 'payout_released', 'approved', 'approved', (int) $item->amount_minor, $p->number . ': ' . $reason );
					}
					$released = 0;
					foreach ( self::items( $id, true ) as $item ) {
						$released += (int) $item->amount_minor;
					}
					\WPAffiliateCaptain\Commissions\Balance::post( (int) $p->affiliate_id, $p->currency, 'payout_cancelled', $released, 0, (int) $id, $p->number );
					$it = Db::table( 'payout_items' );
					$ct = Db::table( 'commissions' );
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE {$it} SET released_at = %s, active_commission_id = NULL WHERE payout_id = %d AND released_at IS NULL", Db::now(), $id ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = 0, updated_at = %s WHERE payout_id = %d AND status = 'approved'", Db::now(), $id ) );
					// phpcs:enable
					return $p;
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		Statement::generate_storno( (int) $id );
		Audit::log(
			'payout.cancelled',
			'payout',
			(int) $id,
			array(
				'reason'       => $reason,
				'was_exported' => 'exported' === $p->status,
			)
		);
		return true;
	}

	/**
	 * Build a SEPA file for payouts and mark them exported (atomically).
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
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'wpac_empty', __( 'No exportable payouts selected.', 'wp-affiliate-captain' ) );
		}
		try {
			$result = Db::transaction(
				static function () use ( $wpdb, $ids, $allow_reexport, $debtor ) {
					$transfers = array();
					$prefix    = (string) Settings::get( 'remittance_prefix' );
					$used      = array();
					foreach ( $ids as $id ) {
						$p = self::lock( $id ); // Serializes concurrent exports and cancellations.
						if ( ! $p || ! in_array( $p->status, array( 'pending', 'exported' ), true ) ) {
							continue;
						}
						if ( 'exported' === $p->status && ! $allow_reexport ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'reexport', sprintf( __( 'Payout %s was already exported. Tick "export again" only if the bank did not process the previous file.', 'wp-affiliate-captain' ), $p->number ) );
						}
						if ( 'EUR' !== $p->currency ) {
							throw new IntegrationException( 'currency', __( 'SEPA transfers are only possible in EUR.', 'wp-affiliate-captain' ) );
						}
						$profile = ProfileRepository::get( (int) $p->affiliate_id );
						if ( $profile && strtotime( $profile->updated_at . ' UTC' ) > strtotime( $p->created_at . ' UTC' ) ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'profile_changed', sprintf( __( 'The partner changed the payout details after payout %s was created. Please verify the change, cancel the payout and create it again.', 'wp-affiliate-captain' ), $p->number ) );
						}
						$iban = ProfileRepository::iban( $profile );
						if ( ! $iban || '' === (string) $p->number ) {
							/* translators: %s: payout number */
							throw new IntegrationException( 'iban', sprintf( __( 'Bank details for payout %s are missing or cannot be decrypted.', 'wp-affiliate-captain' ), $p->number ) );
						}
						$transfers[]          = array(
							'end_to_end_id' => $p->number,
							'name'          => $profile->account_holder,
							'iban'          => $iban,
							'bic'           => $profile->bic,
							'amount_minor'  => (int) $p->amount_minor,
							'remittance'    => trim( $prefix . ' ' . $p->number ),
						);
						$used[ (int) $p->id ] = $p->status;
					}
					if ( ! $transfers ) {
						throw new IntegrationException( 'empty', __( 'No exportable payouts selected.', 'wp-affiliate-captain' ) );
					}
					// Unique message ID, even for two exports in the same second.
					$batch = 'ACSEPA' . gmdate( 'YmdHis' ) . strtoupper( wp_generate_password( 4, false ) );
					try {
						$xml = SepaXml::build( $debtor, $transfers, $batch, SepaXml::next_business_day( time() ) );
					} catch ( \InvalidArgumentException $e ) {
						throw new IntegrationException( 'sepa', $e->getMessage() );
					}
					$pt = Db::table( 'payouts' );
					foreach ( $used as $id => $status ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$rows = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$pt} SET status = 'exported', sepa_batch = %s, exported_at = %s WHERE id = %d AND status = %s", $batch, Db::now(), $id, $status ) );
						if ( 1 !== $rows ) {
							throw new IntegrationException( 'state', 'Payout ' . $id . ' changed during export.' );
						}
					}
					return array( $batch, $xml, array_keys( $used ) );
				}
			);
		} catch ( IntegrationException $e ) {
			return new \WP_Error( 'wpac_' . $e->error_code, $e->getMessage() );
		}
		list( $batch, $xml, $used ) = $result;
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
	 * @param int  $id          Payout.
	 * @param bool $active_only Only items not released by a cancellation.
	 * @return array
	 */
	public static function items( $id, $active_only = false ) {
		global $wpdb;
		$i = Db::table( 'payout_items' );
		$c = Db::table( 'commissions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT i.*, c.source, c.source_order_id, c.description, c.kind, c.created_at AS commission_date FROM {$i} i LEFT JOIN {$c} c ON c.id = i.commission_id WHERE i.payout_id = %d" . ( $active_only ? ' AND i.released_at IS NULL' : '' ) . ' ORDER BY i.id ASC', $id ) );
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
		return \WPAffiliateCaptain\Core\Paged::fetch( $t, $where, $params, $args, array( 'id', 'created_at', 'amount_minor', 'status', 'period_end' ) );
	}
}
