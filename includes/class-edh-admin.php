<?php
/**
 * EDH Bad Bots - Admin Class
 *
 * Handles all administrative functionalities, including menu pages, form processing,
 * and displaying blocked bots and whitelisted IPs.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EDH_Admin {

    private $db; // Instance of EDH_Database class
    private $admin_page_slug = 'edh-bad-bots-settings'; // Unique slug for the admin page

    /**
     * Constructor for the EDH_Admin class.
     *
     * @param EDH_Database $db An instance of the EDH_Database class.
     */
    public function __construct( EDH_Database $db ) {
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
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'edhbb_add_whitelist_ip_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        $ip_address = isset( $_POST['edhbb_whitelist_ip'] ) ? sanitize_text_field( $_POST['edhbb_whitelist_ip'] ) : '';

        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            if ( $this->db->add_whitelisted_ip( $ip_address ) ) {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_added',
                    sprintf( __( 'IP address %s added to whitelist.', 'edh-bad-bots' ), esc_html( $ip_address ) ),
                    'success'
                );
            } else {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_add_fail',
                    sprintf( __( 'Failed to add IP address %s to whitelist (it might already exist).', 'edh-bad-bots' ), esc_html( $ip_address ) ),
                    'error'
                );
            }
        } else {
            add_settings_error(
                'edhbb_messages',
                'edhbb_ip_invalid',
                __( 'Invalid IP address provided for whitelist.', 'edh-bad-bots' ),
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
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'edhbb_remove_whitelist_ip_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        $ip_address = isset( $_POST['edhbb_whitelist_ip'] ) ? sanitize_text_field( $_POST['edhbb_whitelist_ip'] ) : '';

        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            if ( $this->db->remove_whitelisted_ip( $ip_address ) ) {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_removed',
                    sprintf( __( 'IP address %s removed from whitelist.', 'edh-bad-bots' ), esc_html( $ip_address ) ),
                    'success'
                );
            } else {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_ip_remove_fail',
                    sprintf( __( 'Failed to remove IP address %s from whitelist.', 'edh-bad-bots' ), esc_html( $ip_address ) ),
                    'error'
                );
            }
        } else {
            add_settings_error(
                'edhbb_messages',
                'edhbb_ip_invalid',
                __( 'Invalid IP address provided for whitelist removal.', 'edh-bad-bots' ),
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
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'edhbb_remove_blocked_bot_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        $ip_address = isset( $_POST['edhbb_blocked_bot_ip'] ) ? sanitize_text_field( $_POST['edhbb_blocked_bot_ip'] ) : '';

        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            if ( $this->db->remove_blocked_bot( $ip_address ) ) {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_bot_unblocked',
                    sprintf( __( 'Bot IP address %s unblocked.', 'edh-bad-bots' ), esc_html( $ip_address ) ),
                    'success'
                );
            } else {
                add_settings_error(
                    'edhbb_messages',
                    'edhbb_bot_unblock_fail',
                    sprintf( __( 'Failed to unblock bot IP address %s.', 'edh-bad-bots' ), esc_html( $ip_address ) ),
                    'error'
                );
            }
        } else {
            add_settings_error(
                'edhbb_messages',
                'edhbb_ip_invalid',
                __( 'Invalid IP address provided for bot unblock.', 'edh-bad-bots' ),
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
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'edhbb_save_options_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'edh-bad-bots' ) );
        }

        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'edh-bad-bots' ) );
        }

        // Get the value of the .htaccess blocking checkbox.
        // If the checkbox is checked, $_POST['edhbb_enable_htaccess_blocking'] will be 'yes'.
        // If unchecked, it won't be set, so we default to 'no'.
        $enable_htaccess_blocking = isset( $_POST['edhbb_enable_htaccess_blocking'] ) ? 'yes' : 'no';

        // Update the WordPress option.
        // update_option() will add the option if it doesn't exist or update it if it does.
        update_option( 'edhbb_enable_htaccess_blocking', $enable_htaccess_blocking );

        // After updating the option, trigger an .htaccess update.
        // The update_htaccess_block_rules method in EDH_Database will read the new option
        // and either add or remove the .htaccess rules accordingly.
        $this->db->update_htaccess_block_rules();

        // Add a success message to be displayed on the admin page.
        add_settings_error(
            'edhbb_messages',
            'edhbb_options_saved',
            __( 'Plugin options saved.', 'edh-bad-bots' ),
            'success'
        );

        // Redirect back to the admin page, specifically the 'options' tab, after saving.
        $redirect_url = admin_url( 'tools.php?page=' . $this->admin_page_slug . '&tab=options' );
        wp_redirect( esc_url_raw( $redirect_url ) );
        exit; // Important: Always exit after a redirect to prevent further script execution.
    }
}
