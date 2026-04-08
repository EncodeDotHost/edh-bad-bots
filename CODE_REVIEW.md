# Code Review: EDH Bad Bots Plugin (v1.4.3)

---

## DNS Logging Issue

**Root cause: IPs with `[No PTR Record]` are retried every hour forever.**

The `get_blocked_ips_without_hostnames()` query in `includes/class-edhbb-database.php:465` includes `OR hostname = '[No PTR Record]'`. This means every IP that failed a PTR lookup is retried on every hourly cron run. The transient cache duration is also 1 hour, so it expires in sync with the cron — it never actually prevents the repeat lookups. Each retry triggers 4 `error_log()` calls in `class-edhbb-dnslookup.php`.

**Fix:** Remove `OR hostname = '[No PTR Record]'` from the query in `get_blocked_ips_without_hostnames()`. Once an IP has no PTR record, stop retrying it via cron.

```php
// Before (class-edhbb-database.php:465):
"SELECT ip_address FROM %i WHERE (hostname IS NULL OR hostname = '' OR hostname = '[No PTR Record]') AND expires_at > %s LIMIT %d",

// After:
"SELECT ip_address FROM %i WHERE (hostname IS NULL OR hostname = '') AND expires_at > %s LIMIT %d",
```

---

## Code Review Findings

### HIGH PRIORITY

**1. `ReflectionClass` used to call a private method (`edh-bad-bots.php:119–121`)**

The cron function uses Reflection to access `get_hostname_for_ip()` from `EDHBB_Blocker`. This is fragile — it breaks silently on rename and bypasses encapsulation for no reason, since `EDHBB_DNSLookup::get_hostname_for_blocked_ip()` is already a public static method.

```php
// Before:
$reflection = new ReflectionClass( $edh_blocker );
$hostname_method = $reflection->getMethod( 'get_hostname_for_ip' );
$hostname_method->setAccessible( true );
$hostname = $hostname_method->invoke( $edh_blocker, $ip_address );

// After:
$hostname = EDHBB_DNSLookup::get_hostname_for_blocked_ip( $ip_address );
```

---

**2. `clean_old_blocked_bots()` runs on every page request (`class-edhbb-database.php:326, 288`)**

Called from `is_bot_blocked()` and `get_blocked_bots()`, which fire on every page load. This triggers a DELETE query and potentially a full htaccess rewrite on every single request.

Fix: Gate with a transient so cleanup runs at most once per hour.

```php
public function clean_old_blocked_bots() {
    if ( get_transient( 'edhbb_last_cleanup' ) ) {
        return; // Already ran recently
    }
    set_transient( 'edhbb_last_cleanup', true, HOUR_IN_SECONDS );

    // ... existing DELETE query ...
}
```

---

**3. `column_exists()` INFORMATION_SCHEMA query runs on every page request (`class-edhbb-database.php:222, 291`)**

Called inside `add_blocked_bot()` and `get_blocked_bots()` — both of which run on every request. Querying `INFORMATION_SCHEMA.COLUMNS` is expensive and unnecessary after the column has been confirmed to exist.

Fix: Cache the result in a WordPress option on first successful check.

```php
private function column_exists( $table_name, $column_name ) {
    $cache_key = 'edhbb_col_' . md5( $table_name . $column_name );
    $cached = get_option( $cache_key );
    if ( $cached !== false ) {
        return (bool) $cached;
    }
    // ... existing INFORMATION_SCHEMA query ...
    update_option( $cache_key, $exists ? '1' : '0', false );
    return $exists;
}
```

---

### MEDIUM PRIORITY

**4. Verbose debug logging on every DNS lookup (`class-edhbb-dnslookup.php:54–89`)**

Four `error_log()` calls fire per lookup when `WP_DEBUG_LOG` is true, including routine "Starting lookup" and "Final result" messages. Only error-path messages are useful in normal operation.

Fix: Remove the start/end routine log messages; keep only DoH failure, fallback, and exception messages.

---

**5. htaccess regex markers not escaped (`class-edhbb-database.php:536, 608`)**

`$start_marker` and `$end_marker` are used directly in `preg_replace()` without escaping. The current strings are safe, but it is a fragile pattern.

