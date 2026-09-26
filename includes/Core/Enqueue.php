<?php
/**
 * Asset manager: bridges Vite output into wp-admin.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Vite bundle for both dev (HMR server) and production (manifest).
 */
final class Enqueue {

	/**
	 * Vite dev server origin.
	 */
	private const DEV_SERVER = 'http://localhost:5173';

	/**
	 * Script handle for the application entry module.
	 */
	private const HANDLE = 'admin-suite-app';

	/**
	 * Text domain of the plugin. Must match the `Text Domain` header.
	 */
	private const TEXT_DOMAIN = 'admin-suite';

	/**
	 * Handle the SPA depends on.
	 *
	 * `wp-i18n` is registered by core and exposes `window.wp.i18n`, whose
	 * `__` / `_x` / `_n` / `_nx` take the same arguments as their PHP
	 * counterparts. Depending on core's copy rather than shipping
	 * `@wordpress/i18n` from npm means the SPA uses WordPress's own
	 * localisation instead of a parallel implementation of it.
	 */
	private const SCRIPT_DEPS = array( 'wp-i18n' );

	/**
	 * Absolute path of the translation catalogues.
	 */
	private const LANG_DIR = 'languages';

	/**
	 * Register the enqueue hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ), 1 );

		// Registered unconditionally: the tag is only rewritten when the handle
		// is actually enqueued, so no-op on every other admin screen.
		add_filter( 'script_loader_tag', array( $this, 'filterScriptTag' ), 10, 3 );
	}

	/**
	 * Serve the bundle as an ES module.
	 *
	 * Vite emits `import`/`export` statements, which a classic script cannot
	 * parse — the browser throws `SyntaxError: Cannot use import statement
	 * outside a module` and the SPA silently renders nothing at all. Core has
	 * no loading strategy for modules (`defer` and `async` are the only ones
	 * `WP_Scripts` accepts), so the attribute has to be added here.
	 *
	 * `$tag` also carries the `before`/`after` inline scripts, and it arrives
	 * with their tags already emitted, so a blind `<script` replacement would
	 * convert the bootstrap payload into an inline module too. Keying off the
	 * exact id that `wp_get_script_tag()` derives from the handle targets the
	 * real tag and nothing else: the inline tags are `…-js-before`/`…-js-after`.
	 *
	 * @param string $tag    The `<script>` markup for the handle.
	 * @param string $handle Registered script handle.
	 * @param string $src    Script source URL.
	 */
	public function filterScriptTag( string $tag, string $handle, string $src ): string {
		unset( $src );

		if ( self::HANDLE !== $handle || str_contains( $tag, 'type="module"' ) ) {
			return $tag;
		}

		$id = 'id="' . self::HANDLE . '-js"';

		if ( ! str_contains( $tag, $id ) ) {
			return $tag;
		}

		return str_replace( $id, $id . ' type="module"', $tag );
	}

	/**
	 * Enqueue assets on our screens only.
	 *
	 * @param string $hookSuffix Current admin screen hook suffix.
	 */
	public function enqueueAdminAssets( string $hookSuffix ): void {
		if ( ! AdminShell::isSuiteScreen( $hookSuffix ) ) {
			return;
		}

		$this->enqueue( ADMIN_SUITE_URL, ADMIN_SUITE_DIR );
	}

	/**
	 * Enqueue the entry module and its stylesheet.
	 *
	 * In development the assets are served by the Vite dev server; in production
	 * they are resolved from `.vite/manifest.json` next to the built assets.
	 *
	 * @param string $baseUrl Public URL of the plugin directory.
	 * @param string $baseDir Absolute path of the plugin directory.
	 */
	public function enqueue( string $baseUrl, string $baseDir ): void {
		if ( $this->isDevMode() ) {
			wp_enqueue_script(
				self::HANDLE,
				self::DEV_SERVER . '/assets/src/main.ts',
				self::SCRIPT_DEPS,
				ADMIN_SUITE_VERSION,
				true
			);

			$this->prepareScript();

			return;
		}

		$entry = $this->resolveEntry( $baseDir . '/assets/dist/.vite/manifest.json' );

		if ( null === $entry ) {
			return;
		}

		foreach ( $entry['css'] as $index => $handle ) {
			wp_enqueue_style(
				'admin-suite-app-' . $index,
				$baseUrl . 'assets/dist/' . $handle,
				array(),
				$this->assetVersion( $baseDir . '/assets/dist/' . $handle )
			);
		}

		wp_enqueue_script(
			self::HANDLE,
			$baseUrl . 'assets/dist/' . $entry['file'],
			self::SCRIPT_DEPS,
			$this->assetVersion( $baseDir . '/assets/dist/' . $entry['file'] ),
			true
		);

		$this->prepareScript();
	}

