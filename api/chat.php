<?php
// POST api/chat.php: one assistant reply for the chat on the page.
// Request:  {messages, channel, speed, cart, open_tickets, next_ticket_id, wa_button}
// Response: {text, actions, steps} (plus truncated or refused when that happens)
declare(strict_types=1);

const GHAIM_API = true;
require __DIR__ . '/lib/bootstrap.php';

ghaim_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ghaim_send(405, ['error' => 'method_not_allowed'], ['Allow' => 'POST']);
}
if (!ghaim_same_origin()) {
    ghaim_send(403, ['error' => 'forbidden']);
}

$cfg = ghaim_ready_config();
if ($cfg === null) {
    ghaim_send(503, ['error' => 'paused']);
}

$store = ghaim_load_store();
$req = ghaim_read_request($store);

$storage = ghaim_storage_dir($cfg);
if ($storage === null) {
    ghaim_send(503, ['error' => 'paused']);
}
$limit = ghaim_rate_limit($cfg, $storage);
if ($limit !== null) {
    ghaim_send(429, ['error' => 'rate_limited'] + $limit, ['Retry-After' => (string) $limit['retry_after']]);
}

@set_time_limit(GHAIM_TIME_BUDGET + 30);
$provider = $cfg['provider'];
$model = ghaim_model($cfg, $req['speed']);
$usage = ghaim_new_usage();
try {
    $reply = $provider === 'gemini'
        ? ghaim_gemini_chat($cfg, $model, $req, $store, $usage)
        : ghaim_chat($cfg, $model, $req, $store, $usage);
} catch (GhaimUpstreamError $e) {
    error_log('ghaim: ' . ($provider === 'gemini' ? 'Gemini' : 'Claude') . ' API ' . $e->getMessage());
    ghaim_log_usage($storage, $provider, $model, $usage, $e->kind);
    if ($e->kind === 'busy') {
        ghaim_send(503, ['error' => 'busy'], ['Retry-After' => (string) ($e->retryAfter ?? 10)]);
    }
    if ($e->kind === 'paused') {
        ghaim_send(503, ['error' => 'paused']);
    }
    ghaim_send(502, ['error' => 'error']);
}

ghaim_log_usage($storage, $provider, $model, $usage, isset($reply['refused']) ? 'refused' : (isset($reply['truncated']) ? 'truncated' : 'ok'));
ghaim_send(200, $reply);