```php
// Before:
$htaccess_content = preg_replace( "/{$start_marker}.*?{$end_marker}\s*/s", '', $htaccess_content );

// After:
$htaccess_content = preg_replace(
    '/' . preg_quote( $start_marker, '/' ) . '.*?' . preg_quote( $end_marker, '/' ) . '\s*/s',
    '',
    $htaccess_content
);
```

---

**6. Force-refresh-all-hostnames has no record limit (`class-edhbb-admin.php`)**

Calls `get_blocked_bots(0)` (no limit) and resolves all IPs synchronously. On a large dataset this will hit PHP's execution time limit or memory limit.

Fix: Add a batch size cap (e.g. 50) with a redirect loop or background processing.

---

**7. No caching on `is_ip_whitelisted()` and `is_bot_blocked()` (`class-edhbb-database.php:410, 325`)**

Both methods hit the database on every request. The whitelist rarely changes and is a prime candidate for transient caching (invalidated on add/remove).

---

### LOW PRIORITY

**8. `traditional_hostname_lookup()` fallback is broken (`class-edhbb-blocker.php:184`)**

Passes the raw IP to `dns_get_record( $ip_address, DNS_PTR )`. PTR lookups require reverse DNS format (e.g. `4.3.2.1.in-addr.arpa`), not a raw IP. The `EDHBB_DNSLookup` class handles this correctly; this fallback does not and will always return empty.

Fix: Either remove the fallback entirely (since `EDHBB_DNSLookup` is always available), or fix the format using the same `ip_to_reverse_dns()` approach.

---

**9. Synchronous DNS lookup on trap hit adds latency (`class-edhbb-blocker.php:133`)**

When a bot hits the trap, a DoH lookup (up to 5s timeout) runs before the 403 is returned. The hostname is informational — the block would happen regardless.

Fix: Block immediately (`block_request_action()` first), then store an empty hostname and let the cron fill it in on the next run.

---

**10. IPv6 IPs not blocked at .htaccess level (`class-edhbb-database.php:559`)**

`Deny from` does not reliably handle IPv6 on all Apache configurations. IPv6 bots will be blocked at PHP level but not at the .htaccess layer.

Fix: Document this limitation in the plugin options, or use `Require not ip` syntax (requires Apache 2.4+ with `mod_authz_host`).

---

---

## Additional Findings

### HIGH PRIORITY

**11. IP Spoofing / Denial of Service Vulnerability (`class-edhbb-blocker.php:51–68`)**

`get_client_ip()` trusts `HTTP_CLIENT_IP` and `HTTP_X_FORWARDED_FOR`, which can be trivially spoofed by malicious bots to impersonate whitelisted IPs (e.g. Googlebot, the site admin). This causes the wrong IPs to be blocked globally.

Fix: Rewrite to use only `REMOTE_ADDR`.

```php
// Before:
if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ... ) { ... }
elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ... ) { ... }
elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ... ) { ... }

// After:
private function get_client_ip() {
    $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'UNKNOWN';
    if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
        return $ip_address;
    }
    return 'UNKNOWN';
}
```

---

**12. N+1 Database Queries During .htaccess Generation (`class-edhbb-database.php:544–550`)**

Inside `update_htaccess_block_rules()`, the `foreach` over all blocked IPs calls `$this->is_ip_whitelisted()` on each iteration — one SQL query per bot. With 1,000 blocked IPs this executes 1,000 queries per .htaccess write.

Fix: Fetch the full whitelist once before the loop using the existing `get_whitelisted_ips()` method, then use `in_array()`.

```php
// Before:
$blocked_ips_raw = $this->get_blocked_bots();
$blocked_ips_for_htaccess = [];
foreach ($blocked_ips_raw as $bot) {
    if (!$this->is_ip_whitelisted($bot['ip_address'])) {
        $blocked_ips_for_htaccess[] = $bot['ip_address'];
    }
}

// After:
$blocked_ips_raw = $this->get_blocked_bots();
$whitelisted_ips_data = $this->get_whitelisted_ips();
$whitelisted_ips = array_column( $whitelisted_ips_data, 'ip_address' );
$blocked_ips_for_htaccess = [];
foreach ($blocked_ips_raw as $bot) {
    if ( ! in_array( $bot['ip_address'], $whitelisted_ips, true ) ) {
        $blocked_ips_for_htaccess[] = $bot['ip_address'];
    }
}
```

