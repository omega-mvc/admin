<?php
/**
 * Decides which wp-admin screens the suite renders, and retires the old entry.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Mounts the Vue application on WordPress's own dashboard.
 *
 * The suite does not add a page to wp-admin: it takes over `index.php` in
 * every admin area and mounts on `#dashboard-widgets-wrap`, the element
 * `wp-admin/index.php` already prints. That element is what makes the takeover
 * possible without output buffering. `wp_dashboard()` is invoked inline rather
 * than through a `do_action()`, so there is no hook to detach the core widgets
 * with, while `createApp().mount()` replaces an element's contents and keeps
 * the element. The `<h1>`, the admin bar, the notices and the wp-admin menu all
 * stay where WordPress puts them, and if the bundle ever fails to boot the real
 * dashboard is still on screen.
 */
final class AdminShell {

	/**
	 * Slug of the retired `admin.php?page=` entry, kept only to redirect it.
	 */
	private const LEGACY_SLUG = 'admin-suite';

	/**
	 * Register the shell hooks.
	 */
	public function register(): void {
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
		add_action( 'init', array( $this, 'retireLegacyEntry' ) );
	}

	/**
	 * Whether the given screen is rendered by the suite.
	 *
	 * `admin_enqueue_scripts` hands over a *hook suffix*, whereas
	 * `admin_body_class` passes nothing and has to be answered from the current
	 * screen. Both spellings are accepted, which is why `dashboardIds()` lists
	 * the hook suffix and the screen ids together.
	 *
	 * @param string|null $hookSuffix Optional. Screen hook suffix. When omitted
	 *                                 the current screen is inspected instead.
	 */
	public static function isSuiteScreen( ?string $hookSuffix = null ): bool {
		if ( null !== $hookSuffix ) {
			return in_array( $hookSuffix, self::dashboardIds(), true );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof \WP_Screen && in_array( $screen->id, self::dashboardIds(), true );
	}

	/**
	 * Every name the three dashboards go by.
	 *
	 * `index.php` is the hook suffix on all of them: the suite no longer
	 * registers a menu page, so `wp-admin/admin.php` falls through to
	 * `$hook_suffix = $pagenow` and every dashboard answers `index.php`. The
	 * other three are the screen ids `AdminContext::screenId()` returns, which is
	 * what the screen lookup sees, and they are derived from it rather than
	 * repeated so the mapping stays in one place.
	 *
	 * @return list<string>
	 */
	private static function dashboardIds(): array {
		return array_merge(
			array( 'index.php' ),
			array_map(
				static fn ( string $context ): string => AdminContext::screenId( $context ),
				AdminContext::all()
			)
		);
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
	 * Send the retired `admin.php?page=admin-suite` entry to the dashboard.
	 *
	 * The suite used to register its own top-level menu page, so bookmarks and
	 * muscle memory still point at it. Left alone WordPress answers 403, and
	 * not from the block that would suggest: `wp-admin/menu.php:375` calls
	 * `user_can_access_admin_page()`, which dies as soon as `$_registered_pages`
	 * has no entry for a page nobody registered. That require happens at
	 * `wp-admin/admin.php:158-163`, which is 22 lines *before* `admin_init` at
	 * line 180, so an `admin_init` hook is already too late to run. `init` fires
	 * from the `wp-load.php` require at line 35 and is early enough to win.
	 */
	public function retireLegacyEntry(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only legacy URL check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::LEGACY_SLUG !== $page ) {
			return;
		}

		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}
}
