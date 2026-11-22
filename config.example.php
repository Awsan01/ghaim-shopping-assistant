<?php
// Ghaim demo: server settings.
//
// Copy this file OUTSIDE the web root and fill in your API key. On Hostinger:
//   domains/<your-domain>/private/ghaim-config.php   (the folder next to public_html)
// The app finds it there by itself. Other places: point the GHAIM_CONFIG environment
// variable at the file. For local development with php -S only, you can save it in the
// project folder as config.local.php (ignored by git).
//
// Never commit the real file, and never put the key in any file inside public_html.

return [
    // Required. Your Claude API key (Claude Console > API keys), starts with sk-ant-.
    'anthropic_api_key' => '',

    // Optional. Sent as the anthropic-workspace-id header when set.
    'workspace_id' => '',

    // The model behind each "Reply speed" setting. The browser can only ask for fast or smart.
    'models' => [
        'fast' => 'claude-haiku-5-5',
        'smart' => 'claude-sonnet-5-5',
    ],

    // Most tokens one model call may produce, thinking included. Both models think before
    // answering (on by default) and that counts toward this cap, so keep room for it: replies
    // are short anyway, and only the tokens actually generated are billed. Much lower values
    // cut replies off ("the reply was cut off"), mostly in longer chats.
    'max_tokens' => 4096,

    // false pauses the demo: every API call answers 503 "demo paused". Claude is not called.
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

    // Claude API address. Change it only for tests against a local mock.
    'anthropic_base_url' => 'https://api.anthropic.com',
];
