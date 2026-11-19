<?php
// GET api/health.php: {"ok":true} when the config is found, enabled and has an API key.
// It never calls Claude. The page uses it for the status dot next to the store name.
declare(strict_types=1);

const GHAIM_API = true;
require __DIR__ . '/lib/bootstrap.php';

ghaim_boot();

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'HEAD') {
    ghaim_send(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'GET, HEAD']);
}

$cfg = ghaim_ready_config();
if ($cfg === null || !function_exists('curl_init') || ghaim_storage_dir($cfg) === null) {
    ghaim_send(503, ['ok' => false, 'error' => 'paused']);
}
ghaim_load_store();   // a broken data file answers 500 here too

ghaim_send(200, ['ok' => true]);
