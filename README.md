# Ghaim Perfumes (عطور غَيم) · AI shopping assistant demo

مساعد تسوق ذكي يتحدث العربية لمتجر عطور سعودي تجريبي: يقترح العطور المناسبة، ويتابع الطلبات، ويضيف إلى السلة، ويحوّل المحادثة إلى فريق الخدمة.
يعمل على Claude عبر خادم PHP صغير يحفظ المفتاح بعيداً عن المتصفح، مع معاينة لقناة واتساب وصندوق لفريق خدمة العملاء.

**Live demo: [shop.awsankhaled.com](https://shop.awsankhaled.com)**

An Arabic-first shopping assistant for a sample perfume store in Saudi Arabia. Customers chat in Gulf Arabic, Modern Standard Arabic or English, and the assistant:

- **recommends perfumes** by taste, budget, occasion and who they are for, shown as product cards with an add-to-cart button;
- **tracks orders** (try 1042 to 1046), including numbers typed with Arabic digits (١٠٤٢);
- **adds products to the cart** when the customer asks;
- **hands the chat to a person** for complaints, refunds, damaged items or payment problems, with a ready summary in the staff inbox.

The same assistant runs in three tabs: the store **website**, a **WhatsApp preview** (shorter replies, WhatsApp look) and the **staff inbox** where handed-over chats arrive with a summary and the full conversation. The page switches between Arabic (right to left) and English.

| Website chat (desktop) | Staff inbox |
| --- | --- |
| ![Chat on a laptop: a gift question answered with three product cards](docs/screenshot-desktop.png) | ![Staff inbox with a handed-over chat, its reason, summary and transcript](docs/screenshot-staff.png) |

| Website chat (phone) | WhatsApp preview (phone) |
| --- | --- |
| <img src="docs/screenshot-phone.png" alt="The chat on a phone" width="320"> | <img src="docs/screenshot-whatsapp.png" alt="The WhatsApp preview on a phone, with a handover card" width="320"> |

<sub>Screenshots taken locally against the test mock (`tests/mock-anthropic.php`), so the replies are canned.</sub>

## How it works

```mermaid
flowchart LR
    B["Browser<br/>index.html"] -- "POST api/chat.php<br/>last 16 turns, channel, cart, tickets" --> C["api/chat.php<br/>checks, limits, system prompt"]
    C -- "Messages API<br/>prompt + 4 tools" --> A["Claude"]
    A -- "tool_use" --> C
    C -- "search_products<br/>get_order_status" --> D[("data/products.json<br/>data/orders.json")]
    C -- "tool_result<br/>(up to 5 model calls)" --> A
    A -- "final reply" --> C
    C -- "text, actions, steps" --> B
```

1. **The page** (`index.html`, one file with inline CSS and JS) keeps the chat, cart and staff tickets in the browser. Only the settings (language, reply speed, steps on or off, WhatsApp number) are saved, in `localStorage`. For each message it posts the last 16 turns with the channel, reply speed, cart, open tickets and the next ticket id to `api/chat.php`. The browser never sends prompt text and cannot pick a model.
2. **`api/chat.php`** checks the Origin header and the request, counts the message against the limits, and builds the system prompt on the server in two blocks. The first one holds the instructions and store policies; it is cached together with the four tools. The second holds what changes per request: the channel, today's date in Saudi time, the cart and the open tickets.
3. **The tool loop** calls the Claude Messages API with cURL. `search_products` and `get_order_status` are answered from the files in `data/`. `add_to_cart` and `handoff_to_staff` are checked on the server (the product exists and is in stock; one handover per reply, using the ticket id the page sent) and come back to the page as actions. A message gets at most 5 model calls; the last allowed call has to answer in text.
4. **The reply** is `{text, actions, steps}`. While waiting, the page shows typing dots. It then shows the steps one by one (about 250 ms apart), applies the actions to the cart and the staff inbox, and renders the text with its product cards (`[[p01]]`) and quick replies (`[[chips: a | b]]`).

`api/health.php` answers `{"ok":true}` when the private config is found, enabled and has a key. It never calls Claude. The page uses it for the green status dot.

## Project structure

```
.
├── index.html              the whole front end: HTML, CSS and JS in one file
├── favicon.svg
├── assets/og-image.png     link preview image (Open Graph)
├── data/
│   ├── products.json       catalogue: products, scent families, audiences, occasions (public)
│   └── orders.json         sample orders, read only by the server (blocked from the web)
├── api/
│   ├── chat.php            POST: one assistant reply, with the tool loop
│   ├── health.php          GET: {"ok":true} when the server is configured
│   └── lib/                server code (blocked from the web)
│       ├── bootstrap.php     error handling, JSON responses, small helpers
│       ├── config.php        finds the private config, prepares the storage folder
│       ├── request.php       Origin check and request validation
│       ├── store.php         catalogue loading, search_products, get_order_status
│       ├── prompt.php        system prompt, store policies, the four tools
│       ├── claude.php        Messages API calls with cURL, retries, the tool loop
│       └── limits.php        rate limits and the monthly usage log
├── config.example.php      template for the private config
├── tests/                  mock Claude API, smoke test, browser check (blocked from the web)
├── docs/                   screenshots for this README
├── .htaccess               access rules for LiteSpeed and Apache
└── .gitignore
```

Plain PHP 8.1+ with the cURL extension. No SDK, no Composer, no build step.

## Deploying on Hostinger

The site is plain files: Hostinger's Git deployment copies the `main` branch as it is into the subdomain's folder. The API key never goes into that folder.

1. **PHP version.** In hPanel open **Advanced → PHP Configuration** for the website and choose PHP 8.1 or newer (8.3 is fine). The cURL extension is on by default.
2. **Subdomain.** Create the `shop` subdomain (**Domains → Subdomains**). Hostinger puts its files in a folder inside `public_html`, for example `domains/awsankhaled.com/public_html/shop`. The folder must be empty for the first Git deployment.
3. **Git deployment.** In **Advanced → Git**, add the repository `https://github.com/Awsan01/ghaim-shopping-assistant.git`, branch `main`, and set the install path to the subdomain folder (`shop`). For a private repository, add the SSH key that hPanel shows there as a deploy key in GitHub (**Settings → Deploy keys**) and use the `git@github.com:` address. Press **Deploy**. To deploy on every push, copy the auto-deployment webhook URL from the same page into GitHub (**Settings → Webhooks**).
4. **Private config.** Open **Files → File Manager** and go to `domains/awsankhaled.com/`, the folder that contains `public_html`. Create a folder named `private`, and in it a file named `ghaim-config.php`. Paste the content of [`config.example.php`](config.example.php), then fill in:
   - `anthropic_api_key`: a key from the [Claude Console](https://platform.claude.com/). Use a separate key for this demo, so you can limit or revoke it on its own.
   - `ip_salt`: any long random string (or leave it empty and one is generated).

   The final paths look like this:

   ```
   domains/awsankhaled.com/
   ├── private/
   │   ├── ghaim-config.php     ← your key, outside the web root
   │   └── ghaim-storage/       ← created by the app: rate limits and usage logs
   └── public_html/
       └── shop/                ← this repository
   ```

   The app looks for `private/ghaim-config.php` in the folders above itself (up to 6 levels), skipping `public_html` and everything inside it. If hPanel gave the subdomain its own folder (`domains/shop.awsankhaled.com/public_html`), put `private/` next to that `public_html` instead. If your files live elsewhere, set the `GHAIM_CONFIG` environment variable to the config's full path.
5. **HTTPS.** Under **Security → SSL**, make sure the subdomain has a certificate and turn on **Force HTTPS**.
6. **Check it.**
   - `https://shop.awsankhaled.com/api/health.php` shows `{"ok":true}`;
   - `https://shop.awsankhaled.com/.git/config`, `/data/orders.json`, `/README.md` and `/api/lib/config.php` all answer 403;
   - the page shows a green dot next to the store name, and a question gets a reply.
7. **Spend limit.** In the Claude Console, set a monthly spend limit for the workspace that owns the key. If the limit or the credit runs out, the demo shows "paused" instead of an error.

### Pausing the demo

Edit `private/ghaim-config.php` in the File Manager and set `'enabled' => false`. From the next request on, every API call answers 503 and visitors see "The demo is paused for now"; Claude is not called. Set it back to `true` to resume. You can also lower the `limits` instead of pausing.

### Usage log

Each answered message adds one line to `private/ghaim-storage/usage-YYYY-MM.csv`:

```
time,model,input_tokens,output_tokens,cache_write_tokens,cache_read_tokens,status
2026-11-19T22:45:19+03:00,claude-haiku-5-5,280,120,1650,1650,ok
```

It holds token counts and a status (`ok`, `truncated`, `refused`, `busy`, `paused` or `error`), never message text or IP addresses.

## Local development

```bash
cp config.example.php config.local.php
```

Put your API key in `config.local.php`. It is ignored by git and read only by PHP's built-in server. Then start the server from the project folder:

```bash
php -S localhost:8000
```

Open http://localhost:8000. Rate limits and logs go to `./ghaim-storage` (also ignored by git).

To work on the page without an API key or spending tokens, run the test mock and point `config.local.php` at it with `'anthropic_api_key' => 'local'` and `'anthropic_base_url' => 'http://127.0.0.1:8090'`:

```bash
php -S 127.0.0.1:8090 tests/mock-anthropic.php
```

### Tests

```bash
tests/smoke.sh
```

It runs `php -l` on every PHP file, starts the app and the mock Claude API with `php -S`, and checks, among others:

- a recommendation with product cards, the lookup of order 1042, the add-to-cart action, the handoff action with its ticket id;
- the 5-call tool loop, retries on 529 and 5xx, and how API errors map to busy, paused and error;
- invalid input → 400, too many messages → 429, wrong Origin → 403, missing config or `enabled => false` → 503;
- that the config is found above `public_html` with `open_basedir` set, that the usage log has no message text, and that the key never reaches a response.

With Node and [Playwright](https://playwright.dev) installed it also runs `tests/browser-check.js`, which drives the page in headless Chromium. It checks for console errors, a reply with product cards, the cart badge, the WhatsApp tab, a ticket in the staff inbox, the language switch, the busy message with its retry button and the stop button.

## Editing the store

**Products: `data/products.json`.** The page and the server both read this file.

- `products`: one entry per product.
  - `id`: `p` plus two digits, like `p16`; the reply marker `[[p16]]` uses it.
  - `ar` and `en`: names; `notes_ar` and `notes_en`: scent notes.
  - `fam`: a key of `families`; `for`: `women`, `men` or `unisex`.
  - `sz`: `[100, "ml"]`, `[50, "g"]` or `null`; `price`: in SAR.
  - `hours`: how long it lasts, `[8, 10]`, or `null`.
  - `stock`: `0` shows "sold out", 5 or less shows "only N left".
  - `tags`: occasions, keys of `occasions`.
- `families`: Arabic and English names, plus the two bottle colours (`c`) and bottle shape (`s`, 0 to 3) used for the card picture.
- `who` and `occasions`: labels in both languages. Their keys are also the values the search tool offers Claude.
- `sample_orders`: the order numbers shown as "Order numbers to try" on the page.

**Orders: `data/orders.json`.** One entry per order number, for example `"1042"`.

- `items`: `[product id, quantity]` pairs.
- `status`: `processing`, `shipped`, `out_for_delivery` or `delivered`.
- `placed`, `shipped`, `eta`, `delivered`: day offsets from today in Saudi time, so `-2` means two days ago and `2` means in two days. The dates never go stale.
- `city`, and an optional `note`.

**Policies and instructions: `api/lib/prompt.php`.** Shipping, payment, returns, gift wrapping and opening hours are in `GHAIM_POLICIES` at the top of the file. The rest of the assistant's instructions are in `ghaim_static_prompt()` just below.

After editing, run `tests/smoke.sh`: it also checks that the JSON is valid and that every order uses known products.

## Models and costs

| Reply speed (Settings) | Model in `config.example.php` |
| --- | --- |
| Fast (default) | `claude-haiku-5-5` |
| Smarter, slower | `claude-sonnet-5-5` |

The models are set in the private config. The browser only says `fast` or `smart`.

What drives the cost:

- **Calls per message.** Each message takes 1 to 5 model calls: a tool round (search, order lookup, cart, handover) adds a call. A typical recommendation takes two.
- **Reply size.** `max_tokens` is 700 per call. The fast model (Haiku) runs at medium effort, so it thinks briefly before answering and checks its Arabic grammar; it gets 400 extra tokens for that thinking. The smart model (Sonnet) writes well at low effort and keeps the plain 700.
- **Caching.** The tools and the fixed instructions (about 2,000 tokens) are cached with prompt caching. Later calls within 5 minutes read them from the cache at a fraction of the input price. The conversation is cached automatically during the tool loop.
- **Limits.** Per visitor IP: 30 messages per hour and 100 per day. Whole site: 500 messages per day. So the daily worst case is 500 messages × 5 calls.

Prices change, so this README does not list them. See Anthropic's [pricing page](https://platform.claude.com/docs/en/about-claude/pricing), and multiply by the token counts in the usage log for real numbers. A spend limit in the Claude Console is the final safety net.

## Security notes

- **The API key stays on the server**, in a file outside `public_html`. It is never sent to the browser. Responses contain no raw API errors, no stack traces and no PHP warnings; details go to the server's error log.
- **`.htaccess`** turns off directory listings and blocks dotfiles (including `.git`), Markdown files, `config*.php`, `data/orders.json`, `api/lib/` and `tests/`. It uses mod_rewrite where available, with mod_alias and file-name rules as fallbacks, and `api/lib/` and `tests/` have their own deny-all files. The rules were tested on Apache with mod_rewrite, with only mod_alias, and with neither.
- **Requests are checked strictly.**
  - A POST needs an Origin header that matches the host.
  - The body must be JSON, at most 32 KB.
  - At most 16 messages; user messages up to 1,000 characters, assistant messages up to 2,000.
  - Known product ids only, and ticket ids like `T-1234`.
  - Every JSON response is sent with `Cache-Control: no-store`.
- **Cost limits** count messages per visitor and for the whole site in small files guarded with `flock`. Visitor IPs are stored only as salted hashes, and old counter files are removed.
- **Limited reach.** The assistant can only read the catalogue and the sample orders. Its two other tools only change the visitor's own page (cart and staff inbox), so a visitor who tries to trick it cannot affect other visitors or any real system.
- **Privacy.** Messages are sent to the Claude API to produce a reply. The server stores no message text, and the settings sheet asks visitors not to share personal details.
- **Behind a CDN or proxy**, `REMOTE_ADDR` can be the proxy's address. The per-visitor limits then apply to everyone together, while the site-wide limit still holds.

## Troubleshooting

- **Red dot or "paused" on every message.** Open `api/health.php`. A 503 means:
  - the config was not found (check that the path is `domains/<domain>/private/ghaim-config.php`);
  - or `enabled` is `false`;
  - or the key is empty;
  - or the storage folder cannot be created next to the config.

  The server's PHP error log has a line starting with `ghaim:` that names the cause.
- **"The service is busy".** The Claude API was overloaded or rate limited, even after one retry. Visitors can press "Try again".
- **"The demo is paused" though `enabled` is true.** The API reported no credit, a reached spend limit, or an invalid key. Check the Claude Console.

---

Built by Awsan Khaled.
