<?php
// Ghaim demo: server settings.
//
// Copy this file OUTSIDE the web root and fill in the API key of the provider you use. On Hostinger:
//   domains/<your-domain>/private/ghaim-config.php   (the folder next to public_html)
// The app finds it there by itself. Other places: point the GHAIM_CONFIG environment
// variable at the file. For local development with php -S only, you can save it in the
// project folder as config.local.php (ignored by git).
//
// Never commit the real file, and never put a key in any file inside public_html.
//
// A config written before the provider switch still works: without a 'provider' line the
// demo uses Gemini, so add gemini_api_key, or add 'provider' => 'claude' to stay on Claude.

return [
    // Which AI answers the customers: 'gemini' (Google Gemini API, the default) or 'claude'
    // (Claude API). Only the selected provider's key is needed and checked.
    'provider' => 'gemini',

    // ---- Gemini (provider 'gemini') -----------------------------------------------------
    // Your Gemini API key from Google AI Studio (aistudio.google.com > Get API key). A key
    // from a project without billing uses the free tier. It is sent in the x-goog-api-key
    // header, never in the URL.
    'gemini_api_key' => '',

    // The Gemini model behind each "Reply speed" setting. Both are on the free tier.
    'gemini_models' => [
        'fast' => 'gemini-3.1-flash-lite',
        'smart' => 'gemini-3.8-flash',
    ],

    // Gemini API address. Change it only for tests against a local mock.
    'gemini_base_url' => 'https://generativelanguage.googleapis.com',

    // ---- Claude (provider 'claude') -----------------------------------------------------
    // Your Claude API key (Claude Console > API keys), starts with sk-ant-.
    'anthropic_api_key' => '',

    // Optional. Sent as the anthropic-workspace-id header when set.
    'workspace_id' => '',

    // The Claude model behind each "Reply speed" setting. The browser can only ask for fast or smart.
    'models' => [
        'fast' => 'claude-haiku-5-5',
        'smart' => 'claude-sonnet-5-5',
    ],

    // Claude API address. Change it only for tests against a local mock.
    'anthropic_base_url' => 'https://api.anthropic.com',

    // ---- Both providers -----------------------------------------------------------------
    // Most tokens one model call may produce, thinking included. The models think before
    // answering (kept as low as each model allows) and that counts toward this cap, so keep
    // room for it: replies are short anyway, and only the tokens actually generated are
    // billed. Much lower values cut replies off ("the reply was cut off"), mostly in longer chats.
    'max_tokens' => 4096,

    // false pauses the demo: every API call answers 503 "demo paused". No AI provider is called.
    'enabled' => true,

    // Messages allowed per visitor IP address, and for the whole site. 0 blocks all.
    'limits' => [
        'ip_per_hour' => 30,
        'ip_per_day' => 100,
        'site_per_day' => 500,
    ],

    // Secret used to hash visitor IPs for the limits, so no raw IP is stored. Use a long
    // random string. Leave it empty and one is generated in the storage folder.
    'ip_salt' => '',

    // Optional. Folder for the rate-limit counters and the usage logs (usage-YYYY-MM.csv).
    // Empty: a ghaim-storage folder next to this file. Keep it outside public_html.
    'storage_dir' => '',
];
