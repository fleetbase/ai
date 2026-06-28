<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => env('AI_API_PREFIX', 'ai'),
            'internal_prefix' => env('AI_INTERNAL_API_PREFIX', 'int')
        ],
    ]
];
