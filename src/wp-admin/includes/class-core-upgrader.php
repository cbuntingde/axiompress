<?php
/**
 * Upgrade API: Core_Upgrader class
 *
 * @package WordPress
 * @subpackage Upgrader
 * @since 4.6.0
 */

/**
 * Core class used for updating core.
 *
 * It allows for WordPress to upgrade itself in combination with
 * the wp-admin/includes/update-core.php file.
 *
 * Note: Newly introduced functions and methods cannot be used here.
 * All functions must be present in the previous version being upgraded from
 * as this file is used there too.
 *
 * @since 2.8.0
 * @since 4.6.0 Moved to its own file from wp-admin/includes/class-wp-upgrader.php.
 *
 * @see WP_Upgrader
 */
class Core_Upgrader extends WP_Upgrader {

	/**
	 * Initializes the upgrade strings.
	 *
	 * @since 2.8.0
	 */
	public function upgrade_strings() {
		$this->strings['up_to_date'] = __( 'WordPress is at the latest version.' );
		$this->strings['locked']     = __( 'Another update is currently in progress.' );
		$this->strings['no_package'] = __( 'Update package not available.' );
		/* translators: %s: Package URL. */
		$this->strings['downloading_package']   = sprintf( __( 'Downloading update from %s&#8230;' ), '<span class="code pre">%s</span>' );
		$this->strings['unpack_package']        = __( 'Unpacking the update&#8230;' );
		$this->strings['copy_failed']           = __( 'Could not copy files.' );
		$this->strings['copy_failed_space']     = __( 'Could not copy files. You may have run out of disk space.' );
		$this->strings['start_rollback']        = __( 'Attempting to restore the previous version.' );
		$this->strings['rollback_was_required'] = __( 'Due to an error during updating, WordPress has been restored to your previous version.' );
	}

	/**
	 * Upgrades WordPress core.
	 *
	 * @since 2.8.0
	 *
	 * @global WP_Filesystem_Base $wp_filesystem                WordPress filesystem subclass.
	 * @global callable           $_wp_filesystem_direct_method Filesystem direct method callback.
	 *
	 * @param object $current Response object for whether WordPress is current.
	 * @param array  $args {
	 *     Optional. Arguments for upgrading WordPress core. Default empty array.
	 *
	 *     @type bool $pre_check_md5    Whether to check the file checksums before
	 *                                  attempting the upgrade. Default true.
	 *     @type bool $attempt_rollback Whether to attempt to rollback the chances if
	 *                                  there is a problem. Default false.
	 *     @type bool $do_rollback      Whether to perform this "upgrade" as a rollback.
	 *                                  Default false.
	 * }
	 * @return string|false|WP_Error New WordPress version on success, false or WP_Error on failure.
	 */
	public function upgrade( $current, $args = array() ) {
		return new WP_Error( 'axiompress_core_updates_disabled', 'AxiomPress core is managed by external deployment.' );
	}

	/**
	 * Determines if this WordPress Core version should update to an offered version or not.
	 *
	 * @since 3.7.0
	 *
	 * @param string $offered_ver The offered version, of the format x.y.z.
	 * @return bool True if we should update to the offered version, otherwise false.
	 */
	public static function should_update_to_version( $offered_ver ) {
		return false;
	}

	/**
	 * Compares the disk file checksums against the expected checksums.
	 *
	 * @since 3.7.0
	 *
	 * @global string $wp_version       The WordPress version string.
	 * @global string $wp_local_package Locale code of the package.
	 *
	 * @return bool True if the checksums match, otherwise false.
	 */
	public function check_files() {
		global $wp_version, $wp_local_package;

		$checksums = get_core_checksums( $wp_version, $wp_local_package ?? 'en_US' );

		if ( ! is_array( $checksums ) ) {
			return false;
		}

		foreach ( $checksums as $file => $checksum ) {
			// Skip files which get updated.
			if ( str_starts_with( $file, 'wp-content' ) ) {
				continue;
			}
			if ( ! file_exists( ABSPATH . $file ) || md5_file( ABSPATH . $file ) !== $checksum ) {
				return false;
			}
		}

		return true;
	}
}
