<?php
/**
 * Moves the admin bar's "Howdy" account menu into the sidebar, above Dashboard.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Relocates the account menu from the admin bar to the top of the sidebar.
 *
 * Nothing is lost in the move. The avatar survives because a menu entry's icon
 * slot is not dashicon-only: `menu-header.php` renders an `<img>` whenever the
 * slot holds a URL, and a dashicon class only when it holds a dashicon name.
 * And the sidebar is the *easier* place to extend the menu, not the harder one,
 * because the dropdown we build here is a plain `$submenu` array we own end to
 * end, whereas the admin bar's node tree exposes no filters at all.
 */
final class AccountMenu {

	/**
	 * Sentinel slug for the top-level entry.
	 *
	 * The slug is not a URL on purpose. `_wp_menu_output()` decides whether an
	 * entry gets `wp-has-submenu` by looking its slug up in `$submenu`, and then
	 * builds the parent anchor's href out of the *first submenu row's* slug
	 * rather than out of the entry's own. A sentinel lets the submenu exist while
	 * the parent link still points at a real destination.
	 */
	private const SLUG = 'admin-suite-account';

	/**
	 * Slug for the separator that closes the account block.
	 *
	 * `add_menu_classes()` decides whether an entry is a separator by looking at
	 * nothing but whether slot 2 starts with the literal `separator`, so the
	 * prefix is load-bearing rather than cosmetic. The separator is also what
	 * makes core mark the account entry `menu-top-last` and hand
	 * `menu-top-first` to Dashboard, which is the whole reason one is needed:
	 * without it the account block and Dashboard are a single group, and a
	 * collapsed sidebar has no gap to show the avatar in.
	 */
	private const SEPARATOR_SLUG = 'separator-account';

	/**
	 * Registers the hooks.
	 *
	 * Both run late on purpose. `admin_menu` fires at `includes/menu.php:168`,
	 * well before the sidebar is printed at `admin-header.php:268`, and running
	 * last means every plugin has already added what it wants, so the account
	 * entry can be placed ahead of all of it rather than ahead of only what
	 * existed when we loaded.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addToSidebar' ), 9999 );
		add_action( 'admin_bar_menu', array( $this, 'removeFromBar' ), 9999 );
	}

	/**
	 * Drops the bar's `my-account` node and everything under it.
	 *
	 * This removes the *node* rather than unhooking the two callbacks that build
	 * it, because those callbacks are registered from inside
	 * `WP_Admin_Bar::initialize()` — reached through `_wp_admin_bar_init()`,
	 * which is hooked on `admin_init` at priority 10 — and `remove_action()` is
	 * a silent no-op for a callback that has not been added yet. At this
	 * priority the node is guaranteed to exist, and `remove_node()` recurses, so
	 * the `user-actions` group and both of its children go with it.
	 *
	 * The argument is typed natively rather than only in the docblock: the method
	 * is hooked to `admin_bar_menu`, which core only ever fires with a
	 * WP_Admin_Bar, and stating it in the signature is what lets the instanceof
	 * check go instead of lingering as a branch that cannot be false.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The bar being built.
	 */
	public function removeFromBar( \WP_Admin_Bar $wp_admin_bar ): void {
		$wp_admin_bar->remove_node( 'my-account' );
	}

