<?php
/**
 * Contextual help for the SPA dashboard.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reproduces the dashboard's WordPress help tabs for the SPA.
 *
 * WordPress registers these inline in `wp-admin/index.php` (lines 36-135) and
 * nowhere else — `grep -rn "help-layout" wp-admin/` returns a single hit. That
 * file is a screen, not an includable API: requiring it from a REST request would
 * render the whole admin. So the tabs cannot be *read* from core, they have to be
 * rebuilt.
 *
 * The duplication is therefore unavoidable, and the price for keeping it honest
 * is to reuse core's own source strings verbatim and translate them with the
 * `default` textdomain, which is where core's catalogue lives. A non-English site
 * therefore gets core's translations, not ours. If a site disagrees with the
 * wording, `admin_suite_dashboard_help` can replace the whole structure.
 */
final class DashboardHelp {

	/**
	 * Build the help payload.
	 *
	 * The return type stays a bare `array` rather than the precise shape: a
	 * filter can return anything, so the exact type is not provable here. It is
	 * asserted on the other side of the wire instead, by `HelpPayload` in
	 * `types/api.ts`. `tabs()` and `sidebar()` below do carry precise types,
	 * because those are the functions actually building the values.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		/**
		 * Filters the SPA dashboard help payload.
		 *
		 * @param array $payload Tabs and sidebar, as built from core's strings.
		 */
		return (array) apply_filters(
			'admin_suite_dashboard_help',
			array(
				'tabs'    => self::tabs(),
				'sidebar' => self::sidebar(),
			)
		);
	}

	/**
	 * The four help tabs, in core's order.
	 *
	 * @return list<array{id: string, title: string, content: string}>
	 */
	private static function tabs(): array {
		$tabs = array();

		$overview  = '<p>' . __( 'Welcome to your WordPress Dashboard!', 'default' ) . '</p>';
		$overview .= '<p>' . __( 'The Dashboard is the first place you will come to every time you log into your site. It is where you will find all your WordPress tools. If you need help, just click the &#8220;Help&#8221; tab above the screen title.', 'default' ) . '</p>';

		$tabs[] = array(
			'id'      => 'overview',
			'title'   => __( 'Overview', 'default' ),
			'content' => $overview,
		);

		$navigation  = '<p>' . __( 'The left-hand navigation menu provides links to all of the WordPress administration screens, with submenu items displayed on hover. You can minimize this menu to a narrow icon strip by clicking on the Collapse Menu arrow at the bottom.', 'default' ) . '</p>';
		$navigation .= '<p>' . __( 'Links in the Toolbar at the top of the screen connect your dashboard and the front end of your site, and provide access to your profile and helpful WordPress information.', 'default' ) . '</p>';

		$tabs[] = array(
			'id'      => 'help-navigation',
			'title'   => __( 'Navigation', 'default' ),
			'content' => $navigation,
		);

		$layout  = '<p>' . __( 'You can use the following controls to arrange your Dashboard screen to suit your workflow. This is true on most other administration screens as well.', 'default' ) . '</p>';
		$layout .= '<p>' . __( '<strong>Screen Options</strong> &mdash; Use the Screen Options tab to choose which Dashboard boxes to show.', 'default' ) . '</p>';
		$layout .= '<p>' . __( '<strong>Drag and Drop</strong> &mdash; To rearrange the boxes, drag and drop by clicking on the title bar of the selected box and releasing when you see a gray dotted-line rectangle appear in the location you want to place the box.', 'default' ) . '</p>';
		$layout .= '<p>' . __( '<strong>Box Controls</strong> &mdash; Click the title bar of the box to expand or collapse it. Some boxes added by plugins may have configurable content, and will show a &#8220;Configure&#8221; link in the title bar if you hover over it.', 'default' ) . '</p>';

		$tabs[] = array(
			'id'      => 'help-layout',
			'title'   => __( 'Layout', 'default' ),
			'content' => $layout,
		);

		// The Content tab is capability-gated in core too; the same gates are
		// applied so the SPA never advertises a box the user cannot see.
		$content = '<p>' . __( 'The boxes on your Dashboard screen are:', 'default' ) . '</p>';

		if ( current_user_can( 'edit_theme_options' ) ) {
			$content .= '<p>' . __( '<strong>Welcome</strong> &mdash; Shows links for some of the most common tasks when setting up a new site.', 'default' ) . '</p>';
		}

		if ( current_user_can( 'view_site_health_checks' ) ) {
			$content .= '<p>' . __( '<strong>Site Health Status</strong> &mdash; Informs you of any potential issues that should be addressed to improve the performance or security of your website.', 'default' ) . '</p>';
		}

		if ( current_user_can( 'edit_posts' ) ) {
			$content .= '<p>' . __( '<strong>At a Glance</strong> &mdash; Displays a summary of the content on your site and identifies which theme and version of WordPress you are using.', 'default' ) . '</p>';
		}

		$content .= '<p>' . __( '<strong>Activity</strong> &mdash; Shows the upcoming scheduled posts, recently published posts, and the most recent comments on your posts and allows you to moderate them.', 'default' ) . '</p>';

		if ( is_blog_admin() && current_user_can( 'edit_posts' ) ) {
			$content .= '<p>' . __( "<strong>Quick Draft</strong> &mdash; Allows you to create a new post and save it as a draft. Also displays links to the 3 most recent draft posts you've started.", 'default' ) . '</p>';
		}

		$content .= '<p>' . sprintf(
			/* translators: %s: WordPress Planet URL. */
			__( '<strong>WordPress Events and News</strong> &mdash; Upcoming events near you as well as the latest news from the official WordPress project and the <a href="%s">WordPress Planet</a>.', 'default' ),
			__( 'https://planet.wordpress.org/', 'default' )
		) . '</p>';

		$tabs[] = array(
			'id'      => 'help-content',
			'title'   => __( 'Content', 'default' ),
			'content' => $content,
		);

		return $tabs;
	}

	/**
	 * The help sidebar: docs, forums and the version line.
	 */
	private static function sidebar(): string {
		$version = get_bloginfo( 'version', 'display' );

		/* translators: %s: WordPress version. */
		$version_text = sprintf( __( 'Version %s', 'default' ), $version );

		// Core only links the version when it is a release build; alpha/beta/RC
		// strings have no documentation page.
		if ( ! preg_match( '/alpha|beta|RC/', $version ) ) {
			$version_text = sprintf(
				'<a href="%1$s">%2$s</a>',
				sprintf(
					/* translators: %s: WordPress version. */
					esc_url( __( 'https://wordpress.org/documentation/wordpress-version/version-%s/', 'default' ) ),
					sanitize_title( $version )
				),
				$version_text
			);
		}

		return '<p><strong>' . __( 'For more information:', 'default' ) . '</strong></p>' .
			'<p>' . __( '<a href="https://wordpress.org/documentation/article/dashboard-screen/">Documentation on Dashboard</a>', 'default' ) . '</p>' .
			'<p>' . __( '<a href="https://wordpress.org/support/forums/">Support forums</a>', 'default' ) . '</p>' .
			'<p>' . $version_text . '</p>';
	}
}
