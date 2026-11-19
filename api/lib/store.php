<?php
// Store data and the two read-only tools (product search and order status).
// The logic is a line-by-line port of the original browser version.
declare(strict_types=1);

if (!defined('GHAIM_API')) {
    http_response_code(404);
    exit;
}

/** Catalogue from data/products.json and orders from data/orders.json. */
function ghaim_load_store(): array
{
    $catalogue = ghaim_read_json(GHAIM_APP_DIR . '/data/products.json');
    $orders = ghaim_read_json(GHAIM_APP_DIR . '/data/orders.json');

    foreach (['families', 'who', 'occasions', 'products'] as $key) {
        if (!isset($catalogue[$key]) || !is_array($catalogue[$key]) || $catalogue[$key] === []) {
            throw new RuntimeException("data/products.json: \"$key\" is missing or empty");
        }
    }
    $byId = [];
    foreach ($catalogue['products'] as $i => $p) {
        $where = 'data/products.json: product ' . (is_array($p) && is_string($p['id'] ?? null) ? $p['id'] : '#' . ($i + 1));
        foreach (['id', 'ar', 'en', 'fam', 'for', 'notes_ar', 'notes_en'] as $field) {
            if (!is_string($p[$field] ?? null) || $p[$field] === '') {
                throw new RuntimeException("$where: \"$field\" must be a text");
            }
        }
        if (!isset($catalogue['families'][$p['fam']])) {
            throw new RuntimeException("$where: unknown family \"{$p['fam']}\"");
        }
        if (!is_int($p['price'] ?? null) && !is_float($p['price'] ?? null)) {
            throw new RuntimeException("$where: \"price\" must be a number");
        }
        if (!is_int($p['stock'] ?? null) || $p['stock'] < 0) {
            throw new RuntimeException("$where: \"stock\" must be a whole number");
        }
        if (!array_key_exists('sz', $p) || !($p['sz'] === null || (is_array($p['sz']) && count($p['sz']) === 2))) {
            throw new RuntimeException("$where: \"sz\" must be null or [size, unit]");
        }
        if (!array_key_exists('hours', $p) || !($p['hours'] === null || (is_array($p['hours']) && count($p['hours']) === 2))) {
            throw new RuntimeException("$where: \"hours\" must be null or [from, to]");
        }
        if (!is_array($p['tags'] ?? null)) {
            throw new RuntimeException("$where: \"tags\" must be a list");
        }
        foreach ($p['tags'] as $tag) {
            if (!is_string($tag) || !isset($catalogue['occasions'][$tag])) {
                throw new RuntimeException("$where: unknown occasion tag");
            }
        }
        if (isset($byId[$p['id']])) {
            throw new RuntimeException("$where: the id is used twice");
        }
        $byId[$p['id']] = $p;
    }

    return [
        'families' => $catalogue['families'],
        'who' => $catalogue['who'],
        'occasions' => $catalogue['occasions'],
        'products' => array_values($catalogue['products']),
        'by_id' => $byId,
        'orders' => $orders,
    ];
}

function ghaim_read_json(string $file): array
{
    $raw = @file_get_contents($file);
    if (!is_string($raw)) {
        throw new RuntimeException('cannot read ' . basename($file));
    }
    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException(basename($file) . ' is not valid JSON: ' . $e->getMessage());
    }
    if (!is_array($data)) {
        throw new RuntimeException(basename($file) . ' must hold a JSON object');
    }
    return $data;
}

/** Product as the tools show it to the model (view_ in the browser version). */
function ghaim_view(array $p): array
{
    $v = [
        'id' => $p['id'],
        'name_ar' => $p['ar'],
        'name_en' => $p['en'],
        'family' => $p['fam'],
        'for' => $p['for'],
        'notes' => $p['notes_en'],
        'notes_ar' => $p['notes_ar'],
        'size' => $p['sz'] ? $p['sz'][0] . ' ' . $p['sz'][1] : 'gift box',
        'price_sar' => $p['price'],
    ];
    if ($p['hours']) {
        $v['lasts_hours'] = implode('-', $p['hours']);
    }
    $v['stock'] = $p['stock'] === 0 ? 'out of stock' : ($p['stock'] <= 5 ? 'only ' . $p['stock'] . ' left' : 'in stock');
    $v['good_for'] = implode(', ', $p['tags']);
    return $v;
}

