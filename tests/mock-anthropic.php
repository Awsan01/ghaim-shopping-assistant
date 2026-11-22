<?php
// A stand-in for the Claude Messages API, for local tests only. No real key, no network.
//
//   MOCK_LOG=/tmp/mock.jsonl php -S 127.0.0.1:8090 tests/mock-anthropic.php
//
// It checks each request the way the real API would (headers, system blocks, tools,
// alternating roles, tool results that match the tool calls), then answers from a script:
// the customer's latest message picks a scenario, the first call gets a canned tool_use
// reply and the next call gets the final text, written from the tool results.
// Messages containing MOCK_... trigger error cases for tests/smoke.sh.
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$logFile = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/ghaim-mock.jsonl';
$stateFile = $logFile . '.state';

function reply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('request-id: req_mock_' . bin2hex(random_bytes(6)));
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $status, string $type, string $message, array $extra = []): never
{
    $error = ['type' => $type, 'message' => $message] + $extra;
    reply($status, ['type' => 'error', 'error' => $error, 'request_id' => 'req_mock']);
}

function invalid(string $message): never
{
    api_error(400, 'invalid_request_error', $message);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/v1/messages') {
    api_error(404, 'not_found_error', 'Not found');
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$objects = json_decode($raw);   // same body as objects, to see {} versus []
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';

file_put_contents($logFile, json_encode([
    'time' => date('c'),
    'key_sent' => $key !== '',
    'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '',
    'workspace' => $_SERVER['HTTP_ANTHROPIC_WORKSPACE_ID'] ?? '',
    'body' => $body,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// ---- the checks the real API would make ----------------------------------------------
if ($key === '') {
    api_error(401, 'authentication_error', 'x-api-key header is required');
}
if (($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') !== '2026-10-21') {
    invalid('anthropic-version header is missing or unknown');
}
if (!is_array($body) || !is_object($objects)) {
    invalid('body must be a JSON object');
}
foreach (['temperature', 'top_p', 'top_k'] as $gone) {
    if (array_key_exists($gone, $body)) {
        invalid("$gone is not supported on this API version");
    }
}
if (!is_string($body['model'] ?? null) || !is_int($body['max_tokens'] ?? null)) {
    invalid('model and max_tokens are required');
}
$system = $body['system'] ?? null;
if (!is_array($system) || count($system) !== 2 || ($system[0]['cache_control']['type'] ?? '') !== 'ephemeral'
    || isset($system[1]['cache_control']) || !is_string($system[0]['text'] ?? null) || !is_string($system[1]['text'] ?? null)) {
    invalid('mock expects two system blocks, the first one cached');
}
$names = array_map(static fn($t) => $t['name'] ?? '', $body['tools'] ?? []);
if ($names !== ['search_products', 'get_order_status', 'add_to_cart', 'handoff_to_staff']) {
    invalid('mock expects the four store tools');
}
foreach ($objects->tools as $tool) {
    if (!is_object($tool->input_schema ?? null) || !is_object($tool->input_schema->properties ?? null)) {
        invalid('tools.input_schema must be an object');
    }
}
$messages = $body['messages'] ?? null;
if (!is_array($messages) || $messages === [] || ($messages[0]['role'] ?? '') !== 'user'
    || ($messages[count($messages) - 1]['role'] ?? '') !== 'user') {
    invalid('messages must start and end with a user turn');
}
foreach ($messages as $i => $m) {
    if ($i > 0 && $m['role'] === $messages[$i - 1]['role']) {
        invalid("messages: roles must alternate between \"user\" and \"assistant\" (at $i)");
    }
    if ($m['role'] === 'assistant' && is_array($m['content'])) {
        foreach ($objects->messages[$i]->content as $block) {
            if (($block->type ?? '') === 'tool_use' && !is_object($block->input ?? null)) {
                invalid("messages.$i: tool_use.input must be an object");
            }
        }
    }
    if ($m['role'] === 'user' && is_array($m['content']) && ($m['content'][0]['type'] ?? '') === 'tool_result') {
        $prev = $messages[$i - 1]['content'] ?? [];
        $calls = is_array($prev) ? array_column(array_filter($prev, static fn($b) => ($b['type'] ?? '') === 'tool_use'), 'id') : [];
        $results = array_column($m['content'], 'tool_use_id');
        sort($calls);
        sort($results);
        if ($calls !== $results) {
            invalid("messages.$i: every tool_use needs exactly one tool_result");
        }
    }
}

// ---- which scenario, and how far into it ---------------------------------------------
$lastText = '';
$rounds = 0;   // assistant turns after the customer's latest message
for ($i = count($messages) - 1; $i >= 0; $i--) {
    $m = $messages[$i];
    if ($m['role'] === 'assistant') {
        $rounds++;
        continue;
    }
    if (is_string($m['content'])) {
        $lastText = $m['content'];
        break;
    }
}
$parts = preg_split("/\n\n/", $lastText);
$customer = (string) end($parts);
$ar = (bool) preg_match('/\p{Arabic}/u', $customer);
$noTools = ($body['tool_choice']['type'] ?? '') === 'none';
$toolResults = $rounds > 0 ? $messages[count($messages) - 1]['content'] : [];
$result = static function (int $n = 0) use ($toolResults): array {
    $r = $toolResults[$n] ?? [];
    $decoded = json_decode((string) ($r['content'] ?? ''), true);
    return ['data' => is_array($decoded) ? $decoded : [], 'text' => (string) ($r['content'] ?? ''), 'error' => !empty($r['is_error'])];
};

function once(string $stateFile, string $name): bool
{
    $state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
    $first = empty($state[$name]);
    $state[$name] = true;
    file_put_contents($stateFile, json_encode($state));
    return $first;
}

function message(array $content, string $stop, string $model, int $rounds): never
{
    reply(200, [
        'id' => 'msg_mock_' . bin2hex(random_bytes(6)),
        'type' => 'message',
        'role' => 'assistant',
        'model' => $model,
        'content' => $content,
        'stop_reason' => $stop,
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 120 + 40 * $rounds,
            'output_tokens' => 60,
            'cache_creation_input_tokens' => $rounds === 0 ? 1650 : 0,
            'cache_read_input_tokens' => $rounds === 0 ? 0 : 1650,
        ],
    ]);
}

function text(string $t, string $model, int $rounds, string $stop = 'end_turn'): never
{
    message([['type' => 'text', 'text' => $t]], $stop, $model, $rounds);
}

function tool(string $name, array $input, string $model, int $rounds): never
{
    message([['type' => 'tool_use', 'id' => 'toolu_mock_' . bin2hex(random_bytes(5)), 'name' => $name, 'input' => (object) $input]], 'tool_use', $model, $rounds);
}

$model = $body['model'];

// ---- error cases ----------------------------------------------------------------------
if (str_contains($customer, 'MOCK_529')) {
    api_error(529, 'overloaded_error', 'Overloaded');
}
if (str_contains($customer, 'MOCK_500_ONCE') && once($stateFile, 'fail-once-' . md5($customer))) {
    api_error(500, 'api_error', 'Internal server error');
}
if (str_contains($customer, 'MOCK_CREDIT')) {
    invalid('Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.');
}
if (str_contains($customer, 'MOCK_SPEND')) {
    api_error(429, 'rate_limit_error', 'You have reached your specified workspace API usage limits.', ['details' => ['error_code' => 'enforced_spend_limit_reached']]);
}
if (str_contains($customer, 'MOCK_RATE')) {
    api_error(429, 'rate_limit_error', 'Number of request tokens has exceeded your per-minute rate limit');
}
if (str_contains($customer, 'MOCK_AUTH')) {
    api_error(401, 'authentication_error', 'invalid x-api-key');
}
if (str_contains($customer, 'MOCK_BADREQ')) {
    invalid('messages.0.content: something the API does not accept');
}
if (str_contains($customer, 'MOCK_SLOW')) {
    sleep(3);   // gives the page time to show the stop button
}
if (str_contains($customer, 'MOCK_REFUSE')) {
    message([], 'refusal', $model, $rounds);
}
if (str_contains($customer, 'MOCK_TRUNC')) {
    text("هذا رد طويل انقطع قبل أن يكتمل", $model, $rounds, 'max_tokens');
}
if (str_contains($customer, 'MOCK_LOOP')) {
    if ($noTools) {
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
        $id = static fn() => 'toolu_mock_' . bin2hex(random_bytes(5));
        message([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-mock-123'],
            ['type' => 'tool_use', 'id' => $id(), 'name' => 'add_to_cart', 'input' => ['product_id' => 'p01', 'quantity' => 2]],
            ['type' => 'tool_use', 'id' => $id(), 'name' => 'add_to_cart', 'input' => ['product_id' => 'p08']],
            ['type' => 'tool_use', 'id' => $id(), 'name' => 'handoff_to_staff', 'input' => ['reason' => 'Refund', 'summary' => 'Customer wants a refund for order 1044.']],
            ['type' => 'tool_use', 'id' => $id(), 'name' => 'handoff_to_staff', 'input' => ['summary' => 'Second handoff in the same reply.']],
        ], 'tool_use', $model, $rounds);
    }
    $sent = $messages[count($messages) - 2]['content'][0] ?? [];
    if (($sent['type'] ?? '') !== 'thinking' || ($sent['signature'] ?? '') !== 'sig-mock-123') {
        invalid('thinking blocks must be passed back unchanged');
    }
    $flags = implode(',', array_map(static fn($r) => empty($r['is_error']) ? 'ok' : 'error', $toolResults));
    text("Results: $flags | " . $result(1)['text'] . ' | ' . $result(3)['text'], $model, $rounds);
}

// ---- the demo conversations ------------------------------------------------------------
// "Another option" after earlier suggestions: the server marks what the customer has already
// seen; a new product is offered if there is one, otherwise the reply says so and offers another
// scent family or a higher price.
if (preg_match('/خيار ثاني|خيار آخر|another option/iu', $customer)) {
    if ($rounds === 0) {
        $first = is_string($messages[0]['content'] ?? null) ? $messages[0]['content'] : '';
        $for = preg_match('/زوجي|husband|رجالي|\bmen\b/iu', $first) ? 'men' : 'women';
        tool('search_products', ['family' => 'oriental', 'for' => $for, 'max_price' => 300], $model, $rounds);
    }
    $new = array_values(array_filter($result()['data']['results'] ?? [], static fn(array $p): bool => empty($p['shown_before'])));
    if ($new) {
        text(($ar ? 'عندي لك واحد ما شفته للحين، ريحته دافية وتناسب المساء.' : "Here's one you haven't seen yet, warm and good for evenings.")
            . "\n[[" . $new[0]['id'] . "]]\n[[chips: " . ($ar ? 'أضفه للسلة | شي أخف' : 'Add it to my cart | Something lighter') . ']]', $model, $rounds);
    }
    $up = $result()['data']['higher_price'][0] ?? null;   // the nearest one above the budget, if any
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
