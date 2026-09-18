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
    // Budgets in token equivalents (cache reads x0.1, cache writes x1.25, output x5);
    // roughly 1M equivalents = US$3 on Sonnet. 0 disables a budget.
    'daily_token_limit' => (int)env('AI_ASSISTANT_DAILY_TOKEN_LIMIT', 300000),          // per account/day  (~US$0.90)
    'monthly_token_limit' => (int)env('AI_ASSISTANT_MONTHLY_TOKEN_LIMIT', 3000000),     // per account/month (~US$9)
    'global_daily_token_limit' => (int)env('AI_ASSISTANT_GLOBAL_DAILY_TOKEN_LIMIT', 5000000), // whole platform/day (~US$15)
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
    'max_history_messages' => (int)env('AI_ASSISTANT_MAX_HISTORY_MESSAGES', 16),
    // Messages older than the last N are clipped to this many characters before
    // reaching the model.
    'history_recent_intact' => 6,
    'history_clip_length' => 700,
    'max_message_length' => 4000,
];