	/**
	 * Cache-busting version for a built asset.
	 *
	 * The plugin version is the wrong thing to key on. It only moves when someone
	 * remembers to move it, so two builds of the same plugin version produce the
	 * same URL, and a browser that already fetched the first one never asks again.
	 * That is not a development annoyance: a user who updates the plugin keeps the
	 * old bundle and the old stylesheet until they empty the cache by hand, and
	 * the site silently renders the previous design.
	 *
	 * Hashing the file ties the URL to the content, which is the only property
	 * that matters for a build artifact. A fresh checkout still gets a correct
	 * value, because the hash is derived from the bytes that were checked out.
	 *
	 * @param string $path Absolute path to the built file.
	 * @return string Value for the `ver` argument of an enqueue call.
	 */
	private function assetVersion( string $path ): string {
		$hash = md5_file( $path );

		if ( false === $hash ) {
			return ADMIN_SUITE_VERSION;
		}

		return $hash;
	}

	/**
	 * Attach the bootstrap payload and the translation catalogue to the handle.
	 *
	 * Both belong on every build, not only the production one: the bootstrap is
	 * read by the very first line of the module, so a dev-server build that
	 * skipped it would throw before rendering anything.
	 */
	private function prepareScript(): void {
		wp_add_inline_script(
			self::HANDLE,
			'window.ADMIN_SUITE_BOOTSTRAP = ' . wp_json_encode( $this->bootstrapData() ) . ';',
			'before'
		);

		// The path is required: the handle belongs to a plugin rather than to
		// wp-content's core bundle, so WordPress cannot derive where to look
		// for the JSON catalogues. It loads
		// languages/admin-suite-{locale}-{handle}.json and falls back to the
		// untranslated source strings when a locale has no file, which is why
		// an empty languages/ directory is not an error.
		wp_set_script_translations( self::HANDLE, self::TEXT_DOMAIN, ADMIN_SUITE_DIR . self::LANG_DIR );
	}

	/**
	 * Whether the Vite dev server should be used.
	 */
	private function isDevMode(): bool {
		/**
		 * Filters whether Admin Suite loads assets from the Vite dev server.
		 *
		 * @param bool $dev_mode Default false.
		 */
		return (bool) apply_filters( 'admin_suite_use_dev_server', defined( 'ADMIN_SUITE_DEV' ) && constant( 'ADMIN_SUITE_DEV' ) );
	}

	/**
	 * Resolve the manifest entry for the application entry module.
	 *
	 * @param string $manifestPath Absolute path to the Vite manifest.
	 * @return array{file: string, css: list<string>}|null
	 */
	private function resolveEntry( string $manifestPath ): ?array {
		if ( ! is_readable( $manifestPath ) ) {
			return null;
		}

		$decoded = wp_json_file_decode( $manifestPath, array( 'associative' => true ) );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$key = $this->findEntryKey( $decoded );

		if ( null === $key ) {
			return null;
		}

		return array(
			'file' => (string) $decoded[ $key ]['file'],
			'css'  => $this->collectCss( $decoded, $key ),
		);
	}

	/**
	 * Collect every stylesheet the entry depends on.
	 *
	 * Two shapes have to be handled: a normal build lists stylesheets in the
	 * entry chunk's `css` array, while library mode emits them as standalone
	 * manifest entries (for example `style.css`) with no `isEntry` flag.
	 * Missing the second shape ships the app with no styles at all.
	 *
	 * @param array<mixed> $manifest Decoded manifest.
	 * @param string       $entryKey Manifest key of the entry chunk.
	 * @return list<string>
	 */
	private function collectCss( array $manifest, string $entryKey ): array {
		$css = array();

		if ( isset( $manifest[ $entryKey ]['css'] ) && is_array( $manifest[ $entryKey ]['css'] ) ) {
			foreach ( $manifest[ $entryKey ]['css'] as $handle ) {
				if ( is_string( $handle ) ) {
					$css[] = $handle;
				}
			}
		}

		foreach ( $manifest as $chunk ) {
			if ( ! is_array( $chunk ) || ! isset( $chunk['file'], $chunk['src'] ) ) {
				continue;
			}

			if ( ! is_string( $chunk['file'] ) || ! is_string( $chunk['src'] ) ) {
				continue;
			}

			if ( ! str_ends_with( $chunk['src'], '.css' ) ) {
				continue;
			}

			$css[] = $chunk['file'];
		}

		return array_values( array_unique( $css ) );
	}

