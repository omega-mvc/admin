<?php
/**
 * Uninstall routine: removes plugin options, user meta and transients.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything the plugin stored in the current site's database.
 *
 * This has to run once per site on multisite. Site options and site transients
 * live in each site's own set of `wp_options` rows, so deleting only the site
 * the uninstall was triggered from would leave every other site of the network
 * with a stale `admin_suite_settings` and orphaned captured screens.
 *
 * The plugin stores no network options, so `wp_sitemeta` is deliberately not
 * touched. If a network option is ever added, it has to be deleted here too.
 *
 * @return void
 */
function admin_suite_uninstall_current_site(): void {
	delete_option( 'admin_suite_settings' );

	/**
	 * The database handle.
	 *
	 * @var wpdb $wpdb WordPress assigns this global without a declaration.
	 */
	global $wpdb;

	// Two LIKE clauses, not one: WordPress stores a transient as the value row
	// `_transient_<name>` plus a separate expiry row
	// `_transient_timeout_<name>`. Matching only the first leaves the expiry
	// rows behind, which is a row leak on every uninstalled site.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must run on uninstall.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.I18n.SqlIdentifiedByQuery -- Uninstall cleanup.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '_transient_admin_suite_captured_screen_%'
		OR option_name LIKE '_transient_timeout_admin_suite_captured_screen_%'"
	);
}

if ( is_multisite() ) {
	$admin_suite_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $admin_suite_site_ids as $admin_suite_site_id ) {
		switch_to_blog( (int) $admin_suite_site_id );
		admin_suite_uninstall_current_site();
		// restore_current_blog(), not the restore_blog() that was removed in
		// WordPress 6.1. Calling the old name would fatal on every multisite
		// uninstall, which is exactly the case this loop exists for.
		restore_current_blog();
	}
} else {
	admin_suite_uninstall_current_site();
}

unset( $admin_suite_site_ids, $admin_suite_site_id );

/**
 * The database handle.
 *
 * @var wpdb $wpdb WordPress assigns this global without a declaration.
 */
// User meta lives in one global table shared by the whole network, so these
// two deletes cover every site and do not need the loop above. Both keys are
// named here because DashboardController writes the layout meta and
// UserPreferencesController writes the preferences meta.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must run on uninstall.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'admin_suite_preferences' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must run on uninstall.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'admin_suite_dashboard_layout' ) );
