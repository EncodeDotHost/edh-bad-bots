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