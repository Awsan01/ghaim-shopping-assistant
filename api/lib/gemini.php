<?php
// Calls the Google Gemini API (REST generateContent) with cURL and runs the tool loop for one
// customer message. Same prompt, tools, limits and reply shape as the Claude path in claude.php.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

// Finish reasons that mean Gemini blocked the reply. They come back to the page as refused.
const GHAIM_GEMINI_BLOCKED = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII',
    'IMAGE_SAFETY', 'IMAGE_PROHIBITED_CONTENT', 'IMAGE_RECITATION'];

/**
 * Runs one assistant turn on Gemini: up to GHAIM_MAX_CALLS model calls, executing tools in
 * between. Returns ['text' => ..., 'actions' => [...], 'steps' => [...]] plus truncated/refused
 * flags, like ghaim_chat(). $usage collects token counts even when an exception is thrown.
 */
function ghaim_gemini_chat(array $cfg, string $model, array $req, array $store, array &$usage): array
{
    $now = ghaim_now();
    // The same two prompt parts as on Claude: the fixed instructions first (so Google's implicit
    // caching can reuse them), then the per-request part.
    $system = ['parts' => [
        ['text' => ghaim_static_prompt()],
        ['text' => ghaim_request_prompt($req, $store, $now)],
    ]];
    $tools = [['functionDeclarations' => ghaim_gemini_functions($store)]];
    $contents = [];
    foreach ($req['messages'] as $m) {
        $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
    }
    $state = ['cart' => $req['cart'], 'handoff' => null, 'actions' => [], 'steps' => []];
    $ctx = ['today' => $now->setTime(0, 0), 'next_ticket_id' => $req['next_ticket_id'], 'wa_button' => $req['wa_button'],
        'shown' => ghaim_shown_products($store, $req['messages'])];
    $deadline = microtime(true) + GHAIM_TIME_BUDGET;
    $thinking = ghaim_gemini_thinking($model);
    $texts = [];
    $finish = '';

    for ($call = 1; $call <= GHAIM_MAX_CALLS; $call++) {
        $payload = [
            'systemInstruction' => $system,
            'contents' => $contents,
            'tools' => $tools,
            // Last allowed call: the model must answer with the results it already has.
            'toolConfig' => ['functionCallingConfig' => ['mode' => $call === GHAIM_MAX_CALLS ? 'NONE' : 'AUTO']],
            'generationConfig' => ['maxOutputTokens' => $cfg['max_tokens']],
        ];
        if ($thinking !== null) {
            $payload['generationConfig']['thinkingConfig'] = $thinking;
        }

        try {
            $res = ghaim_gemini_request($cfg, $model, $payload, $deadline, $usage);
        } catch (GhaimGeminiThinkingRejected $e) {
            // The model did not accept the thinking setting: once, try again without it, and
            // leave it out for the rest of this message.
            error_log('ghaim: Gemini rejected the thinking setting for ' . $model . ', retrying without it: ' . $e->getMessage());
            $thinking = null;
            unset($payload['generationConfig']['thinkingConfig']);
            $res = ghaim_gemini_request($cfg, $model, $payload, $deadline, $usage, false);
        }

        $block = is_string($res->promptFeedback->blockReason ?? null) ? $res->promptFeedback->blockReason : '';
        $candidate = is_array($res->candidates ?? null) ? ($res->candidates[0] ?? null) : null;
        if ($block !== '' || !is_object($candidate)) {
            // The prompt itself was blocked: no candidate comes back.
            error_log('ghaim: Gemini blocked the prompt' . ($block !== '' ? ' (' . $block . ')' : ''));
            return ['text' => '', 'actions' => [], 'steps' => [], 'refused' => true];
        }
        $finish = is_string($candidate->finishReason ?? null) ? $candidate->finishReason : '';
        if (in_array($finish, GHAIM_GEMINI_BLOCKED, true)) {
            error_log('ghaim: Gemini blocked the reply (' . $finish . ')');
            return ['text' => '', 'actions' => [], 'steps' => [], 'refused' => true];
        }

        $content = is_object($candidate->content ?? null) ? $candidate->content : null;
        $parts = $content !== null && is_array($content->parts ?? null) ? $content->parts : [];
        $calls = [];
        foreach ($parts as $part) {
            if (!is_object($part) || !empty($part->thought)) {
                continue;   // thought summaries are never shown
            }
            if (is_object($part->functionCall ?? null)) {
                $calls[] = $part->functionCall;
            } elseif (is_string($part->text ?? null) && trim($part->text) !== '') {
                $texts[] = trim($part->text);
            }
        }
        if (!$calls || $call === GHAIM_MAX_CALLS) {
            if ($finish !== 'STOP' && $finish !== 'MAX_TOKENS' && $finish !== '') {
                error_log('ghaim: Gemini finished with ' . $finish);
            }
            break;
        }

        // The model's turn goes back unchanged (thought signatures included), then all function
        // responses, in the same order, in one user turn.
        if (!isset($content->role)) {
            $content->role = 'model';
        }
        $contents[] = $content;
        $responses = [];
        foreach ($calls as $fc) {
            $name = is_string($fc->name ?? null) ? $fc->name : '';
            $input = json_decode(json_encode($fc->args ?? new stdClass()), true);
            $out = ghaim_run_tool($name, is_array($input) ? $input : [], $state, $store, $ctx);
            $response = ['name' => $name, 'response' => ghaim_gemini_tool_response($out)];
            if (is_string($fc->id ?? null) && $fc->id !== '') {
                $response = ['id' => $fc->id] + $response;
            }
            $responses[] = ['functionResponse' => $response];
        }
        $contents[] = ['role' => 'user', 'parts' => $responses];
    }

    $reply = ['text' => implode("\n\n", $texts), 'actions' => $state['actions'], 'steps' => $state['steps']];
    if ($finish === 'MAX_TOKENS') {
        $reply['truncated'] = true;
    }
    return $reply;
}

