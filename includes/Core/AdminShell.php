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
 * the element. The notices and the wp-admin menu stay where WordPress puts
 * them, while the `<h1>` is blanked and the admin bar is detached, so the
 * panel is ours from the first pixel. If the bundle ever fails to boot the
 * real dashboard is still on screen.
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
		add_action( 'admin_init', array( $this, 'detachAdminBar' ) );
		add_action( 'admin_head', array( $this, 'removeContextualHelp' ) );
		add_action( 'in_admin_header', array( $this, 'suppressScreenHeading' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'detachWelcomePanel' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'detachNativeWidgets' ) );
	}

	/**
	 * Remove WordPress's own topbar.
	 *
	 * Core registers the renderer on `in_admin_header` at priority 0 in
	 * `default-filters.php:724`, and `WP_Admin_Bar::_render()` is the only
	 * thing in wp-admin that prints the markup: neither `admin-header.php` nor
	 * `admin-footer.php` mentions the bar. Detaching the callback therefore
	 * takes the wrapper, the quicklinks container and every node together.
	 *
	 * The documented way of hiding the bar, the `show_admin_bar` filter, cannot
	 * work on an admin screen. `is_admin_bar_showing()` returns `true` at
	 * `admin-bar.php:1443-1445` for any request where `is_admin()`, which is
	 * before its own `apply_filters()` at `:1465` is reached.
	 *
	 * The band the bar reserved is undone in `style.css` instead. The class
	 * that carries it is built by a local in `_wp_admin_html_begin()`
	 * (`template.php:2661`) and has no filter, so CSS is the only seam left.
	 *
	 * The detachment has to happen before the hook fires rather than during it.
	 * Core's callback is already at priority 0 by then, so doing this from a
	 * later `in_admin_header` callback detaches nothing and still leaves the
	 * markup in the buffer. `admin_init` is the seam: the admin header is
	 * required long after it, and the action is registered by then.
	 */
	public function detachAdminBar(): void {
		remove_action( 'in_admin_header', 'wp_admin_bar_render', 0 );
	}

	/**
	 * Blank the page heading, so the suite is not labelled by WordPress.
	 *
	 * `wp-admin/index.php:141` prints the heading as a bare echo with no filter
	 * around it, so the string has to be emptied rather than intercepted.
	 *
	 * `in_admin_header` is the only seam that works. It fires at
	 * `wp-admin/admin-header.php:277`, which is *inside* the
	 * `require_once admin-header.php` on `index.php:137` and therefore before
	 * line 141. `$title` is set on `index.php:33` and, because the screen file is
	 * included at global scope by `wp-admin/admin.php`, it is a real global.
	 *
	 * Timing also keeps the document title intact: the `<title>` element is written
	 * at `admin-header.php:95`, long before this runs, so only the visible heading
	 * is affected.
	 *
	 * The element itself still exists and is empty. `style.css` collapses it with
	 * an `:empty` rule, which can only ever match a heading with no content in it.
	 */
	public function suppressScreenHeading(): void {
		global $title;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- Blanking core's own heading; see above.
		$title = '';
	}

	/**
	 * Drop the dashboard's contextual help.
	 *
	 * The Help button is drawn by `WP_Screen::render_screen_meta()` at
	 * `wp-admin/admin-header.php:291`, and it is gated on `$screen->get_help_tabs()`
	 * being non-empty — the Screen Options button next to it is gated on
	 * `show_screen_options()` instead, so clearing the tabs removes only the help.
	 *
	 * `admin_head` is the hook to do it on. `wp-admin/index.php` registers the four
	 * dashboard help tabs at lines 41, 54, 67 and 101, all of them before it
	 * requires `admin-header.php` at line 137; `admin_head` fires at
	 * `admin-header.php:168`, which is after the registration and before line 291.
	 * Any earlier hook would run before the tabs exist and any later one would run
	 * after the button is already in the markup.
	 *
	 * `remove_help_tabs()` is used rather than removing the four known ids, so a tab
	 * added by another plugin on this screen goes too, and so this keeps working if
	 * core renumbers them.
	 */
	public function removeContextualHelp(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen ) {
			$screen->remove_help_tabs();
		}
	}

	/**
	 * Stop WordPress from printing the welcome panel above the suite.
	 *
	 * `wp-admin/index.php:174` guards the whole block on `has_action( 'welcome_panel' )`
	 * and then calls `do_action( 'welcome_panel' )` inline at line 197, so there is no
	 * filter to intercept and `remove_action()` against core's own callback would
	 * leave the block in place for any plugin that also hooks it.
	 *
	 * `wp_dashboard_setup()` is the only viable moment. It fires
	 * `do_action( 'wp_dashboard_setup' )` at `wp-admin/includes/dashboard.php:135`,
	 * which is after it has registered `wp_welcome_panel` and before `index.php:174`
	 * reads `has_action()`. `admin_init` is too early: it fires at
	 * `wp-admin/admin.php:180`, before the screen file is loaded at all.
	 *
	 * The panel itself is not lost. `DashboardController` renders it inside the
	 * suite instead, by calling `wp_welcome_panel()` directly.
	 */
	public function detachWelcomePanel(): void {
		remove_all_actions( 'welcome_panel' );
	}

	/**
	 * Stop WordPress from printing its own widgets into the mount point.
	 *
	 * `wp_dashboard()` is called directly at `wp-admin/index.php:205` rather than
	 * through a `do_action()`, so the boxes cannot be detached with `remove_action()`
	 * and there is no filter to intercept. They are printed inside
	 * `#dashboard-widgets-wrap`, which is the element the SPA mounts on, so between
	 * the HTML arriving and the module executing, a visitor sees the native dashboard
	 * for a fraction of a second and then watches it get replaced. Removing the boxes
	 * is the only way to stop that: the markup is never produced, so there is nothing
	 * to flash.
	 *
	 * This gives up the fallback on purpose. The boxes were left registered precisely
	 * so that a bundle which failed to boot would leave WordPress's own dashboard on
	 * screen. A blank area is the more honest failure mode: a core dashboard that the
	 * suite is about to replace reads as a rendering fault, not as a fallback.
	 *
	 * REST requests are excluded, and must be. `DashboardController::nativeWidgets()`
	 * calls `wp_dashboard_setup()` to build the very inventory the SPA is served, so
	 * removing the boxes unconditionally would leave the suite with nothing to show.
	 * That is also why the check comes before the screen test: during a REST request
	 * the controller installs a faked `dashboard` screen, so `isSuiteScreen()` answers
	 * yes and cannot be relied on to tell the two apart.
	 *
	 * `wp_dashboard_setup` is the only hook in the window. It fires at
	 * `wp-admin/includes/dashboard.php:135`, after the boxes are registered and long
	 * before `index.php:205` prints them. `admin_init` is too early: it fires at
	 * `wp-admin/admin.php:180`, before the screen file is loaded at all.
	 */
	public function detachNativeWidgets(): void {
		global $wp_meta_boxes;

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || ! self::isSuiteScreen() ) {
			return;
		}

		// The screen id is already the per-area key, and the inventory is keyed the
		// same way, so there is nothing to recompute here.
		$screen_id = $screen->id;

		if ( empty( $wp_meta_boxes[ $screen_id ] ) || ! is_array( $wp_meta_boxes[ $screen_id ] ) ) {
			return;
		}

		foreach ( $wp_meta_boxes[ $screen_id ] as $context => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}

			foreach ( $priorities as $boxes ) {
				if ( ! is_array( $boxes ) ) {
					continue;
				}

				foreach ( $boxes as $box ) {
					if ( ! is_array( $box ) || empty( $box['id'] ) ) {
						continue;
					}

					remove_meta_box( $box['id'], $screen_id, $context );
				}
			}
		}
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
