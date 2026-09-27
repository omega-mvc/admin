<?php
/**
 * A canary test whose only job is to prove the runner starts and reports.
 *
 * It asserts nothing about the plugin. The real tests arrive with the WordPress
 * bootstrap; without at least one test file Pest refuses to produce a coverage
 * or type coverage report at all.
 *
 * @package AdminSuite
 */

declare( strict_types=1 );

it( 'runs', function (): void {
	expect( PHP_VERSION_ID )->toBeGreaterThan( 0 );
} );