/** The four store tools as Gemini function declarations (same names, descriptions and schemas). */
function ghaim_gemini_functions(array $store): array
{
    return array_map(static fn(array $t): array => [
        'name' => $t['name'],
        'description' => $t['description'],
        'parameters' => $t['input_schema'],
    ], ghaim_tools($store));
}

/** A tool result as the object Gemini expects in functionResponse.response. */
function ghaim_gemini_tool_response(array $out): array
{
    if ($out['is_error']) {
        return ['error' => $out['content']];
    }
    $data = json_decode($out['content'], true);
    return is_array($data) && !array_is_list($data) ? $data : ['result' => $out['content']];
}

/**
 * The lowest thinking setting each model allows, chosen by model name:
 * - Gemini 2.5: thinkingBudget, 0 (off) on Flash and Flash-Lite, 128 (the least) on Pro;
 * - Gemini 3 and later: thinkingLevel, "minimal" where Google lists it (Flash-Lite, 3 Flash,
 *   3.6 Flash), otherwise "low" (for example 3.8 Flash and the Pro models);
 * - older models have no thinking: nothing is sent.
 * If a model rejects the setting anyway, the call is made once more without it.
 */
function ghaim_gemini_thinking(string $model): ?array
{
    $m = strtolower($model);
    if (!preg_match('/^gemini-(\d+)(?:\.(\d+))?/', $m, $v)) {
        return null;
    }
    $major = (int) $v[1];
    $minor = isset($v[2]) ? (int) $v[2] : 0;
    if ($major === 2 && $minor === 5) {
        return ['thinkingBudget' => str_contains($m, '-pro') ? 128 : 0];
    }
    if ($major < 3) {
        return null;
    }
    $minimal = $major === 3 && (str_contains($m, 'flash-lite')
        || preg_match('/^gemini-3(?:\.6)?-flash(?:-preview)?(?:-\d{2}-\d{4})?$/', $m));
    return ['thinkingLevel' => $minimal ? 'MINIMAL' : 'LOW'];
}

/** The thinking setting was refused (400). Caught by the tool loop, which retries once without it. */
final class GhaimGeminiThinkingRejected extends RuntimeException
{
}

/**
 * One generateContent call. Retries once on 5xx errors and dropped connections; 429 is not
 * retried here (the visitor gets "busy" with Google's retry time). The key goes in the
 * x-goog-api-key header, never in the URL.
 */
function ghaim_gemini_request(array $cfg, string $model, array $payload, float $deadline, array &$usage, bool $thinkingMayFail = true): object
{
    $url = $cfg['gemini_base_url'] . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $headers = [
        'content-type: application/json',
        'accept: application/json',
        'x-goog-api-key: ' . $cfg['gemini_api_key'],
    ];

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
            if (!is_object($res)) {
                throw new GhaimUpstreamError('error', 'unexpected response body');
            }
            ghaim_gemini_add_usage($usage, $res->usageMetadata ?? null);
            return $res;
        }
        if ($attempt === 1 && $status >= 500) {
            usleep(1000000);
            continue;
        }
        if ($thinkingMayFail && $status === 400 && isset($payload['generationConfig']['thinkingConfig'])
            && preg_match('/thinking[ _]?(level|budget|config)|thinkingConfig|thinking is not supported/i', $raw)) {
            throw new GhaimGeminiThinkingRejected(ghaim_gemini_log_text($status, $raw));
        }
        throw ghaim_gemini_upstream_error($status, $raw);
    }
}

