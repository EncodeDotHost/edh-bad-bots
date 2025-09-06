=== EDH Bad Bots ===
Contributors: EncodeDotHost, nbwpuk
Tags: Security, Bots, DNS, PTR, Hostname
Requires at least: 6.2
Tested up to: 6.8
Stable tag: 1.4.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A smart WordPress plugin that automatically blocks malicious bots and crawlers that ignore your site's `robots.txt` file.

## Description

EDH Bad Bots is an intelligent bot detection and blocking system that protects your WordPress site from unwanted crawlers and malicious bots. Unlike traditional blocking methods that rely on user agent strings (which can be easily spoofed), this plugin uses a honeypot technique to identify and block bots that don't respect your site's `robots.txt` directives.

## Key Features

- **Automatic Bot Detection**: Identifies bad bots using a hidden trap URL technique
- **Smart Blocking System**: Blocks misbehaving bots with configurable duration (default 30 days)
- **Advanced DNS Resolution**: PTR record lookups with DNS over HTTPS (DoH) support for hostname identification
- **Dual-Level Blocking**: Server-level `.htaccess` blocking AND PHP-level blocking for maximum effectiveness
- **Configurable Blocking Methods**: Choose between `.htaccess` blocking (Apache) or PHP-only blocking (Nginx compatible)
- **IP Whitelist Management**: Protect trusted IPs from ever being blocked
- **Enhanced Admin Interface**: Clean dashboard with hostname display, manual hostname updates, and debug tools
- **Background Processing**: Automated hostname resolution via WordPress cron jobs
- **Zero False Positives**: Legitimate search engine bots that follow robots.txt rules are never affected
- **Database Optimization**: Automatic cleanup of expired blocks to maintain performance
- **Security-First Design**: All forms include proper nonce verification and user capability checks

## How It Works

The plugin implements a sophisticated honeypot system:

1. **Trap URL Generation**: Creates a unique, hidden URL specific to your domain
2. **Robots.txt Integration**: Automatically adds a `Disallow` rule for the trap URL
3. **Hidden Link Placement**: Places an invisible link to the trap URL in your site's footer
4. **Bot Detection**: When bad bots ignore robots.txt and follow the hidden link, they're identified
5. **Automatic Blocking**: Detected bot IPs are blocked with configurable duration and immediate effect
6. **Hostname Resolution**: PTR record lookups identify the hostname/organization behind blocked IPs
7. **Legitimate Bot Protection**: Good bots (like Googlebot) respect robots.txt and never trigger the trap

## Installation

1. Upload the `edh-bad-bots` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin works immediately - no configuration required!
4. Optionally, visit **Tools > Bad Bots** to manage whitelisted IPs and view blocked bots

## Configuration

### Admin Dashboard

Access the plugin dashboard at **Tools > Bad Bots** in your WordPress admin:

#### Whitelisted IPs Tab
- Add IP addresses that should never be blocked
- Remove IPs from the whitelist
- View all currently whitelisted addresses with timestamps

#### Blocked Bots Tab
- View all currently blocked IP addresses with hostnames
- See when each IP was blocked and when the block expires
- Manually update missing hostnames for better identification
- Force refresh all hostnames to clear cache and re-resolve
- Debug hostname resolution issues (when WP_DEBUG is enabled)
- Manually unblock IPs if needed

#### Options Tab
- **`.htaccess Blocking`**: Enable/disable server-level IP blocking via `.htaccess` file
- **Block Duration**: Configure how many days to block detected bots
- Configure blocking method based on your server setup (Apache vs Nginx)
- Server-level blocking bypasses caching for immediate effect

#### Help Tab
- Detailed explanation of how the plugin works
- Best practices for managing IPs
- Information about `.htaccess` blocking options
- Unique trap URL for caching plugin exclusion

### Requirements

- WordPress 6.2 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- Apache server (for `.htaccess` blocking) or Nginx (PHP-only blocking)
- Writable `.htaccess` file (if using Apache server-level blocking)

## Technical Details

### Database Tables

The plugin creates two custom database tables:

- `wp_edhbb_blocked_bots`: Stores blocked IP addresses with expiration dates and hostnames
- `wp_edhbb_whitelisted_ips`: Stores permanently whitelisted IP addresses

### DNS Resolution System

The plugin includes an advanced DNS lookup system:

#### DNS over HTTPS (DoH) Support
- **Primary providers**: Cloudflare DNS, Google DNS
- **Secure queries**: HTTPS-encrypted DNS requests for enhanced privacy
- **Fallback system**: Automatic fallback to traditional DNS methods

