<?php
/**
 * Dashboard controller: data, native widget inventory and layout persistence.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Api;

use AdminSuite\Core\DashboardHelp;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET /admin-suite/v1/dashboard, GET .../dashboard/help,
 * GET .../dashboard/widget/<id> and POST .../dashboard.
 *
 * The grid is a mix of two kinds of panel: native widgets registered through
 * wp_add_dashboard_widget(), and panels the SPA renders itself. Both kinds live
 * in the same saved layout so a single drag handle moves either, and the built-in
 * ids are whitelisted server-side exactly like native ones.
 */
final class DashboardController {

	/**
	 * User meta key holding the ordered widget layout.
	 */
	private const LAYOUT_META = 'admin_suite_dashboard_layout';

	/**
	 * How many recent posts to return.
	 */
	private const RECENT_POSTS = 5;

	/**
	 * How many activity entries to return.
	 */
	private const ACTIVITY_LIMIT = 8;

	/**
	 * Hard ceiling on captured native widget HTML, in bytes.
	 *
	 * Native callbacks are arbitrary third-party code; the cap keeps a runaway
	 * widget from filling the response.
	 */
	private const MAX_WIDGET_BYTES = 262144;

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/dashboard',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'getDashboard' ),
					'permission_callback' => array( $this, 'canRead' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'saveLayout' ),
					'permission_callback' => array( $this, 'canEditDashboard' ),
				),
			)
		);

		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/dashboard/widget/(?P<id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getWidget' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Native dashboard widget id.', 'admin-suite' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			ADMIN_SUITE_REST_NAMESPACE,
			'/dashboard/help',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getHelp' ),
				'permission_callback' => array( $this, 'canRead' ),
			)
		);
	}

	/**
	 * Contextual help tabs, rebuilt from core's own strings.
	 *
	 * Kept off `GET /dashboard` deliberately: the help markup is a few kilobytes
	 * of translated HTML that most visits never open, so the SPA fetches it when
	 * the Help panel is first expanded.
	 */
	public function getHelp(): \WP_REST_Response {
		$response = rest_ensure_response( DashboardHelp::payload() );

		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Any logged-in user may read the dashboard.
	 */
	public function canRead(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Reordering the dashboard requires the same capability core uses for
	 * customising it.
	 */
	public function canEditDashboard(): bool {
		return current_user_can( 'edit_dashboard' );
	}

	/**
	 * Build the whole dashboard payload.
	 */
	public function getDashboard(): \WP_REST_Response {
		$widgets = $this->allWidgets();

		$response = rest_ensure_response(
			array(
				'site'        => $this->site(),
				'counts'      => $this->counts(),
				'recentPosts' => $this->recentPosts(),
				'activity'    => $this->activity(),
				'widgets'     => array_values( $widgets ),
				'layout'      => $this->reconcileLayout( array_keys( $widgets ) ),
			)
		);

		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Capture the rendered HTML of a single native dashboard widget.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming request.
	 */
	public function getWidget( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = $request->get_param( 'id' );
		$id = is_string( $id ) ? $id : '';

		$widgets = $this->allWidgets();

		if ( ! isset( $widgets[ $id ] ) ) {
			return new \WP_Error(
				'admin_suite_unknown_widget',
				__( 'Unknown dashboard widget.', 'admin-suite' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * Built-in panels have no PHP callback: the SPA owns their markup, so
		 * asking for HTML is a client bug rather than a missing widget.
		 */
		if ( 'suite' === $widgets[ $id ]['context'] ) {
			return new \WP_Error(
				'admin_suite_widget_is_builtin',
				__( 'This panel is rendered by the dashboard itself and has no server-side HTML.', 'admin-suite' ),
				array( 'status' => 409 )
			);
		}

		$box = $this->metaBoxes()[ $id ] ?? null;

		if ( ! is_array( $box ) || ! isset( $box['callback'] ) ) {
			return new \WP_Error(
				'admin_suite_widget_not_renderable',
				__( 'This widget has no render callback.', 'admin-suite' ),
				array( 'status' => 409 )
			);
		}

		$html = $this->renderCallback( $box );

		return rest_ensure_response(
			array(
				'id'    => $id,
				'title' => $widgets[ $id ]['title'],
				'html'  => $html,
			)
		);
	}

	/**
	 * Persist the widget order and visibility for the current user.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming request.
	 */
	public function saveLayout( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'admin_suite_not_logged_in',
				__( 'You must be logged in to save the layout.', 'admin-suite' ),
				array( 'status' => 401 )
			);
		}

		$payload = $request->get_json_params();
		$layout  = ( is_array( $payload ) && isset( $payload['layout'] ) && is_array( $payload['layout'] ) )
			? $payload['layout']
			: null;

		if ( null === $layout ) {
			return new \WP_Error(
				'rest_missing_callback_param',
				__( 'Missing parameter(s): layout', 'admin-suite' ),
				array( 'status' => 400 )
			);
		}

		$known  = array_keys( $this->allWidgets() );
		$clean  = $this->sanitizeLayout( $layout, $known );
		$merged = $this->reconcileLayout( $known, $clean );

		update_user_meta( $user_id, self::LAYOUT_META, $merged );

		return rest_ensure_response( array( 'layout' => $merged ) );
	}

	/**
	 * Site level information shown in the dashboard header.
	 *
	 * @return array<string, string>
	 */
	private function site(): array {
		return array(
			'name'     => (string) get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
			'adminUrl' => admin_url( '/' ),
			'language' => (string) get_bloginfo( 'language' ),
			'charset'  => (string) get_bloginfo( 'charset' ),
			'timezone' => (string) wp_timezone_string(),
			'version'  => (string) get_bloginfo( 'version' ),
			'php'      => PHP_VERSION,
		);
	}

	/**
	 * Content counters for the stat tiles.
	 *
	 * @return array<string, int>
	 */
	private function counts(): array {
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$media    = wp_count_posts( 'attachment' );
		$comments = wp_count_comments();
		$users    = count_users();

		return array(
			'posts'           => $this->total( $posts ),
			'postsDraft'      => isset( $posts->draft ) ? (int) $posts->draft : 0,
			'pages'           => $this->total( $pages ),
			'media'           => isset( $media->inherit ) ? (int) $media->inherit : 0,
			'users'           => isset( $users['total_users'] ) ? (int) $users['total_users'] : 0,
			'commentsPending' => isset( $comments->moderated ) ? (int) $comments->moderated : 0,
		);
	}

	/**
	 * Sum the statuses of a post type that a user would count as content.
	 *
	 * `auto-draft` and `trash` are excluded: WordPress creates an auto-draft
	 * row for every post that is merely opened in the editor, so including it
	 * inflates the counter and makes "Posts" disagree with the Posts list screen.
	 *
	 * @param \stdClass|null $counts Result of wp_count_posts().
	 */
	private function total( ?\stdClass $counts ): int {
		if ( ! $counts instanceof \stdClass ) {
			return 0;
		}

		$skip = array( 'auto-draft', 'trash' );
		$sum  = 0;

		foreach ( get_object_vars( $counts ) as $status => $value ) {
			if ( in_array( (string) $status, $skip, true ) || ! is_numeric( $value ) ) {
				continue;
			}

			$sum += (int) $value;
		}

		return $sum;
	}

	/**
	 * The most recent posts, in a shape the Vue tiles can render directly.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function recentPosts(): array {
		$query = new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page'      => self::RECENT_POSTS,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$out = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$out[] = array(
				'id'     => (int) $post->ID,
				'title'  => (string) get_the_title( $post ),
				'status' => (string) $post->post_status,
				'author' => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				'date'   => (string) get_post_modified_time( 'c', true, $post ),
				'url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
				'thumb'  => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
			);
		}

		return $out;
	}

	/**
	 * Normalised activity feed, built from the same `activity` option core uses
	 * for its own Activity widget.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function activity(): array {
		$stored = get_option( 'activity' );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$stored = array_reverse( $stored, false );
		$out    = array();

		foreach ( $stored as $key => $entry ) {
			if ( count( $out ) >= self::ACTIVITY_LIMIT ) {
				break;
			}

			$out[] = $this->normaliseActivity( is_array( $entry ) ? $entry : array(), (string) $key );
		}

		return $out;
	}

	/**
	 * Flatten one raw activity record.
	 *
	 * The stored shape varies by activity type and across WordPress versions,
	 * so every field is treated as optional.
	 *
	 * @param array<mixed> $entry Raw record.
	 * @param string       $key   Array key, which is the record id.
	 * @return array<string, mixed>
	 */
	private function normaliseActivity( array $entry, string $key ): array {
		$time = isset( $entry['time'] ) && is_numeric( $entry['time'] ) ? (int) $entry['time'] : 0;
		$type = isset( $entry['comment'] ) ? 'comment' : 'update';

		$text = array();

		foreach ( array( 'message', 'text', 'title' ) as $field ) {
			if ( isset( $entry[ $field ] ) && is_scalar( $entry[ $field ] ) ) {
				$text[] = (string) $entry[ $field ];
			}
		}

		return array(
			'id'      => $key,
			'type'    => $type,
			'actor'   => isset( $entry['author'] ) && is_scalar( $entry['author'] ) ? (string) $entry['author'] : '',
			'subject' => isset( $entry['object'] ) && is_scalar( $entry['object'] ) ? (string) $entry['object'] : '',
			'summary' => wp_strip_all_tags( trim( implode( ' ', $text ) ) ),
			'time'    => $time > 0 ? $this->isoTime( $time ) : '',
		);
	}

	/**
	 * Convert a Unix timestamp to an ISO 8601 string in the site timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function isoTime( int $timestamp ): string {
		return (string) wp_date( 'c', $timestamp );
	}

	/**
	 * The full panel inventory: SPA panels first, then native widgets.
	 *
	 * `span` is how many of the three grid columns a panel occupies, so the SPA
	 * never has to know that a native 'normal' context widget is wider than a
	 * 'side' one.
	 *
	 * @return array<string, array<string, string|int>> Keyed by panel id.
	 */
	private function allWidgets(): array {
		$widgets = array();

		foreach ( $this->builtInPanels() as $id => $panel ) {
			$widgets[ $id ] = array(
				'id'      => (string) $id,
				'title'   => (string) $panel['title'],
				'context' => (string) $panel['context'],
				'span'    => (int) $panel['span'],
			);
		}

		foreach ( $this->nativeWidgets() as $id => $widget ) {
			// A built-in id always wins: the SPA knows how to render it.
			if ( isset( $widgets[ $id ] ) ) {
				continue;
			}

			$context = (string) ( $widget['context'] ?? 'normal' );

			$widgets[ $id ] = array(
				'id'      => (string) $id,
				'title'   => (string) ( $widget['title'] ?? $id ),
				'context' => $context,
				'span'    => $this->spanOf( $context ),
			);
		}

		return $widgets;
	}

	/**
	 * The panels the SPA renders itself.
	 *
	 * The titles are translated here rather than held in a constant because a
	 * class constant cannot call __().
	 *
	 * @return array<string, array<string, string|int>> Keyed by panel id.
	 */
	private function builtInPanels(): array {
		return array(
			'suite-stats'        => array(
				'title'   => __( 'Site overview', 'admin-suite' ),
				'context' => 'suite',
				'span'    => 3,
			),
			'suite-recent-posts' => array(
				'title'   => __( 'Recent posts', 'admin-suite' ),
				'context' => 'suite',
				'span'    => 1,
			),
			'suite-activity'     => array(
				'title'   => __( 'Recent activity', 'admin-suite' ),
				'context' => 'suite',
				'span'    => 1,
			),
		);
	}

	/**
	 * How many grid columns a native context occupies.
	 *
	 * WordPress itself lays the dashboard out as one narrow sidebar column
	 * beside a wider main area, so 'side' maps to a single column and the
	 * main contexts span two of the three.
	 *
	 * @param string $context Meta box context.
	 */
	private function spanOf( string $context ): int {
		return 'side' === $context ? 1 : 2;
	}

	/**
	 * The registered native dashboard widgets.
	 *
	 * `wp_add_dashboard_widget()` funnels everything through add_meta_box(),
	 * which needs a current screen, so a temporary one is installed here. A
	 * REST request has none; leaving the 'front' screen behind afterwards is
	 * harmless and keeps the global consistent.
	 *
	 * @return array<string, array<string, string>> Keyed by widget id.
	 */
	private function nativeWidgets(): array {
		$this->loadAdminIncludes();

		$previous = get_current_screen();

		set_current_screen( 'dashboard' );

		try {
			wp_dashboard_setup();

			$widgets = array();

			foreach ( $this->metaBoxes() as $id => $box ) {
				$title = isset( $box['title'] ) && is_scalar( $box['title'] )
					? wp_strip_all_tags( (string) $box['title'] )
					: '';

				$widgets[ $id ] = array(
					'id'      => $id,
					'title'   => '' === $title ? $id : $title,
					'context' => (string) ( $this->contextOf( $id ) ?? 'normal' ),
				);
			}
		} catch ( \Throwable $e ) {
			$widgets = array();
		} finally {
			set_current_screen( $previous instanceof \WP_Screen ? $previous->id : 'front' );
		}

		/**
		 * Allows plugins to contribute dashboard widgets to the SPA.
		 *
		 * @param array<string, array<string, string>> $widgets Keyed by widget id.
		 */
		$filtered = apply_filters( 'admin_suite_dashboard_widgets', $widgets );

		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * Load the wp-admin includes the dashboard builder depends on.
	 *
	 * A REST request never loads them: `get_current_screen()` lives in
	 * screen.php, `add_meta_box()` in template.php and `wp_dashboard_setup()` in
	 * dashboard.php, so each function_exists() guard is load-bearing — checking
	 * only for wp_dashboard_setup() fatals on the first call.
	 */
	private function loadAdminIncludes(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		if ( ! function_exists( 'add_meta_box' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		if ( ! function_exists( 'wp_dashboard_setup' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}
	}

	/**
	 * Flatten `$wp_meta_boxes` for the dashboard screen into id => box.
	 *
	 * `add_meta_box()` stores boxes as
	 * $wp_meta_boxes[$page][$context][$priority][$id], where context is one of
	 * normal|side|high. Context must be the outer loop: 'side' is not a valid
	 * priority, so iterating the axes the other way round silently drops every
	 * sidebar widget (Quick Draft, WordPress Events and News).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function metaBoxes(): array {
		$flat = array();

		foreach ( $this->dashboardBoxes() as $group ) {
			foreach ( array( 'core', 'high', 'low', 'default' ) as $priority ) {
				$boxes = isset( $group[ $priority ] ) && is_array( $group[ $priority ] ) ? $group[ $priority ] : array();

				foreach ( $boxes as $id => $box ) {
					if ( is_array( $box ) && ! isset( $flat[ $id ] ) ) {
						$flat[ $id ] = $box;
					}
				}
			}
		}

		return $flat;
	}

	/**
	 * The dashboard's meta box groups, keyed by context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function dashboardBoxes(): array {
		global $wp_meta_boxes;

		return isset( $wp_meta_boxes['dashboard'] ) && is_array( $wp_meta_boxes['dashboard'] )
			? $wp_meta_boxes['dashboard']
			: array();
	}

	/**
	 * Which dashboard column a widget was registered into.
	 *
	 * @param string $id Widget id.
	 */
	private function contextOf( string $id ): ?string {
		foreach ( $this->dashboardBoxes() as $context => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( array( 'core', 'high', 'low', 'default' ) as $priority ) {
				$boxes = $group[ $priority ] ?? null;

				if ( is_array( $boxes ) && isset( $boxes[ $id ] ) ) {
					return (string) $context;
				}
			}
		}

		return null;
	}

	/**
	 * Run a widget callback and capture its output.
	 *
	 * The call mirrors do_meta_boxes() exactly: it invokes every meta box
	 * callback as `call_user_func( $callback, $data_object, $box )`, and the
	 * dashboard passes an empty string as $data_object
	 * (wp-admin/includes/dashboard.php).
	 *
	 * Passing $box['args'] as a single argument is *not* equivalent, even though
	 * it looks right: wp_dashboard_quick_press() takes its $message as the first
	 * parameter, so the args array lands there and gets rendered as an admin
	 * notice, which logs "Array to string conversion" on every request.
	 *
	 * @param array<string, mixed> $box The whole meta box definition.
	 */
	private function renderCallback( array $box ): string {
		$callback = $box['callback'] ?? null;

		if ( ! is_callable( $callback ) ) {
			return '';
		}

		ob_start();

		try {
			call_user_func( $callback, '', $box );
		} catch ( \Throwable ) {
			ob_end_clean();

			return '';
		}

		$html = (string) ob_get_clean();

		if ( strlen( $html ) > self::MAX_WIDGET_BYTES ) {
			return '';
		}

		return $html;
	}

	/**
	 * The stored layout, if any.
	 *
	 * @return list<mixed>
	 */
	private function storedLayout(): array {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::LAYOUT_META, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( $stored );
	}

	/**
	 * Merge a proposed layout with the set of widgets that actually exist.
	 *
	 * Stored ids that no longer resolve are dropped, duplicates collapse, and
	 * widgets added by a plugin after the layout was saved are appended rather
	 * than hidden.
	 *
	 * @param list<string>     $known           Registered widget ids.
	 * @param list<mixed>|null $layout      Proposed layout, or null to use the stored one.
	 * @return list<array{id: string, visible: bool}>
	 */
	private function reconcileLayout( array $known, ?array $layout = null ): array {
		$rows = null === $layout ? $this->storedLayout() : $layout;
		$seen = array();
		$out  = array();

		foreach ( $rows as $row ) {
			$id = '';

			if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) ) {
				$id = $row['id'];
			}

			if ( '' === $id || ! in_array( $id, $known, true ) || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$out[]       = array(
				'id'      => $id,
				'visible' => ! isset( $row['visible'] ) || (bool) $row['visible'],
			);
		}

		foreach ( $known as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$out[] = array(
					'id'      => $id,
					'visible' => true,
				);
			}
		}

		return $out;
	}

	/**
	 * Validate an incoming layout payload.
	 *
	 * @param array<mixed> $layout Raw payload.
	 * @param list<string> $known  Registered widget ids.
	 * @return list<array{id: string, visible: bool}>
	 */
	private function sanitizeLayout( array $layout, array $known ): array {
		$clean = array();

		foreach ( $layout as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) ) {
				continue;
			}

			if ( ! in_array( $row['id'], $known, true ) ) {
				continue;
			}

			$clean[] = array(
				'id'      => $row['id'],
				'visible' => ! isset( $row['visible'] ) || (bool) $row['visible'],
			);
		}

		return $clean;
	}
}
