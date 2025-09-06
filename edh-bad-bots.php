<?php
/**
 * Plugin Name: EDH Bad Bots
 * Plugin URI: https://github.com/EncodeDotHost/edh-bad-bots
 * Description: This plugin is used to block bots that don't honor the robots.txt file from the site.
 * Version: 1.4.3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Tested up to: 6.8
 * Author: EncodeDotHost
 * Author URI: https://encode.host
 * Contributor: EncodeDotHost, nbwpuk
 * License: GPL v2 or later
 * Text Domain: edh-bad-bots
 *
 * @package edh-bad-bots
 * @author EncodeDotHost
 * @contributor nbwpuk
 * @version 1.4.3
 * @link https://github.com/EncodeDotHost/edh-bad-bots
 * @license GPL v3 or later
 */
declare(strict_types=1);

if(!defined('ABSPATH')) exit;

/**
 * Define plugin constants.
 * This helps in making paths and URLs consistent and easily manageable.
 */
define( 'EDHBB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDHBB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EDHBB_VERSION', '1.4.3' );

/**
 * Include core plugin files.
 * These files contain the main logic and classes for the plugin.
 * Ensure the order of inclusion if there are dependencies (e.g., database before blocker).
 */
require_once EDHBB_PLUGIN_DIR . 'includes/class-edhbb-database.php';
require_once EDHBB_PLUGIN_DIR . 'includes/class-edhbb-dnslookup.php';
require_once EDHBB_PLUGIN_DIR . 'includes/class-edhbb-blocker.php';
require_once EDHBB_PLUGIN_DIR . 'includes/class-edhbb-admin.php';

/**
 * Register plugin activation and deactivation hooks.
 * These functions are called when the plugin is activated or deactivated.
 */
register_activation_hook( __FILE__, 'edhbb_activate_plugin' );
register_deactivation_hook( __FILE__, 'edhbb_deactivate_plugin' );

/**
 * Schedule hostname update cron job on activation.
 */
add_action( 'edhbb_update_hostnames_cron', 'edhbb_update_missing_hostnames' );

/**
 * Schedule the cron event if it's not already scheduled.
 */
if ( ! wp_next_scheduled( 'edhbb_update_hostnames_cron' ) ) {
    wp_schedule_event( time(), 'hourly', 'edhbb_update_hostnames_cron' );
}

/**
 * Activation callback function.
 * This is where database tables and initial options are set up.
 */
function edhbb_activate_plugin() {
    // Instantiate the database class and create tables.
    $edh_database = new EDHBB_Database();
    $edh_database->create_tables();

    // You might also set up initial options here if needed.
}

/**
 * Deactivation callback function.
 * This is where plugin-specific data can be cleaned up.
 */
function edhbb_deactivate_plugin() {
    // Clear the scheduled cron job
    wp_clear_scheduled_hook( 'edhbb_update_hostnames_cron' );
    
    // Optionally, you can drop tables here. Be cautious with this as users might want to reactivate.
    // For now, we'll leave it empty to preserve data on deactivation.
    // $edh_database = new EDHBB_Database();
    // $edh_database->drop_tables();
}

/**
 * Initialize all core plugin classes.
 * This function ensures that all parts of the plugin are loaded and ready to go.
 */
function edhbb_init_plugin() {
    $edh_database = new EDHBB_Database();
    $edh_blocker = new EDHBB_Blocker( $edh_database ); // Pass database instance to blocker
    $edh_admin = new EDHBB_Admin( $edh_database );     // Pass database instance to admin

    // Any other initializations can go here.
}
add_action( 'plugins_loaded', 'edhbb_init_plugin' );

/**
 * Background cron function to update missing hostnames.
 * This runs periodically to resolve hostnames for blocked IPs that don't have them.
 */
