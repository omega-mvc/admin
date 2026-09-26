<?php
/**
 * Menu controller: normalises the WordPress admin menu for the SPA sidebar.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET /admin-suite/v1/menu.
 */
final class MenuController {

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
						'sanitize_callback' => 'sanitize_key',
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

		try {
			require_once ABSPATH . 'wp-admin/menu.php';
		} catch ( \Throwable $e ) {
			return array();
		}

		return $menu;
	}

	/**
	 * Read the global `$submenu` built alongside `$menu`.
	 *
	 * @return array<string, array<mixed>>
	 */
	private function buildSubmenu(): array {
		global $submenu;

		return is_array( $submenu ) ? $submenu : array();
	}

	/**
	 * Convert the two WordPress global menus into a single tree.
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

			if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
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
	 * @param array<int, mixed> $entry Menu tuple.
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
	 * @param array<int, mixed> $entry Menu tuple.
	 */
	private function isSeparator( array $entry ): bool {
		$classes = $entry[4] ?? '';

		return is_string( $classes ) && str_contains( $classes, 'wp-menu-separator' );
	}

	/**
	 * Extract a safe, translated label from a menu tuple.
	 *
	 * @param array<int, mixed> $entry Menu tuple.
	 */
	private function label( array $entry ): string {
		$label = $entry[0] ?? '';

		return is_string( $label ) ? wp_strip_all_tags( $label ) : '';
	}

	/**
	 * Extract a safe URL from a menu tuple.
	 *
	 * @param array<int, mixed> $entry Menu tuple.
	 */
	private function url( array $entry ): string {
		$url = $entry[2] ?? '';

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		if ( str_starts_with( $url, 'admin.php' ) || str_starts_with( $url, 'tools.php' ) ) {
			return esc_url_raw( admin_url( $url ) );
		}

		return esc_url_raw( $url );
	}
}
