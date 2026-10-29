<?php
/**
 * @group axiompress
 */
class Tests_AxiomPress_Updates extends WP_UnitTestCase {
	public function test_discovery_does_not_make_http_requests() {
		$requests = 0;
		$reject   = static function () use ( &$requests ) {
			++$requests;
			return new WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $reject );
		try {
			set_site_transient( 'update_core', (object) array( 'updates' => array( (object) array( 'response' => 'upgrade' ) ) ) );
			wp_version_check( array(), true );
			$this->assertFalse( get_site_transient( 'update_core' ) );
			$this->assertSame( 0, $requests );
			require_once ABSPATH . 'wp-admin/includes/update.php';
			$this->assertFalse( get_core_checksums( '999.0', 'en_US' ) );
			$this->assertSame( 0, $requests );
		} finally {
			remove_filter( 'pre_http_request', $reject );
		}
	}

	public function test_cached_offers_and_forced_automatic_updates_are_rejected() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		set_site_transient(
			'update_core',
			(object) array(
				'updates' => array(
					(object) array(
						'response' => 'autoupdate',
						'current'  => '999.0',
					),
				),
			)
		);
		add_filter( 'auto_update_core', '__return_true' );
		try {
			$this->assertSame( array(), get_core_updates() );
			$this->assertFalse( find_core_auto_update() );
			$this->assertFalse( Core_Upgrader::should_update_to_version( '999.0' ) );
			$this->assertFalse( ( new WP_Automatic_Updater() )->should_update( 'core', (object) array( 'current' => '999.0' ), ABSPATH ) );
		} finally {
			remove_filter( 'auto_update_core', '__return_true' );
			delete_site_transient( 'update_core' );
		}
	}

	public function test_installer_and_low_level_replacement_fail_before_filesystem_access() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update-core.php';
		$result = ( new Core_Upgrader() )->upgrade( (object) array( 'response' => 'reinstall' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'axiompress_core_updates_disabled', $result->get_error_code() );
		$result = update_core( '/nonexistent-package', ABSPATH );
		$this->assertWPError( $result );
		$this->assertSame( 'axiompress_core_updates_disabled', $result->get_error_code() );
	}

	public function test_core_discovery_is_not_scheduled() {
		wp_schedule_event( time(), 'twicedaily', 'wp_version_check' );
		wp_schedule_update_checks();
		$this->assertFalse( wp_next_scheduled( 'wp_version_check' ) );
	}
}
