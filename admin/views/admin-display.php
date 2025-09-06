<?php
/**
 * EDH Bad Bots - Admin Display View
 *
 * This file contains the HTML and PHP for the plugin's administration page.
 * It displays blocked bots, allows whitelisting IPs, and manages unblocking.
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Ensure $db is available from the calling context (EDHBB_Admin::render_admin_page()).
if ( ! isset( $db ) || ! ( $db instanceof EDHBB_Database ) ) {
    return; // Safety check
}

// Fetch data from the database using the provided $db instance.
$blocked_bots = $db->get_blocked_bots();
$whitelisted_ips = $db->get_whitelisted_ips();

// Determine which tab is active (default to 'whitelist')
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switching doesn't require nonce verification
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'whitelist';

// Get current option for .htaccess blocking
$enable_htaccess_blocking = get_option( 'edhbb_enable_htaccess_blocking', 'no' ) === 'yes';
$block_duration_days = get_option( 'edhbb_block_duration_days', 30 );

?>

<div class="wrap">
    <h1><?php esc_html_e( 'EDH Bad Bots Blocker', 'edh-bad-bots' ); ?> by <a class="edhbb-credit-link" href="https://encode.host" target="_blank">EncodeDotHost</a></h1>
    <p><?php esc_html_e( 'Manage blocked bots and whitelisted IP addresses.', 'edh-bad-bots' ); ?></p>

    <!-- Display any success or error messages from form submissions -->
    <?php settings_errors( 'edhbb_messages' ); ?>

    <!-- Tab Navigation -->
    <h2 class="nav-tab-wrapper">
        <a href="?page=edh-bad-bots-settings&tab=whitelist" class="nav-tab <?php echo $active_tab == 'whitelist' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Whitelisted IPs', 'edh-bad-bots' ); ?>
        </a>
        <a href="?page=edh-bad-bots-settings&tab=blocked" class="nav-tab <?php echo $active_tab == 'blocked' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Blocked Bots', 'edh-bad-bots' ); ?>
        </a>
        <a href="?page=edh-bad-bots-settings&tab=options" class="nav-tab <?php echo $active_tab == 'options' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Options', 'edh-bad-bots' ); ?>
        </a>
        <a href="?page=edh-bad-bots-settings&tab=help" class="nav-tab <?php echo $active_tab == 'help' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Help', 'edh-bad-bots' ); ?>
        </a>
    </h2>

    <div class="tab-content">
        <?php if ( $active_tab == 'whitelist' ) : ?>
            <!-- Section for Whitelisting IP Addresses -->
            <div class="card edhbb-card">
                <h2 class="title"><?php esc_html_e( 'Whitelist IP Address', 'edh-bad-bots' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="edhbb_add_whitelist_ip">
                    <?php wp_nonce_field( 'edhbb_add_whitelist_ip_nonce' ); ?>

                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="edhbb_whitelist_ip"><?php esc_html_e( 'IP Address', 'edh-bad-bots' ); ?></label></th>
                                <td>
                                    <input type="text" id="edhbb_whitelist_ip" name="edhbb_whitelist_ip" value="" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., 192.168.1.1', 'edh-bad-bots' ); ?>" required />
                                    <p class="description"><?php esc_html_e( 'Enter an IP address to add to the whitelist. These IPs will never be blocked.', 'edh-bad-bots' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Add to Whitelist', 'edh-bad-bots' ), 'primary', 'submit_whitelist_ip' ); ?>
                </form>
            </div>

            <!-- Section for Currently Whitelisted IP Addresses -->
            <div class="card edhbb-card">
                <h2 class="title"><?php esc_html_e( 'Currently Whitelisted IPs', 'edh-bad-bots' ); ?></h2>
                <?php if ( ! empty( $whitelisted_ips ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'IP Address', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Added At', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Actions', 'edh-bad-bots' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $whitelisted_ips as $ip ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $ip['ip_address'] ); ?></td>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $ip['added_at'] ) ) ); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to remove this IP from the whitelist?', 'edh-bad-bots' ); ?>');">
                                            <input type="hidden" name="action" value="edhbb_remove_whitelist_ip">
                                            <input type="hidden" name="edhbb_whitelist_ip" value="<?php echo esc_attr( $ip['ip_address'] ); ?>">
                                            <?php wp_nonce_field( 'edhbb_remove_whitelist_ip_nonce' ); ?>
                                            <?php submit_button( __( 'Remove', 'edh-bad-bots' ), 'delete', 'submit_remove_whitelist_ip', false ); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e( 'No IP addresses currently whitelisted.', 'edh-bad-bots' ); ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ( $active_tab == 'blocked' ) : ?>
            <!-- Section for Blocked Bots -->
            <div class="card edhbb-card">
                <h2 class="title"><?php esc_html_e( 'Currently Blocked Bots', 'edh-bad-bots' ); ?></h2>
                
                <!-- Manual hostname update buttons -->
                <div style="margin-bottom: 20px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
                        <input type="hidden" name="action" value="edhbb_update_hostnames">
                        <?php wp_nonce_field( 'edhbb_update_hostnames_nonce' ); ?>
                        <?php submit_button( __( 'Update Missing Hostnames', 'edh-bad-bots' ), 'secondary', 'submit_update_hostnames', false ); ?>
                    </form>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline; margin-left: 10px;">
                        <input type="hidden" name="action" value="edhbb_force_refresh_all_hostnames">
                        <?php wp_nonce_field( 'edhbb_force_refresh_all_hostnames_nonce' ); ?>
                        <?php submit_button( __( 'Force Refresh All Hostnames', 'edh-bad-bots' ), 'secondary', 'submit_force_refresh_all', false ); ?>
                    </form>
                    
                    <p class="description" style="margin-top: 5px;">
                        <?php esc_html_e( 'Update Missing: Resolves hostnames for IPs that show empty or "[No PTR Record]". Force Refresh: Clears cache and re-resolves ALL hostnames.', 'edh-bad-bots' ); ?>
                    </p>
                </div>

                <?php
                // Display debug information if available and WP_DEBUG is enabled
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    $debug_info = get_transient( 'edhbb_debug_info' );
                    if ( $debug_info ) :
                        delete_transient( 'edhbb_debug_info' ); // Delete after displaying
                ?>
                    <div class="notice notice-info inline">
                        <h3><?php esc_html_e( 'Hostname Resolution Debug Info', 'edh-bad-bots' ); ?></h3>
                        <p><?php esc_html_e( 'The following information was gathered to help debug hostname resolution issues.', 'edh-bad-bots' ); ?></p>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Function', 'edh-bad-bots' ); ?></th>
                                    <th><?php esc_html_e( 'Exists?', 'edh-bad-bots' ); ?></th>
                                    <th><?php esc_html_e( 'Callable?', 'edh-bad-bots' ); ?></th>
                                    <th><?php esc_html_e( 'Result for 8.8.8.8', 'edh-bad-bots' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>gethostbyaddr()</code></td>
                                    <td><?php echo esc_html( $debug_info['gethostbyaddr']['exists'] ); ?></td>
                                    <td><?php echo esc_html( $debug_info['gethostbyaddr']['callable'] ); ?></td>
                                    <td><pre><?php echo esc_html( print_r( $debug_info['gethostbyaddr']['result'], true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug output only shown when WP_DEBUG is enabled ?></pre></td>
                                </tr>
                                <tr>
                                    <td><code>dns_get_record()</code></td>
                                    <td><?php echo esc_html( $debug_info['dns_get_record']['exists'] ); ?></td>
                                    <td><?php echo esc_html( $debug_info['dns_get_record']['callable'] ); ?></td>
                                    <td><pre><?php echo esc_html( print_r( $debug_info['dns_get_record']['result'], true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug output only shown when WP_DEBUG is enabled ?></pre></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif;
                } ?>

                <?php if ( ! empty( $blocked_bots ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'IP Address', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Hostname', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Blocked At', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Expires At', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Actions', 'edh-bad-bots' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $blocked_bots as $bot ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $bot['ip_address'] ); ?></td>
                                    <td>
                                        <?php 
                                        $hostname = esc_html( $bot['hostname'] );
                                        if ( $hostname === '[No PTR Record]' || empty( $hostname ) ) {
                                            echo '<em style="color: #666;">[No PTR Record]</em>';
                                        } else {
                                            echo esc_html( $hostname );
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $bot['blocked_at'] ) ) ); ?></td>
                                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $bot['expires_at'] ) ) ); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to unblock this IP?', 'edh-bad-bots' ); ?>');">
                                            <input type="hidden" name="action" value="edhbb_remove_blocked_bot">
                                            <input type="hidden" name="edhbb_blocked_bot_ip" value="<?php echo esc_attr( $bot['ip_address'] ); ?>">
                                            <?php wp_nonce_field( 'edhbb_remove_blocked_bot_nonce' ); ?>
                                            <?php submit_button( __( 'Unblock', 'edh-bad-bots' ), 'secondary', 'submit_unblock_bot', false ); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e( 'No bots currently blocked.', 'edh-bad-bots' ); ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ( $active_tab == 'options' ) : // New Options Tab Section ?>
            <div class="card edhbb-card">
                <h2 class="title"><?php esc_html_e( 'Plugin Options', 'edh-bad-bots' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="edhbb_save_options">
                    <?php wp_nonce_field( 'edhbb_save_options_nonce' ); ?>

                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e( '.htaccess Blocking', 'edh-bad-bots' ); ?></th>
                                <td>
                                    <label for="edhbb_enable_htaccess_blocking">
                                        <input type="checkbox" id="edhbb_enable_htaccess_blocking" name="edhbb_enable_htaccess_blocking" value="yes" <?php checked( $enable_htaccess_blocking ); ?> />
                                        <?php esc_html_e( 'Enable server-level IP blocking via .htaccess file.', 'edh-bad-bots' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'When enabled, blocked bot IPs will be added to your site\'s .htaccess file for immediate server-level blocking, bypassing caching. Disable if you experience conflicts or use Nginx.', 'edh-bad-bots' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="edhbb_block_duration_days"><?php esc_html_e( 'Block Duration', 'edh-bad-bots' ); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="edhbb_block_duration_days" name="edhbb_block_duration_days" value="<?php echo esc_attr( $block_duration_days ); ?>" class="small-text" min="1" />
                                    <p class="description">
                                        <?php esc_html_e( 'Number of days to block a bot\'s IP address.', 'edh-bad-bots' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Save Options', 'edh-bad-bots' ), 'primary', 'submit_options' ); ?>
                </form>
            </div>
        <?php elseif ( $active_tab == 'help' ) : ?>
            <?php
            // Generate the trap URL to display in the help text.
            $site_url_parts = wp_parse_url( site_url() );
            $host = $site_url_parts['host'];
            $scheme = $site_url_parts['scheme'];
            $hash = wp_hash( 'site-' . $host . '-disallow-rule-' . $scheme );
            $path = !empty($site_url_parts['path']) ? $site_url_parts['path'] : '';
            $trap_url = home_url( $path . '/' . $hash . '/' );
            
            // Get current block duration setting
            $current_block_duration = get_option( 'edhbb_block_duration_days', 30 );
            ?>
            <!-- Section for Help Text -->
            <div class="card edhbb-card">
                <h2 class="title"><?php esc_html_e( 'How EDH Bad Bots Works', 'edh-bad-bots' ); ?></h2>
                <p>
                    <?php esc_html_e( 'This plugin helps protect your site from bots that do not respect the `robots.txt` file using an advanced honeypot system with intelligent hostname identification.', 'edh-bad-bots' ); ?>
                </p>
                <h3><?php esc_html_e( 'The Blocking Process:', 'edh-bad-bots' ); ?></h3>
                <ol>
                    <li>
                        <?php esc_html_e( 'A unique, hidden URL is generated for your site and added to your `robots.txt` file with a `Disallow` rule.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'A link to this hidden URL is placed on your website\'s homepage (in the footer) with a `nofollow` attribute. This link is invisible to human users.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'Legitimate search engine bots (like Googlebot) will read `robots.txt` and know not to visit this URL.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'Bad bots, which ignore `robots.txt`, will follow the hidden link on your homepage and hit the trap URL.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <?php 
                        echo sprintf(
                            /* translators: %d: number of days bots are blocked */
                            esc_html__( 'When a bot hits the trap URL, its IP address is recorded and added to a blocklist for %d days (configurable in Options).', 'edh-bad-bots' ),
                            $current_block_duration
                        ); 
                        ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'The plugin performs reverse DNS lookups (PTR records) to identify the hostname/organization behind the blocked IP for better analysis.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'Any subsequent requests from a blocked IP address will be immediately denied access to your site.', 'edh-bad-bots' ); ?>
                    </li>
                </ol>

                <h3><?php esc_html_e( 'Hostname Resolution System:', 'edh-bad-bots' ); ?></h3>
                <p>
                    <?php esc_html_e( 'The plugin includes an advanced DNS lookup system to identify blocked bots:', 'edh-bad-bots' ); ?>
                </p>
                <ul>
                    <li>
                        <strong><?php esc_html_e( 'DNS over HTTPS (DoH):', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Uses secure, encrypted DNS queries via Cloudflare and Google DNS for enhanced privacy and reliability.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'PTR Record Lookups:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Converts IP addresses to hostnames (e.g., "crawl-66-249-66-1.googlebot.com") for better identification of what\'s being blocked.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Background Processing:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Hostname resolution runs automatically in the background via WordPress cron to avoid delays.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Manual Updates:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Use the "Update Missing Hostnames" and "Force Refresh All Hostnames" buttons in the Blocked Bots tab for manual control.', 'edh-bad-bots' ); ?>
                    </li>
                </ul>

                <h3><?php esc_html_e( 'Managing IPs:', 'edh-bad-bots' ); ?></h3>
                <ul>
                    <li>
                        <strong><?php esc_html_e( 'Whitelisted IPs', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'IP addresses added to the whitelist will never be blocked, even if they hit the bot trap. Use this for your own IP address, trusted services, or known legitimate bots.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Blocked Bots', 'edh-bad-bots' ); ?></strong>
                        <?php 
                        echo sprintf(
                            /* translators: %d: number of days bots are blocked */
                            esc_html__( 'This tab shows all IP addresses currently on the blocklist with their resolved hostnames. IPs are automatically removed after %d days, but you can manually unblock them here at any time.', 'edh-bad-bots' ),
                            $current_block_duration
                        ); 
                        ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Options Tab Features:', 'edh-bad-bots' ); ?></strong>
                        <ul>
                            <li><?php esc_html_e( '.htaccess Blocking: Enable or disable server-level IP blocking via the .htaccess file. If disabled, blocking will rely solely on PHP, which might be less effective with caching plugins.', 'edh-bad-bots' ); ?></li>
                            <li><?php esc_html_e( 'Block Duration: Configure how many days to block detected bot IPs (default: 30 days).', 'edh-bad-bots' ); ?></li>
                        </ul>
                    </li>
                </ul>

                <h3><?php esc_html_e( 'Admin Tools:', 'edh-bad-bots' ); ?></h3>
                <ul>
                    <li>
                        <strong><?php esc_html_e( 'Update Missing Hostnames:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Resolves hostnames for blocked IPs that show empty or "[No PTR Record]" to help identify what type of bots are being blocked.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Force Refresh All Hostnames:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Clears the DNS cache and re-resolves hostnames for all blocked IPs. Useful for troubleshooting or getting updated information.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Debug Information:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'When WP_DEBUG is enabled, diagnostic information about hostname resolution functions is displayed to help troubleshoot issues.', 'edh-bad-bots' ); ?>
                    </li>
                </ul>

                <h3><?php esc_html_e( 'Caching Plugin Exclusion:', 'edh-bad-bots' ); ?></h3>
                <p>
                    <?php esc_html_e( 'To ensure that the bot trap works correctly, you must exclude the following unique URL from your caching plugin. This prevents the trap page from being cached and served to human visitors.', 'edh-bad-bots' ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Your unique trap URL is:', 'edh-bad-bots' ); ?></strong>
                </p>
                <p>
                    <code><?php echo esc_url( $trap_url ); ?></code>
                </p>
                <p>
                    <?php esc_html_e( 'Please refer to your caching plugin\'s documentation for instructions on how to exclude a URL.', 'edh-bad-bots' ); ?>
                </p>

                <h3><?php esc_html_e( 'Technical Notes:', 'edh-bad-bots' ); ?></h3>
                <ul>
                    <li>
                        <strong><?php esc_html_e( 'IPv4 and IPv6 Support:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'The hostname resolution system supports both IPv4 and IPv6 addresses for comprehensive coverage.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'DNS Caching:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'Hostname lookups are cached for 1 hour to improve performance and reduce DNS server load.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Fallback Methods:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'If DNS over HTTPS fails, the system automatically falls back to traditional DNS methods for maximum compatibility.', 'edh-bad-bots' ); ?>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div><!-- .tab-content -->

</div><!-- .wrap -->