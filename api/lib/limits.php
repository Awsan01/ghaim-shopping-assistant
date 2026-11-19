<?php
// Message limits per visitor and for the whole site, and the monthly usage log.
// Counters are small JSON files guarded with flock. Visitor IPs are stored only as salted hashes.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

/**
 * Counts this message against the limits (sliding windows). Returns null when it may go
 * ahead, or ['scope' => 'visitor'|'site', 'retry_after' => seconds] when a limit is reached.
 */
function ghaim_rate_limit(array $cfg, string $storage): ?array
{
    $dir = $storage . DIRECTORY_SEPARATOR . 'limits';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create the limits folder');
    }
    $now = time();
    $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
    $visitor = substr(hash_hmac('sha256', $ip, ghaim_ip_salt($cfg, $storage)), 0, 40);
    $limits = $cfg['limits'];

    $visitorFile = ghaim_open_locked($dir . DIRECTORY_SEPARATOR . 'visitor-' . $visitor . '.json');
    $siteFile = null;
    try {
        $siteFile = ghaim_open_locked($dir . DIRECTORY_SEPARATOR . 'site.json');
        $visitorTimes = ghaim_read_times($visitorFile, $now - 86400);
        $siteTimes = ghaim_read_times($siteFile, $now - 86400);

        $wait = ghaim_wait_for($visitorTimes, [[3600, $limits['ip_per_hour']], [86400, $limits['ip_per_day']]], $now);
        if ($wait > 0) {
            return ['scope' => 'visitor', 'retry_after' => $wait];
        }
        $wait = ghaim_wait_for($siteTimes, [[86400, $limits['site_per_day']]], $now);
        if ($wait > 0) {
            return ['scope' => 'site', 'retry_after' => $wait];
        }
        $visitorTimes[] = $now;
        $siteTimes[] = $now;
        ghaim_write_times($visitorFile, $visitorTimes);
        ghaim_write_times($siteFile, $siteTimes);
        return null;
    } finally {
        foreach ([$visitorFile, $siteFile] as $h) {
            if (is_resource($h)) {
                flock($h, LOCK_UN);
                fclose($h);
            }
        }
        if (random_int(1, 50) === 1) {
            ghaim_clean_limits($dir, $now);
        }
    }
}

/** Seconds until one more message fits in every [window, limit] rule; 0 when it fits now. */
function ghaim_wait_for(array $times, array $rules, int $now): int
{
    $wait = 0;
    foreach ($rules as [$window, $limit]) {
        $recent = array_values(array_filter($times, static fn(int $t): bool => $t > $now - $window));
        if (count($recent) < $limit) {
            continue;
        }
        sort($recent);
        // Wait until enough old messages leave the window to get below the limit.
        $free = $limit > 0 ? $recent[count($recent) - $limit] + $window - $now : $window;
        $wait = max($wait, $free, 1);
    }
    return $wait;
}

/** @return resource */
function ghaim_open_locked(string $file)
{
    $h = @fopen($file, 'c+');
    if ($h === false || !flock($h, LOCK_EX)) {
        throw new RuntimeException('cannot open or lock ' . basename($file));
    }
    return $h;
}

/** @param resource $h */
function ghaim_read_times($h, int $since): array
{
    rewind($h);
    $data = json_decode((string) stream_get_contents($h), true);
    if (!is_array($data)) {
        return [];
    }
    return array_values(array_filter($data, static fn($t): bool => is_int($t) && $t > $since));
}

/** @param resource $h */
function ghaim_write_times($h, array $times): void
{
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, (string) json_encode(array_values($times)));
    fflush($h);
}

/** Visitor files idle for two days can go (the longest window is one day). */
function ghaim_clean_limits(string $dir, int $now): void
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'visitor-*.json') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $now - 2 * 86400) {
            @unlink($file);
        }
    }
}

/** ip_salt from the config, or a random salt created once in the storage folder. */
function ghaim_ip_salt(array $cfg, string $storage): string
{
    $salt = is_string($cfg['ip_salt']) ? trim($cfg['ip_salt']) : '';
    if ($salt !== '') {
        return $salt;
    }
    $file = $storage . DIRECTORY_SEPARATOR . 'ip-salt.txt';
    $saved = @file_get_contents($file);
    if (is_string($saved) && strlen(trim($saved)) >= 32) {
        return trim($saved);
    }
    $new = bin2hex(random_bytes(32));
    $h = @fopen($file, 'x');
    if ($h !== false) {
        fwrite($h, $new);
        fclose($h);
        @chmod($file, 0600);
        return $new;
    }
    usleep(50000);   // another request is creating it right now
    $saved = @file_get_contents($file);
    return is_string($saved) && strlen(trim($saved)) >= 32 ? trim($saved) : $new;
}

/**
 * One CSV line per answered request in usage-YYYY-MM.csv: time, model, token counts and
 * status. No message text and no IP addresses.
 */
function ghaim_log_usage(string $storage, string $model, array $usage, string $status): void
{
    $now = ghaim_now();
    $file = $storage . DIRECTORY_SEPARATOR . 'usage-' . $now->format('Y-m') . '.csv';
    $h = @fopen($file, 'a');
    if ($h === false) {
        error_log('ghaim: cannot write the usage log');
        return;
    }
    if (flock($h, LOCK_EX)) {
        if ((fstat($h)['size'] ?? 0) === 0) {
            @chmod($file, 0600);
            fwrite($h, "time,model,input_tokens,output_tokens,cache_write_tokens,cache_read_tokens,status\n");
        }
        fwrite($h, implode(',', [
            $now->format('c'),
            preg_replace('/[^A-Za-z0-9._:@\/-]/', '', $model),
            $usage['input'],
            $usage['output'],
            $usage['cache_write'],
            $usage['cache_read'],
            preg_replace('/[^a-z_]/', '', $status),
        ]) . "\n");
        fflush($h);
        flock($h, LOCK_UN);
    }
    fclose($h);
}