#### PTR Record Lookups
- **Reverse DNS**: Converts IP addresses to hostnames for better identification
- **IPv4 and IPv6 support**: Full support for both IP versions
- **Caching**: Results cached for 1 hour to improve performance
- **Background processing**: Automated hostname resolution via WordPress cron

### Blocking Methods

The plugin offers two blocking approaches:

#### 1. Server-Level Blocking (`.htaccess`)
- **Default method** for Apache servers
- Blocks IPs at the server level before WordPress loads
- Bypasses caching plugins for immediate effect
- More efficient and faster blocking
- Automatically manages `.htaccess` file with unique markers
- Safe cleanup on plugin deactivation

#### 2. PHP-Level Blocking
- **Alternative method** for Nginx or when `.htaccess` is unavailable
- Blocks IPs during WordPress initialization
- Compatible with all web servers
- May be affected by caching plugins
- No server configuration files modified

### Security Features

- **Nonce Verification**: All forms use WordPress nonces for CSRF protection
- **Capability Checks**: Only users with `manage_options` capability can access admin features
- **Input Sanitization**: All user inputs are properly sanitized and validated
- **SQL Injection Protection**: All database queries use prepared statements
- **Safe `.htaccess` Management**: Uses unique markers and automatic cleanup

### Performance Optimization

- **Automatic Cleanup**: Expired blocks are automatically removed from the database
- **Efficient Queries**: Database operations are optimized for minimal performance impact
- **Smart Loading**: Admin assets only load on the plugin's admin page
- **Server-Level Blocking**: `.htaccess` blocking prevents blocked requests from reaching PHP
- **Whitelist Filtering**: Whitelisted IPs are excluded from `.htaccess` rules automatically
- **DNS Caching**: Hostname lookups cached to reduce DNS query overhead
- **Background Processing**: Hostname resolution runs in background to avoid delays

## API Hooks

### Actions
- `plugins_loaded`: Plugin initialization
- `init`: Early request blocking check
- `template_redirect`: Bot trap detection
- `wp_footer`: Hidden link injection
- `admin_menu`: Admin page registration
- `edhbb_update_hostnames_cron`: Background hostname resolution

### Filters
- `robots_txt`: Adds disallow rule to robots.txt

## File Structure

```
edh-bad-bots/
├── admin/
│   └── views/
│       └── admin-display.php    # Admin interface HTML
├── assets/
│   ├── css/
│   │   └── admin-style.css      # Admin page styling
│   └── js/
│       └── admin-script.js      # Admin page JavaScript
├── includes/
│   ├── class-edhbb-admin.php    # Admin functionality
│   ├── class-edhbb-blocker.php  # Bot detection and blocking
│   ├── class-edhbb-database.php # Database operations
│   └── class-edhbb-dnslookup.php # DNS/PTR lookup system
├── edh-bad-bots.php            # Main plugin file
├── LICENSE
└── readme.txt
```

## Screenshots
1. Allow list management
2. Block list management with hostname display
3. Options Page with configurable settings

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup

1. Clone the repository to your WordPress plugins directory
2. Ensure you have a WordPress development environment running
3. Activate the plugin and test your changes

## Changelog

### Version 1.4.3
- **New**: Advanced DNS lookup system with DNS over HTTPS (DoH) support
- **New**: PTR record resolution for hostname identification of blocked IPs
- **New**: Background hostname resolution via WordPress cron jobs
- **New**: Manual hostname update buttons in admin interface
- **New**: Force refresh all hostnames feature with cache clearing
- **New**: Configurable block duration (days) in Options tab
- **New**: Enhanced admin interface with hostname display in blocked bots table
- **New**: Debug information panel for hostname resolution troubleshooting
- **New**: Support for both IPv4 and IPv6 PTR lookups
- **Enhancement**: Improved blocked bots table with hostname column
- **Enhancement**: Better identification of blocked bots through hostname resolution
- **Enhancement**: Automatic database migration system for hostname column
- **Enhancement**: Fallback DNS resolution methods for better compatibility
- **Enhancement**: DNS query caching for improved performance
- **Fix**: Updated queries to gracefully handle missing columns during migration
- **Fix**: Added backward compatibility for existing installations
- **Fix**: Improved error handling and logging for database migrations

### Version 1.4.2
- Fixed Internal.LineEndings.Mixed in class-edhbb-blocker.php

### Version 1.4.1
- Fixed blank admin page

### Version 1.4.0
- Refactored all class names to use the `EDHBB_` prefix to prevent conflicts.
- Updated the "Tested up to" WordPress version to 6.8.
- Corrected the "Requires PHP" version to 7.4 for consistency.

### Version 1.3.0
- Bringing up to WordPress coding standards

