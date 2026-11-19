<?php
// The assistant's instructions and its four tools. The browser never sends prompt text.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

// Store policies the assistant quotes. Edit these lines to change shipping, payment or returns.
const GHAIM_POLICIES = <<<'TXT'
- Saudi Arabia: delivery in 1 to 3 working days; free over 199 SAR, otherwise 25 SAR. Cash on delivery in Saudi Arabia costs 15 SAR extra.
- Gulf countries: 3 to 6 working days, 45 SAR.
- Turkey and other countries: 6 to 10 working days, 95 SAR. Customs fees, if any, are paid by the customer.
- Payment: mada, Visa, Mastercard, Apple Pay, or 4 interest-free instalments.
- Returns: unopened items within 7 days of delivery; opened items only if damaged or wrong. Refunds take 5 to 7 working days.
- Free gift wrapping with a printed message card. 2 free samples with orders over 300 SAR.
- Customer care: 10 am to 11 pm Saudi time, every day.
TXT;

/**
 * First system block: the same for every request, so it is cached together with the tools.
 * The channel sentence and the "Now" section are per request and live in the second block.
 */
function ghaim_static_prompt(): string
{
    return <<<'TXT'
You are the shopping assistant of Ghaim Perfumes (عطور غيم), an online perfume store in Saudi Arabia. This is a demo store: products and orders come only from the store data.

Style
- Reply in the customer's language and tone: Gulf Arabic if they write Gulf Arabic, simple Modern Standard Arabic for other Arabic, English for English.
- Warm and brief: 1 to 3 short sentences. Plain text: no headings, tables, bullet lists or emoji.
- If you don't know who the perfume is for, the taste, or the budget, ask one short question before recommending, with 2 or 3 options.
- Recommend at most 3 products and say in a few words why each fits (scent, how long it lasts, price in SAR).
- Arabic: write like a friendly Saudi store employee texting a customer. Use natural Gulf Arabic with correct grammar and normal word order, in short sentences. Use أبغى (never أبي) for "I want", تبغى for "you want" and وش for "what". If a sentence sounds awkward in dialect, write it in simple Modern Standard Arabic instead, and never mix the two inside one sentence. Examples of the right style:
  «أكيد! وش نوع الريحة اللي تحبها زوجتك؟ زهرية ناعمة ولا شرقية؟»
  «أكيد! تحب ريحته تكون زهرية ناعمة ولا شرقية ولا مسكية؟»
  «هذي أفضل الخيارات تحت 300 ريال، وكلها ريحتها تثبت طول اليوم.»
  «هذا العطر ريحته ناعمة ويثبت طول اليوم، وسعره مناسب للهدية.»
  «تم شحن طلبك رقم 1042 أمس، ويوصلك خلال يومين إن شاء الله.»
- Quick replies follow the same style, for example «أبغى شي ناعم».
- Arabic grammar: make every pronoun, adjective and verb agree with the noun it refers to. When you talk about a perfume, refer to it as عطر, which is masculine, even when it is a gift or its name has a feminine word in it: «ريحته، ثباته، سعره، يثبت»، «عطر باقة العروس ريحته ناعمة». The word ريحة on its own is feminine: «ريحة ناعمة تثبت». Several products together take the feminine singular: «كلها تثبت». Address the customer in the masculine unless they write in the feminine, and match the person the gift is for: «وش الريحة اللي تحبها زوجتك؟»، «وش العطر اللي يحبه أبوك؟». Reread every Arabic reply once for these before you send it.

Facts
- Never invent products, prices, stock, dates or order details. Get them from the tools. Call tools silently, without writing anything first, and write your reply after the results.
- If something is out of stock, say so and offer the closest in-stock option.
- Help only with this store: perfumes, gifts, orders, shipping, payment and returns. For anything else, say politely that you can only help with the store.
- Hand over to the customer care team (call handoff_to_staff) when the customer asks for a person, complains, reports a damaged or wrong item, asks for a refund or has a payment problem. Then tell them a team member will continue with them.
- If the customer keeps chatting after a handover, for example to keep shopping, help them normally and don't repeat the handover message.

Product cards
- After your text, put each product you recommend on its own line as [[p01]] (the product id). The page shows these as cards with a picture, price and an add-to-cart button, so don't list prices again under them.
- End every reply with one line of 2 or 3 short replies the customer might tap next, in their language: [[chips: first | second | third]]

Store policies

TXT . GHAIM_POLICIES;
}

/** Second system block: channel, today's date, cart and open tickets. Not cached. */
function ghaim_request_prompt(array $req, array $store, DateTimeImmutable $now): string
{
    $channel = $req['channel'] === 'wa'
        ? "You chat with customers on the store's WhatsApp Business number, so keep replies short like a WhatsApp message."
        : 'You chat with customers on the store website.';
    $cart = ghaim_cart_summary($store, $req['cart']);
    $cartText = $cart['items']
        ? implode(', ', array_map(static fn(array $i): string => $i['name_en'] . ' x' . $i['qty'], $cart['items'])) . ' (total ' . $cart['total_sar'] . ' SAR)'
        : 'empty';
    $open = $req['open_tickets'] ? implode(', ', $req['open_tickets']) : 'none';

    return $channel . "\n\n"
        . "Now\n"
        . '- Today is ' . $now->format('D M d Y') . ".\n"
        . '- Cart: ' . $cartText . ".\n"
        . '- Open staff tickets: ' . $open . '.';
}

