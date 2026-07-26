<?php

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Http\Controllers\Internal\AiConfigController;
use Fleetbase\Ai\Http\Controllers\Internal\AiToolController;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AnthropicProvider;
use Fleetbase\Ai\Services\LocalAIProvider;
use Fleetbase\Ai\Services\OpenAIProvider;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Fleetbase\Http\Requests\AdminRequest;
use Illuminate\Http\Request;

test('tool controller returns registered capability metadata', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(new CurrentPageContextCapability());

    $payload = aiJsonPayload((new AiToolController())->index($registry));

    expect($payload['tools'])->toHaveCount(1)
        ->and($payload['tools'][0]['key'])->toBe('core.current_page_context')
        ->and($payload['tools'][0]['mode'])->toBe('context')
        ->and($payload['tools'][0]['preview_only'])->toBeTrue()
        ->and($payload['tools'][0]['executable'])->toBeFalse();
});

test('config controller status reports normalized disabled default config', function () {
    $providers = new AiProviderManager(new LocalAIProvider(), new OpenAIProvider(), new AnthropicProvider());

    $payload = aiJsonPayload((new AiConfigController())->status(Request::create('/'), $providers));

    expect($payload)->toBe(['enabled' => false]);
});

test('config controller test provider returns provider response payload', function () {
    $provider = new class implements AIProviderInterface {
        public function complete(AiTask $task, array $messages = [], array $options = []): array
        {
            return [];
        }

        public function test(array $config = []): array
        {
            return [
                'status'   => 'success',
                'provider' => $config['provider'] ?? null,
            ];
        }
    };

    $request = AdminRequest::create('/', 'POST', ['config' => ['provider' => 'local']]);

    $payload = aiJsonPayload((new AiConfigController())->testProvider($request, $provider));

    expect($payload)->toBe([
        'status'   => 'success',
        'provider' => 'local',
    ]);
});

test('config controller test provider serializes provider exceptions', function () {
    $provider = new class implements AIProviderInterface {
        public function complete(AiTask $task, array $messages = [], array $options = []): array
        {
            return [];
        }

        public function test(array $config = []): array
        {
            throw new InvalidArgumentException('Provider unavailable.');
        }
    };

    $payload = aiJsonPayload((new AiConfigController())->testProvider(AdminRequest::create('/'), $provider));

    expect($payload['status'])->toBe('error')
        ->and($payload['message'])->toBe('Provider unavailable.')
        ->and($payload['type'])->toBe(InvalidArgumentException::class);
});
