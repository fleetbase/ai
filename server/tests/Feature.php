<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AnthropicProvider;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\LocalAIProvider;
use Fleetbase\Ai\Services\OpenAIProvider;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Illuminate\Support\Facades\Http;

test('provider manager exposes backend curated provider and model metadata', function () {
    $manager   = new AiProviderManager(new LocalAIProvider(), new OpenAIProvider(), new AnthropicProvider());
    $providers = collect($manager->metadata()['providers']);
    $openai    = $providers->firstWhere('value', 'openai');
    $anthropic = $providers->firstWhere('value', 'anthropic');

    expect($openai['default_model'])->toBe('gpt-5.4-mini')
        ->and(collect($openai['models'])->pluck('value')->all())->toContain('gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5')
        ->and($anthropic['default_model'])->toBe('claude-haiku-4-5')
        ->and(collect($anthropic['models'])->pluck('value')->all())->toContain('claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8', 'claude-fable-5');
});

test('provider manager falls back to local when ai is disabled or provider is unknown', function () {
    $manager = new AiProviderManager(new LocalAIProvider(), new OpenAIProvider(), new AnthropicProvider());

    expect($manager->providerNameFor(['enabled' => false, 'provider' => 'openai']))->toBe('local')
        ->and($manager->modelFor(['enabled' => false, 'provider' => 'openai']))->toBe('fleetbase-local-preview')
        ->and($manager->providerNameFor(['enabled' => true, 'provider' => 'missing']))->toBe('local')
        ->and($manager->providerNameFor(['enabled' => true, 'provider' => 'anthropic', 'default_model' => 'claude-haiku-4-5']))->toBe('anthropic');
});

test('openai provider requires an api key', function () {
    $provider = new OpenAIProvider();

    $provider->complete(new AiTask(['prompt' => 'Summarize active orders.']), [], [
        'config' => [
            'provider'      => 'openai',
            'default_model' => 'gpt-5.4-mini',
            'providers'     => ['openai' => ['api_key' => '']],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'OpenAI API key is not configured.');

test('openai provider maps responses api content and usage', function () {
    Http::fake([
        OpenAIProvider::DEFAULT_BASE_URL . '/responses' => Http::response([
            'id'          => 'resp_123',
            'status'      => 'completed',
            'output_text' => 'There are 4 active orders that need review.',
            'usage'       => [
                'input_tokens'  => 12,
                'output_tokens' => 9,
                'total_tokens'  => 21,
            ],
        ]),
    ]);

    $result = (new OpenAIProvider())->complete(new AiTask([
        'prompt'  => 'Summarize active orders.',
        'context' => ['route' => 'fleet-ops.operations'],
    ]), [], [
        'config' => [
            'provider'      => 'openai',
            'default_model' => 'gpt-5.4-mini',
            'providers'     => ['openai' => ['api_key' => 'sk-test', 'base_url' => OpenAIProvider::DEFAULT_BASE_URL]],
        ],
    ]);

    expect($result['provider'])->toBe('openai')
        ->and($result['model'])->toBe('gpt-5.4-mini')
        ->and($result['content'])->toBe('There are 4 active orders that need review.')
        ->and($result['usage']['input_tokens'])->toBe(12)
        ->and($result['usage']['output_tokens'])->toBe(9)
        ->and($result['usage']['total_tokens'])->toBe(21)
        ->and($result['metadata']['response_id'])->toBe('resp_123');
});

test('openai provider surfaces responses api errors', function () {
    Http::fake([
        OpenAIProvider::DEFAULT_BASE_URL . '/responses' => Http::response([
            'error' => ['message' => 'Invalid model'],
        ], 400),
    ]);

    (new OpenAIProvider())->complete(new AiTask(['prompt' => 'Summarize active orders.']), [], [
        'config' => [
            'provider'      => 'openai',
            'default_model' => 'gpt-5.4-mini',
            'providers'     => ['openai' => ['api_key' => 'sk-test', 'base_url' => OpenAIProvider::DEFAULT_BASE_URL]],
        ],
    ]);
})->throws(RuntimeException::class, 'Invalid model');

test('anthropic provider requires an api key', function () {
    $provider = new AnthropicProvider();

    $provider->complete(new AiTask(['prompt' => 'Summarize active orders.']), [], [
        'config' => [
            'provider'      => 'anthropic',
            'default_model' => 'claude-haiku-4-5',
            'providers'     => ['anthropic' => ['api_key' => '']],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'Anthropic API key is not configured.');

test('anthropic provider maps messages api content and usage', function () {
    Http::fake([
        AnthropicProvider::DEFAULT_BASE_URL . '/messages' => Http::response([
            'id'          => 'msg_123',
            'stop_reason' => 'end_turn',
            'content'     => [
                ['type' => 'text', 'text' => 'There are 2 delayed orders to review.'],
            ],
            'usage'       => [
                'input_tokens'  => 10,
                'output_tokens' => 8,
            ],
        ]),
    ]);

    $result = (new AnthropicProvider())->complete(new AiTask([
        'prompt'  => 'Summarize delayed orders.',
        'context' => ['route' => 'fleet-ops.operations'],
    ]), [], [
        'config' => [
            'provider'      => 'anthropic',
            'default_model' => 'claude-haiku-4-5',
            'providers'     => ['anthropic' => ['api_key' => 'sk-ant-test', 'base_url' => AnthropicProvider::DEFAULT_BASE_URL]],
        ],
    ]);

    expect($result['provider'])->toBe('anthropic')
        ->and($result['model'])->toBe('claude-haiku-4-5')
        ->and($result['content'])->toBe('There are 2 delayed orders to review.')
        ->and($result['usage']['input_tokens'])->toBe(10)
        ->and($result['usage']['output_tokens'])->toBe(8)
        ->and($result['usage']['total_tokens'])->toBe(18)
        ->and($result['metadata']['response_id'])->toBe('msg_123');
});

test('capability registry exposes registered capability metadata', function () {
    $registry = new AiCapabilityRegistry();

    expect($registry->list())->toBe([]);

    $registry->register(new CurrentPageContextCapability());
    $tools = $registry->list();

    expect($tools)->toHaveCount(1)
        ->and($tools[0]['key'])->toBe('core.current_page_context')
        ->and($tools[0]['type'])->toBe('read')
        ->and($tools[0]['preview_only'])->toBeTrue()
        ->and($tools[0])->not->toHaveKey('handler');
});
