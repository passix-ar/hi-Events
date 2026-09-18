<?php

// Added by Passix on 2026-09-11: AI assistant for organizers (read-only, tool-calling).
return [
    'enabled' => (bool)env('AI_ASSISTANT_ENABLED', false),
    // Tools that create data. Everything they create is a draft, never published.
    'writes_enabled' => (bool)env('AI_ASSISTANT_WRITES_ENABLED', true),
    'provider' => env('AI_ASSISTANT_PROVIDER', 'anthropic'),
    'model' => env('AI_ASSISTANT_MODEL', 'claude-sonnet-5'),
    'max_steps' => (int)env('AI_ASSISTANT_MAX_STEPS', 12),
    'max_tokens' => (int)env('AI_ASSISTANT_MAX_TOKENS', 2048),
    'request_timeout' => (int)env('AI_ASSISTANT_REQUEST_TIMEOUT', 45),
    'rate_limit_per_minute' => (int)env('AI_ASSISTANT_RATE_LIMIT_PER_MINUTE', 20),
    // Input + output tokens one account may spend per day. 0 disables the cap.
    'daily_token_limit' => (int)env('AI_ASSISTANT_DAILY_TOKEN_LIMIT', 300000),
    'help_docs' => [
        'path' => __DIR__ . '/../Resources/help-docs',
        'base_url' => env('AI_ASSISTANT_DOCS_URL', 'https://docs.getpassix.com'),
    ],
    // Proactive emails when a live event is behind on sales. Off by default.
    'alerts' => [
        'enabled' => (bool)env('AI_ASSISTANT_ALERTS_ENABLED', false),
        'days_ahead' => (int)env('AI_ASSISTANT_ALERTS_DAYS_AHEAD', 14),
        'min_sold_pct' => (int)env('AI_ASSISTANT_ALERTS_MIN_SOLD_PCT', 40),
    ],
    'max_history_messages' => 20,
    'max_message_length' => 4000,
];
