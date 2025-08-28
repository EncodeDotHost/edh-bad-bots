# EDH Bad Bots

A smart WordPress plugin that automatically blocks malicious bots and crawlers that ignore your site's `robots.txt` file.

## Description

EDH Bad Bots is an intelligent bot detection and blocking system that protects your WordPress site from unwanted crawlers and malicious bots. Unlike traditional blocking methods that rely on user agent strings (which can be easily spoofed), this plugin uses a honeypot technique to identify and block bots that don't respect your site's `robots.txt` directives.

## Key Features

- **Automatic Bot Detection**: Identifies bad bots using a hidden trap URL technique
- **Smart Blocking System**: Blocks misbehaving bots for 30 days automatically
- **IP Whitelist Management**: Protect trusted IPs from ever being blocked
- **Clean Admin Interface**: Easy-to-use dashboard with tabbed navigation
- **Zero False Positives**: Legitimate search engine bots that follow robots.txt rules are never affected
- **Database Optimization**: Automatic cleanup of expired blocks to maintain performance
- **Security-First Design**: All forms include proper nonce verification and user capability checks

## How It Works

The plugin implements a sophisticated honeypot system:

1. **Trap URL Generation**: Creates a unique, hidden URL specific to your domain
2. **Robots.txt Integration**: Automatically adds a `Disallow` rule for the trap URL
3. **Hidden Link Placement**: Places an invisible link to the trap URL in your site's footer
4. **Bot Detection**: When bad bots ignore robots.txt and follow the hidden link, they're identified
5. **Automatic Blocking**: Detected bot IPs are blocked for 30 days with immediate effect
6. **Legitimate Bot Protection**: Good bots (like Googlebot) respect robots.txt and never trigger the trap

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
- View all currently blocked IP addresses
- See when each IP was blocked and when the block expires
- Manually unblock IPs if needed

#### Help Tab
- Detailed explanation of how the plugin works
- Best practices for managing IPs

### Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

## Technical Details

### Database Tables

The plugin creates two custom database tables:

- `wp_edhbb_blocked_bots`: Stores blocked IP addresses with expiration dates
- `wp_edhbb_whitelisted_ips`: Stores permanently whitelisted IP addresses

### Security Features

- **Nonce Verification**: All forms use WordPress nonces for CSRF protection
- **Capability Checks**: Only users with `manage_options` capability can access admin features
- **Input Sanitization**: All user inputs are properly sanitized and validated
- **SQL Injection Protection**: All database queries use prepared statements

### Performance Optimization

- **Automatic Cleanup**: Expired blocks are automatically removed from the database
- **Efficient Queries**: Database operations are optimized for minimal performance impact
- **Smart Loading**: Admin assets only load on the plugin's admin page

## API Hooks

### Actions
- `plugins_loaded`: Plugin initialization
- `init`: Early request blocking check
- `template_redirect`: Bot trap detection
- `wp_footer`: Hidden link injection
- `admin_menu`: Admin page registration

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
│   ├── class-edh-admin.php      # Admin functionality
│   ├── class-edh-blocker.php    # Bot detection and blocking
│   └── class-edh-database.php   # Database operations
├── changelog.txt
├── edh-bad-bots.php            # Main plugin file
├── LICENSE
└── README.md
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup

1. Clone the repository to your WordPress plugins directory
2. Ensure you have a WordPress development environment running
3. Activate the plugin and test your changes

## Changelog

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
Bots are automatically blocked for 30 days. You can manually unblock them earlier if needed.

### Can I protect my own IP address?
Yes! Add your IP address to the whitelist in the admin panel to ensure you're never blocked.

### Does this affect site performance?
The plugin is designed for minimal performance impact with efficient database queries and automatic cleanup of old data.

### What happens if I deactivate the plugin?
The blocking stops immediately, but your data (blocked IPs and whitelist) is preserved in case you reactivate the plugin later.
