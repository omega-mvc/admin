<?php
/**
 * PHPStan bootstrap.
 *
 * Declares the constants that admin-suite.php creates at runtime with
 * `define()`, which static analysis cannot see. This file is never loaded by
 * WordPress; it is referenced from `bootstrapFiles` in phpstan.neon only.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

if ( ! defined( 'ADMIN_SUITE_FILE' ) ) {
	define( 'ADMIN_SUITE_FILE', __DIR__ . '/admin-suite.php' );
}

if ( ! defined( 'ADMIN_SUITE_DIR' ) ) {
	define( 'ADMIN_SUITE_DIR', __DIR__ );
}

if ( ! defined( 'ADMIN_SUITE_URL' ) ) {
	define( 'ADMIN_SUITE_URL', 'http://localhost:8080/wp-content/plugins/admin-suite/' );
}

if ( ! defined( 'ADMIN_SUITE_VERSION' ) ) {
	define( 'ADMIN_SUITE_VERSION', '0.1.0' );
}

if ( ! defined( 'ADMIN_SUITE_REST_NAMESPACE' ) ) {
	define( 'ADMIN_SUITE_REST_NAMESPACE', 'admin-suite/v1' );
}
