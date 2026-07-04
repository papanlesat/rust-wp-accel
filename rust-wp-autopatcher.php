<?php
/**
 * Plugin Name: Rust WP Accelerator Auto-Patcher
 * Description: Must-Use plugin to automatically keep WordPress core patched for the Rust Accelerator extension.
 * Version: 1.0.0
 * Author: papanlesat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rust_WP_AutoPatcher {

	private static $functions_file = ABSPATH . 'wp-includes/functions.php';

	public static function init() {
		// Hook runs after WordPress core, plugin, or theme updates
		add_action( 'upgrader_process_complete', [ __CLASS__, 'force_patch' ], 10, 2 );

		// Hook runs on admin pages to periodically check the patch status
		add_action( 'admin_init', [ __CLASS__, 'check_and_patch' ] );
	}

	public static function force_patch( $upgrader_object, $options ) {
		// If it's a core update, force repatch immediately
		if ( isset( $options['type'] ) && $options['type'] === 'core' ) {
			self::apply_patch();
		}
	}

	public static function check_and_patch() {
		// Check transient to avoid heavy disk I/O on every admin load
		if ( false === get_transient( 'rust_wp_autopatcher_status' ) ) {
			self::apply_patch();
			// Set transient to check again in 24 hours
			set_transient( 'rust_wp_autopatcher_status', 'patched', DAY_IN_SECONDS );
		}
	}

	private static function apply_patch() {
		if ( ! file_exists( self::$functions_file ) || ! is_writable( self::$functions_file ) ) {
			return false;
		}

		$content = file_get_contents( self::$functions_file );

		// Check if already patched
		if ( strpos( $content, 'WP_RUST_ACCELERATION_ENABLED' ) !== false ) {
			return true;
		}

		// Alternative target if whitespace differs
		$search_pattern = '/function maybe_unserialize\(\s*\$data\s*\)\s*\{\s*if\s*\(\s*is_serialized\(\s*\$data\s*\)\s*\)\s*\{\s*(?:\/\/.*?\n\s*)?return\s*@unserialize\(\s*trim\(\s*\$data\s*\)\s*\);/s';

		$patch_code = <<<PHP
function maybe_unserialize( \$data ) {
	if ( is_serialized( \$data ) ) { // Don't attempt to unserialize data that wasn't serialized going in.
		if ( defined( 'WP_RUST_ACCELERATION_ENABLED' ) && WP_RUST_ACCELERATION_ENABLED && function_exists( 'fast_maybe_unserialize' ) ) {
			\$trimmed_data = trim( \$data );
			// Cegah objek OOP masuk ke Rust demi keamanan (fallback ke PHP).
			\$is_object = ( is_string( \$trimmed_data ) && preg_match( '/^O:[0-9]+:"/', \$trimmed_data ) );
			
			if ( ! \$is_object ) {
				\$rust_result = fast_maybe_unserialize( \$trimmed_data );
				// Jika Rust mengembalikan nilai yang berbeda dari string input, artinya berhasil dirakit.
				if ( \$rust_result !== \$trimmed_data ) {
					return \$rust_result;
				}
			}
		}

		return @unserialize( trim( \$data ) );
PHP;

		// Perform regex replacement for robust matching
		$new_content = preg_replace( $search_pattern, $patch_code, $content );

		if ( $new_content !== null && $new_content !== $content ) {
			file_put_contents( self::$functions_file, $new_content );
			return true;
		}

		return false;
	}
}

Rust_WP_AutoPatcher::init();
