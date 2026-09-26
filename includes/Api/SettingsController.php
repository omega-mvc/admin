<?php
/**
 * Settings controller: plugin-level options.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET/POST /admin-suite/v1/settings.
 */
final class SettingsController {

	/**
	 * Option name holding the suite settings.
	 */
	public const OPTION = 'admin_suite_settings';

	/**
	 * Defaults for every supported setting.
	 */
	private const DEFAULTS = array(
		'accent'        => 'indigo',
		'density'       => 'comfortable',
		'sidebar'       => 'expanded',
		'enablePlugins' => true,
	);

	/**
	 * Allowed values per setting.
	 */
	private const SCHEMA = array(
		'accent'        => array( 'indigo', 'emerald', 'amber', 'rose', 'slate' ),
		'density'       => array( 'compact', 'comfortable' ),
		'sidebar'       => array( 'expanded', 'collapsed' ),
		'enablePlugins' => array( true, false ),
	);

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'updateSettings' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Read the current settings.
	 */
	public function getSettings(): \WP_REST_Response {
		return rest_ensure_response( $this->read() );
	}

	/**
	 * Validate and persist a partial settings payload.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming request.
	 */
	public function updateSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$current = $this->read();
		$clean   = $this->sanitize( $payload, $current );

		update_option( self::OPTION, $clean, false );

		return rest_ensure_response( $clean );
	}

	/**
	 * Merge stored options with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function read(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::DEFAULTS, array_intersect_key( $stored, self::DEFAULTS ) );
	}

	/**
	 * Sanitize an incoming payload against the schema.
	 *
	 * Unknown keys are dropped rather than stored, so a rogue client cannot
	 * persist arbitrary options under this key.
	 *
	 * @param array<string, mixed> $payload Incoming payload.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $payload, array $current ): array {
		$clean = $current;

		foreach ( self::SCHEMA as $key => $allowed ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$value = $payload[ $key ];

			if ( is_bool( $allowed[0] ) ) {
				$value = $this->toBool( $value );
			} else {
				$value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
			}

			if ( in_array( $value, $allowed, true ) ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Coerce a JSON scalar to bool.
	 *
	 * `rest_sanitize_boolean()` maps the strings "false" and "0" to false,
	 * which is what the SPA sends; plain casting would treat them as true.
	 *
	 * @param mixed $value Raw payload value.
	 */
	private function toBool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'off', 'no' ), true );
		}

		return (bool) $value;
	}
}
