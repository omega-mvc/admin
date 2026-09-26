<?php
/**
 * Decides which wp-admin screens are rendered by the SPA shell.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces supported wp-admin screens with the Vue application.
 */
final class AdminShell {

	/**
	 * Menu slug of the SPA entry.
	 */
	public const SLUG = 'admin-suite';

	/**
	 * Register the shell hooks.
	 */
	public function register(): void {
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
		add_action( 'admin_menu', array( $this, 'registerMenu' ), 999 );
	}

	/**
	 * Whether the given screen is rendered by the suite.
	 *
	 * The `page` query parameter holds the menu *slug*, while the value passed to
	 * `admin_enqueue_scripts` is a *hook suffix*. The two are different strings,
	 * so both representations are compared.
	 *
	 * @param string|null $hookSuffix Optional. Screen hook suffix. When omitted
	 *                                 the current request is inspected instead.
	 */
	public static function isSuiteScreen( ?string $hookSuffix = null ): bool {
		if ( null !== $hookSuffix ) {
			return in_array( $hookSuffix, self::hookSuffixes(), true );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::SLUG === $page;
	}

	/**
	 * Every hook suffix WordPress may use for the SPA screen.
	 *
	 * Derived from the slug rather than hardcoded, because the exact suffix
	 * depends on the WP version and on whether the entry has a parent.
	 *
	 * @return list<string>
	 */
	private static function hookSuffixes(): array {
		return array( 'toplevel_page_' . self::SLUG, get_plugin_page_hookname( self::SLUG, '' ) );
	}

	/**
	 * Add a marker class so styles can target the suite layout.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public function bodyClass( string $classes ): string {
		if ( self::isSuiteScreen() ) {
			$classes .= ' admin-suite-shell';
		}

		return $classes;
	}

	/**
	 * Register the top-level SPA entry.
	 */
	public function registerMenu(): void {
		add_menu_page(
			__( 'Admin Suite', 'admin-suite' ),
			__( 'Dashboard', 'admin-suite' ),
			'read',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-dashboard',
			3
		);
	}
	/**
	 * Render the mount point for the Vue application.
	 *
	 * Deliberately empty: `createApp().mount()` replaces the contents of the
	 * element it mounts on, so anything left inside survives only when the
	 * application failed to boot.
	 */
	public function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'admin-suite' ) );
		}

		echo '<div id="admin-suite-root"></div>';
	}
}