/**
 * Token counts in the same columns as Claude's: input = prompt tokens not read from Google's
 * cache, output = reply plus thinking tokens, cache_read = cached prompt tokens.
 */
function ghaim_gemini_add_usage(array &$usage, mixed $u): void
{
    $usage['calls']++;
    if (!is_object($u)) {
        return;
    }
    $prompt = (int) ($u->promptTokenCount ?? 0) + (int) ($u->toolUsePromptTokenCount ?? 0);
    $cached = (int) ($u->cachedContentTokenCount ?? 0);
    $usage['input'] += max(0, $prompt - $cached);
    $usage['output'] += (int) ($u->candidatesTokenCount ?? 0) + (int) ($u->thoughtsTokenCount ?? 0);
    $usage['cache_read'] += $cached;
}

/** A short line for the server log. The key is never in an error body, but strip it anyway. */
function ghaim_gemini_log_text(int $status, string $raw): string
{
    $data = json_decode($raw, true);
    $err = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
    $code = is_scalar($err['status'] ?? null) ? (string) $err['status'] : (is_scalar($err['code'] ?? null) ? (string) $err['code'] : '');
    $message = is_string($err['message'] ?? null) ? $err['message'] : '';
    return "HTTP $status $code " . substr(preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[key]', $message) ?? '', 0, 300);
}

/**
 * Maps a Gemini API error to busy / paused / error, like ghaim_upstream_error() for Claude.
 * 429 or RESOURCE_EXHAUSTED: busy, with Google's retry time when it sends one. An invalid,
 * leaked or unauthorised key, an unknown model or a failed precondition: paused (the owner
 * has to act). Anything else is a generic error. The raw message goes to the server log only.
 */
function ghaim_gemini_upstream_error(int $status, string $raw): GhaimUpstreamError
{
    $data = json_decode($raw, true);
    $err = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
    $grpc = strtoupper(is_string($err['status'] ?? null) ? $err['status'] : (is_string($err['code'] ?? null) ? $err['code'] : ''));
    $message = strtolower(is_string($err['message'] ?? null) ? $err['message'] : '');
    $reasons = [];
    $retry = null;
    foreach (is_array($err['details'] ?? null) ? $err['details'] : [] as $d) {
        if (!is_array($d)) {
            continue;
        }
        if (is_string($d['reason'] ?? null)) {
            $reasons[] = strtoupper($d['reason']);
        }
        if (is_string($d['retryDelay'] ?? null) && preg_match('/^(\d+(?:\.\d+)?)s$/', $d['retryDelay'], $m)) {
            $retry = max(1, min(3600, (int) ceil((float) $m[1])));
        }
    }
    $log = ghaim_gemini_log_text($status, $raw);

    $badKey = in_array('API_KEY_INVALID', $reasons, true) || in_array('API_KEY_EXPIRED', $reasons, true)
        || str_contains($message, 'api key not valid') || str_contains($message, 'api key expired')
        || in_array($grpc, ['UNAUTHENTICATED', 'PERMISSION_DENIED', 'AUTHENTICATION', 'PAYMENT_REQUIRED', 'NOT_FOUND', 'MODEL_NOT_FOUND'], true);
    // FAILED_PRECONDITION: for example the free tier is not offered where the server runs,
    // so billing has to be set up. Also something only the owner can fix.
    if ($badKey || $grpc === 'FAILED_PRECONDITION' || in_array($status, [401, 402, 403, 404], true)) {
        return new GhaimUpstreamError('paused', $log);
    }
    if ($status === 429 || in_array($grpc, ['RESOURCE_EXHAUSTED', 'RATE_LIMIT_EXCEEDED', 'QUOTA_EXCEEDED', 'TOO_MANY_REQUESTS'], true)) {
        return new GhaimUpstreamError('busy', $log, $retry);
    }
    if ($status === 408 || $status === 499 || $status >= 500) {
        return new GhaimUpstreamError('busy', $log);
    }
    return new GhaimUpstreamError('error', $log);
}