---

**13. Admin Files Loaded on Every Frontend Request (`class-edhbb-database.php:31`)**

`__construct()` calls `$this->init_filesystem()`, which `require_once`s `wp-admin/includes/file.php` if `WP_Filesystem` is not already loaded. Because `EDHBB_Database` is instantiated on every page load via `plugins_loaded`, this drags a heavy admin-only file into every frontend request.

Fix: Remove `$this->init_filesystem()` from `__construct()` and call it at the top of `update_htaccess_block_rules()` and `remove_htaccess_block_rules()` only — the two places that actually need filesystem access.

---

### MEDIUM PRIORITY

**14. Trap Logic False Positives via `strpos` (`class-edhbb-blocker.php:119`)**

`detect_bot_trap_hit()` uses `strpos( $current_url, $expected_trap_path ) !== false`. This is too loose — a query string or URL parameter that happens to contain the trap hash (e.g. `/?q={hash}`) will trigger a block against a real user.

Fix: Parse the URI and compare only the path component exactly.

```php
// Before:
if ( strpos( $current_url, $expected_trap_path ) !== false ) {

// After:
$parsed_current_url = wp_parse_url( $current_url );
$current_path = isset( $parsed_current_url['path'] ) ? $parsed_current_url['path'] : '';
if ( trim( $current_path, '/' ) === trim( $expected_trap_path, '/' ) ) {
```

---

### LOW PRIORITY

**15. Screen Reader UX — Trap Link Readable by Assistive Technology (`edh-bad-bots.php:194`)**

The hidden trap link has `tabindex="-1"` but no `aria-hidden="true"`. Screen readers will still announce "Sssshhh, secret bot trap!" to visually impaired users.

Fix: Add `aria-hidden="true"` to the `<a>` tag.

```php
// Before:
echo '<a href="' . esc_url( $trap_url ) . '" rel="nofollow" tabindex="-1">Sssshhh, secret bot trap!</a>';

// After:
echo '<a href="' . esc_url( $trap_url ) . '" rel="nofollow" tabindex="-1" aria-hidden="true">Sssshhh, secret bot trap!</a>';
```

---

## Summary of Files to Change

| File | Change |
|------|--------|
| `includes/class-edhbb-database.php:465` | Remove `OR hostname = '[No PTR Record]'` from `get_blocked_ips_without_hostnames()` |
| `includes/class-edhbb-database.php:482` | Gate `clean_old_blocked_bots()` with a transient |
| `includes/class-edhbb-database.php:222,291` | Cache `column_exists()` result in a WordPress option |
| `includes/class-edhbb-database.php:536,608` | Wrap markers in `preg_quote()` |
| `includes/class-edhbb-dnslookup.php:54–89` | Remove routine start/end log messages |
| `edh-bad-bots.php:119–121` | Replace Reflection with direct `EDHBB_DNSLookup::get_hostname_for_blocked_ip()` |
| `includes/class-edhbb-blocker.php:133` | Block immediately, defer hostname lookup to cron |
| `includes/class-edhbb-blocker.php:184` | Fix or remove broken `traditional_hostname_lookup()` |
| `includes/class-edhbb-blocker.php:51–68` | Rewrite `get_client_ip()` to use only `REMOTE_ADDR` (Issue 11) |
| `includes/class-edhbb-database.php:544–550` | Replace per-bot `is_ip_whitelisted()` calls with bulk `get_whitelisted_ips()` + `in_array()` (Issue 12) |
| `includes/class-edhbb-database.php:31` | Remove `init_filesystem()` from constructor; move call into `update_htaccess_block_rules()` and `remove_htaccess_block_rules()` (Issue 13) |
| `includes/class-edhbb-blocker.php:119` | Replace `strpos` trap check with `wp_parse_url` strict path comparison (Issue 14) |
| `edh-bad-bots.php:194` | Add `aria-hidden="true"` to the trap `<a>` tag (Issue 15) |