<?php
/**
 * EDH Bad Bots - Admin Class
 *
 * Handles all administrative functionalities, including menu pages, form processing,
 * and displaying blocked bots and whitelisted IPs.
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EDHBB_Admin {

    private $db; // Instance of EDHBB_Database class
    private $admin_page_slug = 'edh-bad-bots-settings'; // Unique slug for the admin page

    /**
     * Constructor for the EDHBB_Admin class.
     *
     * @param EDHBB_Database $db An instance of the EDHBB_Database class.
     */
    public function __construct( EDHBB_Database $db ) {
        $this->db = $db;

        // Add the admin menu page.
        add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );

        // Enqueue admin scripts and styles.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Handle form submissions for whitelisting/unblocking IPs.
        add_action( 'admin_post_edhbb_add_whitelist_ip', array( $this, 'handle_add_whitelist_ip' ) );
        add_action( 'admin_post_edhbb_remove_whitelist_ip', array( $this, 'handle_remove_whitelist_ip' ) );
        add_action( 'admin_post_edhbb_remove_blocked_bot', array( $this, 'handle_remove_blocked_bot' ) );

        // Handle form submission for plugin options.
        add_action( 'admin_post_edhbb_save_options', array( $this, 'handle_save_options' ) );
        
        // Handle manual hostname update trigger.
        add_action( 'admin_post_edhbb_update_hostnames', array( $this, 'handle_update_hostnames' ) );
        
        // Handle force refresh all hostnames trigger.
        add_action( 'admin_post_edhbb_force_refresh_all_hostnames', array( $this, 'handle_force_refresh_all_hostnames' ) );
    }

    /**
     * Adds the plugin's administration menu page.
     */
    public function add_admin_menu_page() {
        add_management_page(
            __( 'Bad Bots Blocker', 'edh-bad-bots' ), // Page title
            __( 'Bad Bots', 'edh-bad-bots' ),         // Menu title
            'manage_options',                         // Capability required to access
            $this->admin_page_slug,                   // Menu slug
            array( $this, 'render_admin_page' )       // Callback function to render the page content
        );
    }

    /**
     * Enqueues admin-specific scripts and styles for the plugin page.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        // Only enqueue on our specific admin page.
        if ( 'tools_page_' . $this->admin_page_slug !== $hook ) {
            return;
        }

        // Enqueue admin CSS.
        wp_enqueue_style(
            'edhbb-admin-style',
            EDHBB_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            EDHBB_VERSION
        );

        // Enqueue admin JavaScript.
        wp_enqueue_script(
            'edhbb-admin-script',
            EDHBB_PLUGIN_URL . 'assets/js/admin-script.js',
            array( 'jquery' ), // Dependency on jQuery, common for admin scripts
            EDHBB_VERSION,
            true // Load in footer
        );

        // Pass any data from PHP to JS (e.g., nonce for AJAX if needed later).
        // wp_localize_script(
        //     'edhbb-admin-script',
        //     'edhbb_admin_vars',
        //     array(
        //         'ajax_url' => admin_url( 'admin-ajax.php' ),
        //         'nonce'    => wp_create_nonce( 'edhbb-admin-nonce' ),
        //     )
        // );
    }

    /**
     * Renders the content of the admin page.
     * This method loads the view file 'admin/views/admin-display.php'.
     */
    public function render_admin_page() {
        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Display any success or error messages (handled by WordPress settings API usually).
        // settings_errors( 'edhbb_messages' ); // Moved this call inside admin-display.php

        // Include the actual HTML content for the admin page.
        // We pass the database instance to the view so it can fetch data.
        $db = $this->db;
        include EDHBB_PLUGIN_DIR . 'admin/views/admin-display.php';
    }

    /**
     * Handles the form submission for adding an IP to the whitelist.
     */
    public function handle_add_whitelist_ip() {
        // Verify nonce for security.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edhbb_add_whitelist_ip_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        $ip_address = isset( $_POST['edhbb_whitelist_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['edhbb_whitelist_ip'] ) ) : '';

        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            if ( $this->db->add_whitelisted_ip( $ip_address ) ) {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_added',
                    sprintf(
                        /* translators: %s: IP address */
                        __( 'IP address %s added to whitelist.', 'edh-bad-bots' ),
                        esc_html( $ip_address )
                    ),
                    'success'
                );
            } else {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_add_fail',
                    sprintf(
                        /* translators: %s: IP address */
                        __( 'Failed to add IP address %s to whitelist (it might already exist).', 'edh-bad-bots' ),
                        esc_html( $ip_address )
                    ),
                    'error'
                );
            }
        } else {
            add_settings_error(
                'edhbb_messages',
                'edhbb_ip_invalid',
                esc_html__( 'Invalid IP address provided for whitelist.', 'edh-bad-bots' ),
                'error'
            );
        }

        // Redirect back to the admin page, maintaining the current tab.
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=whitelist' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Handles the form submission for removing an IP from the whitelist.
     */
    public function handle_remove_whitelist_ip() {
        // Verify nonce for security.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edhbb_remove_whitelist_ip_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        $ip_address = isset( $_POST['edhbb_whitelist_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['edhbb_whitelist_ip'] ) ) : '';

        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            if ( $this->db->remove_whitelisted_ip( $ip_address ) ) {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_removed',
                    sprintf(
                        /* translators: %s: IP address */
                        __( 'IP address %s removed from whitelist.', 'edh-bad-bots' ),
                        esc_html( $ip_address )
                    ),
                    'success'
                );
            } else {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_remove_fail',
                    sprintf(
                        /* translators: %s: IP address */
                        __( 'Failed to remove IP address %s from whitelist.', 'edh-bad-bots' ),
                        esc_html( $ip_address )
                    ),
                    'error'
                );
            }
        } else {
            add_settings_error(
                'edhbb_messages',
                'edhbb_ip_invalid',
                esc_html__( 'Invalid IP address provided for whitelist removal.', 'edh-bad-bots' ),
                'error'
            );
        }

        // Redirect back to the admin page, maintaining the current tab.
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=whitelist' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Handles the form submission for removing a bot from the blocked list (unblocking).
     */
    public function handle_remove_blocked_bot() {
        // Verify nonce for security.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edhbb_remove_blocked_bot_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        $ip_address = isset( $_POST['edhbb_blocked_bot_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['edhbb_blocked_bot_ip'] ) ) : '';

        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            if ( $this->db->remove_blocked_bot( $ip_address ) ) {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_bot_unblocked',
                    sprintf(
                        /* translators: %s: IP address */
                        __( 'Bot IP address %s unblocked.', 'edh-bad-bots' ),
                        esc_html( $ip_address )
                    ),
                    'success'
                );
            } else {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_bot_unblock_fail',
                    sprintf(
                        /* translators: %s: IP address */
                        __( 'Failed to unblock bot IP address %s.', 'edh-bad-bots' ),
                        esc_html( $ip_address )
                    ),
                    'error'
                );
            }
        } else {
            add_settings_error(
                'edhbb_messages',
                'edhbb_ip_invalid',
                esc_html__( 'Invalid IP address provided for bot unblock.', 'edh-bad-bots' ),
                'error'
            );
        }

        // Redirect back to the admin page, maintaining the current tab.
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=blocked' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Handles the form submission for saving plugin options.
     */
    public function handle_save_options() {
        // Verify nonce for security.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edhbb_save_options_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        // Get the value of the .htaccess blocking checkbox.
        // If the checkbox is checked, $_POST['edhbb_enable_htaccess_blocking'] will be 'yes'.
        // If unchecked, it won't be set, so we default to 'no'.
        $enable_htaccess_blocking = isset( $_POST['edhbb_enable_htaccess_blocking'] ) ? 'yes' : 'no';
        update_option( 'edhbb_enable_htaccess_blocking', $enable_htaccess_blocking );

        // Get and sanitize the block duration.
        $block_duration_days = isset( $_POST['edhbb_block_duration_days'] ) ? absint( wp_unslash( $_POST['edhbb_block_duration_days'] ) ) : 30;
        if ( $block_duration_days < 1 ) {
            $block_duration_days = 30; // Reset to default if value is invalid.
        }
        update_option( 'edhbb_block_duration_days', $block_duration_days );

        // After updating the option, trigger an .htaccess update.
        // The update_htaccess_block_rules method in EDH_Database will read the new option
        // and either add or remove the .htaccess rules accordingly.
        $this->db->update_htaccess_block_rules();

        // Add a success message to be displayed on the admin page.
        add_settings_error(
            'edhbb_messages',
            'edhbb_options_saved',
            esc_html__( 'Plugin options saved.', 'edh-bad-bots' ),
            'success'
        );

        // Redirect back to the admin page, specifically the 'options' tab, after saving.
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=options' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit; // Important: Always exit after a redirect to prevent further script execution.
    }

    /**
     * Handles the manual hostname update trigger from the admin interface.
     */
    public function handle_update_hostnames() {
        // Verify nonce for security.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edhbb_update_hostnames_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        // Run the debug function if WP_DEBUG is enabled
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->debug_hostname_resolution();
        }

        // Trigger the hostname update function
        $updated_count = $this->manual_hostname_update();

        // Add a success message to be displayed on the admin page.
        add_settings_error(
            'edhbb_messages',
            'edhbb_hostnames_updated',
            sprintf(
                /* translators: %d: number of updated hostnames */
                _n( 
                    'Updated %d hostname.', 
                    'Updated %d hostnames.', 
                    $updated_count, 
                    'edh-bad-bots' 
                ),
                $updated_count
            ),
            'success'
        );

        // Redirect back to the admin page, specifically the 'blocked' tab.
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=blocked' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Manually updates hostnames for blocked IPs that don't have them.
     * 
     * @return int Number of hostnames updated.
     */
    private function manual_hostname_update() {
        // Get IPs without hostnames (limit to 10 for manual processing)
        $ips_without_hostnames = $this->db->get_blocked_ips_without_hostnames( 10 );
        
        if ( empty( $ips_without_hostnames ) ) {
            return 0; // Nothing to do
        }
        
        // Create a blocker instance to access the hostname resolution method
        $blocker = new EDHBB_Blocker( $this->db );
        
        // Use reflection to access the private method
        $reflection = new ReflectionClass( $blocker );
        $hostname_method = $reflection->getMethod( 'get_hostname_for_ip' );
        $hostname_method->setAccessible( true );
        
        $updated_count = 0;
        
        foreach ( $ips_without_hostnames as $ip_address ) {
            // Use the DNS lookup if available, otherwise fall back to blocker method
            if ( class_exists( 'EDHBB_DNSLookup' ) ) {
                $hostname = EDHBB_DNSLookup::get_hostname_for_blocked_ip( $ip_address );
            } else {
                // Fallback to the original blocker method
                $hostname = $hostname_method->invoke( $blocker, $ip_address );
            }
            
            // Update the database with the resolved hostname (even if empty)
            if ( $this->db->update_blocked_bot_hostname( $ip_address, $hostname ) ) {
                $updated_count++;
            }
        }
        
        return $updated_count;
    }

    /**
     * Debugs hostname resolution for a sample IP address.
     */
    public function debug_hostname_resolution() {
        $debug_info = array();
        $ip_to_test = '8.8.8.8'; // A reliable IP for testing

        // Test gethostbyaddr()
        $debug_info['gethostbyaddr']['exists'] = function_exists( 'gethostbyaddr' ) ? 'Yes' : 'No';
        $debug_info['gethostbyaddr']['callable'] = is_callable( 'gethostbyaddr' ) ? 'Yes' : 'No';
        if ( function_exists( 'gethostbyaddr' ) ) {
            $debug_info['gethostbyaddr']['result'] = gethostbyaddr( $ip_to_test );
        }

        // Test dns_get_record()
        $debug_info['dns_get_record']['exists'] = function_exists( 'dns_get_record' ) ? 'Yes' : 'No';
        $debug_info['dns_get_record']['callable'] = is_callable( 'dns_get_record' ) ? 'Yes' : 'No';
        if ( function_exists( 'dns_get_record' ) ) {
            $debug_info['dns_get_record']['result'] = dns_get_record( $ip_to_test, DNS_PTR );
        }

        set_transient( 'edhbb_debug_info', $debug_info, 60 );
    }

    /**
     * Handles the force refresh all hostnames action.
     */
    public function handle_force_refresh_all_hostnames() {
        // Verify nonce for security.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edhbb_force_refresh_all_hostnames_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        // Clear all DNS caches
        if ( class_exists( 'EDHBB_DNSLookup' ) ) {
            // Clear hostname cache
            global $wpdb;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentionally clearing transient cache for this plugin, caching not applicable for cache clearing operations
            $wpdb->query( 
                $wpdb->prepare( 
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 
                    $wpdb->esc_like( '_transient_edhbb_hostname_' ) . '%' 
                ) 
            );
            $wpdb->query( 
                $wpdb->prepare( 
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 
                    $wpdb->esc_like( '_transient_timeout_edhbb_hostname_' ) . '%' 
                ) 
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        // Get ALL blocked IPs (not just ones without hostnames)
        $all_blocked_ips = $this->db->get_blocked_bots( 0 ); // 0 = no limit
        $updated_count = 0;

        foreach ( $all_blocked_ips as $bot ) {
            $ip_address = $bot['ip_address'];
            
            // Force new hostname lookup
            if ( class_exists( 'EDHBB_DNSLookup' ) ) {
                $hostname = EDHBB_DNSLookup::get_hostname_for_blocked_ip( $ip_address );
            } else {
                // Fallback method
                $blocker = new EDHBB_Blocker( $this->db );
                $reflection = new ReflectionClass( $blocker );
                $hostname_method = $reflection->getMethod( 'get_hostname_for_ip' );
                $hostname_method->setAccessible( true );
                $hostname = $hostname_method->invoke( $blocker, $ip_address );
                // Ensure we set a clear indicator for empty results in fallback
                if ( empty( $hostname ) ) {
                    $hostname = '[No PTR Record]';
                }
            }
            
            // Update the database
            if ( $this->db->update_blocked_bot_hostname( $ip_address, $hostname ) ) {
                $updated_count++;
            }
        }

        // Add success message
        add_settings_error(
            'edhbb_messages',
            'edhbb_force_refresh_completed',
            sprintf(
                /* translators: %d: number of updated hostnames */
                _n( 
                    'Force refreshed %d hostname (cleared cache and re-resolved all).', 
                    'Force refreshed %d hostnames (cleared cache and re-resolved all).', 
                    $updated_count, 
                    'edh-bad-bots' 
                ),
                $updated_count
            ),
            'success'
        );

        // Redirect back to the admin page
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=blocked' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }
}
