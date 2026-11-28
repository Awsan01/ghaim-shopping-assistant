<?php
// A stand-in for the Gemini API (REST generateContent), for local tests only. No real key,
// no network.
//
//   MOCK_LOG=/tmp/mock.jsonl php -S 127.0.0.1:8090 tests/mock-gemini.php
//
// It checks each request the way the real API would (key header, never a key in the URL,
// systemInstruction, function declarations, alternating user/model turns, the model's turn
// sent back with its thought signatures, one function response per function call), then
// answers from a script: the customer's latest message picks a scenario, the first call gets
// canned function calls and the next call gets the final text, written from the results.
// The scenarios and texts are the same as in tests/mock-anthropic.php, so tests/smoke.sh can
// check both providers the same way. Messages containing MOCK_... trigger error cases.
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$logFile = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/ghaim-mock-gemini.jsonl';
$stateFile = $logFile . '.state';

function reply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Google's error format: {"error": {"code", "message", "status", "details"}} */
function api_error(int $status, string $grpc, string $message, array $details = []): never
{
    $error = ['code' => $status, 'message' => $message, 'status' => $grpc];
    if ($details) {
        $error['details'] = $details;
    }
    reply($status, ['error' => $error]);
}

function invalid(string $message): never
{
    api_error(400, 'INVALID_ARGUMENT', $message);
}

/** The signature the mock attaches to a function call; it must come back unchanged. */
function signature(string $name): string
{
    return base64_encode('mock-signature:' . $name);
}

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$key = $_SERVER['HTTP_X_GOOG_API_KEY'] ?? '';
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$objects = json_decode($raw);   // same body as objects, to see {} versus []
$model = preg_match('~^/v1beta/models/([A-Za-z0-9._-]+):generateContent$~', $path, $pm) ? $pm[1] : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $model === '') {
    api_error(404, 'NOT_FOUND', 'Not found');
}

