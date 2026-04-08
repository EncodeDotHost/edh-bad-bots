<?php
/**
 * EDH Bad Bots - Uninstall
 *
 * Runs automatically when the plugin is deleted via WP Admin > Plugins.
 * Removes all custom database tables and plugin options to leave the site clean.
 *
 * Note: .htaccess rules are NOT removed here — deactivation already handles that.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom database tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}edhbb_blocked_bots" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}edhbb_whitelisted_ips" );
// phpcs:enable

// Remove plugin options.
delete_option( 'edhbb_enable_htaccess_blocking' );
delete_option( 'edhbb_block_duration_days' );
delete_option( 'edhbb_db_version' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// Remove the column-exists cache options written by column_exists() (keyed with edhbb_col_ prefix).
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'edhbb_col_' ) . '%'
    )
);

// Remove all plugin transients (hostname cache, blocked/whitelist IP cache).
// WordPress will eventually garbage-collect these, but a clean uninstall removes them immediately.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_edhbb_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_edhbb_' ) . '%'
    )
);

// phpcs:enable
