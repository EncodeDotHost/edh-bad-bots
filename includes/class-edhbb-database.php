<?php
/**
 * EDH Bad Bots - Database Class
 *
 * Handles all database interactions for storing blocked bots and whitelisted IPs.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EDHBB_Database {

    private $wpdb;
    private $blocked_bots_table_name;
    private $whitelisted_ips_table_name;
    private $block_duration_days; // Number of days an IP remains blocked
    private $htaccess_path; // Path to the .htaccess file
    private $wp_filesystem; // WP_Filesystem instance

    /**
     * Constructor for the EDHBB_Database class.
     * Initializes the WordPress database object and sets table names.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->blocked_bots_table_name = $this->wpdb->prefix . 'edhbb_blocked_bots';
        $this->whitelisted_ips_table_name = $this->wpdb->prefix . 'edhbb_whitelisted_ips';
        $this->block_duration_days = get_option( 'edhbb_block_duration_days', 30 );
        $this->htaccess_path = ABSPATH . '.htaccess'; // Path to root .htaccess
        $this->init_filesystem();
    }

    /**
     * Initialize WP_Filesystem.
     * This is required for proper WordPress filesystem operations.
     */
    private function init_filesystem() {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        // Initialize the WP_Filesystem
        if ( ! WP_Filesystem() ) {
            $this->wp_filesystem = null;
        } else {
            global $wp_filesystem;
            $this->wp_filesystem = $wp_filesystem;
        }
    }

    /**
     * Creates the custom database tables required by the plugin.
     * Uses dbDelta for safe table creation and upgrades.
     */
    public function create_tables() {
        // Load the upgrade.php file for dbDelta function.
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        // Character set and collate for the tables.
        $charset_collate = $this->wpdb->get_charset_collate();

        /**
         * SQL for the blocked bots table.
         * Stores IP addresses of bots that have hit the trap URL.
         * - id: Primary key.
         * - ip_address: The IP address of the blocked bot.
         * - blocked_at: Timestamp when the bot was blocked.
         * - expires_at: Timestamp when the block will expire (30 days after blocked_at).
         */
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $sql_blocked_bots = $this->wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                ip_address varchar(45) NOT NULL,
                blocked_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ip_address (ip_address)
            )",
            $this->blocked_bots_table_name
        ) . ' ' . $charset_collate;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        /**
         * SQL for the whitelisted IPs table.
         * Stores IP addresses that should never be blocked.
         * - id: Primary key.
         * - ip_address: The IP address to whitelist.
         * - added_at: Timestamp when the IP was added to the whitelist.
         */
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $sql_whitelisted_ips = $this->wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                ip_address varchar(45) NOT NULL,
                added_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ip_address (ip_address)
            )",
            $this->whitelisted_ips_table_name
        ) . ' ' . $charset_collate;
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        // Execute dbDelta to create/update tables.
        dbDelta( $sql_blocked_bots );
        dbDelta( $sql_whitelisted_ips );

        // Ensure .htaccess rules are up-to-date on activation, respecting the option flag
        $this->update_htaccess_block_rules();
    }

    /**
     * Drops the custom database tables required by the plugin.
     * This is typically used during plugin deactivation, but is commented out by default
     * in the main plugin file to preserve data upon deactivation.
     */
    public function drop_tables() {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $this->wpdb->query( $this->wpdb->prepare( "DROP TABLE IF EXISTS %i", $this->blocked_bots_table_name ) );
        $this->wpdb->query( $this->wpdb->prepare( "DROP TABLE IF EXISTS %i", $this->whitelisted_ips_table_name ) );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        // Always remove .htaccess rules on deactivation, regardless of option, for cleanup
        $this->remove_htaccess_block_rules(true); // Pass true to force removal
    }

    /**
     * Adds an IP address to the blocked bots table.
     * The IP will be blocked for the defined block duration.
     *
     * @param string $ip_address The IP address to block.
     * @return bool True on success, false on failure.
     */
    public function add_blocked_bot( $ip_address ) {
        // Clean up old entries before adding a new one to keep the table lean.
        $this->clean_old_blocked_bots();

        // Get current time and calculate expiration time.
        $current_time = current_time( 'mysql' );
        $expires_timestamp = strtotime( $current_time . ' + ' . $this->block_duration_days . ' days' );
        $expires_at = gmdate( 'Y-m-d H:i:s', $expires_timestamp );

        // Insert the IP address into the blocked bots table.
        // Using `INSERT IGNORE` to prevent errors if the IP is already present due to the UNIQUE KEY constraint.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO %i (ip_address, blocked_at, expires_at) VALUES (%s, %s, %s)",
                $this->blocked_bots_table_name,
                $ip_address,
                $current_time,
                $expires_at
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        if ( $result ) {
            $this->update_htaccess_block_rules(); // Update .htaccess when a new bot is blocked, respecting the option.
        }

        return (bool) $result;
    }

    /**
     * Removes an IP address from the blocked bots table.
     *
     * @param string $ip_address The IP address to unblock.
     * @return bool True on success, false on failure.
     */
    public function remove_blocked_bot( $ip_address ) {
        $result = $this->wpdb->delete(
            $this->blocked_bots_table_name,
            array( 'ip_address' => $ip_address ),
            array( '%s' )
        );

        if ( $result ) {
            $this->update_htaccess_block_rules(); // Update .htaccess when a bot is unblocked, respecting the option.
        }

        return (bool) $result;
    }

    /**
     * Retrieves all currently blocked bot IP addresses.
     * Only returns IPs that have not yet expired.
     *
     * @param int $limit Optional. The maximum number of results to return. Default 0 (no limit).
     * @param int $offset Optional. The number of results to skip. Default 0.
     * @return array An array of blocked bot records (id, ip_address, blocked_at, expires_at).
     */
    public function get_blocked_bots( $limit = 0, $offset = 0 ) {
        $this->clean_old_blocked_bots(); // Ensure only current blocks are considered.

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $sql = "SELECT id, ip_address, blocked_at, expires_at FROM %i WHERE expires_at > %s ORDER BY blocked_at DESC";
        $sql_params = array( $this->blocked_bots_table_name, current_time( 'mysql' ) );

        if ( $limit > 0 ) {
            $sql .= " LIMIT %d";
            $sql_params[] = $limit;
        }
        if ( $offset > 0 ) {
            $sql .= " OFFSET %d";
            $sql_params[] = $offset;
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, ...$sql_params ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Checks if a given IP address is currently blocked.
     * Considers the expiration date of the block.
     * Note: This PHP check is less effective with full page caching.
     * Server-level blocking via .htaccess is preferred for active blocks when enabled.
     *
     * @param string $ip_address The IP address to check.
     * @return bool True if the IP is blocked and not expired, false otherwise.
     */
    public function is_bot_blocked( $ip_address ) {
        $this->clean_old_blocked_bots(); // Clean up before checking to ensure accuracy.

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(id) FROM %i WHERE ip_address = %s AND expires_at > %s",
                $this->blocked_bots_table_name,
                $ip_address,
                current_time( 'mysql' )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return ( $result > 0 );
    }

    /**
     * Adds an IP address to the whitelist table.
     *
     * @param string $ip_address The IP address to whitelist.
     * @return bool True on success, false on failure.
     */
    public function add_whitelisted_ip( $ip_address ) {
        // Using `INSERT IGNORE` to prevent errors if the IP is already present due to the UNIQUE KEY constraint.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO %i (ip_address, added_at) VALUES (%s, %s)",
                $this->whitelisted_ips_table_name,
                $ip_address,
                current_time( 'mysql' )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        if ($result) {
            // Re-update .htaccess rules to ensure whitelisted IPs are not blocked by old rules, respecting the option.
            $this->update_htaccess_block_rules();
        }

        return (bool) $result;
    }

    /**
     * Removes an IP address from the whitelist table.
     *
     * @param string $ip_address The IP address to remove from the whitelist.
     * @return bool True on success, false on failure.
     */
    public function remove_whitelisted_ip( $ip_address ) {
        $result = $this->wpdb->delete(
            $this->whitelisted_ips_table_name,
            array( 'ip_address' => $ip_address ),
            array( '%s' )
        );

        if ($result) {
            // Re-update .htaccess rules as a whitelisted IP might now be eligible for blocking if it was in blocked list, respecting the option.
            $this->update_htaccess_block_rules();
        }

        return (bool) $result;
    }

    /**
     * Retrieves all IP addresses from the whitelist table.
     *
     * @return array An array of whitelisted IP records (id, ip_address, added_at).
     */
    public function get_whitelisted_ips() {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        return $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT id, ip_address, added_at FROM %i ORDER BY added_at DESC", $this->whitelisted_ips_table_name ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Checks if a given IP address is in the whitelist.
     *
     * @param string $ip_address The IP address to check.
     * @return bool True if the IP is whitelisted, false otherwise.
     */
    public function is_ip_whitelisted( $ip_address ) {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(id) FROM %i WHERE ip_address = %s",
                $this->whitelisted_ips_table_name,
                $ip_address
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return ( $result > 0 );
    }

    /**
     * Cleans up expired entries from the blocked bots table.
     * This ensures the table doesn't grow indefinitely with old, inactive blocks.
     * And triggers an .htaccess update to remove expired blocks (if enabled).
     */
    public function clean_old_blocked_bots() {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- %i placeholder is valid since WordPress 6.2
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM %i WHERE expires_at <= %s",
                $this->blocked_bots_table_name,
                current_time( 'mysql' )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        if ( $result !== false && $result > 0 ) { // If any rows were deleted
            $this->update_htaccess_block_rules(); // Update .htaccess to remove expired blocks, respecting the option.
        }
    }

    /**
     * Generates and updates the .htaccess file with current IP blocking rules.
     * This function should be called whenever the blocked IPs list changes
     * or the .htaccess blocking option is toggled.
     */
    public function update_htaccess_block_rules() {
        // Retrieve the current setting for .htaccess blocking. Defaults to 'no' if not set.
        $enable_htaccess_blocking = get_option( 'edhbb_enable_htaccess_blocking', 'no' ) === 'yes';

        // Check if WP_Filesystem is available.
        if ( null === $this->wp_filesystem ) {
            if ( $enable_htaccess_blocking && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                error_log( '[EDH Bad Bots] WP_Filesystem not available for .htaccess operations.' );
            }
            return;
        }

        // Perform basic checks for .htaccess file existence and writability.
        if ( ! $this->wp_filesystem->exists( $this->htaccess_path ) || ! $this->wp_filesystem->is_writable( $this->htaccess_path ) ) {
            // Log an error if .htaccess is expected but not found/writable.
            if ( $enable_htaccess_blocking && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                error_log( '[EDH Bad Bots] .htaccess file not found or not writable at: ' . $this->htaccess_path );
            }
            return; // Cannot proceed if file is inaccessible.
        }

        // Get the current content of the .htaccess file.
        $htaccess_content = $this->wp_filesystem->get_contents( $this->htaccess_path );

        // Define our unique markers for the plugin's block.
        $start_marker = '# BEGIN EDH Bad Bots Block';
        $end_marker = '# END EDH Bad Bots Block';

        // Always remove any existing block first. This ensures we start clean before potentially adding new rules
        // or leaving the block empty if the option is disabled.
        if ( strpos( $htaccess_content, $start_marker ) !== false ) {
            $htaccess_content = preg_replace( "/{$start_marker}.*?{$end_marker}\s*/s", '', $htaccess_content );
        }

        // Only generate and insert new rules if the 'enable_htaccess_blocking' option is active.
        if ( $enable_htaccess_blocking ) {
            // Retrieve all currently blocked IPs from the database.
            // Importantly, we filter out any IPs that are also in the whitelist,
            // as whitelisted IPs should never be blocked by .htaccess rules.
            $blocked_ips_raw = $this->get_blocked_bots();
            $blocked_ips_for_htaccess = [];
            foreach ($blocked_ips_raw as $bot) {
                if (!$this->is_ip_whitelisted($bot['ip_address'])) {
                    $blocked_ips_for_htaccess[] = $bot['ip_address'];
                }
            }

            $new_rules = '';
            // If there are IPs to block, generate the .htaccess rules.
            if ( ! empty( $blocked_ips_for_htaccess ) ) {
                $new_rules .= "{$start_marker}\n"; // Add our start marker
                $new_rules .= "<Limit GET POST HEAD>\n"; // Apply rules to specific HTTP methods
                $new_rules .= "Order Allow,Deny\n"; // Define the order of processing
                $new_rules .= "Allow from All\n"; // Allow all by default...
                foreach ( $blocked_ips_for_htaccess as $ip ) {
                    $new_rules .= "Deny from " . $ip . "\n"; // ...then deny specific IPs
                }
                $new_rules .= "</Limit>\n";
                $new_rules .= "{$end_marker}\n\n"; // Add our end marker and an extra newline

                // Insert the new rules at the very beginning of the .htaccess file.
                $htaccess_content = $new_rules . $htaccess_content;
            }
        }

        // Write the (potentially modified) content back to the .htaccess file.
        $this->wp_filesystem->put_contents( $this->htaccess_path, $htaccess_content, FS_CHMOD_FILE );
    }

    /**
     * Removes all plugin-specific rules from the .htaccess file.
     * This should be called during plugin deactivation or when .htaccess blocking is disabled.
     *
     * @param bool $force_remove Whether to force removal even if option is disabled.
     * Useful for plugin deactivation to ensure a clean uninstall.
     */
    private function remove_htaccess_block_rules( $force_remove = false ) {
        // Get the current option status.
        $enable_htaccess_blocking = get_option( 'edhbb_enable_htaccess_blocking', 'no' ) === 'yes';

        // Only remove rules if .htaccess blocking is currently enabled,
        // or if explicitly told to force removal (e.g., during plugin deactivation).
        if ( ! $enable_htaccess_blocking && ! $force_remove ) {
            return; // Do nothing if option is off and not forced.
        }

        // Check if WP_Filesystem is available.
        if ( null === $this->wp_filesystem ) {
            return;
        }

        // Check file accessibility.
        if ( ! $this->wp_filesystem->exists( $this->htaccess_path ) || ! $this->wp_filesystem->is_writable( $this->htaccess_path ) ) {
            return; // Cannot remove if file is inaccessible.
        }

        $htaccess_content = $this->wp_filesystem->get_contents( $this->htaccess_path );

        $start_marker = '# BEGIN EDH Bad Bots Block';
        $end_marker = '# END EDH Bad Bots Block';

        // Remove the entire block if it exists within the file.
        if ( strpos( $htaccess_content, $start_marker ) !== false ) {
            $htaccess_content = preg_replace( "/{$start_marker}.*?{$end_marker}\s*/s", '', $htaccess_content );
            $this->wp_filesystem->put_contents( $this->htaccess_path, $htaccess_content, FS_CHMOD_FILE ); // Write back the cleaned content.
        }
    }
}
