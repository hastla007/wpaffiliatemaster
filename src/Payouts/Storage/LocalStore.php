<?php
/**
 * Default store: a protected folder on this server (behaviour of all earlier versions).
 *
 * Folder: uploads/wpac-statements, or WPAC_STATEMENT_DIR (on a network one subfolder per site),
 * or the folder given by the filter wpac_statement_dir. Created on demand with deny rules
 * (.htaccess) and an index.php. Documents of earlier folders (uploads before WPAC_STATEMENT_DIR
 * was defined, the flat WPAC_STATEMENT_DIR of a network) are still read and deleted.
 *
 * With several app servers the folder must be shared storage mounted on every node – or use
 * object storage (S3Store), which every node reaches.
 *
 * Self-contained (WordPress functions only): uninstall.php uses it without the plugin loaded.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Payouts\Storage;

defined( 'ABSPATH' ) || exit;

final class LocalStore implements StatementStore {

	/** @var string Reason of the last failure. */
	private $error = '';

	/**
	 * Storage folder (created on demand, with its protection files).
	 *
	 * @return string
	 */
	public static function folder() {
		$dir = self::configured();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Configured storage folder (constant, filter or uploads), without creating it.
	 *
	 * @return string
	 */
	public static function configured() {
		if ( defined( 'WPAC_STATEMENT_DIR' ) && '' !== (string) WPAC_STATEMENT_DIR ) {
			$dir = untrailingslashit( (string) WPAC_STATEMENT_DIR );
			if ( is_multisite() ) {
				// All sites of a network share the constant: one subfolder per site, so the documents
				// (and their deletion on uninstall) stay apart. Older files are moved by the v9 migration.
				$dir .= '/site-' . (int) get_current_blog_id();
			}
		} else {
			$upload = wp_upload_dir( null, false );
			$dir    = trailingslashit( $upload['basedir'] ) . 'wpac-statements';
		}
		/**
		 * Folder of the credit notes (e.g. shared storage for several app servers). Use an absolute
		 * path outside the web root; it must be the same on every server.
		 *
		 * @param string $dir Folder.
		 */
		$filtered = (string) apply_filters( 'wpac_statement_dir', $dir );
		return '' !== $filtered ? untrailingslashit( $filtered ) : $dir;
	}

	/**
	 * Whether the folder is a custom one (constant or filter) and not the uploads folder.
	 *
	 * @return bool
	 */
	public static function custom() {
		$upload = wp_upload_dir( null, false );
		return untrailingslashit( self::configured() ) !== trailingslashit( $upload['basedir'] ) . 'wpac-statements';
	}

	/**
	 * Earlier storage folders: the uploads folder (before WPAC_STATEMENT_DIR was defined) and the
	 * flat WPAC_STATEMENT_DIR itself; only those that differ from the current folder.
	 *
	 * @return string[]
	 */
	public static function legacy() {
		$upload = wp_upload_dir( null, false );
		$dirs   = array( trailingslashit( $upload['basedir'] ) . 'wpac-statements' );
		if ( defined( 'WPAC_STATEMENT_DIR' ) && '' !== (string) WPAC_STATEMENT_DIR ) {
			$dirs[] = untrailingslashit( (string) WPAC_STATEMENT_DIR );
		}
		$current = untrailingslashit( self::folder() );
		$out     = array();
		foreach ( array_unique( $dirs ) as $dir ) {
			if ( untrailingslashit( $dir ) !== $current && is_dir( $dir ) ) {
				$out[] = untrailingslashit( $dir );
			}
		}
		return $out;
	}

	/**
	 * Full path of a stored document: the current folder, or an earlier one when the folder was
	 * changed later (e.g. WPAC_STATEMENT_DIR defined after the first credit notes).
	 *
	 * @param string $file Stored file name.
	 * @return string
	 */
	public static function resolve( $file ) {
		$name = basename( (string) $file );
		$path = self::folder() . '/' . $name;
		if ( '' === $name || is_readable( $path ) ) {
			return $path;
		}
		foreach ( self::legacy() as $dir ) {
			if ( is_readable( $dir . '/' . $name ) ) {
				return $dir . '/' . $name;
			}
		}
		return $path;
	}

	/**
	 * Plain file name, '' for nothing usable (empty, hidden files such as .htaccess).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function clean( $name ) {
		$name = basename( (string) $name );
		return '' === $name || '.' === $name[0] ? '' : $name;
	}

	/** {@inheritDoc} */
	public function id() {
		return 'local';
	}

	/** {@inheritDoc} */
	public function label() {
		return self::configured();
	}

	/** {@inheritDoc} */
	public function put( $name, $bytes ) {
		$this->error = '';
		$name        = self::clean( $name );
		if ( '' === $name ) {
			$this->error = 'invalid file name';
			return false;
		}
		$dir = self::folder();
		if ( false === @file_put_contents( $dir . '/' . $name, (string) $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			$last        = error_get_last(); // The cause (disk full, permissions, open_basedir …), hidden by @.
			$this->error = is_array( $last ) ? (string) $last['message'] : 'file could not be written';
			return false;
		}
		return true;
	}

	/** {@inheritDoc} */
	public function get( $name ) {
		$this->error = '';
		$name        = self::clean( $name );
		if ( '' === $name ) {
			return null;
		}
		$path = self::resolve( $name );
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			$this->error = 'file could not be read';
			return null;
		}
		return (string) $bytes;
	}

	/** {@inheritDoc} */
	public function exists( $name ) {
		$name = self::clean( $name );
		return '' !== $name && is_readable( self::resolve( $name ) );
	}

	/** {@inheritDoc} */
	public function delete( $name, $hash = '' ) {
		$this->error = '';
		$name        = self::clean( $name );
		if ( '' === $name ) {
			return true;
		}
		$current = self::folder();
		if ( file_exists( $current . '/' . $name ) ) {
			wp_delete_file( $current . '/' . $name );
		}
		// An earlier folder may be shared (flat WPAC_STATEMENT_DIR of a network): there only the
		// document with this hash is deleted, never another site's file of the same name.
		foreach ( self::legacy() as $dir ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && ( '' === (string) $hash || hash_equals( (string) $hash, (string) hash_file( 'sha256', $path ) ) ) ) {
				wp_delete_file( $path );
			}
		}
		if ( file_exists( $current . '/' . $name ) ) {
			$this->error = 'file could not be deleted';
			return false;
		}
		return true;
	}

	/** {@inheritDoc} */
	public function list_older_than( $timestamp ) {
		$dir = self::configured();
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			$entry = (string) $entry;
			if ( '' === self::clean( $entry ) || 'index.php' === $entry || ! is_file( $dir . '/' . $entry ) ) {
				continue;
			}
			if ( (int) filemtime( $dir . '/' . $entry ) < (int) $timestamp ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/** {@inheritDoc} */
	public function last_error() {
		return $this->error;
	}
}
