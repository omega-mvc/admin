<?php
/**
 * Admin context: which wp-admin area a request belongs to.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Names the admin area a request is in: site, network or user.
 *
 * WordPress has three admin areas, each with its own menu builder, its own
 * dashboard screen and its own URL prefix. The suite takes over the dashboard,
 * and a dashboard exists in all three. `wp-admin/admin.php:158-163` picks the
 * menu builder with these lines:
 *
 *     if ( WP_NETWORK_ADMIN )      { require ABSPATH . 'wp-admin/network/menu.php'; }
 *     elseif ( WP_USER_ADMIN )     { require ABSPATH . 'wp-admin/user/menu.php'; }
 *     else                         { require ABSPATH . 'wp-admin/menu.php'; }
 *
 * The constants cannot answer the question in a REST request: they are only
 * defined in `wp-admin/admin.php`, and the REST bootstrap never runs it. Nor can
 * `is_network_admin()` and `is_user_admin()` — both consult
 * `$GLOBALS['current_screen']` first and only fall back to the constants
 * (`wp-includes/load.php:1407` and `:1431`). In a REST request that global is
 * either absent or, worse, the fake screen `DashboardController` installs while
 * it enumerates widgets, so the answer can change halfway through one request.
 *
 * The area therefore travels explicitly: `detect()` reads it while the page is
 * being enqueued, where the constants and the screen are both real; the value
 * goes into the bootstrap payload so the SPA can echo it back, and
 * `fromRequest()` validates whatever comes in. Nothing downstream guesses.
 */
final class AdminContext {

	/**
	 * The site admin, `/wp-admin/`. Always present, on single site and multisite.
	 */
	public const SITE = 'site';

	/**
	 * The network admin, `/wp-admin/network/`. Multisite only.
	 */
	public const NETWORK = 'network';

	/**
	 * The user admin, `/wp-admin/user/`. Multisite only: super admins are
	 * redirected away from it.
	 */
	public const USER = 'user';

	/**
	 * Every supported area.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::SITE, self::NETWORK, self::USER );
	}

	/**
	 * Detect the admin area of the current PHP request.
	 *
	 * Only trustworthy where the constants are defined and a screen is set, i.e.
	 * while a real wp-admin page is being rendered. REST requests must use
	 * `fromRequest()`.
	 */
	public static function detect(): string {
		if ( ! is_multisite() ) {
			return self::SITE;
		}

		if ( is_network_admin() ) {
			return self::NETWORK;
		}

		if ( is_user_admin() ) {
			return self::USER;
		}

		return self::SITE;
	}

	/**
	 * Resolve the area a REST request is asking about.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request  Incoming request.
	 * @param string|null                            $fallback Area to assume when the
	 *                                                        request says nothing.
	 */
	public static function fromRequest( \WP_REST_Request $request, ?string $fallback = null ): string {
		$assumed = ( null !== $fallback && in_array( $fallback, self::all(), true ) )
			? $fallback
			: self::SITE;

		$requested = $request->get_param( 'admin' );

		if ( ! is_string( $requested ) || ! in_array( $requested, self::all(), true ) ) {
			return $assumed;
		}

		// The network and user admins do not exist on a single site, so a request
		// naming one there is answered with the only area it has.
		if ( self::SITE !== $requested && ! is_multisite() ) {
			return self::SITE;
		}

		return $requested;
	}

	/**
	 * The URL prefix every relative admin path in this area hangs off.
	 *
	 * The three menu builders all store relative filenames in `$menu[2]`
	 * (`edit.php`, `sites.php`, `profile.php`), so resolving them needs the
	 * prefix of the area they were built for. Each helper already returns a
	 * trailing slash.
	 *
	 * @param string $context One of the class constants.
	 */
	public static function baseUrl( string $context ): string {
		switch ( $context ) {
			case self::NETWORK:
				return network_admin_url();

			case self::USER:
				return admin_url( 'user/' );

			default:
				return admin_url();
		}
	}

	/**
	 * The screen id whose `in_admin` matches this area.
	 *
	 * `WP_Screen::get()` derives the area from the *name* of the hook: a name
	 * ending in `-network` sets `in_admin` to `network`, one ending in `-user`
	 * sets it to `user`
	 * (`wp-admin/includes/class-wp-screen.php:245-253`). Installing the matching
	 * screen is what makes `is_network_admin()` answer correctly for the rest of
	 * the request, which `wp-admin/includes/menu.php:9` and `:139` depend on when
	 * they prune the menu and fire `network_admin_menu` / `user_admin_menu`.
	 *
	 * @param string $context One of the class constants.
	 */
	public static function screenId( string $context ): string {
		switch ( $context ) {
			case self::NETWORK:
				return 'dashboard-network';

			case self::USER:
				return 'dashboard-user';

			default:
				return 'dashboard';
		}
	}

	/**
	 * The file that builds `$menu` for this area.
	 *
	 * Mirrors the branch in `wp-admin/admin.php:158-163`. The file can only be
	 * `require`d, never called.
	 *
	 * @param string $context One of the class constants.
	 */
	public static function menuFile( string $context ): string {
		switch ( $context ) {
			case self::NETWORK:
				return 'wp-admin/network/menu.php';

			case self::USER:
				return 'wp-admin/user/menu.php';

			default:
				return 'wp-admin/menu.php';
		}
	}

	/**
	 * The setup function that registers this area's dashboard widgets.
	 *
	 * There is only one: `wp_dashboard_setup()` serves all three areas and picks
	 * its widgets itself, branching on `is_network_admin()` and `is_blog_admin()`
	 * internally (`wp-admin/includes/dashboard.php:20-100`). There is no
	 * `wp_network_dashboard_setup()` in WordPress 7.1 — the network dashboard
	 * widget `network_dashboard_right_now` is registered by the same function.
	 * Installing the right screen (see `screenId()`) is therefore all that is
	 * needed to get the right widget set.
	 *
	 * @return string The name of the setup function to call.
	 */
	public static function dashboardSetup(): string {
		return 'wp_dashboard_setup';
	}
}
