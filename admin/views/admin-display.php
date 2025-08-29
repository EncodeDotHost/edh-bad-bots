<?php
/**
 * EDH Bad Bots - Admin Display View
 *
 * This file contains the HTML and PHP for the plugin's administration page.
 * It displays blocked bots, allows whitelisting IPs, and manages unblocking.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Ensure $db is available from the calling context (EDH_Admin::render_admin_page()).
if ( ! isset( $db ) || ! ( $db instanceof EDH_Database ) ) {
    return; // Safety check
}

// Fetch data from the database using the provided $db instance.
$blocked_bots = $db->get_blocked_bots();
$whitelisted_ips = $db->get_whitelisted_ips();

// Determine which tab is active (default to 'whitelist')
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'whitelist';

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
            <div class="card">
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
            <div class="card">
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
            <div class="card">
                <h2 class="title"><?php esc_html_e( 'Currently Blocked Bots', 'edh-bad-bots' ); ?></h2>
                <?php if ( ! empty( $blocked_bots ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'IP Address', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Blocked At', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Expires At', 'edh-bad-bots' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Actions', 'edh-bad-bots' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $blocked_bots as $bot ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $bot['ip_address'] ); ?></td>
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
            <div class="card">
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
            $site_url_parts = parse_url( site_url() );
            $host = $site_url_parts['host'];
            $scheme = $site_url_parts['scheme'];
            $hash = wp_hash( 'site-' . $host . '-disallow-rule-' . $scheme );
            $path = !empty($site_url_parts['path']) ? $site_url_parts['path'] : '';
            $trap_url = esc_url( home_url( $path . '/' . $hash . '/' ) );
            ?>
            <!-- Section for Help Text -->
            <div class="card">
                <h2 class="title"><?php esc_html_e( 'How EDH Bad Bots Works', 'edh-bad-bots' ); ?></h2>
                <p>
                    <?php esc_html_e( 'This plugin helps protect your site from bots that do not respect the `robots.txt` file.', 'edh-bad-bots' ); ?>
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
                        <?php esc_html_e( 'When a bot hits the trap URL, its IP address is recorded and added to a **blocklist** for 30 days.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <?php esc_html_e( 'Any subsequent requests from a blocked IP address will be immediately denied access to your site.', 'edh-bad-bots' ); ?>
                    </li>
                </ol>

                <h3><?php esc_html_e( 'Managing IPs:', 'edh-bad-bots' ); ?></h3>
                <ul>
                    <li>
                        <strong><?php esc_html_e( 'Whitelisted IPs', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'IP addresses added to the whitelist will **never** be blocked, even if they hit the bot trap. Use this for your own IP address, trusted services, or known legitimate bots.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Blocked Bots', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'This tab shows all IP addresses currently on the blocklist. IPs are automatically removed after 30 days, but you can manually unblock them here at any time.', 'edh-bad-bots' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( '.htaccess Blocking Option:', 'edh-bad-bots' ); ?></strong>
                        <?php esc_html_e( 'On the "Options" tab, you can choose to enable or disable server-level IP blocking via the .htaccess file. If disabled, blocking will rely solely on PHP, which might be less effective with caching plugins.', 'edh-bad-bots' ); ?>
                    </li>
                </ul>

                <h3><?php esc_html_e( 'Caching Plugin Exclusion:', 'edh-bad-bots' ); ?></h3>
                <p>
                    <?php esc_html_e( 'To ensure that the bot trap works correctly, you **must** exclude the following unique URL from your caching plugin. This prevents the trap page from being cached and served to human visitors.', 'edh-bad-bots' ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Your unique trap URL is:', 'edh-bad-bots' ); ?></strong>
                </p>
                <p>
                    <code><?php echo $trap_url; ?></code>
                </p>
                <p>
                    <?php esc_html_e( 'Please refer to your caching plugin\'s documentation for instructions on how to exclude a URL.', 'edh-bad-bots' ); ?>
                </p>

            </div>
        <?php endif; ?>
    </div><!-- .tab-content -->

</div><!-- .wrap -->