<?php
/**
 * Prevent the repository CLI from installing upstream core packages.
 * Filesystem permissions remain the boundary for independently invoked tools.
 */
foreach ( array( 'core update', 'core download', 'core check-update', 'core verify-checksums' ) as $axiompress_command ) {
	WP_CLI::add_hook(
		'before_invoke:' . $axiompress_command,
		static function () {
			WP_CLI::error( 'AxiomPress core is managed by external deployment; official WordPress core operations are disabled.' );
		}
	);
}
