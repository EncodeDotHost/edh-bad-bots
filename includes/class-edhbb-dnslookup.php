<?php
/**
 * EDH Bad Bots - DNS Lookup Class
 *
 * Handles PTR record lookups (reverse DNS) for blocked IP addresses.
 * Uses DNS over HTTPS (DoH) with fallback to traditional DNS methods.
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EDHBB_DNSLookup {

    /**
     * DNS over HTTPS providers for PTR lookups
     *
     * @var array
     */
    private static $doh_providers = [
        'cloudflare' => 'https://cloudflare-dns.com/dns-query',
        'google'     => 'https://dns.google/dns-query'
    ];

    /**
     * Cache duration for hostname results (in seconds)
     *
     * @var int
     */
    private static $cache_duration = 3600; // 1 hour

    /**
     * Main method: Get hostname for a blocked IP address
     * This is the primary method your plugin should use.
     *
     * @param string $ip_address IP address to lookup hostname for
     * @return string            Hostname or empty string if not found
     */
    public static function get_hostname_for_blocked_ip( $ip_address ) {
        // Validate IP address
        if ( ! filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        // Check cache first
        $cache_key = 'edhbb_hostname_' . md5( $ip_address );
        $cached_hostname = get_transient( $cache_key );
        if ( false !== $cached_hostname ) {
            return $cached_hostname;
        }

        $hostname = '';

        // Debug logging if enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
            error_log( "[EDH Bad Bots] Starting hostname lookup for IP: {$ip_address}" );
        }

        // Try DoH providers first
        foreach ( self::$doh_providers as $provider_name => $provider_url ) {
            $hostname = self::doh_ptr_lookup( $ip_address, $provider_name );
            if ( ! empty( $hostname ) ) {
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                    error_log( "[EDH Bad Bots] DoH lookup successful via {$provider_name}: {$hostname}" );
                }
                break;
            }
        }

        // If DoH failed, try traditional DNS
        if ( empty( $hostname ) ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                error_log( "[EDH Bad Bots] DoH failed, trying traditional DNS for: {$ip_address}" );
            }
            $hostname = self::traditional_ptr_lookup( $ip_address );
        }

        // If no hostname found, set a clear indicator instead of empty string
        if ( empty( $hostname ) ) {
            $hostname = '[No PTR Record]';
        }

        // Final debug log
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
            error_log( "[EDH Bad Bots] Final hostname result for {$ip_address}: {$hostname}" );
        }

        // Cache the result
        set_transient( $cache_key, $hostname, self::$cache_duration );

        return $hostname;
    }

    /**
     * Perform PTR lookup via DNS over HTTPS
     *
     * @param string $ip_address IP address to lookup
     * @param string $provider   DoH provider name
     * @return string|false      Hostname if found, false on failure
     */
    private static function doh_ptr_lookup( $ip_address, $provider = 'cloudflare' ) {
        // Convert IP to reverse DNS format
        $reverse_ip = self::ip_to_reverse_dns( $ip_address );
        if ( false === $reverse_ip ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                error_log( "[EDH Bad Bots] Failed to convert IP to reverse DNS format: {$ip_address}" );
            }
            return false;
        }

        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
            error_log( "[EDH Bad Bots] Reverse DNS format for {$ip_address}: {$reverse_ip}" );
        }

        // Build query URL
        $url = add_query_arg([
            'name' => $reverse_ip,
            'type' => 'PTR'
        ], self::$doh_providers[ $provider ]);

        // Perform the request
        $response = wp_remote_get( $url, [
            'timeout'   => 5,
            'headers'   => [
                'Accept'     => 'application/dns-json',
                'User-Agent' => 'EDH-Bad-Bots/' . EDHBB_VERSION
            ],
            'sslverify' => true
        ]);

        // Handle request errors
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            return false;
        }

        // Parse JSON response
        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return false;
        }

        // Extract hostname from PTR response
        if ( isset( $data['Answer'] ) && ! empty( $data['Answer'] ) ) {
            foreach ( $data['Answer'] as $answer ) {
                if ( isset( $answer['type'] ) && 12 === (int) $answer['type'] && isset( $answer['data'] ) ) {
                    $hostname = rtrim( $answer['data'], '.' ); // Remove trailing dot
                    if ( self::validate_hostname( $hostname ) ) {
                        return $hostname;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Traditional PTR lookup using PHP's built-in functions
     *
     * @param string $ip_address IP address to lookup
     * @return string|false      Hostname if found, false on failure
     */
    private static function traditional_ptr_lookup( $ip_address ) {
        $hostname = '';

        // Try dns_get_record first (more reliable for PTR records)
        if ( function_exists( 'dns_get_record' ) && is_callable( 'dns_get_record' ) ) {
            try {
                $reverse_ip = self::ip_to_reverse_dns( $ip_address );
                if ( false !== $reverse_ip ) {
                    $ptr_records = @dns_get_record( $reverse_ip, DNS_PTR );

                    if ( $ptr_records && ! empty( $ptr_records[0]['target'] ) ) {
                        $resolved = rtrim( $ptr_records[0]['target'], '.' );
                        if ( self::validate_hostname( $resolved ) ) {
                            $hostname = $resolved;
                        }
                    }
                }
            } catch ( Exception $e ) {
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                    error_log( "[EDH Bad Bots] dns_get_record failed for {$ip_address}: " . $e->getMessage() );
                }
            }
        }

        // Fallback to gethostbyaddr if dns_get_record failed
        if ( empty( $hostname ) && function_exists( 'gethostbyaddr' ) && is_callable( 'gethostbyaddr' ) ) {
            try {
                $resolved = @gethostbyaddr( $ip_address );
                if ( $resolved && $resolved !== $ip_address && self::validate_hostname( $resolved ) ) {
                    $hostname = $resolved;
                }
            } catch ( Exception $e ) {
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG_LOG is enabled
                    error_log( "[EDH Bad Bots] gethostbyaddr failed for {$ip_address}: " . $e->getMessage() );
                }
            }
        }

        return ! empty( $hostname ) ? $hostname : false;
    }

    /**
     * Convert IP address to reverse DNS format for PTR lookups
     *
     * @param string $ip_address IP address (IPv4 or IPv6)
     * @return string|false      Reverse DNS format or false on failure
     */
    private static function ip_to_reverse_dns( $ip_address ) {
        if ( filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            // IPv4: Reverse octets and append .in-addr.arpa
            $octets = explode( '.', $ip_address );
            return implode( '.', array_reverse( $octets ) ) . '.in-addr.arpa';
        } elseif ( filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            // IPv6: Expand to full format and create reverse DNS name
            return self::ipv6_to_reverse_dns( $ip_address );
        }

        return false;
    }

    /**
     * Convert IPv6 address to reverse DNS format
     *
     * @param string $ipv6 IPv6 address
     * @return string|false Reverse DNS format or false on failure
     */
    private static function ipv6_to_reverse_dns( $ipv6 ) {
        // Expand IPv6 to full format
        $expanded = self::expand_ipv6( $ipv6 );
        if ( false === $expanded ) {
            return false;
        }

        // Remove colons and split into nibbles
        $hex_string = str_replace( ':', '', $expanded );
        $nibbles = str_split( $hex_string );

        // Reverse nibbles and join with dots, append .ip6.arpa
        return implode( '.', array_reverse( $nibbles ) ) . '.ip6.arpa';
    }

    /**
     * Expand IPv6 address to full 32-character format
     *
     * @param string $ipv6 IPv6 address
     * @return string|false Expanded IPv6 or false on failure
     */
    private static function expand_ipv6( $ipv6 ) {
        // Use inet_pton and inet_ntop for reliable expansion
        $binary = inet_pton( $ipv6 );
        if ( false === $binary ) {
            return false;
        }

        // Convert binary back to expanded format
        $hex = bin2hex( $binary );
        
        // Split into 4-character groups and join with colons
        $groups = str_split( $hex, 4 );
        return implode( ':', $groups );
    }

    /**
     * Validate hostname format
     *
     * @param string $hostname Hostname to validate
     * @return bool            True if valid, false otherwise
     */
    private static function validate_hostname( $hostname ) {
        if ( empty( $hostname ) || strlen( $hostname ) > 253 ) {
            return false;
        }

        // Basic hostname validation
        $pattern = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/';
        
        return preg_match( $pattern, $hostname ) === 1;
    }
}