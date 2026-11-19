<?php
// Shared setup for the API endpoints: error handling, JSON responses and small
// string helpers. Everything in api/lib is blocked from the web by .htaccess.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

define('GHAIM_APP_DIR', dirname(__DIR__, 2));

const GHAIM_TZ = 'Asia/Riyadh';
const GHAIM_MAX_BODY = 32768;      // bytes per chat request
const GHAIM_MAX_MESSAGES = 16;     // turns of history per request
const GHAIM_MAX_USER_CHARS = 1000;
const GHAIM_MAX_ASSISTANT_CHARS = 2000;
const GHAIM_MAX_CALLS = 5;         // model calls per customer message (tool loop)

require __DIR__ . '/config.php';
require __DIR__ . '/request.php';
require __DIR__ . '/store.php';
require __DIR__ . '/prompt.php';
require __DIR__ . '/claude.php';
require __DIR__ . '/limits.php';

/** Hide PHP errors from visitors and turn anything unexpected into a plain JSON error. */
function ghaim_boot(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    ob_start();

    set_exception_handler(static function (Throwable $e): void {
        error_log('ghaim: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        ghaim_send(500, ['error' => 'error']);
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($err === null || !in_array($err['type'], $fatal, true) || headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"error":"error"}';
    });
}

/** Send a JSON response and stop. Any stray output (warnings, BOMs) is dropped first. */
function ghaim_send(int $status, array $body, array $headers = []): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo ghaim_json($body);
    exit;
}

function ghaim_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

/** Number of characters (code points) in a UTF-8 string. */
function ghaim_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./su', $s);
}

/** First $max characters of a UTF-8 string. */
function ghaim_cut(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    preg_match('/^.{0,' . $max . '}/su', $s, $m);
    return $m[0] ?? '';
}

/** Length the way JavaScript counts it (UTF-16 code units). */
function ghaim_utf16_len(string $s): int
{
    return (int) preg_match_all('/./su', $s) + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $s);
}

function ghaim_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** trim() that also removes Unicode spaces, like JavaScript's String.prototype.trim. */
function ghaim_trim(string $s): string
{
    return (string) preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $s);
}

/** JavaScript String(value) for the values a tool input can hold. */
function ghaim_js_string(mixed $v): string
{
    if (is_string($v)) {
        return $v;
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    if ($v === null) {
        return 'null';
    }
    if (is_array($v)) {
        if (!array_is_list($v)) {
            return '[object Object]';
        }
        return implode(',', array_map(static fn($x) => $x === null ? '' : ghaim_js_string($x), $v));
    }
    return '';
}

/** JavaScript Number(value), with NaN read as 0 (every caller treats NaN like 0). */
function ghaim_js_number(mixed $v): float
{
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    if (is_bool($v)) {
        return $v ? 1.0 : 0.0;
    }
    if (is_string($v)) {
        $t = ghaim_trim($v);
        return $t !== '' && is_numeric($t) ? (float) $t : 0.0;
    }
    if (is_array($v) && array_is_list($v) && count($v) === 1) {
        return ghaim_js_number(ghaim_js_string($v[0] ?? ''));
    }
    return 0.0;
}

/** JavaScript parseInt(value, 10); null stands for NaN. */
function ghaim_js_parse_int(mixed $v): ?int
{
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return is_finite($v) && abs($v) < 1e15 ? (int) $v : null;
    }
    if (is_string($v) && preg_match('/^\s*([+-]?\d+)/', $v, $m)) {
        return (int) $m[1];
    }
    return null;
}

/** Today's date in Saudi Arabia. */
function ghaim_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(GHAIM_TZ));
}