file_put_contents($logFile, json_encode([
    'time' => date('c'),
    'key_sent' => $key !== '',
    'key_in_url' => $query !== '' && (bool) preg_match('/(^|&)key=/', $query),
    'path' => $path,
    'model' => $model,
    'body' => $body,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// ---- the checks the real API would make ----------------------------------------------
if ($query !== '') {
    invalid('mock: the API key must go in the x-goog-api-key header, not in the URL');
}
if ($key === '') {
    api_error(403, 'PERMISSION_DENIED', "Method doesn't allow unregistered callers. Please use API Key or other form of API consumer identity to call this API.");
}
if (!is_array($body) || !is_object($objects)) {
    invalid('Invalid JSON payload received.');
}
$parts = $body['systemInstruction']['parts'] ?? null;
if (!is_array($parts) || count($parts) !== 2 || !is_string($parts[0]['text'] ?? null) || !is_string($parts[1]['text'] ?? null)) {
    invalid('mock expects systemInstruction with two text parts');
}
$decls = $body['tools'][0]['functionDeclarations'] ?? null;
$names = is_array($decls) ? array_map(static fn($d) => $d['name'] ?? '', $decls) : [];
if (count($body['tools'] ?? []) !== 1 || $names !== ['search_products', 'get_order_status', 'add_to_cart', 'handoff_to_staff']) {
    invalid('mock expects the four store tools as functionDeclarations');
}
foreach ($objects->tools[0]->functionDeclarations as $d) {
    if (!is_string($d->description ?? null) || ($d->parameters->type ?? '') !== 'object' || !is_object($d->parameters->properties ?? null)) {
        invalid('functionDeclarations: parameters must be an object schema');
    }
}
$mode = $body['toolConfig']['functionCallingConfig']['mode'] ?? '';
if (!in_array($mode, ['AUTO', 'NONE'], true)) {
    invalid('mock expects toolConfig.functionCallingConfig.mode AUTO or NONE');
}
if (!is_int($body['generationConfig']['maxOutputTokens'] ?? null)) {
    invalid('generationConfig.maxOutputTokens must be an integer');
}
$thinking = $body['generationConfig']['thinkingConfig'] ?? null;
$isGemini3 = (bool) preg_match('/^gemini-[3-9]/', $model);
if ($thinking !== null) {
    if (isset($thinking['thinkingLevel']) && !$isGemini3) {
        invalid('Thinking level is not supported for this model.');
    }
    if (isset($thinking['thinkingBudget']) && $isGemini3) {
        invalid('mock: use thinkingLevel on Gemini 3 models');
    }
    if (($thinking['thinkingLevel'] ?? '') === 'MINIMAL' && str_starts_with($model, 'gemini-3.8-flash')) {
        invalid('thinking_level MINIMAL is not supported by this model.');
    }
}
$contents = $body['contents'] ?? null;
if (!is_array($contents) || $contents === [] || ($contents[0]['role'] ?? '') !== 'user'
    || ($contents[count($contents) - 1]['role'] ?? '') !== 'user') {
    invalid('contents must start and end with a user turn');
}
foreach ($contents as $i => $c) {
    if (!in_array($c['role'] ?? '', ['user', 'model'], true) || !is_array($c['parts'] ?? null) || $c['parts'] === []) {
        invalid("contents[$i]: role must be user or model, with parts");
    }
    if ($i > 0 && $c['role'] === $contents[$i - 1]['role']) {
        invalid("contents[$i]: turns must alternate between user and model");
    }
    $calls = array_values(array_filter($c['parts'], static fn($p) => isset($p['functionCall'])));
    if ($c['role'] === 'model' && $calls) {
        // Gemini 3: the first function call of a turn carries the signature; drop it and the
        // API answers 400.
        if (($calls[0]['thoughtSignature'] ?? '') !== signature($calls[0]['functionCall']['name'] ?? '')) {
            invalid("contents[$i]: Function call is missing a thought_signature in functionCall parts.");
        }
        foreach ($c['parts'] as $j => $p) {
            if (isset($p['functionCall']) && !is_object($objects->contents[$i]->parts[$j]->functionCall->args ?? null)) {
                invalid("contents[$i].parts[$j]: functionCall.args must be an object");
            }
        }
        $next = $contents[$i + 1]['parts'] ?? [];
        $responses = array_values(array_filter($next, static fn($p) => isset($p['functionResponse'])));
        if (count($responses) !== count($next) || count($responses) !== count($calls)) {
            invalid('Please ensure that the number of function response parts is equal to the number of function call parts of the function call turn.');
        }
        foreach ($calls as $k => $call) {
            $fr = $responses[$k]['functionResponse'];
            if (($fr['name'] ?? '') !== $call['functionCall']['name'] || ($fr['id'] ?? null) !== ($call['functionCall']['id'] ?? null)
                || !is_object($objects->contents[$i + 1]->parts[$k]->functionResponse->response ?? null)) {
                invalid("contents[" . ($i + 1) . "]: function responses must match the calls in order (name, id) and response must be an object");
            }
        }
    }
}

// ---- which scenario, and how far into it ---------------------------------------------
$lastText = '';
$rounds = 0;   // model turns after the customer's latest message
for ($i = count($contents) - 1; $i >= 0; $i--) {
    $c = $contents[$i];
    if ($c['role'] === 'model') {
        $rounds++;
        continue;
    }
    if (is_string($c['parts'][0]['text'] ?? null)) {
        $lastText = $c['parts'][0]['text'];
        break;
    }
}
$split = preg_split("/\n\n/", $lastText);
$customer = (string) end($split);
$ar = (bool) preg_match('/\p{Arabic}/u', $customer);
$noTools = $mode === 'NONE';
$toolResults = $rounds > 0 ? array_map(static fn($p) => $p['functionResponse']['response'] ?? [], $contents[count($contents) - 1]['parts']) : [];
$result = static function (int $n = 0) use ($toolResults): array {
    $r = $toolResults[$n] ?? [];
    $error = isset($r['error']);
    return ['data' => is_array($r) ? $r : [], 'text' => $error ? (string) $r['error'] : json_encode($r, JSON_UNESCAPED_UNICODE), 'error' => $error];
};

function once(string $stateFile, string $name): bool
{
    $state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
    $first = empty($state[$name]);
    $state[$name] = true;
    file_put_contents($stateFile, json_encode($state));
    return $first;
}

function respond(array $parts, string $finish, string $model, int $rounds): never
{
    $candidate = ['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish, 'index' => 0];
    if ($parts === []) {
        unset($candidate['content']['parts']);
    }
    reply(200, [
        'candidates' => [$candidate],
        'usageMetadata' => [
            'promptTokenCount' => 1770 + 40 * $rounds,
            'candidatesTokenCount' => 60,
            'thoughtsTokenCount' => 20,
            'cachedContentTokenCount' => $rounds === 0 ? 0 : 1650,
            'totalTokenCount' => 1850 + 40 * $rounds,
        ],
        'modelVersion' => $model,
        'responseId' => 'mock_' . bin2hex(random_bytes(6)),
    ]);
}

function text(string $t, string $model, int $rounds, string $finish = 'STOP'): never
{
    respond([['text' => $t]], $finish, $model, $rounds);
}

function tool(string $name, array $input, string $model, int $rounds): never
{
    respond([['functionCall' => ['name' => $name, 'args' => (object) $input], 'thoughtSignature' => signature($name)]], 'STOP', $model, $rounds);
}

// ---- error cases ----------------------------------------------------------------------
if (str_contains($customer, 'MOCK_529')) {
    api_error(503, 'UNAVAILABLE', 'The model is overloaded. Please try again later.');
}
if (str_contains($customer, 'MOCK_500_ONCE') && once($stateFile, 'fail-once-' . md5($customer))) {
    api_error(500, 'INTERNAL', 'An internal error has occurred.');
}
if (str_contains($customer, 'MOCK_RATE_NODELAY')) {
    api_error(429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).');
}
if (str_contains($customer, 'MOCK_RATE')) {
    api_error(429, 'RESOURCE_EXHAUSTED', 'You exceeded your current quota, please check your plan and billing details.', [
        ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests', 'quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier']]],
        ['@type' => 'type.googleapis.com/google.rpc.Help', 'links' => [['description' => 'Learn more about Gemini API quotas', 'url' => 'https://ai.google.dev/gemini-api/docs/rate-limits']]],
        ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '16.5s'],
    ]);
}
if (str_contains($customer, 'MOCK_AUTH')) {
    api_error(400, 'INVALID_ARGUMENT', 'API key not valid. Please pass a valid API key.', [
        ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com', 'metadata' => ['service' => 'generativelanguage.googleapis.com']],
    ]);
}
if (str_contains($customer, 'MOCK_DENIED')) {
    api_error(403, 'PERMISSION_DENIED', 'Your API key was reported as leaked. Please use another API key.');
}
if (str_contains($customer, 'MOCK_NOMODEL')) {
    api_error(404, 'NOT_FOUND', 'models/' . $model . ' is not found for API version v1beta, or is not supported for generateContent.');
}
if (str_contains($customer, 'MOCK_BADREQ')) {
    invalid('Invalid value at \'contents[0]\': something the API does not accept');
}
if (str_contains($customer, 'MOCK_THINKREJECT') && $thinking !== null) {
    invalid('thinking_level is not supported by this model.');
}
if (str_contains($customer, 'MOCK_SLOW')) {
    sleep(3);   // gives the page time to show the stop button
}
if (str_contains($customer, 'MOCK_REFUSE')) {
    respond([], 'SAFETY', $model, $rounds);
}
if (str_contains($customer, 'MOCK_PROMPTBLOCK')) {
    reply(200, ['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT'], 'usageMetadata' => ['promptTokenCount' => 1770, 'totalTokenCount' => 1770]]);
}
if (str_contains($customer, 'MOCK_TRUNC')) {
    text("هذا رد طويل انقطع قبل أن يكتمل", $model, $rounds, 'MAX_TOKENS');
}
if (str_contains($customer, 'MOCK_LOOP') || str_contains($customer, 'MOCK_THINKREJECT')) {
    if ($noTools || (str_contains($customer, 'MOCK_THINKREJECT') && $rounds >= 1)) {
        text('Final answer after ' . $rounds . " tool rounds.\n[[chips: OK]]", $model, $rounds);
    }
    tool('search_products', ['query' => 'loop ' . $rounds], $model, $rounds);
}
if (str_contains($customer, 'MOCK_EMPTYINPUT')) {
    if ($rounds === 0) {
        tool('search_products', [], $model, $rounds);
    }
    text('Found ' . ($result()['data']['count'] ?? '?') . ' products.', $model, $rounds);
}
if (str_contains($customer, 'MOCK_PARALLEL')) {
    if ($rounds === 0) {
        // A thought summary part, then parallel calls with ids; only the first call is signed.
        respond([
            ['text' => 'Thinking about the cart and the refund.', 'thought' => true, 'thoughtSignature' => base64_encode('mock-thought')],
            ['functionCall' => ['id' => 'fc-1', 'name' => 'add_to_cart', 'args' => ['product_id' => 'p01', 'quantity' => 2]], 'thoughtSignature' => signature('add_to_cart')],
            ['functionCall' => ['id' => 'fc-2', 'name' => 'add_to_cart', 'args' => ['product_id' => 'p08']]],
            ['functionCall' => ['id' => 'fc-3', 'name' => 'handoff_to_staff', 'args' => ['reason' => 'Refund', 'summary' => 'Customer wants a refund for order 1044.']]],
            ['functionCall' => ['id' => 'fc-4', 'name' => 'handoff_to_staff', 'args' => ['summary' => 'Second handoff in the same reply.']]],
        ], 'STOP', $model, $rounds);
    }
    $sent = $contents[count($contents) - 2]['parts'][0] ?? [];
    if (empty($sent['thought']) || ($sent['thoughtSignature'] ?? '') !== base64_encode('mock-thought')) {
        invalid('the model turn must be passed back unchanged, thought parts included');
    }
    $flags = implode(',', array_map(static fn($r) => isset($r['error']) ? 'error' : 'ok', $toolResults));
    text("Results: $flags | " . $result(1)['text'] . ' | ' . $result(3)['text'], $model, $rounds);
}

// ---- the demo conversations (same as tests/mock-anthropic.php) ---------------------------
if (preg_match('/خيار ثاني|خيار آخر|another option/iu', $customer)) {
    if ($rounds === 0) {
        $first = (string) ($contents[0]['parts'][0]['text'] ?? '');
        $for = preg_match('/زوجي|husband|رجالي|\bmen\b/iu', $first) ? 'men' : 'women';
        tool('search_products', ['family' => 'oriental', 'for' => $for, 'max_price' => 300], $model, $rounds);
    }
    $new = array_values(array_filter($result()['data']['results'] ?? [], static fn(array $p): bool => empty($p['shown_before'])));
    if ($new) {
        text(($ar ? 'عندي لك واحد ما شفته للحين، ريحته دافية وتناسب المساء.' : "Here's one you haven't seen yet, warm and good for evenings.")
            . "\n[[" . $new[0]['id'] . "]]\n[[chips: " . ($ar ? 'أضفه للسلة | شي أخف' : 'Add it to my cart | Something lighter') . ']]', $model, $rounds);
    }
    $up = $result()['data']['higher_price'][0] ?? null;
    text($ar
        ? 'هذا كل اللي عندنا من العطور الشرقية بحدود 300 ريال.' . ($up ? ' ولو تقدر تزيد الميزانية شوي، عندنا ' . $up['name_ar'] . ' بـ' . $up['price_sar'] . ' ريال.' : '')
            . " تحب أوريك نوع ثاني بنفس الميزانية، ولا أشوف لك عطر شرقي بسعر أعلى شوي؟\n[[chips: نوع ثاني بنفس الميزانية | شرقي بسعر أعلى]]"
        : "That's everything we have in oriental within 300 SAR." . ($up ? ' If you can stretch the budget a little, ' . $up['name_en'] . ' is ' . $up['price_sar'] . ' SAR.' : '')
            . " Shall I show you another scent family at the same budget, or oriental at a slightly higher price?\n[[chips: Another family, same budget | Oriental, higher price]]",
        $model, $rounds);
}
if (preg_match('/turkey|تركيا/iu', $customer)) {
    text($ar
        ? "إيه نعم، نشحن لتركيا خلال 6 إلى 10 أيام عمل، والتوصيل بـ 95 ريال. الرسوم الجمركية إن وجدت يدفعها العميل.\n[[chips: أبغى أشوف هدايا | وش طرق الدفع؟ | وين طلبي؟]]"
        : "Yes, we ship to Turkey: delivery takes 6 to 10 working days and costs 95 SAR. Customs fees, if any, are paid by the customer.\n[[chips: Show me gift ideas | Payment options | Track my order]]", $model, $rounds);
}

if (preg_match('/مكسور|تالف|خربان|broken|damaged/iu', $customer)) {
    preg_match('/\d{4}/', $customer, $num);
    $order = $num[0] ?? '';
    if ($rounds === 0) {
        tool('handoff_to_staff', $ar
            ? ['reason' => 'منتج تالف', 'summary' => 'العميل استلم الطلب ' . $order . ' والعلبة مكسورة، ويبغى استبدال أو استرجاع. يحتاج متابعة من فريق الخدمة.']
            : ['reason' => 'Damaged item', 'summary' => 'The customer received order ' . $order . ' with a broken box and wants a replacement or a refund.'], $model, $rounds);
    }
    $ticket = $result()['data']['ticket_id'] ?? '?';
    text($ar
        ? "آسفين جداً على هذا! حوّلت طلبك لفريق خدمة العملاء برقم التذكرة {$ticket}، وبيتواصل معك أحد الموظفين خلال دقائق عشان يرتب لك الاستبدال أو الاسترجاع.\n[[chips: تمام، شكراً | وش سياسة الاسترجاع؟]]"
        : "I'm really sorry about that! I've passed it to our customer care team (ticket $ticket); a team member will contact you within minutes to arrange a replacement or refund.\n[[chips: Thanks | Returns policy]]", $model, $rounds);
}

if (preg_match('/[0-9٠-٩۰-۹]{4}/u', $customer, $m)) {
    if ($rounds === 0) {
        // Arabic-Indic digits on purpose: the server has to normalise them
        $digits = strtr($m[0], ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        tool('get_order_status', ['order_number' => $digits], $model, $rounds);
    }
    $o = $result()['data'];
    if (empty($o['found'])) {
        text($ar
            ? "ما لقيت طلب بهذا الرقم. تأكد من الرقم في رسالة تأكيد الطلب.\n[[chips: أبغى أكلم موظف | أبغى عطر]]"
            : "I couldn't find an order with that number. Please check the number in your confirmation message.\n[[chips: Talk to a person | Find a perfume]]", $model, $rounds);
    }
    preg_match('/GH-\d+/', (string) ($o['note'] ?? ''), $track);
    $days = isset($o['expected_delivery']) ? (int) round((strtotime($o['expected_delivery']) - strtotime('today')) / 86400) : 0;
    $tracking = $track[0] ?? '';
    if (($o['status'] ?? '') === 'shipped') {
        text($ar
            ? "تم شحن طلبك رقم {$o['order_number']} أمس، وهو الحين مع شركة الشحن. يوصلك خلال " . ($days === 2 ? 'يومين' : "$days أيام") . " إن شاء الله" . ($tracking ? "، ورقم التتبع $tracking." : '.') . "\n[[chips: وش طرق الدفع؟ | أبغى أضيف عطر | شكراً]]"
            : "Your order {$o['order_number']} shipped yesterday and is with the courier now. It should arrive in about $days days" . ($tracking ? "; the tracking number is $tracking." : '.') . "\n[[chips: Payment options | Add another perfume | Thanks]]", $model, $rounds);
    }
    $statusAr = ['processing' => 'قيد التجهيز الحين، ويتم شحنه بكرة إن شاء الله', 'delivered' => 'تم تسليمه، وإذا فيه أي مشكلة بلغني وأحوّلك لفريق الخدمة', 'out_for_delivery' => 'مع المندوب اليوم، ويوصلك بين 4 و8 المساء إن شاء الله'];
    $statusEn = ['processing' => 'is being prepared and ships tomorrow', 'delivered' => 'has been delivered; if anything is wrong, tell me and I will connect you with our team', 'out_for_delivery' => 'is out for delivery today, arriving between 4 and 8 pm'];
    $st = (string) ($o['status'] ?? '');
    text($ar
        ? "طلبك رقم {$o['order_number']} " . ($statusAr[$st] ?? $st) . ".\n[[chips: شكراً | أبغى أكلم موظف]]"
        : "Your order {$o['order_number']} " . ($statusEn[$st] ?? $st) . ".\n[[chips: Thanks | Talk to a person]]", $model, $rounds);
}

if (preg_match('/\badd\b|أضف|ضيف/iu', $customer)) {
    if ($rounds === 0) {
        tool('add_to_cart', ['product_id' => ' P01 ', 'quantity' => 1], $model, $rounds);
    }
    $r = $result();
    if ($r['error']) {
        text("Sorry: {$r['text']}", $model, $rounds);
    }
    $items = $r['data']['items'] ?? [];
    $last = end($items) ?: ['name_ar' => '', 'name_en' => ''];
    $total = $r['data']['total_sar'] ?? 0;
    text($ar
        ? "أضفت {$last['name_ar']} للسلة، والمجموع الحين $total ريال. التوصيل مجاني لأن طلبك فوق 199 ريال.\n[[chips: أكمل التسوق | وش طرق الدفع؟ | أبغى تغليف هدية]]"
        : "Added {$last['name_en']} to your cart. Your total is now $total SAR, and delivery is free over 199 SAR.\n[[chips: Keep shopping | Payment options | Gift wrapping]]", $model, $rounds);
}

if (preg_match('/person|staff|human|agent|موظف/iu', $customer)) {
    if ($rounds === 0) {
        tool('handoff_to_staff', $ar
            ? ['reason' => 'العميل طلب التحدث مع موظف', 'summary' => 'العميل يبغى يكلم موظف من خدمة العملاء. ما ذكر رقم طلب.']
            : ['reason' => 'Customer asked for a person', 'summary' => 'The customer wants to talk to a member of the customer care team. No order number given.'], $model, $rounds);
    }
    $ticket = $result()['data']['ticket_id'] ?? '?';
    text($ar
        ? "أكيد، حوّلت محادثتك لفريق خدمة العملاء برقم التذكرة {$ticket}، وبيكمل معك أحد الموظفين خلال دقائق.\n[[chips: تمام، شكراً | أبغى أكمل التسوق]]"
        : "Sure, I've passed your chat to our customer care team (ticket $ticket). A team member will continue with you within minutes.\n[[chips: Thanks | Keep shopping]]", $model, $rounds);
}

if (preg_match('/gift|perfume|هدية|هدايا|عطر/iu', $customer)) {
    if ($rounds === 0) {
        tool('search_products', ['for' => 'women', 'max_price' => 300, 'occasion' => 'gift'], $model, $rounds);
    }
    text($ar
        ? "هذي أفضل الخيارات للهدية تحت 300 ريال. ورد الطائف الملكي زهري فخم ويثبت لين 10 ساعات، وطقم هدية غَيم يجيك جاهز في علبة هدية، ومسك الحرير ناعم ويناسب الاستخدام اليومي.\n[[p01]]\n[[p14]]\n[[p03]]\n[[chips: أضف ورد الطائف للسلة | أبغى شي أخف | عندكم تغليف هدايا؟]]"
        : "Here are the best gift picks under 300 SAR. Royal Taif Rose is a rich floral that lasts up to 10 hours, the Ghaim Gift Set comes ready in a gift box, and Silk Musk is soft enough for every day.\n[[p01]]\n[[p14]]\n[[p03]]\n[[chips: Add Royal Taif Rose | Something lighter | Gift wrapping?]]", $model, $rounds);
}

text($ar
    ? "حيّاك الله! أقدر أساعدك تختار عطر، أو أتابع طلبك، أو أوصلك بأحد موظفينا. وش تبغى اليوم؟\n[[chips: أبغى عطر هدية | وين طلبي؟ | أبغى أكلم موظف]]"
    : "Happy to help! I can suggest a perfume, track an order or connect you with our team. What would you like to do?\n[[chips: A gift idea | Track my order | Talk to a person]]", $model, $rounds);