/** The four tools, with the same names, descriptions and schemas as the browser version. */
function ghaim_tools(array $store): array
{
    return [
        [
            'name' => 'search_products',
            'description' => 'Search the store catalogue. Returns up to 6 products with id, Arabic and English names, scent family, who it is for, notes, size, price in SAR, how many hours it lasts and stock. Use it before recommending or quoting any product. All filters are optional.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to match in names and notes, Arabic or English, e.g. rose, oud, vanilla'],
                    'family' => ['type' => 'string', 'enum' => array_map('strval', array_keys($store['families']))],
                    'for' => ['type' => 'string', 'enum' => ['women', 'men', 'unisex']],
                    'max_price' => ['type' => 'number', 'description' => 'Highest price in SAR'],
                    'min_hours' => ['type' => 'number', 'description' => 'Minimum hours the scent should last'],
                    'occasion' => ['type' => 'string', 'enum' => array_map('strval', array_keys($store['occasions']))],
                    'in_stock_only' => ['type' => 'boolean'],
                ],
            ],
        ],
        [
            'name' => 'get_order_status',
            'description' => 'Look up an order by its number. Returns status (processing, shipped, out_for_delivery, delivered), items, city, total and dates: placed, shipped, expected delivery or delivered. Returns found:false if there is no such order.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['order_number' => ['type' => 'string']],
                'required' => ['order_number'],
            ],
        ],
        [
            'name' => 'add_to_cart',
            'description' => "Add a product to the customer's cart, only when the customer asks to buy or add it. Returns the cart with its total in SAR.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                ],
                'required' => ['product_id'],
            ],
        ],
        [
            'name' => 'handoff_to_staff',
            'description' => "Hand the chat to a person on the customer care team. Use when the customer asks for a person, complains, reports a damaged or wrong item, asks for a refund or has a payment problem. summary: 2 or 3 sentences for the staff member, in the customer's language, with any order number.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['summary'],
            ],
        ],
    ];
}

/**
 * Runs one tool call. Search and order lookups answer from the store data; add_to_cart and
 * handoff_to_staff are validated here and recorded as actions for the page to apply.
 * Returns ['content' => string, 'is_error' => bool].
 */
function ghaim_run_tool(string $name, array $input, array &$state, array $store, array $ctx): array
{
    switch ($name) {
        case 'search_products':
            $r = ghaim_search_products($store, $input);
            $state['steps'][] = ['k' => 'search', 'f' => ghaim_step_filter($input), 'c' => $r['count']];
            return ['content' => ghaim_json($r), 'is_error' => false];

        case 'get_order_status':
            $n = ghaim_digits($input['order_number'] ?? null);
            $r = ghaim_order_status($store, $n, $ctx['today']);
            $state['steps'][] = ['k' => 'order', 'n' => $n, 'st' => $r['found'] ? $r['status'] : 'none'];
            return ['content' => ghaim_json($r), 'is_error' => false];

        case 'add_to_cart':
            $raw = $input['product_id'] ?? '';
            $id = ghaim_trim(ghaim_lower(ghaim_js_string(ghaim_js_truthy($raw) ? $raw : '')));
            $p = $id !== '' ? ($store['by_id'][$id] ?? null) : null;
            if ($p === null) {
                return ['content' => 'No product with id ' . $id, 'is_error' => true];
            }
            if ($p['stock'] <= 0) {
                return ['content' => $p['en'] . ' is out of stock', 'is_error' => true];
            }
            $qty = max(1, min(5, ghaim_js_parse_int($input['quantity'] ?? null) ?: 1));
            $state['cart'][$p['id']] = min(($state['cart'][$p['id']] ?? 0) + $qty, $p['stock']);
            $state['actions'][] = ['type' => 'add_to_cart', 'product_id' => $p['id'], 'quantity' => $qty];
            $state['steps'][] = ['k' => 'cart', 'id' => $p['id']];
            return ['content' => ghaim_json(ghaim_cart_summary($store, $state['cart'])), 'is_error' => false];

        case 'handoff_to_staff':
            if ($state['handoff'] !== null) {
                return ['content' => ghaim_json(['ticket_id' => $state['handoff'], 'note' => 'Already handed over']), 'is_error' => false];
            }
            $reason = $input['reason'] ?? null;
            $summary = $input['summary'] ?? null;
            $ticket = $ctx['next_ticket_id'];
            $state['handoff'] = $ticket;
            $state['actions'][] = [
                'type' => 'handoff',
                'ticket_id' => $ticket,
                'reason' => ghaim_cut(ghaim_js_string(ghaim_js_truthy($reason) ? $reason : ''), 300),
                'summary' => ghaim_cut(ghaim_js_string(ghaim_js_truthy($summary) ? $summary : (ghaim_js_truthy($reason) ? $reason : '')), 800),
            ];
            $state['steps'][] = ['k' => 'hand', 'id' => $ticket];
            return ['content' => ghaim_json([
                'ticket_id' => $ticket,
                'team' => 'Ghaim customer care',
                'expected_reply' => 'within 10 minutes, 10 am to 11 pm Saudi time',
                'whatsapp_button_shown' => $ctx['wa_button'],
            ]), 'is_error' => false];
    }
    return ['content' => 'Unknown tool: ' . ghaim_cut($name, 64), 'is_error' => true];
}

/** The search filters the page shows in its steps line: known keys and plain values only. */
function ghaim_step_filter(array $f): object
{
    $out = [];
    foreach (['query', 'family', 'for', 'max_price', 'min_hours', 'occasion', 'in_stock_only'] as $key) {
        $v = $f[$key] ?? null;
        if (is_string($v)) {
            $out[$key] = ghaim_cut($v, 100);
        } elseif (is_int($v) || is_float($v) || is_bool($v)) {
            $out[$key] = $v;
        }
    }
    return (object) $out;
}
