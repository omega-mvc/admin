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
	 * @param \WP_Admin_Bar $wp_admin_bar The bar being built.
	 */
	public function removeFromBar( $wp_admin_bar ): void {
		if ( $wp_admin_bar instanceof \WP_Admin_Bar ) {
			$wp_admin_bar->remove_node( 'my-account' );
		}
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
		 */
	public function addToSidebar(): void {
		global $menu, $submenu;

		if ( ! is_array( $menu ) || ! current_user_can( 'read' ) ) {
			return;
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

		$this->handOverFirstClass( $menu, $key );
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
			'menu-top menu-top-first',
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

		if ( is_string( $target ) && '' !== $target ) {
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

		if ( is_string( $logout ) && '' !== $logout ) {
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
		 * Moves `menu-top-first` from whoever was holding it to the new top item.
		 *
		 * The class belongs to the first item of a group, not to Dashboard
		 * specifically: `add_menu_classes()` re-arms its search at every separator
		 * and hands the class to the next item it finds, which is why a stock
		 * sidebar carries the class several times over. The one hardcoded on
		 * Dashboard exists only because Dashboard was first. With the account entry
		 * above it the class has to move, or both of the two top items are marked as
		 * the start of a group.
		 *
		 * @param array<array-key, mixed> $menu   Menu by reference, so the edit is the one that sticks.
		 * @param int                     $winner Key of the entry that should end up holding the class.
		 */
	private function handOverFirstClass( array &$menu, int $winner ): void {
		foreach ( $menu as $key => $item ) {
			if ( $key === $winner || ! isset( $item[4] ) ) {
				continue;
			}

			$classes = (string) $item[4];

			if ( ! preg_match( '/(^|\s)menu-top-first(\s|$)/', $classes ) ) {
				continue;
			}

			$stripped = (string) preg_replace( '/\s*menu-top-first\s*/', ' ', $classes );

			$menu[ $key ][4] = trim( (string) preg_replace( '/\s+/', ' ', $stripped ) );

			break;
		}

		$current = isset( $menu[ $winner ][4] ) ? (string) $menu[ $winner ][4] : '';

		if ( ! str_contains( $current, 'menu-top-first' ) ) {
			$menu[ $winner ][4] = add_cssclass( 'menu-top-first', $current );
		}
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