	/**
	 * Find the manifest key of the application entry chunk.
	 *
	 * Vite flags entry chunks with `isEntry`; relying on that instead of a
	 * hardcoded source path means renaming `src/main.ts` needs no PHP change.
	 *
	 * @param array<mixed> $manifest Decoded manifest.
	 */
	private function findEntryKey( array $manifest ): ?string {
		$candidates = array();

		foreach ( $manifest as $key => $chunk ) {
			if ( ! is_array( $chunk ) || ! isset( $chunk['file'] ) || ! is_string( $chunk['file'] ) ) {
				continue;
			}

			if ( isset( $chunk['isEntry'] ) && true === $chunk['isEntry'] ) {
				return (string) $key;
			}

			$candidates[] = (string) $key;
		}

		// Single-chunk builds may omit the flag; fall back to the first chunk.
		return $candidates[0] ?? null;
	}

	/**
	 * Data handed to the SPA on boot (REST root, nonce, capabilities).
	 *
	 * `admin` and `suiteUrl` are the multisite part. The suite takes over the
	 * dashboard, and each admin area puts its dashboard at a different path
	 * (`/wp-admin/index.php`, `/wp-admin/network/index.php`,
	 * `/wp-admin/user/index.php`). detect() is trustworthy here, unlike in a REST
	 * request, because this only runs while rendering a real screen, where both
	 * the WP_NETWORK_ADMIN / WP_USER_ADMIN constants and the current screen
	 * are set. The SPA uses both values as the base of its REST calls and of
	 * its hash router, so a hardcoded path would send the network admin's
	 * requests to the site admin.
	 *
	 * `locale` is `determine_locale()` rather than `get_locale()`: inside wp-admin
	 * that is the *user's* locale, and it is the very same function
	 * `wp_set_script_translations()` resolves the JSON catalogue against, so the
	 * strings the SPA looks up and the locale its `Intl` formatters use cannot
	 * end up describing two different languages.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrapData(): array {
		$admin = AdminContext::detect();
		$base  = AdminContext::baseUrl( $admin );

		return array(
			'restUrl'      => esc_url_raw( rest_url( 'admin-suite/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'homeUrl'      => esc_url_raw( $base ),
			'admin'        => $admin,
			'suiteUrl'     => esc_url_raw( $base . 'index.php' ),
			'canManage'    => current_user_can( 'manage_options' ),
			'canEdit'      => current_user_can( 'edit_posts' ),
			'canUpload'    => current_user_can( 'upload_files' ),
			'siteName'     => get_bloginfo( 'name' ),
			'locale'       => determine_locale(),
			'pluginVer'    => ADMIN_SUITE_VERSION,
			'newContent'   => $this->newContentMenu(),
			'siteFrontUrl' => $this->siteFrontUrl(),
			'docs'         => $this->documentationMenu(),
		);
	}

	/**
	 * Core's admin bar "New" menu, rebuilt for the SPA toolbar.
	 *
	 * `wp_admin_bar_new_content_menu()` is a plain function that fills whatever
	 * `WP_Admin_Bar` instance it is handed, and the class is happy on a bare
	 * `new WP_Admin_Bar()` with no `initialize()` behind it: a CLI probe
	 * produced an identical node list either way, so nothing has to be faked.
	 * The SPA therefore gets exactly the entries the admin bar itself would
	 * show, in core's order, behind core's capability checks and carrying core's
	 * own translations, and none of the markup coupling that reading the
	 * rendered `#wpadminbar` out of the DOM would mean.
	 *
	 * What is deliberately *not* reproduced is the registration guard. Core only
	 * hooks this in when `! is_network_admin() && ! is_user_admin()`
	 * (`class-wp-admin-bar.php:669`), so in the network and the user admin the
	 * menu does not exist at all. Here that becomes an empty list. The test is
	 * safe to make with `AdminContext::detect()` because this only runs from the
	 * enqueue path, where a real screen and the `WP_NETWORK_ADMIN` constant both
	 * exist; inside a REST request neither does, and the constant in particular
	 * would answer the wrong question.
	 *
	 * Core also emits no node whatsoever when the user cannot create anything,
	 * which is a second and completely legitimate route to an empty menu: an
	 * administrator has no `manage_links`, so there is no Link entry either.
	 *
	 * @return array{label: string, items: list<array{id: string, label: string, url: string}>}
	 */
	private function newContentMenu(): array {
		if ( AdminContext::SITE !== AdminContext::detect() ) {
			return self::emptyMenu();
		}

		if ( ! function_exists( 'wp_admin_bar_new_content_menu' ) ) {
			return self::emptyMenu();
		}

		$bar = self::emptyBar();

		if ( ! $bar instanceof \WP_Admin_Bar ) {
			return self::emptyMenu();
		}

		wp_admin_bar_new_content_menu( $bar );

		return $this->menuFromBar( $bar, 'new-content' );
	}

