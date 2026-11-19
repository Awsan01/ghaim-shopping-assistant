<?php
// Finds and loads the private config, and prepares the storage folder.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

const GHAIM_DEFAULTS = [
    'anthropic_api_key' => '',
    'workspace_id' => '',
    'models' => [
        'fast' => 'claude-haiku-5-5',
        'smart' => 'claude-sonnet-5-5',
    ],
    'max_tokens' => 700,
    'enabled' => true,
    'limits' => [
        'ip_per_hour' => 30,
        'ip_per_day' => 100,
        'site_per_day' => 500,
    ],
    'ip_salt' => '',
    'storage_dir' => '',
    'anthropic_base_url' => 'https://api.anthropic.com',
];

/**
 * Where the config lives, in this order:
 * 1. the path in the GHAIM_CONFIG environment variable;
 * 2. private/ghaim-config.php in a folder above the app (at most 6 levels up),
 *    skipping public_html and everything inside it. On Hostinger that is
 *    domains/<domain>/private/ghaim-config.php, next to public_html;
 * 3. config.local.php in the app folder, only under PHP's built-in server (php -S).
 */
function ghaim_find_config(): ?string
{
    $env = getenv('GHAIM_CONFIG');
    if (!is_string($env) || $env === '') {
        $env = is_string($_SERVER['GHAIM_CONFIG'] ?? null) ? $_SERVER['GHAIM_CONFIG'] : '';
    }
    if ($env !== '') {
        return ghaim_readable_file($env) ? $env : null;
    }

    $dir = GHAIM_APP_DIR;
    for ($level = 1; $level <= 6; $level++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
        if (preg_match('~(^|[/\\\\])public_html([/\\\\]|$)~i', $dir)) {
            continue;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'ghaim-config.php';
        if (ghaim_readable_file($file)) {
            return $file;
        }
    }

    if (PHP_SAPI === 'cli-server') {
        $local = GHAIM_APP_DIR . DIRECTORY_SEPARATOR . 'config.local.php';
        if (ghaim_readable_file($local)) {
            return $local;
        }
    }
    return null;
}

/** is_file() that never touches a path outside open_basedir, so no warnings are raised. */
function ghaim_readable_file(string $path): bool
{
    return ghaim_inside_open_basedir($path) && @is_file($path) && @is_readable($path);
}

function ghaim_inside_open_basedir(string $path): bool
{
    $setting = (string) ini_get('open_basedir');
    if ($setting === '') {
        return true;
    }
    foreach (explode(PATH_SEPARATOR, $setting) as $base) {
        $base = trim($base);
        if ($base === '') {
            continue;
        }
        if ($base === '.') {
            $base = (string) getcwd();
        }
        $real = @realpath($base);
        if (is_string($real)) {
            $base = $real;
        }
        $base = rtrim($base, '/\\');
        if ($base === '' || $path === $base
            || str_starts_with($path, $base . '/') || str_starts_with($path, $base . '\\')) {
            return true;
        }
    }
    return false;
}

/** The config merged over the defaults, or null when no config file was found. */
function ghaim_load_config(): ?array
{
    $file = ghaim_find_config();
    if ($file === null) {
        return null;
    }
    $loaded = (static fn(string $f): mixed => include $f)($file);
    if (!is_array($loaded)) {
        error_log('ghaim: the config file does not return an array: ' . $file);
        return null;
    }
    $cfg = array_replace_recursive(GHAIM_DEFAULTS, $loaded);
    $cfg['_file'] = $file;
    return $cfg;
}

/**
 * The config when the assistant may answer, or null for "demo paused":
 * no config file, enabled = false, or no usable API key or base URL.
 */
function ghaim_ready_config(): ?array
{
    $cfg = ghaim_load_config();
    if ($cfg === null) {
        return null;
    }
    if (filter_var($cfg['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
        return null;
    }
    $key = is_string($cfg['anthropic_api_key']) ? trim($cfg['anthropic_api_key']) : '';
    if ($key === '' || !preg_match('/^[\x21-\x7e]+$/', $key)) {
        return null;
    }
    $workspace = is_string($cfg['workspace_id']) ? trim($cfg['workspace_id']) : '';
    if ($workspace !== '' && !preg_match('/^[\x21-\x7e]+$/', $workspace)) {
        error_log('ghaim: workspace_id has characters that cannot go in a header');
        return null;
    }
    if (!ghaim_base_url_ok($cfg['anthropic_base_url'])) {
        error_log('ghaim: anthropic_base_url must be https (http is allowed only for localhost)');
        return null;
    }
    $cfg['anthropic_api_key'] = $key;
    $cfg['workspace_id'] = $workspace;
    $cfg['anthropic_base_url'] = rtrim(trim($cfg['anthropic_base_url']), '/');
    $cfg['max_tokens'] = is_numeric($cfg['max_tokens']) ? max(64, min(4096, (int) $cfg['max_tokens'])) : 700;
    $limits = is_array($cfg['limits']) ? $cfg['limits'] : [];
    $cfg['limits'] = [];
    foreach (GHAIM_DEFAULTS['limits'] as $name => $default) {
        $cfg['limits'][$name] = is_numeric($limits[$name] ?? null) ? (int) $limits[$name] : $default;
    }
    return $cfg;
}

/** The key never travels over plain HTTP, except to a local mock in tests. */
function ghaim_base_url_ok(mixed $url): bool
{
    if (!is_string($url)) {
        return false;
    }
    $p = parse_url(trim($url));
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return false;
    }
    $scheme = strtolower($p['scheme']);
    $host = strtolower(trim($p['host'], '[]'));
    return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
}

/** Model id for "fast" or "smart". The browser can only pick one of these two names. */
function ghaim_model(array $cfg, string $speed): string
{
    $model = $cfg['models'][$speed] ?? null;
    if (!is_string($model) || !preg_match('/^[A-Za-z0-9._:@\/-]{1,100}$/', $model)) {
        $model = GHAIM_DEFAULTS['models'][$speed];
    }
    return $model;
}

/**
 * Folder for rate-limit counters and usage logs: storage_dir from the config,
 * or a ghaim-storage folder next to the config file. Created with mode 0700.
 */
function ghaim_storage_dir(array $cfg): ?string
{
    $dir = is_string($cfg['storage_dir']) ? trim($cfg['storage_dir']) : '';
    $configDir = dirname($cfg['_file']);
    if ($dir === '') {
        $dir = $configDir . DIRECTORY_SEPARATOR . 'ghaim-storage';
    } elseif (!preg_match('~^([/\\\\]|[A-Za-z]:[/\\\\])~', $dir)) {
        $dir = $configDir . DIRECTORY_SEPARATOR . $dir;   // relative to the config file
    }
    $dir = rtrim($dir, '/\\');
    if (!ghaim_inside_open_basedir($dir)) {
        error_log('ghaim: the storage folder is outside open_basedir: ' . $dir);
        return null;
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('ghaim: cannot create the storage folder: ' . $dir);
            return null;
        }
        @chmod($dir, 0700);
        // In case the folder was placed inside the web root by mistake
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!is_writable($dir)) {
        error_log('ghaim: the storage folder is not writable: ' . $dir);
        return null;
    }
    return $dir;
}
