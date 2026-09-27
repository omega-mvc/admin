<?php
/**
 * Search controller: unified search powering the command palette.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET /admin-suite/v1/search.
 */
final class SearchController {

	/**
	 * Maximum number of results returned per group.
	 */
	private const PER_GROUP_LIMIT = 5;

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'canSearch' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 2,
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 */
	public function canSearch(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Run the unified search.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$term = $request->get_param( 'q' );
		$term = is_scalar( $term ) ? (string) $term : '';

		$found = array(
			'posts'    => $this->searchPosts( $term ),
			'terms'    => $this->searchTerms( $term ),
			'users'    => $this->searchUsers( $term ),
			'settings' => $this->searchSettings( $term ),
		);

		$found = array_filter(
			$found,
			static fn ( array $group ): bool => array() !== $group
		);

		$response = rest_ensure_response(
			array(
				'term'   => $term,
				'groups' => $found,
				'total'  => array_sum( array_map( 'count', $found ) ),
			)
		);

		// Short-lived private cache: safe for one user, avoids hammering the DB.
		$response->header( 'Cache-Control', 'private, max-age=15' );

		return $response;
	}

	/**
	 * Search posts the user may edit.
	 *
	 * @param string $term Search term.
	 * @return list<array<string, mixed>>
	 */
	private function searchPosts( string $term ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'                      => $term,
				'posts_per_page'         => self::PER_GROUP_LIMIT,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'orderby'                => 'relevance',
			)
		);

		$results = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$results[] = array(
				'id'    => $post->ID,
				'type'  => 'post',
				'label' => wp_strip_all_tags( (string) get_the_title( $post ) ),
				'sub'   => (string) $post->post_type,
				'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $results;
	}

	/**
	 * Search taxonomy terms.
	 *
	 * @param string $term Search term.
	 * @return list<array<string, mixed>>
	 */
	private function searchTerms( string $term ): array {
		$taxonomies = get_taxonomies(
			array(
				'public'            => true,
				'show_in_admin_bar' => true,
			)
		);

		if ( array() === $taxonomies ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'name'       => $term,
				'hide_empty' => false,
				'number'     => self::PER_GROUP_LIMIT,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$results = array();

		foreach ( $terms as $found ) {
			$results[] = array(
				'id'    => $found->term_id,
				'type'  => 'term',
				'label' => wp_strip_all_tags( $found->name ),
				'sub'   => (string) $found->taxonomy,
				'url'   => (string) get_edit_term_link( $found->term_id, $found->taxonomy, 'raw' ),
			);
		}

		return $results;
	}

	/**
	 * Search users, only for users allowed to list them.
	 *
	 * @param string $term Search term.
	 * @return list<array<string, mixed>>
	 */
	private function searchUsers( string $term ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			return array();
		}

		/**
		 * The matched users.
		 *
		 * @var list<\WP_User> $users Documented as WP_User[] whatever the query.
		 */
		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => self::PER_GROUP_LIMIT,
				'count_total'    => false,
			)
		);

		return array_map(
			static fn ( \WP_User $user ): array => array(
				'id'    => $user->ID,
				'type'  => 'user',
				'label' => wp_strip_all_tags( $user->display_name ),
				'sub'   => (string) $user->user_email,
				'url'   => (string) get_edit_user_link( $user->ID ),
			),
			$users
		);
	}

	/**
	 * Match site settings by label, for administrators only.
	 *
	 * @param string $term Search term.
	 * @return list<array<string, mixed>>
	 */
	private function searchSettings( string $term ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		$matches = array();

		foreach ( array(
			'Site Title' => 'blogname',
			'Tagline'    => 'blogdescription',
			'Timezone'   => 'timezone_string',
		) as $label => $option ) {
			if ( false === stripos( $label . ' ' . $option, $term ) ) {
				continue;
			}

			$matches[] = array(
				'id'    => $option,
				'type'  => 'setting',
				'label' => $label,
				'sub'   => 'settings',
				'url'   => admin_url( 'options-general.php' ),
			);
		}

		return $matches;
	}
}
