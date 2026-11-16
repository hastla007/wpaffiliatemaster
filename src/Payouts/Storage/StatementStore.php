<?php
/**
 * Storage of credit note and cancellation PDFs.
 *
 * Every read, write and deletion of a stored document goes through the active store
 * (Statement::store()): the local folder (LocalStore, default) or S3-compatible object storage
 * (S3Store, configured with WPAC_S3_* constants in wp-config.php). A custom store can be supplied
 * with the filter wpac_statement_store.
 *
 * Names are the plain file names stored in the payouts table (statement_file / storno_file);
 * a store never accepts a path.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts\Storage;

defined( 'ABSPATH' ) || exit;

interface StatementStore {

	/**
	 * Short identifier: "local", "s3" or the identifier of a custom store.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Description for the admin (location without any secret).
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Store a document (private, never publicly readable).
	 *
	 * @param string $name  File name.
	 * @param string $bytes Content.
	 * @return bool Whether it was stored.
	 */
	public function put( $name, $bytes );

	/**
	 * Content of a stored document.
	 *
	 * @param string $name File name.
	 * @return string|null Content, null when it is missing or could not be read (see last_error()).
	 */
	public function get( $name );

	/**
	 * Whether a document is stored.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public function exists( $name );

	/**
	 * Delete a document. Copies in earlier storage locations that may be shared with others are
	 * only deleted when their content has the given hash.
	 *
	 * @param string $name File name.
	 * @param string $hash SHA-256 of the document ('' = unknown).
	 * @return bool True when the document is gone (also when it was never there).
	 */
	public function delete( $name, $hash = '' );

	/**
	 * Names of the documents stored before a point in time (clean-up of a deleted site's documents
	 * after the retention period, left-over probe objects).
	 *
	 * @param int $timestamp Unix time.
	 * @return string[]
	 */
	public function list_older_than( $timestamp );

	/**
	 * Reason of the last failure (no secrets), '' when the last operation worked.
	 *
	 * @return string
	 */
	public function last_error();
}
