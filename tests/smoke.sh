#!/usr/bin/env bash
# Smoke test: runs the app and a mock Claude API with PHP's built-in server and checks the
# API end to end. No API key and no network needed.
#
#   tests/smoke.sh
#
# Needs php (8.1+) with the curl extension, and curl. If Node and Playwright are installed,
# it also runs tests/browser-check.js against the page.
set -uo pipefail

cd "$(dirname "$0")/.."
ROOT=$PWD
unset GHAIM_CONFIG
TMP=$(mktemp -d)
APP_PORT=${APP_PORT:-8765}
MOCK_PORT=${MOCK_PORT:-8766}
WALK_PORT=${WALK_PORT:-8767}
APP="http://127.0.0.1:$APP_PORT"
MOCK_LOG="$TMP/mock.jsonl"
CONFIG="$TMP/ghaim-config.php"
STORAGE="$TMP/storage"
PHPLOG="$TMP/php-errors.log"
KEY="test-key-not-real-$$"
PIDS=()
FAILED=0
PASSED=0

cleanup() {
  for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; done
  wait 2>/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT

ok()   { PASSED=$((PASSED + 1)); printf '  ok   %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check() { if eval "$2"; then ok "$1"; else fail "$1" "${3:-}"; fi; }

# check_json "label" 'PHP expression using $j (the decoded last response)'
check_json() {
  if php -r '$j = json_decode(file_get_contents($argv[1]), true); exit((is_array($j) && ('"$2"')) ? 0 : 1);' "$TMP/body" 2>/dev/null; then
    ok "$1"
  else
    fail "$1" "response: $(head -c 400 "$TMP/body")"
  fi
}

# write_config [extra PHP array entries]
write_config() {
  cat > "$CONFIG" <<EOF
<?php
return [
    'anthropic_api_key' => '$KEY',
    'anthropic_base_url' => 'http://127.0.0.1:$MOCK_PORT',
    'storage_dir' => '$STORAGE',
    'ip_salt' => 'smoke-test-salt',
    'limits' => ['ip_per_hour' => 1000, 'ip_per_day' => 1000, 'site_per_day' => 1000],
    $1
];
EOF
}

serve() { # port docroot [router] -- environment is inherited
  local port=$1 root=$2 router=${3:-}
  php -d log_errors=1 -d error_log="$PHPLOG" -d display_errors=0 ${PHP_EXTRA:-} \
    -S "127.0.0.1:$port" -t "$root" $router >>"$TMP/servers.log" 2>&1 &
  PIDS+=($!)
  for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$port/" && return 0
    sleep 0.1
  done
  echo "server on port $port did not start"; cat "$TMP/servers.log"; exit 1
}

# chat 'customer text' [channel] [speed] [cart json] [open tickets json] [next ticket] -> status
body() {
  php -r '$a = $argv; array_shift($a);
    echo json_encode(["messages" => [["role" => "user", "content" => $a[0]]], "channel" => $a[1] ?? "web",
      "speed" => $a[2] ?? "fast", "cart" => json_decode($a[3] ?? "[]", true), "open_tickets" => json_decode($a[4] ?? "[]", true),
      "next_ticket_id" => $a[5] ?? "T-1001", "wa_button" => false], JSON_UNESCAPED_UNICODE);' -- "$@"
}
post() { # raw-json [extra curl args] -> prints the status, body in $TMP/body
  local data=$1 code; shift
  code=$(curl -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X POST "$APP/api/chat.php" \
    -H 'Content-Type: application/json' -H "Origin: $APP" --data-binary "$data" "$@")
  cat "$TMP/body" "$TMP/headers" >> "$TMP/all-responses"
  echo "$code"
}
# N alternating turns ending with the customer
turns() {
  php -r '$n = (int) $argv[1]; $m = [];
    for ($i = 0; $i < $n; $i++) $m[] = ["role" => (($n - 1 - $i) % 2 === 0) ? "user" : "assistant", "content" => "hi"];
    echo json_encode(["messages" => $m]);' -- "$1"
}
chat() { post "$(body "$@")"; }
mock_calls() { [ -f "$MOCK_LOG" ] && wc -l < "$MOCK_LOG" | tr -d ' ' || echo 0; }
last_mock() { tail -n 1 "$MOCK_LOG"; }

for port in $APP_PORT $MOCK_PORT $WALK_PORT; do
  if curl -s -o /dev/null "http://127.0.0.1:$port/"; then echo "port $port is busy; set APP_PORT/MOCK_PORT/WALK_PORT"; exit 1; fi
done

echo "== PHP syntax"
while IFS= read -r f; do
  if out=$(php -l "$f" 2>&1); then ok "php -l $f"; else fail "php -l $f" "$out"; fi
done < <(find . -name '*.php' -not -path './.git/*' | sort)

echo "== store data"
check "products.json and orders.json are valid, orders use known products, every product photo exists" \
  "php -r '\$p = json_decode(file_get_contents(\"data/products.json\"), true, 64, JSON_THROW_ON_ERROR);
    \$o = json_decode(file_get_contents(\"data/orders.json\"), true, 64, JSON_THROW_ON_ERROR);
    \$ids = array_column(\$p[\"products\"], \"id\");
    foreach (\$o as \$order) foreach (\$order[\"items\"] as \$i) if (!in_array(\$i[0], \$ids, true)) exit(1);
    foreach (\$p[\"sample_orders\"] as \$n) if (!isset(\$o[\$n])) exit(1);
    foreach (\$p[\"products\"] as \$x) if (isset(\$x[\"img\"]) && !is_file(\$x[\"img\"])) exit(1);'"
check "oriental and oud perfumes are on offer within 300 SAR (women, men and oud found without relaxing the search)" \
  "php -r 'define(\"GHAIM_API\", true); require \"api/lib/bootstrap.php\"; \$s = ghaim_load_store();
    foreach ([[\"family\" => \"oriental\", \"for\" => \"women\"], [\"family\" => \"oriental\", \"for\" => \"men\"], [\"family\" => \"oud\"]] as \$f) {
      \$r = ghaim_search_products(\$s, \$f + [\"max_price\" => 300, \"in_stock_only\" => true]);
      if (\$r[\"count\"] < 1 || isset(\$r[\"note\"])) exit(1);
    }'"

echo "== servers"
write_config ""
MOCK_LOG="$MOCK_LOG" serve "$MOCK_PORT" "$ROOT" tests/mock-anthropic.php
GHAIM_CONFIG="$CONFIG" serve "$APP_PORT" "$ROOT"
ok "app on $APP, mock Claude API on port $MOCK_PORT"

echo "== health"
check "health answers {ok:true} when configured" '[ "$(curl -s "$APP/api/health.php")" = "{\"ok\":true}" ]'
check "health does not call Claude" '[ "$(mock_calls)" = 0 ]'
check "health sends Cache-Control: no-store" 'curl -s -D - -o /dev/null "$APP/api/health.php" | grep -qi "^cache-control: no-store"'

echo "== conversations"
status=$(chat 'أبغى عطر هدية لزوجتي، ميزانيتي 300 ريال')
check "recommendation: 200" '[ "$status" = 200 ]'
check_json "recommendation: reply has product cards [[p01]] [[p14]] [[p03]] and chips" \
  'str_contains($j["text"], "[[p01]]") && str_contains($j["text"], "[[p14]]") && str_contains($j["text"], "[[p03]]") && str_contains($j["text"], "[[chips:")'
check_json "recommendation: one search step with the filters and the result count" \
  '$j["steps"] === [["k" => "search", "f" => ["for" => "women", "max_price" => 300, "occasion" => "gift"], "c" => 10]] && $j["actions"] === []'
check "recommendation: tools and the static prompt are cached; per-request part in a second block" \
  'last_mock | php -r "\$r = json_decode(stream_get_contents(STDIN), true)[\"body\"];
     exit((\$r[\"system\"][0][\"cache_control\"][\"type\"] === \"ephemeral\" && !isset(\$r[\"system\"][1][\"cache_control\"])
       && (\$r[\"cache_control\"][\"type\"] ?? \"\") === \"ephemeral\" && str_contains(\$r[\"system\"][1][\"text\"], \"on the store website\")
       && !str_contains(\$r[\"system\"][0][\"text\"], \"Today is\") && str_contains(\$r[\"system\"][1][\"text\"], \"Today is \" . (new DateTime(\"now\", new DateTimeZone(\"Asia/Riyadh\")))->format(\"D M d Y\"))) ? 0 : 1);"'
check "recommendation: fast = claude-haiku-5-5 at low effort, max_tokens 700, key sent" \
  'last_mock | php -r "\$l = json_decode(stream_get_contents(STDIN), true); \$r = \$l[\"body\"];
     exit((\$l[\"key_sent\"] && \$l[\"version\"] === \"2026-10-21\" && \$r[\"model\"] === \"claude-haiku-5-5\" && \$r[\"max_tokens\"] === 700 && \$r[\"output_config\"][\"effort\"] === \"low\") ? 0 : 1);"'
check "recommendation: the cached prompt has the Arabic grammar rule and its examples" \
  'last_mock | php -r "\$s = json_decode(stream_get_contents(STDIN), true)[\"body\"][\"system\"][0][\"text\"];
     exit((str_contains(\$s, \"Arabic grammar: make every pronoun\") && str_contains(\$s, \"تحب ريحته تكون زهرية ناعمة\")) ? 0 : 1);"'
check "recommendation: the cached prompt has the selling rules (no repeats, honest when nothing else fits)" \
  'last_mock | php -r "\$s = json_decode(stream_get_contents(STDIN), true)[\"body\"][\"system\"][0][\"text\"];
     exit((str_contains(\$s, \"Sell like an experienced perfume seller\") && str_contains(\$s, \"Never offer a product twice as a new choice\")
       && str_contains(\$s, \"هذا كل اللي عندنا من العطور الشرقية بحدود 300 ريال\")) ? 0 : 1);"'

status=$(chat 'وين طلبي رقم 1042؟' wa smart '[{"id":"p03","qty":2}]' '["T-1001"]' T-1002)
check "order 1042: 200" '[ "$status" = 200 ]'
check_json "order 1042: shipped, with the order step" \
  '$j["steps"] === [["k" => "order", "n" => "1042", "st" => "shipped"]] && str_contains($j["text"], "1042") && str_contains($j["text"], "GH-58817342")'
check "order 1042: Arabic-Indic digits from the model were normalised; dates are Riyadh days" \
  'last_mock | php -r "\$r = json_decode(stream_get_contents(STDIN), true)[\"body\"]; \$res = json_decode(end(\$r[\"messages\"])[\"content\"][0][\"content\"], true);
     \$t = new DateTimeImmutable(\"today\", new DateTimeZone(\"Asia/Riyadh\"));
     exit((\$res[\"found\"] === true && \$res[\"order_number\"] === \"1042\" && \$res[\"expected_delivery\"] === \$t->modify(\"+2 days\")->format(\"Y-m-d\")
       && \$res[\"shipped_on\"] === \$t->modify(\"-1 day\")->format(\"Y-m-d\") && \$res[\"total_sar\"] === 289) ? 0 : 1);"'
check "order 1042: smart = claude-sonnet-5-5 at low effort, max_tokens 700; WhatsApp channel, cart and open tickets are in the prompt" \
  'last_mock | php -r "\$r = json_decode(stream_get_contents(STDIN), true)[\"body\"]; \$s = \$r[\"system\"][1][\"text\"];
     exit((\$r[\"model\"] === \"claude-sonnet-5-5\" && str_contains(\$s, \"WhatsApp Business number\") && str_contains(\$s, \"Cart: Silk Musk x2 (total 398 SAR).\")
       && str_contains(\$s, \"Open staff tickets: T-1001.\") && \$r[\"output_config\"][\"effort\"] === \"low\" && \$r[\"max_tokens\"] === 700) ? 0 : 1);"'

status=$(chat 'أضف ورد الطائف للسلة' web fast '[{"id":"p01","qty":1}]')
check_json "add to cart: action and step for p01 (model sent \" P01 \")" \
  '$j["actions"] === [["type" => "add_to_cart", "product_id" => "p01", "quantity" => 1]] && $j["steps"] === [["k" => "cart", "id" => "p01"]]'
check_json "add to cart: the model saw the updated cart total" 'str_contains($j["text"], "578")'

status=$(chat 'أبغى أكلم موظف' web fast '[]' '["T-1001","T-1002"]' T-1003)
check_json "handoff: action with the ticket id the page asked for" \
  '$j["actions"][0]["type"] === "handoff" && $j["actions"][0]["ticket_id"] === "T-1003" && $j["actions"][0]["summary"] !== "" && $j["steps"] === [["k" => "hand", "id" => "T-1003"]] && str_contains($j["text"], "T-1003")'

# "another option" after earlier suggestions: [[p16]] [[p17]] were shown, [[p16]] only in the second run
another() { # shown-markers -> a 3-turn chat ending with "أبغى خيار ثاني"
  post "$(php -r 'echo json_encode(["messages" => [
      ["role" => "user", "content" => "أبغى عطر شرقي لزوجتي بحدود 300"],
      ["role" => "assistant", "content" => "عندي لك خيارات حلوة.\n" . $argv[1] . "\n[[chips: أبغى خيار ثاني]]"],
      ["role" => "user", "content" => "أبغى خيار ثاني"]],
    "channel" => "web", "speed" => "fast", "cart" => [], "open_tickets" => [], "next_ticket_id" => "T-1001", "wa_button" => false],
    JSON_UNESCAPED_UNICODE);' -- "$1")"
}
status=$(another $'[[p16]]\n[[p17]]')
check_json "another option, nothing new left: says so, offers another family or a higher price, repeats no card" \
  'str_contains($j["text"], "هذا كل اللي عندنا") && str_contains($j["text"], "شرقي بسعر أعلى") && !preg_match("/\[\[p\d\d\]\]/", $j["text"])'
check "another option: shown products are listed in the prompt; search marks them and says nothing new fits" \
  'last_mock | php -r "\$r = json_decode(stream_get_contents(STDIN), true)[\"body\"]; \$res = json_decode(end(\$r[\"messages\"])[\"content\"][0][\"content\"], true);
     exit((str_contains(\$r[\"system\"][1][\"text\"], \"Already shown in this chat: Saffron Rose / ورد وزعفران (p16), Amber Musk / مسك العنبر (p17).\")
       && \$res[\"count\"] === 2 && \$res[\"new_count\"] === 0 && \$res[\"results\"][0][\"shown_before\"] === true && \$res[\"results\"][1][\"shown_before\"] === true
       && str_contains(\$res[\"note\"], \"already shown\")) ? 0 : 1);"'
status=$(another '[[p16]]')
check_json "another option, one new left: offers the product not seen yet (p17), not p16 again" \
  'str_contains($j["text"], "[[p17]]") && !str_contains($j["text"], "[[p16]]")'

status=$(chat 'MOCK_PARALLEL' web fast '[{"id":"p01","qty":13}]' '[]' T-1007)
check_json "parallel tools: p01 capped at stock, p08 out of stock is a tool error, one handoff per reply" \
  '$j["actions"] === [["type" => "add_to_cart", "product_id" => "p01", "quantity" => 2], ["type" => "handoff", "ticket_id" => "T-1007", "reason" => "Refund", "summary" => "Customer wants a refund for order 1044."]]
   && str_contains($j["text"], "Results: ok,error,ok,ok") && str_contains($j["text"], "Amber Vanilla is out of stock") && str_contains($j["text"], "Already handed over")'

status=$(chat 'MOCK_EMPTYINPUT')
check_json "empty tool input {} goes back to the API as an object" '$j["text"] === "Found 19 products." && $j["steps"][0]["f"] === []'

before=$(mock_calls)
status=$(chat 'MOCK_LOOP')
check_json "tool loop stops after 5 model calls; the last call must answer" 'str_contains($j["text"], "Final answer after 4 tool rounds") && count($j["steps"]) === 4'
check "tool loop: exactly 5 calls, tool_choice none only on the 5th" \
  '[ $(( $(mock_calls) - before )) = 5 ] && tail -n 5 "$MOCK_LOG" | php -r "\$n = []; while ((\$l = fgets(STDIN)) !== false) \$n[] = json_decode(\$l, true)[\"body\"][\"tool_choice\"][\"type\"] ?? \"-\"; exit(\$n === [\"-\", \"-\", \"-\", \"-\", \"none\"] ? 0 : 1);"'

status=$(chat 'MOCK_TRUNC')
check_json "max_tokens reply is returned with truncated:true" '$j["truncated"] === true && $j["text"] !== ""'
status=$(chat 'MOCK_REFUSE')
check_json "refusal comes back as refused:true with no text" '$j["refused"] === true && $j["text"] === "" && $j["actions"] === []'

echo "== Claude API errors"
before=$(mock_calls)
status=$(chat 'MOCK_529')
check "overloaded (529) twice: retried once, then 503 busy" '[ "$status" = 503 ] && [ $(( $(mock_calls) - before )) = 2 ]'
check_json "busy body is only {error: busy}" '$j === ["error" => "busy"]'
check "busy sends Retry-After" 'grep -qi "^retry-after:" "$TMP/headers"'
status=$(chat 'MOCK_500_ONCE')
check "a single 500 is retried and the reply still arrives" '[ "$status" = 200 ]'
status=$(chat 'MOCK_CREDIT')
check_json "credit balance too low: 503 demo paused, no raw message" '$j === ["error" => "paused"]'
check "credit balance too low: status 503" '[ "$status" = 503 ]'
status=$(chat 'MOCK_SPEND')
check_json "spend limit reached: demo paused" '$j === ["error" => "paused"]'
status=$(chat 'MOCK_AUTH')
check_json "invalid key: demo paused" '$j === ["error" => "paused"]'
status=$(chat 'MOCK_RATE')
check_json "Claude API rate limit (429): busy" '$j === ["error" => "busy"]'
status=$(chat 'MOCK_BADREQ')
check "other API errors: 502 with a generic error" '[ "$status" = 502 ] && [ "$(cat "$TMP/body")" = "{\"error\":\"error\"}" ]'

echo "== request checks"
check "GET chat.php: 405" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$APP/api/chat.php")" = 405 ]'
check "wrong Origin: 403" '[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST "$APP/api/chat.php" -H "Content-Type: application/json" -H "Origin: https://evil.example" --data-binary "$(body hello)")" = 403 ]'
check "look-alike Origin: 403" '[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST "$APP/api/chat.php" -H "Content-Type: application/json" -H "Origin: http://127.0.0.1:$APP_PORT.evil.example" --data-binary "$(body hello)")" = 403 ]'
check "no Origin header: 403" '[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST "$APP/api/chat.php" -H "Content-Type: application/json" --data-binary "$(body hello)")" = 403 ]'
check "not JSON content type: 415" '[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST "$APP/api/chat.php" -H "Content-Type: text/plain" -H "Origin: $APP" --data-binary "$(body hello)")" = 415 ]'
check "body over 32 KB: 413" '[ "$(post "{\"messages\":[{\"role\":\"user\",\"content\":\"$(head -c 40000 /dev/zero | tr "\0" a)\"}]}")" = 413 ]'
check "invalid JSON: 400" '[ "$(post "{nope")" = 400 ]'
check "role system: 400" '[ "$(post "{\"messages\":[{\"role\":\"system\",\"content\":\"hi\"}]}")" = 400 ]'
check "17 messages: 400" '[ "$(post "$(turns 17)")" = 400 ]'
check "16 messages: accepted" '[ "$(post "$(turns 16)")" = 200 ]'
check "user message over 1000 characters: 400" '[ "$(chat "$(printf "ع%.0s" $(seq 1 1001))")" = 400 ]'
check "user message of 1000 Arabic characters: accepted" '[ "$(chat "$(printf "ع%.0s" $(seq 1 1000))")" = 200 ]'
check "assistant message over 2000 characters: 400" '[ "$(post "{\"messages\":[{\"role\":\"user\",\"content\":\"hi\"},{\"role\":\"assistant\",\"content\":\"$(head -c 2001 /dev/zero | tr "\0" a)\"},{\"role\":\"user\",\"content\":\"hi\"}]}")" = 400 ]'
check "last message from the assistant: 400" '[ "$(post "{\"messages\":[{\"role\":\"user\",\"content\":\"hi\"},{\"role\":\"assistant\",\"content\":\"hello\"}]}")" = 400 ]'
check "unknown product id in the cart: 400" '[ "$(chat hi web fast "[{\"id\":\"p99\",\"qty\":1}]")" = 400 ]'
check "bad ticket id: 400" '[ "$(chat hi web fast "[]" "[\"T-12\"]")" = 400 ]'
check "next ticket id already open: 400" '[ "$(chat hi web fast "[]" "[\"T-1001\"]" T-1001)" = 400 ]'
check "a model the page is not allowed to pick: 400" '[ "$(chat hi web claude-opus)" = 400 ]'
check "unknown field: 400" '[ "$(post "{\"messages\":[{\"role\":\"user\",\"content\":\"hi\"}],\"model\":\"x\"}")" = 400 ]'
check "JSON responses send Cache-Control: no-store" 'grep -qi "^cache-control: no-store" "$TMP/headers"'

echo "== limits"
STORAGE="$TMP/storage-limits"; write_config ""
sed -i "s/'ip_per_hour' => 1000/'ip_per_hour' => 2/" "$CONFIG"
chat hi >/dev/null; chat hi >/dev/null
status=$(chat hi)
check "third message within the hour from one visitor: 429" '[ "$status" = 429 ]'
check_json "429 says when to try again" '$j["error"] === "rate_limited" && $j["scope"] === "visitor" && $j["retry_after"] > 3500 && $j["retry_after"] <= 3600'
check "429 sends Retry-After" 'grep -qi "^retry-after: [0-9]" "$TMP/headers"'
STORAGE="$TMP/storage-site"; write_config ""
sed -i "s/'site_per_day' => 1000/'site_per_day' => 1/" "$CONFIG"
chat hi >/dev/null
status=$(chat hi)
check_json "site-wide daily limit: 429 with scope site" '$j["error"] === "rate_limited" && $j["scope"] === "site"'
check "rate-limit files hold salted hashes, never the IP" '! grep -rq "127.0.0.1" "$TMP/storage-limits" && ls "$TMP/storage-limits/limits" | grep -q "^visitor-[0-9a-f]\{40\}\.json$"'
check "storage folder is created with mode 700" '[ "$(stat -c %a "$TMP/storage-limits")" = 700 ]'

echo "== config"
STORAGE="$TMP/storage"; write_config ""
mv "$CONFIG" "$CONFIG.off"
check "missing config: chat 503 paused" '[ "$(chat hi)" = 503 ] && [ "$(cat "$TMP/body")" = "{\"error\":\"paused\"}" ]'
check "missing config: health 503" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$APP/api/health.php")" = 503 ]'
mv "$CONFIG.off" "$CONFIG"
write_config "'enabled' => false,"
before=$(mock_calls)
check "enabled = false: chat 503 paused" '[ "$(chat hi)" = 503 ] && [ "$(cat "$TMP/body")" = "{\"error\":\"paused\"}" ]'
check "enabled = false: health 503" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$APP/api/health.php")" = 503 ]'
check "enabled = false: Claude is not called" '[ "$(mock_calls)" = "$before" ]'
write_config "'anthropic_base_url' => 'http://example.com',"
check "plain-http API address outside localhost is refused (503)" '[ "$(chat hi)" = 503 ]'
write_config ""

# private/ghaim-config.php one level above public_html, found without GHAIM_CONFIG
WALK="$TMP/home/domains/example.com"
mkdir -p "$WALK/public_html/shop" "$WALK/private"
cp -r index.html favicon.svg api data "$WALK/public_html/shop/"
sed -e "s#'storage_dir' => '[^']*',#'storage_dir' => '',#" "$CONFIG" > "$WALK/private/ghaim-config.php"
PHP_EXTRA="-d open_basedir=$WALK -d error_log=$WALK/php-errors.log" serve "$WALK_PORT" "$WALK/public_html/shop"
check "config found at domains/<domain>/private/ghaim-config.php (open_basedir set)" '[ "$(curl -s "http://127.0.0.1:$WALK_PORT/api/health.php")" = "{\"ok\":true}" ]'
check "default storage folder is ghaim-storage next to the config, mode 700" '[ "$(stat -c %a "$WALK/private/ghaim-storage" 2>/dev/null)" = 700 ]'
mv "$WALK/private" "$WALK/public_html/private"
check "a config inside public_html is ignored (503)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$WALK_PORT/api/health.php")" = 503 ]'
check "walking up past open_basedir raises no warnings" '! grep -q -E "open_basedir restriction|PHP (Warning|Notice)" "$WALK/php-errors.log"' "$(grep -E 'PHP (Warning|Notice)' "$WALK/php-errors.log" 2>/dev/null | head -3)"
check "a missing config is explained in the error log" 'grep -q "ghaim: no config found" "$WALK/php-errors.log"'
mv "$WALK/public_html/private" "$WALK/private"
cp "$WALK/private/ghaim-config.php" "$WALK/public_html/shop/config.local.php"
rm "$WALK/private/ghaim-config.php"
check "config.local.php in the app folder works under php -S" '[ "$(curl -s "http://127.0.0.1:$WALK_PORT/api/health.php")" = "{\"ok\":true}" ]'

echo "== logs and secrets"
check "usage log: header and one line per answered request" \
  'head -n 1 "$STORAGE"/usage-*.csv | grep -qx "time,model,input_tokens,output_tokens,cache_write_tokens,cache_read_tokens,status" && grep -q ",claude-haiku-5-5,.*,ok$" "$STORAGE"/usage-*.csv && grep -q ",busy$" "$STORAGE"/usage-*.csv'
check "usage log has no message text and no IP" '! grep -q -e "ورد" -e "MOCK" -e "127.0.0.1" "$STORAGE"/usage-*.csv'
check "the API key never reaches a response" '! grep -q "$KEY" "$TMP/all-responses"'
check "no PHP warnings, notices or deprecations" '! grep -q -E "PHP (Warning|Notice|Deprecated|Fatal)" "$PHPLOG"' "$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$PHPLOG" | head -3)"

echo "== browser"
if command -v node >/dev/null && node -e "require('playwright')" 2>/dev/null; then
  STORAGE="$TMP/storage-browser"; write_config ""
  if out=$(APP_URL="$APP/" node tests/browser-check.js 2>&1); then ok "browser check (tests/browser-check.js)"; else fail "browser check" "$(echo "$out" | grep -v '  ok ' | tail -5)"; fi
else
  echo "  skip browser check (needs Node and Playwright)"
fi

echo
if [ "$FAILED" -gt 0 ]; then echo "smoke test: $FAILED failed, $PASSED passed"; exit 1; fi
echo "smoke test: all $PASSED checks passed"