### Version 1.2.3
- Added instructions to the "Help" tab for excluding the unique trap URL from caching plugins.

### Version 1.2.2
- **Fix**: Block duration is now a configurable setting

### Version 1.2.1
- **Fix**: Fixing apache error doc

### Version 1.2.0
- **New**: Added custom error page

### Version 1.1.2
- **Fix**: Now adding htaccess rule at top of file

### Version 1.1.1
- **Fix**: Trigger htaccess rules to have higher priority

### Version 1.1.0 - Major Feature Release
- **New**: Server-level IP blocking via `.htaccess` file management
- **New**: "Options" tab in admin interface for configurable blocking methods
- **Enhanced**: Dual-level blocking system (server + PHP) for maximum effectiveness
- **Added**: Automatic `.htaccess` file management with unique markers
- **Added**: Smart whitelist filtering for `.htaccess` rules
- **Improved**: Admin interface with enhanced tabbed navigation
- **Enhanced**: Performance optimization with server-level blocking
- **Added**: Nginx compatibility with PHP-only blocking fallback
- **Updated**: Comprehensive documentation including `.htaccess` functionality
- **Added**: Better error handling and logging for `.htaccess` operations
- **Added**: Automatic cleanup of `.htaccess` rules on deactivation

### Version 1.0.4
- Adding min PHP + WordPress requirements, tested on WordPress 6.8.2

### Version 1.0.3
- Updated README and license

### Version 1.0.2
- Added plugin action links for easier access to settings

### Version 1.0.1
- Changed author name

### Version 1.0.0
- Initial plugin release
- Core bot detection and blocking functionality
- Admin interface for managing whitelisted IPs and blocked bots
- Automatic robots.txt integration

## License

This project is licensed under the GPL v3 or later.

## Author

**EncodeDotHost**
- Website: [https://encode.host](https://encode.host)
- GitHub: [@EncodeDotHost](https://github.com/EncodeDotHost)

### Contributors
- [@nbwpuk](https://github.com/nbwpuk)

## Support

For support, please visit [https://encode.host](https://encode.host) or create an issue on the GitHub repository.

## Frequently Asked Questions

### Will this block legitimate search engines?
No! Legitimate search engines like Google, Bing, and others respect robots.txt files and will never hit the trap URL.

### How long are bots blocked for?
Bots are blocked for a configurable duration (default 30 days) that you can adjust in the Options tab. You can manually unblock them earlier if needed.

### Can I protect my own IP address?
Yes! Add your IP address to the whitelist in the admin panel to ensure you're never blocked.

### What's the difference between .htaccess and PHP blocking?
`.htaccess` blocking (default) blocks bots at the server level before WordPress loads, making it faster and more effective. PHP blocking works during WordPress initialization and is compatible with Nginx servers.

### What is hostname resolution and why is it useful?
The plugin performs PTR record lookups to identify the hostname/organization behind blocked IP addresses. This helps you understand what types of bots are being blocked (e.g., "crawl-66-249-66-1.googlebot.com" vs unknown IPs) for better analysis and decision-making.

### How does the DNS over HTTPS feature work?
The plugin uses secure HTTPS-encrypted DNS queries via providers like Cloudflare and Google DNS for enhanced privacy and reliability when resolving hostnames. It automatically falls back to traditional DNS methods if DoH is unavailable.

### Does this affect site performance?
The plugin is designed for minimal performance impact. Server-level `.htaccess` blocking actually improves performance by stopping blocked requests before they reach PHP. DNS lookups are cached and processed in the background to avoid delays.

### Will this work with caching plugins?
Yes! Server-level `.htaccess` blocking bypasses caching entirely, ensuring blocked bots are stopped immediately. PHP-level blocking may be affected by some caching configurations. To ensure the bot trap works correctly, you should exclude the unique trap URL from your caching plugin. You can find this URL in the "Help" tab of the plugin's settings.

### What happens if I deactivate the plugin?
The blocking stops immediately and `.htaccess` rules are automatically cleaned up. Your data (blocked IPs and whitelist) is preserved in case you reactivate the plugin later.

### Is it safe for my .htaccess file?
Yes! The plugin uses unique markers (`# BEGIN EDH Bad Bots Block` / `# END EDH Bad Bots Block`) to safely manage its rules without affecting other configurations. Rules are automatically removed on deactivation.

### Can I manually update hostnames for blocked IPs?
Yes! In the "Blocked Bots" tab, you can use the "Update Missing Hostnames" button to resolve hostnames for IPs that don't have them, or "Force Refresh All Hostnames" to clear the cache and re-resolve all hostnames.
