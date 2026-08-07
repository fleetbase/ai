<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AnthropicProvider;
use Fleetbase\Ai\Services\LocalAIProvider;
use Fleetbase\Ai\Services\OpenAIProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::swap(new HttpFactory());
});

function aiProviderManager(): AiProviderManager
{
    return new AiProviderManager(new LocalAIProvider(), new OpenAIProvider(), new AnthropicProvider());
}

test('local provider summarizes prompts and reports token usage', function () {
    $provider = new LocalAIProvider();
    $result   = $provider->complete(new AiTask(['prompt' => 'Summarize delayed orders for dispatch']));

    expect($result['provider'])->toBe('local')
        ->and($result['model'])->toBe('fleetbase-local-preview')
        ->and($result['summary'])->toBe('Summarize delayed orders for dispatch')
        ->and($result['usage']['input_tokens'])->toBe(5)
        ->and($result['usage']['output_tokens'])->toBe(36)
        ->and($result['usage']['total_tokens'])->toBe(41)
        ->and($result['metadata']['mode'])->toBe('local-preview')
        ->and($provider->test())->toBe([
            'status'  => 'success',
            'message' => 'Local AI preview provider is available.',
        ]);
});

test('local provider handles empty prompts with a fallback summary', function () {
    $result = (new LocalAIProvider())->complete(new AiTask(['prompt' => '   ']));

    expect($result['summary'])->toBe('AI task')
        ->and($result['usage']['input_tokens'])->toBe(0)
        ->and($result['usage']['total_tokens'])->toBe(36);
});

test('provider manager normalizes config and routes test calls without respecting enabled flag', function () {
    $manager = aiProviderManager();

    $normalized = $manager->normalizeConfig([
        'enabled'       => true,
        'provider'      => 'missing',
        'default_model' => 'not-supported',
        'providers'     => [
            'openai' => ['api_key' => 'sk-test', 'base_url' => 'https://example.test/openai'],
        ],
    ]);

    expect($normalized['enabled'])->toBeTrue()
        ->and($normalized['provider'])->toBe('local')
        ->and($normalized['default_model'])->toBe('fleetbase-local-preview')
        ->and($normalized['providers']['openai']['api_key'])->toBe('sk-test')
        ->and($manager->providerFor(['enabled' => false, 'provider' => 'openai']))->toBeInstanceOf(LocalAIProvider::class)
        ->and($manager->providerFor(['enabled' => false, 'provider' => 'openai'], false))->toBeInstanceOf(OpenAIProvider::class)
        ->and($manager->modelFor(['enabled' => true, 'provider' => 'openai', 'default_model' => 'gpt-5.4']))->toBe('gpt-5.4')
        ->and($manager->test(['provider' => 'local'])['status'])->toBe('success');
});

test('provider manager delegates completion to the normalized provider', function () {
    $manager = aiProviderManager();

    $local = $manager->complete(new AiTask(['prompt' => 'Count active vehicles']), [], [
        'config' => [
            'enabled'  => false,
            'provider' => 'openai',
        ],
    ]);

    expect($local['provider'])->toBe('local')
        ->and($local['summary'])->toBe('Count active vehicles');
});

test('openai provider extracts nested output content and can test connectivity', function () {
    Http::fake([
        'https://fleetbase-openai.test/responses' => Http::response([
            'id'     => 'resp_nested',
            'status' => 'completed',
            'output' => [
                [
                    'content' => [
                        ['text' => 'Nested answer line one.'],
                        ['text' => 'Nested answer line two.'],
                    ],
                ],
            ],
            'usage'  => [
                'input_tokens'  => 7,
                'output_tokens' => 5,
                'total_tokens'  => 12,
            ],
        ]),
    ]);

    $config = [
        'default_model' => 'gpt-5.4',
        'providers'     => [
            'openai' => [
                'api_key'  => 'sk-test',
                'base_url' => 'https://fleetbase-openai.test',
            ],
        ],
    ];

    $provider = new OpenAIProvider();
    $result   = $provider->complete(new AiTask([
        'prompt'  => 'Summarize route work',
        'context' => ['route' => 'fleet-ops.orders'],
    ]), [['capability' => 'fleetbase.ai.temporal_context']], ['config' => $config]);
    $test     = $provider->test($config);

    expect($result['content'])->toBe("Nested answer line one.\nNested answer line two.")
        ->and($result['summary'])->toBe('Nested answer line one. Nested answer line two.')
        ->and($result['usage']['total_tokens'])->toBe(12)
        ->and($result['metadata']['response_id'])->toBe('resp_nested')
        ->and($test['status'])->toBe('success')
        ->and($test['provider'])->toBe('openai')
        ->and($test['response'])->toBe("Nested answer line one.\nNested answer line two.");
});

