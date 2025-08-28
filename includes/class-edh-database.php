<?php
/**
 * EDH Bad Bots - Database Class
 *
 * Handles all database interactions for storing blocked bots and whitelisted IPs.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EDH_Database {

    private $wpdb;
    private $blocked_bots_table_name;
    private $whitelisted_ips_table_name;
    private $block_duration_days = 30; // Number of days an IP remains blocked

    /**
     * Constructor for the EDH_Database class.
     * Initializes the WordPress database object and sets table names.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->blocked_bots_table_name = $this->wpdb->prefix . 'edhbb_blocked_bots';
        $this->whitelisted_ips_table_name = $this->wpdb->prefix . 'edhbb_whitelisted_ips';
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
        $sql_blocked_bots = "CREATE TABLE IF NOT EXISTS $this->blocked_bots_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            blocked_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ip_address (ip_address)
        ) $charset_collate;";

        /**
         * SQL for the whitelisted IPs table.
         * Stores IP addresses that should never be blocked.
         * - id: Primary key.
         * - ip_address: The IP address to whitelist.
         * - added_at: Timestamp when the IP was added to the whitelist.
         */
        $sql_whitelisted_ips = "CREATE TABLE IF NOT EXISTS $this->whitelisted_ips_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            added_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ip_address (ip_address)
        ) $charset_collate;";

        // Execute dbDelta to create/update tables.
        dbDelta( $sql_blocked_bots );
        dbDelta( $sql_whitelisted_ips );
    }

    /**
     * Drops the custom database tables required by the plugin.
     * This is typically used during plugin deactivation, but is commented out by default
     * in the main plugin file to preserve data upon deactivation.
     */
    public function drop_tables() {
        $this->wpdb->query( "DROP TABLE IF EXISTS $this->blocked_bots_table_name" );
        $this->wpdb->query( "DROP TABLE IF EXISTS $this->whitelisted_ips_table_name" );
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
        $expires_at = date( 'Y-m-d H:i:s', strtotime( $current_time . ' + ' . $this->block_duration_days . ' days' ) );

        // Insert the IP address into the blocked bots table.
        // Using `INSERT IGNORE` to prevent errors if the IP is already present due to the UNIQUE KEY constraint.
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO $this->blocked_bots_table_name (ip_address, blocked_at, expires_at) VALUES (%s, %s, %s)",
                $ip_address,
                $current_time,
                $expires_at
            )
        );

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

        $sql = "SELECT id, ip_address, blocked_at, expires_at FROM $this->blocked_bots_table_name WHERE expires_at > %s ORDER BY blocked_at DESC";
        $params = array( current_time( 'mysql' ) );
        $formats = array( '%s' );

        if ( $limit > 0 ) {
            $sql .= " LIMIT %d";
            $params[] = $limit;
            $formats[] = '%d';
        }
        if ( $offset > 0 ) {
            $sql .= " OFFSET %d";
            $params[] = $offset;
            $formats[] = '%d';
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, $params ),
            ARRAY_A
        );
    }

    /**
     * Checks if a given IP address is currently blocked.
     * Considers the expiration date of the block.
     *
     * @param string $ip_address The IP address to check.
     * @return bool True if the IP is blocked and not expired, false otherwise.
     */
    public function is_bot_blocked( $ip_address ) {
        $this->clean_old_blocked_bots(); // Clean up before checking to ensure accuracy.

        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(id) FROM $this->blocked_bots_table_name WHERE ip_address = %s AND expires_at > %s",
                $ip_address,
                current_time( 'mysql' )
            )
        );

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
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO $this->whitelisted_ips_table_name (ip_address, added_at) VALUES (%s, %s)",
                $ip_address,
                current_time( 'mysql' )
            )
        );

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

        return (bool) $result;
    }

    /**
     * Retrieves all IP addresses from the whitelist table.
     *
     * @return array An array of whitelisted IP records (id, ip_address, added_at).
     */
    public function get_whitelisted_ips() {
        return $this->wpdb->get_results(
            "SELECT id, ip_address, added_at FROM $this->whitelisted_ips_table_name ORDER BY added_at DESC",
            ARRAY_A
        );
    }

    /**
     * Checks if a given IP address is in the whitelist.
     *
     * @param string $ip_address The IP address to check.
     * @return bool True if the IP is whitelisted, false otherwise.
     */
    public function is_ip_whitelisted( $ip_address ) {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(id) FROM $this->whitelisted_ips_table_name WHERE ip_address = %s",
                $ip_address
            )
        );

        return ( $result > 0 );
    }

    /**
     * Cleans up expired entries from the blocked bots table.
     * This ensures the table doesn't grow indefinitely with old, inactive blocks.
     */
    public function clean_old_blocked_bots() {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM $this->blocked_bots_table_name WHERE expires_at <= %s",
                current_time( 'mysql' )
            )
        );
    }
}