		/**
		 * Puts the entry at the top of the sidebar.
		 *
		 * The key is the position, and WordPress owns the ordering:
		 * `uksort( $menu, 'strnatcasecmp' )` runs at `wp-admin/includes/menu.php:280`,
		 * long after this hook and long before `add_menu_classes()`. Every key core
		 * uses is a string of digits, and `strnatcasecmp` compares them as text, so a
		 * key starting with a letter sorts after all of them and the entry lands at
		 * the bottom of the sidebar. Rebuilding the array to win on insertion order
		 * is therefore pointless: it is re-sorted before anybody looks at it. All
		 * this has to do is claim the lowest free key and let core's own sort put it
		 * first.
		 *
		 * The submenu is keyed on slot 2, not on the array key, so the two are free
		 * to differ: `SLUG` is a sentinel that is deliberately not a URL, because
		 * `_wp_menu_output()` builds the parent anchor's href out of the first
		 * submenu row rather than out of the entry.
		 *
		 * The separator goes in straight after the entry, which is the one place a
		 * key has to be crafted rather than merely free: the entry takes an integer
		 * key and the next item is the next integer, so there is no integer between
		 * them. `separatorKey()` solves that. Core then does the class bookkeeping
		 * on its own, and doing it by hand would only fight it.
		 */
	public function addToSidebar(): void {
		global $menu, $submenu;

		if ( ! is_array( $menu ) || ! current_user_can( 'read' ) ) {
			return;
		}

		// $submenu is null rather than an array until something registers a
		// submenu, and on a single site nothing ever does. Writing the offset
		// further down is what created it, so say so instead of letting the
		// assignment do it silently.
		if ( ! is_array( $submenu ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the sidebar is rendered from these globals.
			$submenu = array();
		}

		$rows = $this->rows();

		if ( array() === $rows ) {
			return;
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the sidebar is rendered from these globals.
		$submenu[ self::SLUG ] = $rows;

		$key = $this->firstFreeKey( $menu );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the sidebar is rendered from this global.
		$menu[ $key ] = $this->entry();

		$after = $this->separatorKey( $key );

		if ( ! array_key_exists( $after, $menu ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the sidebar is rendered from this global.
			$menu[ $after ] = $this->separator();
		}

		$this->dropHardcodedFirst( $menu, $after );
	}

	/**
	 * The top-level entry: menu title, capability, slug, page title, classes, hook, icon.
	 *
	 * The title is escaped here because nothing downstream does it: slot 0 goes
	 * through `wptexturize()` and is then echoed as-is. It is also the bare
	 * display name, with no `Howdy, %s` around it, because the entry is laid out
	 * as a profile block with the avatar above and the name below, and a
	 * greeting would break that. A display name is user data rather than a
	 * translatable string, so no `__()` call belongs here at all.
	 *
	 * Slot 5 becomes the slug so the `<li>` gets an id, which is what
	 * `style.css` needs to cancel the sidebar's own image and padding rules.
	 * Core's entries carry ids the same way, and it changes nothing else: the
	 * submenu is keyed on slot 2, not on the array key or on this one.
	 *
	 * Slot 4 carries no group-position class. `add_menu_classes()` assigns
	 * `menu-top-last` and `menu-top-first` from the separators it finds, and it
	 * only ever adds a class, never takes one away, so a hardcoded
	 * `menu-top-first` here would survive the separator and mark this entry as
	 * the start of a group it no longer starts.
	 *
	 * @return array<int, string>
	 */
	private function entry(): array {
		$user   = wp_get_current_user();
		$avatar = get_avatar_url( $user->ID, array( 'size' => 28 ) );

		return array(
			esc_html( $user->display_name ),
			'read',
			self::SLUG,
			'',
			'menu-top',
			self::SLUG,
			is_string( $avatar ) ? $avatar : 'none',
		);
	}

	/**
	 * The dropdown rows: Edit Profile, then Log Out.
	 *
	 * Both slots that core interpolates unescaped are escaped here. A submenu
	 * row is printed as `<a href='{$sub_item[2]}'…>{$sub_item[0]}</a>` with no
	 * escaping of its own, so the URL in particular has to arrive already safe —
	 * and `wp_logout_url()` carries a nonce and a redirect in its query string.
	 *
	 * @return list<array<int, string>>
	 */
	private function rows(): array {
		$rows   = array();
		$target = get_edit_profile_url();

		if ( '' !== $target ) {
			$rows[] = array(
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- deliberately core's own string, so a translated site shows core's wording.
				esc_html( __( 'Edit Profile', 'default' ) ),
				'read',
				esc_url( $target ),
				'',
				'',
			);
		}

		$logout = wp_logout_url();

		if ( '' !== $logout ) {
			$rows[] = array(
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- deliberately core's own string, so a translated site shows core's wording.
				esc_html( __( 'Log Out', 'default' ) ),
				'read',
				esc_url( $logout ),
				'',
				'',
			);
		}

		return $rows;
	}

	/**
	 * Menu key that sorts between the account entry and the next item.
	 *
	 * The key is the position, not a handle: `wp-admin/includes/menu.php:280`
	 * runs `uksort( $menu, 'strnatcasecmp' )`, and it runs *after*
	 * `do_action( 'admin_menu' )` at `:168`, so the key is written into an
	 * unsorted array and only then ordered. `firstFreeKey()` hands out an
	 * integer and the item that follows is the next integer, so there is no
	 * integer left between them.
	 *
	 * `strnatcasecmp` is a natural comparison, so the shared numeric prefix
	 * decides and `'1' < '1.5' < '2'` the way `'59' < '59.5' < '60'` does too.
	 * A non-integer string key is safe: `add_menu_classes()` only ever puts
	 * `$order` through a strict `0 === $order` comparison and uses it as an
	 * array index.
	 *
	 * @param int $key Key the account entry took.
	 * @return string Key that sorts immediately after it.
	 */
	private function separatorKey( int $key ): string {
		return (string) $key . '.5';
	}

	/**
	 * The sidebar separator itself.
	 *
	 * Shaped after core's own two, at `wp-admin/menu.php:69` and `:205`, which
	 * are the entries that produce the gaps the suite inherited. Only slot 2 is
	 * load-bearing: `add_menu_classes()` reads
	 * `str_starts_with( $top[2], 'separator' )` and nothing else to decide that
	 * this is a separator rather than a menu item.
	 *
	 * @return array<int, string> Menu row, shaped like core's separators.
	 */
	private function separator(): array {
		return array( '', 'read', self::SEPARATOR_SLUG, '', 'wp-menu-separator' );
	}

	/**
	 * Drop the `menu-top-first` the item after our separator hardcodes.
	 *
	 * `add_menu_classes()` arms its `menu-top-first` on whatever item follows a
	 * separator, and `add_cssclass()` is a blind concatenation
	 * (`wp-admin/includes/menu.php:212`), so it never notices the class is
	 * already there. Core's own separators sit *after* Dashboard, so on a stock
	 * install the two never overlap; ours sits before it, and Dashboard already
	 * carries the class hardcoded at `wp-admin/menu.php:29`. The result would be
	 * the class twice on one element.
	 *
	 * Removing the hardcoded copy is enough: core adds its own a moment later, at
	 * `wp-admin/includes/menu.php:387`, so the element still ends up with exactly
	 * one and still gets it from the one place that knows the group boundaries.
	 *
	 * The item is found by key because the array is not sorted yet — `uksort()`
	 * runs at `wp-admin/includes/menu.php:280` and this hook at `:168` — so this
	 * has to compare keys the way core is about to. The comparison is strict:
	 * the separator's own key has to be excluded, and it is the key that sorts
	 * closest above it, so a `>=` test would pick the separator and strip nothing.
	 *
	 * @param array<array-key, mixed> $menu Menu to clean, passed by reference.
	 * @param string                  $after Key the separator took.
	 */
	private function dropHardcodedFirst( array &$menu, string $after ): void {
		$next = null;

		foreach ( array_keys( $menu ) as $candidate ) {
			if ( strnatcasecmp( (string) $candidate, $after ) <= 0 ) {
				continue;
			}

			if ( null === $next || strnatcasecmp( (string) $candidate, (string) $next ) < 0 ) {
				$next = $candidate;
			}
		}

		if (
			null === $next
			|| ! is_array( $menu[ $next ] )
			|| ! isset( $menu[ $next ][4] )
			|| ! is_string( $menu[ $next ][4] )
		) {
			return;
		}

		$kept = array();

		foreach ( explode( ' ', $menu[ $next ][4] ) as $name ) {
			if ( '' !== $name && 'menu-top-first' !== $name ) {
				$kept[] = $name;
			}
		}

		$menu[ $next ][4] = implode( ' ', $kept );
	}

	/**
	 * Lowest unused menu key, so the entry sorts above Dashboard.
	 *
	 * Zero is skipped on purpose. `add_menu_classes()` compares the key with
	 * `0 === $order` and treats a match as a reserved single-item slot: it
	 * injects the class and then `continue`s, which skips the last-item
	 * bookkeeping for the rest of the loop.
	 *
	 * @param array<array-key, mixed> $menu Menu to look for a free key in.
	 * @return int Positive integer key that is not in use.
	 */
	private function firstFreeKey( array $menu ): int {
		$key = 1;

		while ( array_key_exists( $key, $menu ) ) {
			++$key;
		}

		return $key;
	}
}