test('openai provider surfaces api error messages', function () {
    Http::fake([
        'https://openai-error.test/responses' => Http::response([
            'error' => ['message' => 'Model unavailable.'],
        ], 503),
    ]);

    (new OpenAIProvider())->complete(new AiTask(['prompt' => 'Hello']), [], [
        'config' => [
            'default_model' => 'gpt-5.4-mini',
            'providers'     => ['openai' => ['api_key' => 'sk-test', 'base_url' => 'https://openai-error.test']],
        ],
    ]);
})->throws(RuntimeException::class, 'Model unavailable.');

test('openai provider requires an api key before completion requests', function () {
    (new OpenAIProvider())->complete(new AiTask(['prompt' => 'Hello']), [], [
        'config' => ['providers' => ['openai' => ['api_key' => '']]],
    ]);
})->throws(InvalidArgumentException::class, 'OpenAI API key is not configured.');

test('openai provider validates test configuration and surfaces test errors', function () {
    $configurationError = null;
    try {
        (new OpenAIProvider())->test();
    } catch (InvalidArgumentException $exception) {
        $configurationError = $exception->getMessage();
    }
    expect($configurationError)->toBe('OpenAI API key is not configured.');

    Http::fake([
        'https://openai-test-error.test/responses' => Http::response([
            'error' => ['message' => 'Connectivity failed.'],
        ], 429),
    ]);

    (new OpenAIProvider())->test([
        'default_model' => 'gpt-5.4-mini',
        'providers'     => ['openai' => ['api_key' => 'sk-test', 'base_url' => 'https://openai-test-error.test']],
    ]);
})->throws(RuntimeException::class, 'Connectivity failed.');

test('anthropic provider can test connectivity and reports fallback errors', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'id'          => 'msg_ok',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['text' => 'Claude provider is healthy.'],
                    ['text' => 'Second line.'],
                ],
                'usage'       => [
                    'input_tokens'  => 3,
                    'output_tokens' => 4,
                ],
            ])
            ->push(['error' => []], 500),
    ]);

    $config = [
        'default_model' => 'claude-sonnet-4-6',
        'providers'     => [
            'anthropic' => [
                'api_key'    => 'sk-ant-test',
                'base_url'   => 'https://fleetbase-anthropic.test',
                'max_tokens' => 128,
            ],
        ],
    ];

    $provider = new AnthropicProvider();
    $test     = $provider->test($config);

    expect($test['status'])->toBe('success')
        ->and($test['provider'])->toBe('anthropic')
        ->and($test['model'])->toBe('claude-sonnet-4-6')
        ->and($test['response'])->toBe("Claude provider is healthy.\nSecond line.");

    $provider->complete(new AiTask(['prompt' => 'Hello']), [], ['config' => $config]);
})->throws(RuntimeException::class, 'Anthropic request failed with status code: 500');

test('anthropic provider requires an api key before completion requests', function () {
    (new AnthropicProvider())->complete(new AiTask(['prompt' => 'Hello']), [], [
        'config' => ['providers' => ['anthropic' => ['api_key' => '']]],
    ]);
})->throws(InvalidArgumentException::class, 'Anthropic API key is not configured.');

test('anthropic provider validates test configuration and surfaces test errors', function () {
    $configurationError = null;
    try {
        (new AnthropicProvider())->test();
    } catch (InvalidArgumentException $exception) {
        $configurationError = $exception->getMessage();
    }
    expect($configurationError)->toBe('Anthropic API key is not configured.');
});
