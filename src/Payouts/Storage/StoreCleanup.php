<?php
/**
 * Deleting many documents from a store within a time budget (R15-P2, R15-P3): uninstall, kept
 * credit notes of deleted sites after their retention period. Object storage deletes in batches
 * (S3 DeleteObjects) with a short timeout per request; any other store document by document.
 * What is not deleted is returned, so the caller keeps its record and tries again later.
 *
 * Self-contained (WordPress functions only): uninstall.php uses it without the plugin loaded.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts\Storage;

defined( 'ABSPATH' ) || exit;

final class StoreCleanup {

	/** Timeout of each object storage request during a clean-up (seconds). */
	const TIMEOUT = 5;

	/**
	 * Delete the named documents.
	 *
	 * @param StatementStore $store    Store.
	 * @param string[]       $names    File names.
	 * @param float          $deadline microtime( true ) after which no request is started (0 = none).
	 * @return array{left: string[], error: string} Names not deleted (failed or not reached).
	 */
	public static function delete_names( StatementStore $store, array $names, $deadline = 0 ) {
		$names = array_values( array_unique( array_filter( array_map( 'strval', $names ), 'strlen' ) ) );
		if ( ! $names ) {
			return array(
				'left'  => array(),
				'error' => '',
			);
		}
		if ( $store instanceof S3Store ) {
			$store->set_timeout( self::TIMEOUT );
			$left = $store->delete_many( $names, $deadline );
			return array(
				'left'  => $left,
				'error' => $left ? $store->last_error() : '',
			);
		}
		$left  = array();
		$error = '';
		foreach ( $names as $name ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$left[] = $name;
				$error  = '' !== $error ? $error : 'time budget used up';
				continue;
			}
			if ( ! $store->delete( $name ) ) {
				$left[] = $name;
				$error  = (string) $store->last_error();
			}
		}
		return array(
			'left'  => $left,
			'error' => $left ? ( '' !== $error ? $error : 'not deleted' ) : '',
		);
	}

	/**
	 * Delete every document of a store (its whole location, e.g. the prefix of a deleted site).
	 *
	 * @param StatementStore $store    Store.
	 * @param float          $deadline microtime( true ) after which no request is started (0 = none).
	 * @return array{ok: bool, left: string[], error: string} ok only when the listing worked and
	 *                                                        every document is gone.
	 */
	public static function delete_all( StatementStore $store, $deadline = 0 ) {
		if ( $store instanceof S3Store ) {
			$store->set_timeout( self::TIMEOUT );
		}
		$names = (array) $store->list_older_than( time() + DAY_IN_SECONDS );
		$list  = (string) $store->last_error();
		$res   = self::delete_names( $store, $names, $deadline );
		return array(
			'ok'    => '' === $list && ! $res['left'],
			'left'  => $res['left'],
			'error' => '' !== $list ? $list : $res['error'],
		);
	}
}
