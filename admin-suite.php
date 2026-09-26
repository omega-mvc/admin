<?php
/**
 * Plugin Name:       Admin Suite
 * Plugin URI:        https://example.com/admin-suite
 * Description:       Next-generation wp-admin shell built with Vue 3, TypeScript and the WordPress REST API.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Admin Suite
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admin-suite
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const ADMIN_SUITE_FILE    = __FILE__;
const ADMIN_SUITE_DIR     = __DIR__;
const ADMIN_SUITE_VERSION = '0.1.0';

/**
 * REST namespace shared by every controller in the `admin-suite/v1` namespace.
 *
 * Declared once here so the plugin bootstrap can recognise the suite's own
 * REST requests without duplicating the string in each controller.
 */
const ADMIN_SUITE_REST_NAMESPACE = 'admin-suite/v1';

// Function calls are not allowed in `const` expressions, so the URL is defined here.
define( 'ADMIN_SUITE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Boot the plugin.
 *
 * Prefers the Composer autoloader when the plugin is developed as a standalone
 * package, and falls back to a minimal PSR-4 loader so the plugin also works
 * when it is dropped into wp-content/plugins without a vendor/ directory.
 */
( static function (): void {
	$composer = __DIR__ . '/vendor/autoload.php';

	if ( is_readable( $composer ) ) {
		require_once $composer;
	}

	if ( ! class_exists( AdminSuite\Core\Plugin::class ) ) {
		spl_autoload_register(
			static function ( string $className ): void {
				$prefix = 'AdminSuite\\';

				if ( ! str_starts_with( $className, $prefix ) ) {
					return;
				}

				$relative = substr( $className, strlen( $prefix ) );
				$path     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}

	add_action(
		'plugins_loaded',
		static function (): void {
			AdminSuite\Core\Plugin::instance()->boot();
		}
	);
} )();
