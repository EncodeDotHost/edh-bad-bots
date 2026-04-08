<?php
/**
 * EDH Bad Bots - Blocker Class
 *
 * Handles detecting and blocking bad bots that hit the trap URL.
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EDHBB_Blocker {

    private $db; // Instance of EDHBB_Database class
    private $trap_url_hash; // The unique hash for the bot trap URL

    /**
     * Constructor for the EDHBB_Blocker class.
     *
     * @param EDHBB_Database $db An instance of the EDHBB_Database class.
     */
    public function __construct( EDHBB_Database $db ) {
        $this->db = $db;
        $this->trap_url_hash = $this->generate_trap_url_hash();

        // Register action hooks for bot detection and blocking.
        // `init` is generally early enough to check for blocks.
        // `template_redirect` is ideal for handling the trap URL and blocking.
        add_action( 'init', array( $this, 'maybe_block_request' ) );
        add_action( 'template_redirect', array( $this, 'detect_bot_trap_hit' ) );
    }

    /**
     * Generates the unique hash for the bot trap URL.
     * This needs to be consistent with how the hash is generated in edh-bad-bots.php
     * and how the link is added in the footer.
     *
     * @return string The generated hash.
     */
    private function generate_trap_url_hash() {
        $site_url_parts = wp_parse_url( site_url() );
        $host = $site_url_parts['host'];
        $scheme = $site_url_parts['scheme'];
        return wp_hash( 'site-' . $host . '-disallow-rule-' . $scheme );
    }

    /**
     * Retrieves the current user's IP address, attempting to handle proxies.
     *
     * @return string The user's IP address, or 'UNKNOWN' if it cannot be determined.
     */
    private function get_client_ip() {
        $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'UNKNOWN';
        if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            return $ip_address;
        }
        return 'UNKNOWN';
    }


    /**
     * Checks if the current request's IP should be blocked.
     * This runs early in the WordPress loading process.
     */
    public function maybe_block_request() {
        $client_ip = $this->get_client_ip();

        // If IP is UNKNOWN or invalid, do not proceed with blocking logic.
        if ( $client_ip === 'UNKNOWN' ) {
            return;
        }

        // If the IP is whitelisted, skip any blocking checks.
        if ( $this->db->is_ip_whitelisted( $client_ip ) ) {
            return;
        }

        // If the IP is blocked, terminate the request.
        if ( $this->db->is_bot_blocked( $client_ip ) ) {
            $this->block_request_action( $client_ip );
        }
    }

    /**
     * Detects if a bot has hit the trap URL and adds it to the blocklist.
     * This runs just before WordPress determines which template to load.
     */
    public function detect_bot_trap_hit() {
        // Only run if the site is publicly visible for search engines
        if ( '1' != get_option( 'blog_public' ) ) {
            return;
        }

        // Validate that REQUEST_URI exists before using it
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return; // Cannot proceed without REQUEST_URI
        }
        
        $current_url = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        $site_path = wp_parse_url( site_url(), PHP_URL_PATH );
        
        // Handle cases where site_path might be null (when URL has no path component)
        $site_path = $site_path ?? '';
        $expected_trap_path = rtrim( $site_path, '/' ) . '/' . $this->trap_url_hash . '/';

        // Check if the current URL path exactly matches our trap path.
        $parsed_current_url = wp_parse_url( $current_url );
        $current_path = isset( $parsed_current_url['path'] ) ? $parsed_current_url['path'] : '';

        if ( trim( $current_path, '/' ) === trim( $expected_trap_path, '/' ) ) {
            $client_ip = $this->get_client_ip();

            // If IP is UNKNOWN or invalid, do not proceed.
            if ( $client_ip === 'UNKNOWN' ) {
                return;
            }

            // If the IP is whitelisted, do not block it, even if it hits the trap.
            if ( $this->db->is_ip_whitelisted( $client_ip ) ) {
                return;
            }

            // Block immediately with empty hostname; the cron job resolves it in the background.
            $this->db->add_blocked_bot( $client_ip, '' );
            $this->block_request_action( $client_ip, true ); // Pass true to indicate it's a trap hit block
        }
    }

    /**
     * Executes the blocking action.
     * This can be a simple exit, a redirect, or showing a blank page.
     *
     * @param string $ip_address The IP address that is being blocked.
     * @param bool $is_trap_hit Whether the block is due to hitting the trap URL.
     */
    private function block_request_action( $ip_address, $is_trap_hit = false ) {
        // You can customize the blocking action here.
        // Options:
        // 1. Send a 403 Forbidden header and exit.
        // 2. Redirect to a generic page (e.g., a "not found" page or home).
        // 3. Just output a blank page and exit.

        // Option 1: Send a 403 Forbidden header and die.
        header( 'HTTP/1.1 403 Forbidden' );
        exit;

        // Option 2: Redirect to home page.
        // wp_redirect( home_url() );
        // exit;

        // Option 3: Output blank page.
        // echo '';
        // exit;
    }
}