/** search_products: filters, word matching and ranking exactly as in the browser version. */
function ghaim_search_products(array $store, array $f): array
{
    $query = $f['query'] ?? '';
    $words = array_values(array_filter(
        preg_split('/[\s,،]+/u', ghaim_lower(ghaim_js_string(ghaim_js_truthy($query) ? $query : ''))) ?: [],
        static fn(string $w): bool => ghaim_utf16_len($w) > 1
    ));
    $fam = is_string($f['family'] ?? null) && isset($store['families'][$f['family']]) ? $f['family'] : null;
    $who = is_string($f['for'] ?? null) && isset($store['who'][$f['for']]) ? $f['for'] : null;
    $occ = is_string($f['occasion'] ?? null) && isset($store['occasions'][$f['occasion']]) ? $f['occasion'] : null;
    $maxPrice = ghaim_js_number($f['max_price'] ?? null);
    $max = $maxPrice > 0 ? $maxPrice : INF;
    $minH = ghaim_js_number($f['min_hours'] ?? null);
    $only = ($f['in_stock_only'] ?? null) === true;

    $base = static fn(array $p): bool => $p['price'] <= $max
        && ($who === null || $who === 'unisex' || $p['for'] === $who || $p['for'] === 'unisex')
        && (!$only || $p['stock'] > 0);

    $score = static function (array $p) use ($words, $store): int {
        if (!$words) {
            return 0;
        }
        $hay = ghaim_lower(implode(' ', array_merge(
            [$p['ar'], $p['en'], $p['notes_ar'], $p['notes_en'], $p['fam'], $store['families'][$p['fam']]['ar'] ?? ''],
            $p['tags'],
            array_map(static fn(string $t): string => $store['occasions'][$t][0] ?? '', $p['tags'])
        )));
        $n = 0;
        foreach ($words as $w) {
            if (str_contains($hay, $w)) {
                $n++;
            }
        }
        return $n;
    };

    $list = array_values(array_filter($store['products'], static fn(array $p): bool => $base($p)
        && ($fam === null || $p['fam'] === $fam)
        && ($occ === null || in_array($occ, $p['tags'], true))
        && (is_array($p['hours']) ? ($p['hours'][0] ?? NAN) >= $minH : $minH == 0)));

    if ($words && array_filter($list, static fn(array $p): bool => $score($p) > 0)) {
        $list = array_values(array_filter($list, static fn(array $p): bool => $score($p) > 0));
    }
    $relaxed = false;
    if (!$list) {
        $relaxed = true;
        $list = array_values(array_filter($store['products'], $base));
    }
    usort($list, static fn(array $a, array $b): int => (($b['stock'] > 0) <=> ($a['stock'] > 0))
        ?: ($score($b) <=> $score($a))
        ?: ($max < INF ? $b['price'] <=> $a['price'] : $a['price'] <=> $b['price']));

    $r = ['count' => count($list), 'results' => array_map('ghaim_view', array_slice($list, 0, 6))];
    if ($relaxed) {
        $r['note'] = 'No exact match. These are the closest options for this budget and person.';
    }
    return $r;
}

/** Order numbers with Arabic-Indic (٠-٩) or Persian (۰-۹) digits become plain digits. */
function ghaim_digits(mixed $v): string
{
    $s = $v === null ? '' : ghaim_js_string($v);
    $s = strtr($s, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
    return (string) preg_replace('/\D/', '', $s);
}

/** get_order_status. Dates are day offsets from today in Saudi Arabia. */
function ghaim_order_status(array $store, string $n, DateTimeImmutable $today): array
{
    $o = $n !== '' ? ($store['orders'][$n] ?? null) : null;
    if (!is_array($o)) {
        return ['found' => false, 'order_number' => $n, 'note' => 'No order with this number. Ask the customer to check the number in their confirmation message.'];
    }
    $items = [];
    foreach ($o['items'] ?? [] as $line) {
        $p = $store['by_id'][$line[0] ?? ''] ?? null;
        if ($p !== null) {
            $items[] = ['name_ar' => $p['ar'], 'name_en' => $p['en'], 'qty' => $line[1] ?? 1, 'price_sar' => $p['price']];
        }
    }
    $total = 0;
    foreach ($items as $i) {
        $total += $i['qty'] * $i['price_sar'];
    }
    $day = static fn(mixed $offset): string => $today->modify(sprintf('%+d days', (int) $offset))->format('Y-m-d');
    $r = [
        'found' => true,
        'order_number' => $n,
        'status' => $o['status'] ?? '',
        'city' => $o['city'] ?? '',
        'items' => $items,
        'total_sar' => $total,
        'placed_on' => $day($o['placed'] ?? 0),
    ];
    if (isset($o['shipped'])) {
        $r['shipped_on'] = $day($o['shipped']);
    }
    if (isset($o['eta'])) {
        $r['expected_delivery'] = $day($o['eta']);
    }
    if (isset($o['delivered'])) {
        $r['delivered_on'] = $day($o['delivered']);
    }
    if (is_string($o['note'] ?? null) && $o['note'] !== '') {
        $r['note'] = $o['note'];
    }
    return $r;
}

/** Cart contents and total, as add_to_cart returns them. $cart is [product id => quantity]. */
function ghaim_cart_summary(array $store, array $cart): array
{
    $items = [];
    $total = 0;
    foreach ($cart as $id => $qty) {
        $p = $store['by_id'][$id];
        $items[] = ['id' => (string) $id, 'name_ar' => $p['ar'], 'name_en' => $p['en'], 'qty' => $qty, 'price_sar' => $p['price']];
        $total += $qty * $p['price'];
    }
    return ['items' => $items, 'total_sar' => $total];
}
