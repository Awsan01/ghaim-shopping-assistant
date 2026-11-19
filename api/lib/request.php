<?php
// Reads and validates the chat request from the page.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

/** POSTs must come from a page on this same host (Origin header). */
function ghaim_same_origin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($origin) || !is_string($host) || $origin === '' || $host === '') {
        return false;
    }
    $o = parse_url($origin);
    if (!is_array($o) || !isset($o['scheme'], $o['host']) || isset($o['user']) || isset($o['query'])
        || (isset($o['path']) && $o['path'] !== '' && $o['path'] !== '/')) {
        return false;
    }
    $scheme = strtolower($o['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $originHost = strtolower($o['host']) . (isset($o['port']) ? ':' . $o['port'] : '');
    $strip = static fn(string $h): string => (string) preg_replace('/:(80|443)$/', '', $h);
    return hash_equals($strip(strtolower($host)), $strip($originHost));
}

/** Stops with 400, 413 or 415 unless the body is a valid chat request. */
function ghaim_read_request(array $store): array
{
    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
        ghaim_send(415, ['error' => 'bad_request', 'detail' => 'send JSON']);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > GHAIM_MAX_BODY) {
        ghaim_send(413, ['error' => 'too_large']);
    }
    $raw = file_get_contents('php://input', false, null, 0, GHAIM_MAX_BODY + 1);
    if (!is_string($raw) || $raw === '') {
        ghaim_bad('empty body');
    }
    if (strlen($raw) > GHAIM_MAX_BODY) {
        ghaim_send(413, ['error' => 'too_large']);
    }
    try {
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        ghaim_bad('invalid JSON');
    }
    if (!is_array($data) || array_is_list($data)) {
        ghaim_bad('the body must be a JSON object');
    }
    return ghaim_validate_request($data, $store);
}

function ghaim_bad(string $detail): never
{
    ghaim_send(400, ['error' => 'bad_request', 'detail' => $detail]);
}

function ghaim_validate_request(array $d, array $store): array
{
    $known = ['messages', 'channel', 'speed', 'cart', 'open_tickets', 'next_ticket_id', 'wa_button'];
    foreach (array_keys($d) as $key) {
        if (!in_array($key, $known, true)) {
            ghaim_bad('unknown field');
        }
    }

    // messages: the last 16 turns, plain text, ending with the customer's message
    $messages = $d['messages'] ?? null;
    if (!is_array($messages) || !array_is_list($messages) || $messages === [] || count($messages) > GHAIM_MAX_MESSAGES) {
        ghaim_bad('messages: 1 to ' . GHAIM_MAX_MESSAGES . ' turns');
    }
    $turns = [];
    foreach ($messages as $i => $m) {
        if (!is_array($m) || count($m) !== 2 || !isset($m['role'], $m['content'])
            || !in_array($m['role'], ['user', 'assistant'], true) || !is_string($m['content'])) {
            ghaim_bad("messages[$i]: needs only role (user or assistant) and content (text)");
        }
        $max = $m['role'] === 'user' ? GHAIM_MAX_USER_CHARS : GHAIM_MAX_ASSISTANT_CHARS;
        if (!preg_match('/\S/u', $m['content']) || ghaim_len($m['content']) > $max) {
            ghaim_bad("messages[$i]: content must be 1 to $max characters");
        }
        $turns[] = ['role' => $m['role'], 'content' => $m['content']];
    }
    if ($turns[count($turns) - 1]['role'] !== 'user') {
        ghaim_bad('the last message must be from the customer');
    }

    $channel = $d['channel'] ?? 'web';
    if (!in_array($channel, ['web', 'wa'], true)) {
        ghaim_bad('channel: web or wa');
    }
    $speed = $d['speed'] ?? 'fast';
    if (!in_array($speed, ['fast', 'smart'], true)) {
        ghaim_bad('speed: fast or smart');
    }

    // cart: [{id, qty}] with known product ids
    $cartIn = $d['cart'] ?? [];
    if (!is_array($cartIn) || !array_is_list($cartIn) || count($cartIn) > 50) {
        ghaim_bad('cart: a list of up to 50 items');
    }
    $cart = [];
    foreach ($cartIn as $i => $item) {
        $id = is_array($item) ? ($item['id'] ?? null) : null;
        $qty = is_array($item) ? ($item['qty'] ?? null) : null;
        if (!is_array($item) || count($item) !== 2 || !is_string($id) || !isset($store['by_id'][$id])
            || !is_int($qty) || $qty < 1 || $qty > 100 || isset($cart[$id])) {
            ghaim_bad("cart[$i]: needs a known product id and a quantity from 1 to 100");
        }
        $cart[$id] = $qty;
    }

    // tickets: ids like T-1234
    $ticketPattern = '/^T-\d{4,6}$/';
    $open = $d['open_tickets'] ?? [];
    if (!is_array($open) || !array_is_list($open) || count($open) > 100) {
        ghaim_bad('open_tickets: a list of up to 100 ticket ids');
    }
    foreach ($open as $ticket) {
        if (!is_string($ticket) || !preg_match($ticketPattern, $ticket)) {
            ghaim_bad('open_tickets: ticket ids look like T-1234');
        }
    }
    $next = $d['next_ticket_id'] ?? 'T-1001';
    if (!is_string($next) || !preg_match($ticketPattern, $next) || in_array($next, $open, true)) {
        ghaim_bad('next_ticket_id: a new ticket id like T-1234');
    }
    $wa = $d['wa_button'] ?? false;
    if (!is_bool($wa)) {
        ghaim_bad('wa_button: true or false');
    }

    return [
        'messages' => ghaim_api_messages($turns),
        'channel' => $channel,
        'speed' => $speed,
        'cart' => $cart,
        'open_tickets' => array_values(array_unique($open)),
        'next_ticket_id' => $next,
        'wa_button' => $wa,
    ];
}

/** Messages API shape: starts with the customer, same-role neighbours joined into one turn. */
function ghaim_api_messages(array $turns): array
{
    while ($turns && $turns[0]['role'] !== 'user') {
        array_shift($turns);
    }
    $out = [];
    foreach ($turns as $t) {
        $last = count($out) - 1;
        if ($last >= 0 && $out[$last]['role'] === $t['role']) {
            $out[$last]['content'] .= "\n\n" . $t['content'];
        } else {
            $out[] = $t;
        }
    }
    return $out;
}
