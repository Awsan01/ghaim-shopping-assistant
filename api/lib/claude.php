<?php
// Calls the Claude Messages API with cURL and runs the tool loop for one customer message.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

const GHAIM_API_VERSION = '2026-10-21';
// Low effort keeps replies fast: the models think little, or not at all, on simple requests.
// (Medium made the fast model noticeably slower.) Thinking is on by default on these models
// and counts toward max_tokens, which is why the config leaves it room (4096 by default).
const GHAIM_EFFORT = 'low';
// Seconds one customer message may spend on model calls, retries included.
const GHAIM_TIME_BUDGET = 90;

/**
 * An AI provider failure, sorted into what the visitor sees: busy, paused or error.
 * $retryAfter: seconds the provider asked us to wait (sent on as Retry-After), when known.
 */
final class GhaimUpstreamError extends RuntimeException
{
    public function __construct(public readonly string $kind, string $logMessage, public readonly ?int $retryAfter = null)
    {
        parent::__construct($logMessage);
    }
}

/**
 * Runs one assistant turn: up to GHAIM_MAX_CALLS model calls, executing tools in between.
 * Returns ['text' => ..., 'actions' => [...], 'steps' => [...]] plus truncated/refused flags.
 * $usage collects token counts even when an exception is thrown.
 */