function edhbb_update_missing_hostnames() {
    $edh_database = new EDHBB_Database();
    $edh_blocker = new EDHBB_Blocker( $edh_database );
    
    // Get IPs without hostnames (limit to 5 per run to avoid timeouts)
    $ips_without_hostnames = $edh_database->get_blocked_ips_without_hostnames( 5 );
    
    if ( empty( $ips_without_hostnames ) ) {
        return; // Nothing to do
    }
    
    // Use reflection to access the private method (since we need the improved hostname resolution)
    $reflection = new ReflectionClass( $edh_blocker );
    $hostname_method = $reflection->getMethod( 'get_hostname_for_ip' );
    $hostname_method->setAccessible( true );
    
    foreach ( $ips_without_hostnames as $ip_address ) {
        // Get hostname using the improved method
        $hostname = $hostname_method->invoke( $edh_blocker, $ip_address );
        
        // Update the database with the resolved hostname (even if empty)
        $edh_database->update_blocked_bot_hostname( $ip_address, $hostname );
        
        // Log successful resolution if debug logging is enabled
        if ( ! empty( $hostname ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
            error_log( '[EDH Bad Bots] Background hostname resolution: ' . $ip_address . ' -> ' . $hostname );
        }
        
        // Small delay to prevent overwhelming DNS servers
        usleep( 100000 ); // 100ms delay
    }
}

/**
 * Add Disallow for site hash url to robots.txt.
 * This filter modifies the content of the site's robots.txt file.
 */
add_filter( 'robots_txt', function( $output, $public ) {
    /**
     * If "Search engine visibility" is disabled,
     * strongly tell all robots to go away.
     */
    if ( '0' == $public ) {

        $output = "User-agent: *\nDisallow: /\nDisallow: /*\nDisallow: /*?\n";

    } else {

        /**
         * Get site url and create a unique hash per domain to disallow access to the site.
         */
        $site_url = wp_parse_url( site_url() );
        $hash = wp_hash( 'site-' . $site_url[ 'host' ] . '-disallow-rule-' . $site_url[ 'scheme' ]);

        $path     = ( ! empty( $site_url[ 'path' ] ) ) ? $site_url[ 'path' ] : '';

        $output .= "\n";
        $output .= "User-agent: * \n";

        /**
         * Add new disallow.
         */
        $output .= "Disallow: $path/{$hash}/\n";

    }

    return $output;

}, 99, 2 ); // Priority 99, Number of Arguments 2.

/**
 * Add a hidden nofollow link to the site's footer.
 * This link serves as a trap for bad bots that ignore robots.txt.
 * Bots hitting this URL will be identified and potentially blocked.
 */
add_action( 'wp_footer', function() {
    // Check if the site is publicly visible for search engines
    if ( '1' == get_option( 'blog_public' ) ) {
        $site_url = wp_parse_url( site_url() );
        $hash = wp_hash( 'site-' . $site_url[ 'host' ] . '-disallow-rule-' . $site_url[ 'scheme' ]);
        $path = ( ! empty( $site_url[ 'path' ] ) ) ? $site_url[ 'path' ] : '';
        $trap_url = esc_url( home_url( $path . '/' . $hash . '/' ) );

        // Output a visually hidden link with nofollow to act as a bot trap.
        // The link is styled to be invisible to human users but still present in the DOM for bots.
        echo '<div style="position: absolute; left: -9999px; overflow: hidden; height: 1px;">';
        echo '<a href="' . esc_url( $trap_url ) . '" rel="nofollow" tabindex="-1">Sssshhh, secret bot trap!</a>';
        echo '</div>';
    }
});


/**
 * Add a "Settings" link to the plugin action links on the plugins page.
 *
 * @param array $links Array of action links.
 * @return array Modified array of action links.
 */
function edhbb_add_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=edh-bad-bots-settings' ) ) . '">' . __( 'Settings', 'edh-bad-bots' ) . '</a>';
    array_unshift( $links, $settings_link ); // Add the link to the beginning of the array.
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'edhbb_add_plugin_action_links' );