	/**
	 * The front-end link, taken from the node core itself publishes.
	 *
	 * `wp_admin_bar_site_menu()` decides where "Visit Site" points, which is not
	 * always `home_url( '/' )`: on an install whose `home` and `siteurl` differ,
	 * reading core's own node is the difference between the right answer and a
	 * plausible one. That node is added unconditionally, outside the network and
	 * user admin guard, so there is nothing to test here.
	 */
	private function siteFrontUrl(): string {
		if ( ! function_exists( 'wp_admin_bar_site_menu' ) ) {
			return '';
		}

		$bar = self::emptyBar();

		if ( ! $bar instanceof \WP_Admin_Bar ) {
			return '';
		}

		wp_admin_bar_site_menu( $bar );

		$nodes = $bar->get_nodes();

		if ( ! is_array( $nodes ) ) {
			return '';
		}

		foreach ( $nodes as $node ) {
			if ( 'view-site' === $node->id ) {
				return esc_url_raw( (string) $node->href );
			}
		}

		return '';
	}

	/**
	 * The documentation menu, which in the admin bar is the WordPress logo: the
	 * first menu on the left, holding About WordPress, Get Involved, WordPress.org,
	 * Documentation, Learn WordPress, Support and Feedback.
	 *
	 * `wp_admin_bar_wp_menu()` is registered at priority 10, outside the network
	 * and user admin guard, so this menu does exist in all three admin areas and
	 * needs no test of its own.
	 *
	 * @return array{label: string, items: list<array{id: string, label: string, url: string}>}
	 */
	private function documentationMenu(): array {
		if ( ! function_exists( 'wp_admin_bar_wp_menu' ) ) {
			return self::emptyMenu();
		}

		$bar = self::emptyBar();

		if ( ! $bar instanceof \WP_Admin_Bar ) {
			return self::emptyMenu();
		}

		wp_admin_bar_wp_menu( $bar );

		return $this->menuFromBar( $bar, 'wp-logo' );
	}

	/**
	 * A bare admin bar to hand a core builder.
	 *
	 * `initialize()` is not required: a CLI probe produced an identical node list
	 * with and without it, because every builder under `wp-includes/admin-bar.php`
	 * supplies its own node ids. The class file is not loaded in a REST request,
	 * hence the `class_exists()` test.
	 */
	private static function emptyBar(): ?\WP_Admin_Bar {
		if ( ! class_exists( '\WP_Admin_Bar' ) ) {
			return null;
		}

		return new \WP_Admin_Bar();
	}

	/**
	 * The shape a menu takes when there is nothing to offer, which is a normal
	 * outcome and not an error: core hides a menu rather than rendering it empty.
	 *
	 * @return array{label: string, items: list<never>}
	 */
	private static function emptyMenu(): array {
		return array(
			'label' => '',
			'items' => array(),
		);
	}

	/**
	 * Flattens a built bar into a label and a list of links.
	 *
	 * The parent node carries the menu's own title wrapped in the admin bar's
	 * icon and label spans, so its tags are stripped; every other node is a
	 * child link. `get_nodes()` is typed `array|null` in the stubs, so the
	 * result is checked rather than looped over.
	 *
	 * @param \WP_Admin_Bar $bar     A bar a core builder has already filled.
	 * @param string        $parentId Node id whose title becomes the menu label.
	 *
	 * @return array{label: string, items: list<array{id: string, label: string, url: string}>}
	 */
	private function menuFromBar( \WP_Admin_Bar $bar, string $parentId ): array {
		$nodes = $bar->get_nodes();

		if ( ! is_array( $nodes ) ) {
			return self::emptyMenu();
		}

		$items = array();
		$label = '';

		foreach ( $nodes as $node ) {
			$text = wp_strip_all_tags( (string) $node->title );

			if ( $parentId === $node->id ) {
				$label = $text;

				continue;
			}

			$items[] = array(
				'id'    => (string) $node->id,
				'label' => $text,
				'url'   => esc_url_raw( (string) $node->href ),
			);
		}

		if ( array() === $items ) {
			return self::emptyMenu();
		}

		return array(
			'label' => $label,
			'items' => $items,
		);
	}
}
