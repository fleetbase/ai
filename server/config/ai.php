<?php

/**
 * -------------------------------------------
 * Fleetbase AI Configuration
 * -------------------------------------------.
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix'          => env('AI_API_PREFIX', 'ai'),
            'internal_prefix' => env('AI_INTERNAL_API_PREFIX', 'int'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge base
    |--------------------------------------------------------------------------
    |
    | Fleetbase AI answers product how-to questions from the official Fleetbase
    | documentation. Pages are discovered from the sitemap, tagged with the
    | audience allowed to see them, and indexed for full-text search.
    |
    */
    'knowledge' => [
        'docs' => [
            'enabled'          => env('AI_DOCS_ENABLED', true),
            'sitemap_url'      => env('AI_DOCS_SITEMAP_URL', 'https://fleetbase.io/sitemap.xml'),
            'base_path'        => '/docs',
            'request_delay_ms' => (int) env('AI_DOCS_REQUEST_DELAY_MS', 250),
            'timeout'          => 20,

            // Documentation paths (relative to /docs) that are never indexed.
            'exclude' => [
                'ui',
                'contributing',
                'community/discord',
                'community/github-discussions',
                'community/support-plans',
                'community/licensing',
            ],

            // Paths that only system administrators ($user->type === 'admin') may see.
            'system_admin' => [
                'platform/system-setup',
                'platform/quickstart/running-locally',
                'platform/quickstart/deploy-in-cloud',
                'platform/quickstart/development-setup',
                'platform/quickstart/infrastructure-sizing',
                'platform/getting-started/architecture',
                'cli',
            ],

            // Paths for developers: system administrators or users who can access the Developers console.
            'developer' => [
                'api',
                'extension-development',
                'platform/developer-console',
                'platform/recipes/build-a-custom-integration',
                'platform/recipes/connect-your-first-webhook',
                'platform/recipes/set-up-real-time-tracking',
                'fleet-ops/navigator-app',
                'storefront/app',
                'storefront/customers/authentication',
                'ledger/recipes/payment-gateway-driver',
            ],
        ],

        'snapshot_path' => env('AI_DOCS_SNAPSHOT_PATH'),
    ],
];
