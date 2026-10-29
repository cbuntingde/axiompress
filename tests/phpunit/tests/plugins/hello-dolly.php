<?php

/**
 * @group plugins
 *
 * @covers ::hello_dolly_get_lyric
 * @covers ::hello_dolly
 */
class Tests_Plugins_HelloDolly extends WP_UnitTestCase {

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		require_once ABSPATH . 'wp-content/plugins/hello.php';
	}

	public function test_get_lyric_returns_non_empty_string() {
		$lyric = hello_dolly_get_lyric();

		$this->assertIsString( $lyric );
		$this->assertNotSame( '', trim( wp_strip_all_tags( $lyric ) ) );
	}

	public function test_get_lyric_contains_no_html_tags() {
		$lyric = hello_dolly_get_lyric();

		// Lyrics source is a static allowlist with no markup; output must not introduce tags.
		$this->assertSame( wp_strip_all_tags( $lyric ), $lyric );
	}

	public function test_output_is_escaped_admin_notice() {
		ob_start();
		hello_dolly();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<p id="dolly">', $html );
		$this->assertStringContainsString( 'dir="ltr"', $html );
		$this->assertStringNotContainsString( '<script', strtolower( $html ) );
	}

	public function test_file_uses_modern_2026_apis() {
		$path = ABSPATH . 'wp-content/plugins/hello.php';
		$src  = (string) file_get_contents( $path );

		$this->assertStringContainsString( 'declare( strict_types=1 )', $src );
		$this->assertStringContainsString( 'wp_rand(', $src );
		$this->assertStringNotContainsString( 'mt_rand(', $src );
		$this->assertStringContainsString( 'esc_html__', $src );
		$this->assertStringContainsString( 'wp_kses_post(', $src );
	}
}
