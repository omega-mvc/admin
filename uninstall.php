<?php
/**
 * Uninstall routine: removes plugin options and user meta.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'admin_suite_settings' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must run on uninstall.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'admin_suite_preferences' ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.WP.I18n.SqlIdentifiedByQuery -- Uninstall cleanup.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_admin_suite_captured_screen_%'" );
