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
				ADMIN_SUITE_VERSION
			);
		}

		wp_enqueue_script(
			self::HANDLE,
			$baseUrl . 'assets/dist/' . $entry['file'],
			self::SCRIPT_DEPS,
			ADMIN_SUITE_VERSION,
			true
		);

		$this->prepareScript();
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
			'restUrl'   => esc_url_raw( rest_url( 'admin-suite/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'homeUrl'   => esc_url_raw( $base ),
			'admin'     => $admin,
			'suiteUrl'  => esc_url_raw( $base . 'index.php' ),
			'canManage' => current_user_can( 'manage_options' ),
			'canEdit'   => current_user_can( 'edit_posts' ),
			'canUpload' => current_user_can( 'upload_files' ),
			'siteName'  => get_bloginfo( 'name' ),
			'locale'    => determine_locale(),
			'pluginVer' => ADMIN_SUITE_VERSION,
		);
	}
}
