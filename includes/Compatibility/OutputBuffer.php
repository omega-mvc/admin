<?php
/**
 * Output buffer: isolates legacy plugin screens inside the SPA sandbox.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Compatibility;

use AdminSuite\Core\AdminShell;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the markup of unsupported admin screens so it can be rendered
 * inside the Vue app without inheriting wp-admin's global styles.
 *
 * Screens listed in the `admin_suite_sandbox_screens` filter are buffered on
 * their own admin request, the buffer is stored in a transient, and the SPA
 * later renders it inside a shadow-root friendly container.
 */
final class OutputBuffer {

	/**
	 * Transient holding the captured markup.
	 */
	private const TRANSIENT = 'admin_suite_captured_screen';

	/**
	 * Register the hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybeStartBuffer' ), 0 );
		add_filter( 'admin_suite_sandbox_screens', array( $this, 'defaultScreens' ) );
	}

	/**
	 * Screens buffered by default: the "legacy sandbox" query flag.
	 *
	 * @param list<string> $screens Existing screen slugs.
	 * @return list<string>
	 */
	public function defaultScreens( array $screens ): array {
		$screens[] = 'admin-suite-sandbox';

		return $screens;
	}

	/**
	 * Start buffering when the current request asks for the sandbox.
	 */
	public function maybeStartBuffer(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag.
		if ( ! isset( $_GET['admin-suite-sandbox'] ) ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		ob_start( array( $this, 'capture' ) );
	}

	/**
	 * Store the captured markup in a transient keyed by user and URL.
	 *
	 * @param string $output Buffered output.
	 * @return string Always an empty string, so nothing is printed twice.
	 */
	public function capture( string $output ): string {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || '' === trim( $output ) ) {
			return $output;
		}

		$key = self::TRANSIENT . '_' . $user_id . '_' . md5( $this->currentUrl() );

		set_transient( $key, $output, HOUR_IN_SECONDS );

		// Returning an empty string suppresses the captured markup on this request.
		return '';
	}

	/**
	 * Read a previously captured screen.
	 *
	 * @param string $url Absolute URL of the screen to render.
	 */
	public function retrieve( string $url ): string {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return '';
		}

		$value = get_transient( self::TRANSIENT . '_' . $user_id . '_' . md5( $url ) );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Current request URL, normalised.
	 */
	private function currentUrl(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$scheme = is_ssl() ? 'https' : 'http';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host ) {
			return '';
		}

		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * Whether a screen should be sandboxed rather than replaced.
	 *
	 * @param string $screen Screen hook suffix.
	 */
	public function shouldSandbox( string $screen ): bool {
		/** This filter is documented in wp-includes/plugin.php */
		$screens = (array) apply_filters( 'admin_suite_sandbox_screens', array() );

		return in_array( $screen, $screens, true ) && ! AdminShell::isSuiteScreen( $screen );
	}
}