function ghaim_chat(array $cfg, string $model, array $req, array $store, array &$usage): array
{
    $now = ghaim_now();
    $system = [
        ['type' => 'text', 'text' => ghaim_static_prompt(), 'cache_control' => ['type' => 'ephemeral']],
        ['type' => 'text', 'text' => ghaim_request_prompt($req, $store, $now)],
    ];
    $tools = ghaim_tools($store);
    $messages = $req['messages'];
    $state = ['cart' => $req['cart'], 'handoff' => null, 'actions' => [], 'steps' => []];
    $ctx = ['today' => $now->setTime(0, 0), 'next_ticket_id' => $req['next_ticket_id'], 'wa_button' => $req['wa_button'],
        'shown' => ghaim_shown_products($store, $req['messages'])];
    $deadline = microtime(true) + GHAIM_TIME_BUDGET;
    $texts = [];
    $stop = '';

    for ($call = 1; $call <= GHAIM_MAX_CALLS; $call++) {
        $payload = [
            'model' => $model,
            'max_tokens' => $cfg['max_tokens'],
            'system' => $system,
            'tools' => $tools,
            'messages' => $messages,
            'output_config' => ['effort' => GHAIM_EFFORT],
            // Automatic caching of the conversation so far; the tools and the first system block
            // have their own breakpoint above.
            'cache_control' => ['type' => 'ephemeral'],
        ];
        if ($call === GHAIM_MAX_CALLS) {
            // Last allowed call: the model must answer with the results it already has.
            $payload['tool_choice'] = ['type' => 'none'];
        }

        $res = ghaim_claude_request($cfg, $payload, $deadline);
        ghaim_add_usage($usage, $res->usage ?? null);
        $stop = is_string($res->stop_reason ?? null) ? $res->stop_reason : '';

        $toolUses = [];
        foreach ($res->content as $block) {
            $type = is_object($block) ? ($block->type ?? '') : '';
            if ($type === 'text' && is_string($block->text ?? null) && trim($block->text) !== '') {
                $texts[] = trim($block->text);
            } elseif ($type === 'tool_use') {
                $toolUses[] = $block;
            }
        }
        if ($stop !== 'tool_use' || !$toolUses) {
            break;
        }

        // The assistant turn goes back unchanged (thinking blocks included), then all results
        // in one user message.
        $messages[] = ['role' => 'assistant', 'content' => $res->content];
        $results = [];
        foreach ($toolUses as $use) {
            $input = json_decode(json_encode($use->input ?? new stdClass()), true);
            $out = ghaim_run_tool(is_string($use->name ?? null) ? $use->name : '', is_array($input) ? $input : [], $state, $store, $ctx);
            $result = ['type' => 'tool_result', 'tool_use_id' => (string) ($use->id ?? ''), 'content' => $out['content']];
            if ($out['is_error']) {
                $result['is_error'] = true;
            }
            $results[] = $result;
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }

    if ($stop === 'refusal') {
        return ['text' => '', 'actions' => [], 'steps' => [], 'refused' => true];
    }
    $reply = ['text' => implode("\n\n", $texts), 'actions' => $state['actions'], 'steps' => $state['steps']];
    if ($stop === 'max_tokens') {
        $reply['truncated'] = true;
    }
    return $reply;
}

function ghaim_new_usage(): array
{
    return ['calls' => 0, 'input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0];
}

function ghaim_add_usage(array &$usage, mixed $u): void
{
    $usage['calls']++;
    if (!is_object($u)) {
        return;
    }
    $usage['input'] += (int) ($u->input_tokens ?? 0);
    $usage['output'] += (int) ($u->output_tokens ?? 0);
    $usage['cache_write'] += (int) ($u->cache_creation_input_tokens ?? 0);
    $usage['cache_read'] += (int) ($u->cache_read_input_tokens ?? 0);
}

/**
 * One Messages API call. Retries once on 529 (overloaded), other 5xx errors and dropped
 * connections. The response is decoded into objects so content blocks can be sent back as is.
 */
function ghaim_claude_request(array $cfg, array $payload, float $deadline): object
{
    $url = $cfg['anthropic_base_url'] . '/v1/messages';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $headers = [
        'content-type: application/json',
        'accept: application/json',
        'anthropic-version: ' . GHAIM_API_VERSION,
        'x-api-key: ' . $cfg['anthropic_api_key'],
    ];
    if ($cfg['workspace_id'] !== '') {
        $headers[] = 'anthropic-workspace-id: ' . $cfg['workspace_id'];
    }

    for ($attempt = 1; ; $attempt++) {
        $left = (int) floor($deadline - microtime(true));
        if ($left < 5) {
            throw new GhaimUpstreamError('busy', 'time budget used up');
        }
        [$status, $raw, $errno, $error] = ghaim_http_post($url, $headers, $body, min(60, $left));

        if ($errno !== 0) {
            // Retry a dropped connection once, but not a timeout (it already used its time).
            if ($attempt === 1 && $errno !== 28) {
                usleep(700000);
                continue;
            }
            throw new GhaimUpstreamError('busy', "network error $errno: $error");
        }
        if ($status === 200) {
            $res = json_decode($raw);
            if (!is_object($res) || !isset($res->content) || !is_array($res->content)) {
                throw new GhaimUpstreamError('error', 'unexpected response body');
            }
            return $res;
        }
        if ($attempt === 1 && $status >= 500) {
            usleep(1000000);
            continue;
        }
        throw ghaim_upstream_error($status, $raw);
    }
}

/** Maps an API error to busy / paused / error. The raw message goes to the server log only. */
function ghaim_upstream_error(int $status, string $raw): GhaimUpstreamError
{
    $data = json_decode($raw, true);
    $err = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
    $type = is_string($err['type'] ?? null) ? $err['type'] : '';
    $message = is_string($err['message'] ?? null) ? $err['message'] : '';
    $code = is_string($err['details']['error_code'] ?? null) ? $err['details']['error_code'] : '';
    $log = "HTTP $status $type " . substr($message, 0, 300);
    $lower = strtolower($message);

    $outOfCredit = $status === 402 || $type === 'billing_error' || $code === 'enforced_spend_limit_reached'
        || str_contains($lower, 'credit balance') || str_contains($lower, 'spend limit') || str_contains($lower, 'usage limit');
    if ($outOfCredit || in_array($status, [401, 403, 404], true)) {
        // No credit, spend limit reached, bad key or unknown model: the owner has to act.
        return new GhaimUpstreamError('paused', $log);
    }
    if ($status === 429 || $status === 408 || $status >= 500) {
        return new GhaimUpstreamError('busy', $log);
    }
    return new GhaimUpstreamError('error', $log);
}

/** @return array{0:int,1:string,2:int,3:string} status, body, cURL error number, cURL error */
function ghaim_http_post(string $url, array $headers, string $body, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'ghaim-demo/1.0',
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    return [$status, is_string($raw) ? $raw : '', $errno, $error];
}
