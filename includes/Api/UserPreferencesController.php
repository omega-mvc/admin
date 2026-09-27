<?php
/**
 * User preferences controller: per-user UI state.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET/POST /admin-suite/v1/user-preferences.
 */
final class UserPreferencesController {

	/**
	 * User meta key.
	 */
	private const META_KEY = 'admin_suite_preferences';

	/**
	 * Supported preference keys mapped to the callable that sanitizes them.
	 */
	private const SCHEMA = array(
		'sidebar'   => 'sanitize_key',
		'density'   => 'sanitize_key',
		'lastRoute' => 'sanitize_text_field',
	);

	/**
	 * Allowed values for the enumerated preferences.
	 */
	private const ENUMS = array(
		'sidebar' => array( 'expanded', 'collapsed' ),
		'density' => array( 'compact', 'comfortable' ),
	);

	/**
	 * Defaults.
	 */
	private const DEFAULTS = array(
		'sidebar'   => 'expanded',
		'density'   => 'comfortable',
		'lastRoute' => '/dashboard',
	);

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/user-preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getPreferences' ),
					'permission_callback' => array( $this, 'canEdit' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'updatePreferences' ),
					'permission_callback' => array( $this, 'canEdit' ),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function canEdit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Read the current user's preferences.
	 */
	public function getPreferences(): \WP_REST_Response {
		return rest_ensure_response( $this->current() );
	}

	/**
	 * The stored preferences, merged over the defaults.
	 *
	 * The write path used to reach back into the response with a `(array)` cast
	 * on `get_data()` to get this same array. Shaping it in one method means the
	 * two paths cannot drift apart, and gives the reader a string-keyed type
	 * rather than a bare cast.
	 *
	 * @return array<string, mixed> Preferences, defaults first.
	 */
	private function current(): array {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return self::DEFAULTS;
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::DEFAULTS, array_intersect_key( $stored, self::DEFAULTS ) );
	}

	/**
	 * Persist a partial preferences payload for the current user.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming request.
	 */
	public function updatePreferences( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'admin_suite_not_logged_in',
				__( 'You must be logged in to save preferences.', 'admin-suite' ),
				array( 'status' => 401 )
			);
		}

		/**
		 * The raw request body. Core returns null from get_json_params() when the
		 * body is not JSON, which the stub does not declare, so the null is
		 * restated here. Without it the check below is provably dead, and
		 * dropping the check would hand null to the rest of the method.
		 *
		 * @var array<array-key, mixed>|null $payload
		 */
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$clean = $this->sanitize( $payload, $this->current() );

		update_user_meta( $user_id, self::META_KEY, $clean );

		return rest_ensure_response( $clean );
	}

	/**
	 * Sanitize the payload, dropping unknown keys.
	 *
	 * The payload keys are `array-key` and not `string` on purpose: a JSON body
	 * that is a list arrives with integer keys, and the loop below only ever
	 * looks up the schema's own string keys, so those entries are dropped.
	 *
	 * @param array<array-key, mixed> $payload Incoming payload.
	 * @param array<string, mixed>    $current Current preferences.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $payload, array $current ): array {
		$clean = $current;

		foreach ( self::SCHEMA as $key => $callback ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$raw   = $payload[ $key ];
			$value = call_user_func( $callback, is_scalar( $raw ) ? (string) $raw : '' );

			if ( isset( self::ENUMS[ $key ] ) && ! in_array( $value, self::ENUMS[ $key ], true ) ) {
				continue;
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}
}
