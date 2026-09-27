<?php
/**
 * Menu controller: normalises the WordPress admin menu for the SPA sidebar.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Api;

use AdminSuite\Core\AdminContext;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET /admin-suite/v1/menu.
 */
final class MenuController {

	/**
	 * Which admin area this request is building a menu for.
	 *
	 * WordPress keeps three entirely different menus (site, network, user) and
	 * the SPA has to show the one that matches the screen it was opened from.
	 *
	 * @var string One of AdminContext::SITE, AdminContext::NETWORK, AdminContext::USER.
	 */
	private string $admin = AdminContext::SITE;

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/menu',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getMenu' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(
					'context' => array(
						'type'              => 'string',
						'enum'              => array( 'view', 'edit' ),
						'default'           => 'view',
						// Both callbacks are needed, and the pairing is a core
						// convention rather than a preference: `enum` is enforced
						// by rest_validate_request_arg(), and
						// WP_REST_Request::has_valid_params() only calls
						// `validate_callback` when it is explicitly set, with no
						// fallback of its own. Leaving it unset means `enum` is
						// silently decorative and a bogus value answers 200.
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
					'admin'   => array(
						'type'              => 'string',
						'enum'              => AdminContext::all(),
						'default'           => AdminContext::SITE,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Which admin area to build the menu for.', 'admin-suite' ),
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function canRead(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Build the normalised menu tree.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function getMenu( \WP_REST_Request $request ): \WP_REST_Response {
		// The area has to be resolved before anything else: it decides which
		// menu file gets required, which screen the menu is built against, and
		// the base URL every entry is resolved against.
		$this->admin = AdminContext::fromRequest( $request );

		$menu    = $this->buildMenu();
		$submenu = $this->buildSubmenu();

		$context = 'edit' === $request->get_param( 'context' ) ? 'edit' : 'view';

		$items = $this->normalise( $menu, $submenu, $context );

		$response = rest_ensure_response(
			array(
				'items'  => $items,
				'counts' => array(
					'topLevel' => count( $items ),
				),
			)
		);

		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Build the global `$menu` tree the way an admin screen would.
	 *
	 * WordPress only assembles $menu/$submenu while rendering wp-admin, so a
	 * REST request would otherwise see an empty menu. The builder lives in
	 * `wp-admin/menu.php` and can only be `require`d, never called.
	 *
	 * Because a `require` inherits the scope of the line that executes it, the
	 * file has to be loaded from inside a function, and every global that
	 * `wp-admin/menu.php` and `wp-admin/includes/menu.php` assign must be
	 * declared `global` in that function. `wp-admin/includes/menu.php` only
	 * declares `$menu, $submenu, $compat` itself; the rest
	 * ($_wp_menu_nopriv, $_wp_submenu_nopriv, $_wp_real_parent_file,
	 * $admin_page_hooks) would otherwise stay function-local, leaving the
	 * real globals null and making user_can_access_admin_page() fatal on
	 * array_keys(null).
	 *
	 * @return array<mixed> The global $menu, or an empty array if it cannot be built.
	 */
	private function buildMenu(): array {
		global $menu, $submenu, $compat, $admin_page_hooks, $_wp_menu_nopriv, $_wp_submenu_nopriv;
		global $_wp_real_parent_file, $_wp_last_utility_menu_name, $_wp_unregistered_menu_pages;
		global $_registered_pages, $_parent_pages, $pagenow, $parent_file, $plugin_page, $typenow;

		$this->loadAdminIncludes();

		// Seeding these globals is the whole point of this method: it reproduces
		// the state an admin screen has before wp-admin/menu.php runs, so the
		// override sniff is a false positive here.
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		// Seed what a real admin screen would already have populated.
		$menu                        = array();
		$submenu                     = array();
		$compat                      = array();
		$admin_page_hooks            = array();
		$_registered_pages           = array();
		$_parent_pages               = array();
		$_wp_menu_nopriv             = array();
		$_wp_submenu_nopriv          = array();
		$_wp_unregistered_menu_pages = array();

		// Pretend the Dashboard is the screen being rendered. $plugin_page is
		// deliberately left unset: user_can_access_admin_page() branches on
		// isset( $plugin_page ) and treats an empty string as "set", which
		// makes it reject the request.
		$pagenow     = 'index.php';
		$parent_file = 'index.php';
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		$previous_screen = $this->installScreen();

		try {
			// One area per request, so require_once is safe: the three builders
			// are three different files and a single request only ever builds
			// one of them.
			require_once ABSPATH . AdminContext::menuFile( $this->admin );
		} catch ( \Throwable $e ) {
			// A broken menu should cost the sidebar its contents, not the whole
			// endpoint. loadAdminIncludes() is what keeps the known fatal here
			// from happening in the first place.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Same seeding block as above.
			$menu = array();
		} finally {
			$this->restoreScreen( $previous_screen );
		}

		return $menu;
	}

	/**
	 * Load the admin includes the menu builders quietly assume are present.
	 *
	 * `wp-admin/menu.php` only ever requires `wp-admin/includes/menu.php`, and
	 * that file calls `user_can_access_admin_page()` unconditionally
	 * (`wp-admin/includes/menu.php:375`). A REST request never loads
	 * `wp-admin/includes/plugin.php` on its own, so without this the require
	 * would fatal on an undefined function and the catch in buildMenu() would
	 * convert that into a silently empty menu.
	 *
	 * The three files are loaded separately on purpose: none of them requires
	 * the others, and `screen.php` does not load `class-wp-screen.php` even
	 * though `set_current_screen()` instantiates `WP_Screen`.
	 */
	private function loadAdminIncludes(): void {
		if ( ! class_exists( 'WP_Screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		}

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		if ( ! function_exists( 'user_can_access_admin_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Install the screen that matches the admin area being built.
	 *
	 * `wp-admin/menu.php` branches on `is_network_admin()` and
	 * `is_user_admin()`, and both of those read `$GLOBALS['current_screen']`
	 * before they ever look at the `WP_NETWORK_ADMIN` / `WP_USER_ADMIN`
	 * constants. A REST request never defines those constants (only
	 * `wp-admin/admin.php` does), so the screen is the only signal available
	 * here. The `-network` / `-user` suffix is what `WP_Screen::get()` reads
	 * to set `in_admin`, and it is also what makes
	 * `wp-admin/includes/menu.php` fire `network_admin_menu` and
	 * `user_admin_menu` instead of `admin_menu`.
	 *
	 * @return \WP_Screen|null The screen to hand back to restoreScreen(), or null if there was none.
	 */
	private function installScreen(): ?\WP_Screen {
		$previous = get_current_screen();

		set_current_screen( AdminContext::screenId( $this->admin ) );

		return $previous;
	}

	/**
	 * Put the previous screen back.
	 *
	 * Leaving the fake screen installed would make the rest of the request
	 * believe it is running in the network admin, which changes both
	 * is_network_admin() and the key $wp_meta_boxes is stored under.
	 *
	 * @param \WP_Screen|null $previous Screen returned by installScreen().
	 */
	private function restoreScreen( ?\WP_Screen $previous ): void {
		if ( null === $previous ) {
			unset( $GLOBALS['current_screen'] );

			return;
		}

		set_current_screen( $previous );
	}

	/**
	 * Read the global `$submenu` built alongside `$menu`.
	 *
	 * @return array<string, array<mixed>>
	 */
	private function buildSubmenu(): array {
		global $submenu;

		$built = array();

		// WordPress keys the global by parent slug, but nothing stops a third party
		// from writing into it, so the shape is established here rather than
		// asserted: only a string key holding an array is a usable submenu.
		foreach ( (array) $submenu as $parent => $entries ) {
			if ( is_string( $parent ) && is_array( $entries ) ) {
				$built[ $parent ] = $entries;
			}
		}

		return $built;
	}

	/**
	 * Convert the two WordPress global menus into a single tree.
	 *
	 * A menu tuple is indexed by position, but it is not a list: core writes
	 * `$menu[ $position ]` as well as `$menu[]`, so a tuple reached through
	 * a gap in the top-level menu has non-sequential integer keys. The tuple
	 * readers below therefore take any key type rather than a list.
	 *
	 * @param array<mixed>                $menu    Global $menu, as built by WordPress.
	 * @param array<string, array<mixed>> $submenu Global $submenu.
	 * @param string                      $context Either 'view' or 'edit'.
	 * @return list<array<string, mixed>>
	 */
	private function normalise( array $menu, array $submenu, string $context ): array {
		$items = array();

		foreach ( $menu as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$slug = $this->slug( $entry );

			if ( '' === $slug || $this->isSeparator( $entry ) ) {
				continue;
			}

			$capability = isset( $entry[1] ) && is_string( $entry[1] ) ? $entry[1] : 'read';

			if ( ! current_user_can( $capability ) ) {
				continue;
			}

			$children = array();

			if ( isset( $submenu[ $slug ] ) ) {
				$children = $this->normalise( $submenu[ $slug ], array(), $context );
			}

			$items[] = array(
				'id'         => $slug,
				'label'      => $this->label( $entry ),
				'url'        => $this->url( $entry ),
				'icon'       => isset( $entry[6] ) && is_string( $entry[6] ) ? $entry[6] : 'dashicons-menu',
				'capability' => $capability,
				'context'    => $context,
				'children'   => $children,
			);
		}

		return $items;
	}

	/**
	 * Extract the slug from a menu tuple.
	 *
	 * @param array<array-key, mixed> $entry Menu tuple.
	 */
	private function slug( array $entry ): string {
		$slug = $entry[2] ?? '';

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Whether a menu tuple is a visual separator rather than a real page.
	 *
	 * Core inserts `array( '', 'read', 'separator1', '', 'wp-menu-separator' )`
	 * into the top-level menu. It has no label, no icon and no capability, so it
	 * must not be rendered as a navigable item.
	 *
	 * @param array<array-key, mixed> $entry Menu tuple.
	 */
	private function isSeparator( array $entry ): bool {
		$classes = $entry[4] ?? '';

		return is_string( $classes ) && str_contains( $classes, 'wp-menu-separator' );
	}

	/**
	 * Extract a safe, translated label from a menu tuple.
	 *
	 * @param array<array-key, mixed> $entry Menu tuple.
	 */
	private function label( array $entry ): string {
		$label = $entry[0] ?? '';

		return is_string( $label ) ? wp_strip_all_tags( $label ) : '';
	}

	/**
	 * Extract a safe, absolute URL from a menu tuple.
	 *
	 * The third field of a menu tuple is not always a file. WordPress puts
	 * three different things in it: a relative admin file (`edit.php`), a
	 * plugin page hook suffix (`my-plugin`, written by `add_menu_page()`),
	 * and occasionally an already absolute URL. Two failures follow from not
	 * telling them apart. Treating a hook suffix as a path turns a plugin's
	 * own menu entry into `http://my-plugin`, and returning a relative
	 * filename untouched only works by accident, because it resolves against
	 * `/wp-admin/` on a site screen but against `/wp-admin/network/` or
	 * `/wp-admin/user/` on the other two.
	 *
	 * A hook suffix is sanitised to letters, digits, dashes and underscores, so
	 * it never contains `.php` or a slash. That is the discriminator.
	 *
	 * @param array<array-key, mixed> $entry Menu tuple.
	 */
	private function url( array $entry ): string {
		$url = $entry[2] ?? '';

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' ) ) {
			return esc_url_raw( $url );
		}

		$base = AdminContext::baseUrl( $this->admin );

		if ( str_contains( $url, '.php' ) || str_contains( $url, '/' ) ) {
			return esc_url_raw( $base . ltrim( $url, '/' ) );
		}

		// A hook suffix. `add_menu_page()` and `add_submenu_page()` record
		// themselves in $_parent_pages while `wp-admin/includes/menu.php` fires
		// the action, so by the time this runs the parent is already described
		// there. This mirrors `menu_page_url()` in
		// `wp-admin/includes/plugin.php`, with one deliberate difference: it
		// resolves against the base URL of the area being built. Core's version
		// hardcodes `admin_url()`, which is always `/wp-admin/` and would send
		// the network admin's own entry back to the site admin.
		global $_parent_pages;

		$pages  = is_array( $_parent_pages ) ? $_parent_pages : array();
		$parent = $pages[ $url ] ?? null;

		if ( is_string( $parent ) && '' !== $parent && ! isset( $pages[ $parent ] ) ) {
			return esc_url_raw( $base . add_query_arg( 'page', $url, $parent ) );
		}

		return esc_url_raw( $base . 'admin.php?page=' . rawurlencode( $url ) );
	}
}
