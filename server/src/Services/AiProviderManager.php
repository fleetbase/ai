<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Models\Setting;
use Illuminate\Support\Arr;

class AiProviderManager implements AIProviderInterface
{
    public function __construct(protected LocalAIProvider $local, protected OpenAIProvider $openai, protected AnthropicProvider $anthropic)
    {
    }

    public function complete(AiTask $task, array $messages = [], array $options = []): array
    {
        $config = Arr::get($options, 'config', Setting::system('ai', []));

        return $this->providerFor($config, true)->complete($task, $messages, array_merge($options, ['config' => $config]));
    }

    public function test(array $config = []): array
    {
        return $this->providerFor($config, false)->test($config);
    }

    public function providerFor(array $config, bool $respectEnabled = true): AIProviderInterface
    {
        if ($respectEnabled && !Arr::get($config, 'enabled', false)) {
            return $this->local;
        }

        return match (Arr::get($config, 'provider', 'local')) {
            'openai'    => $this->openai,
            'anthropic' => $this->anthropic,
            default     => $this->local,
        };
    }

    public function metadata(): array
    {
        return [
            'providers' => [
                [
                    'label'         => 'Local Preview',
                    'value'         => 'local',
                    'default_model' => 'fleetbase-local-preview',
                    'models'        => [
                        ['label' => 'Fleetbase Local Preview', 'value' => 'fleetbase-local-preview'],
                    ],
                ],
                [
                    'label'         => 'OpenAI',
                    'value'         => 'openai',
                    'default_model' => 'gpt-5.4-mini',
                    'models'        => [
                        ['label' => 'GPT-5.4 Mini', 'value' => 'gpt-5.4-mini'],
                        ['label' => 'GPT-5.4', 'value' => 'gpt-5.4'],
                        ['label' => 'GPT-5.5', 'value' => 'gpt-5.5'],
                    ],
                ],
                [
                    'label'         => 'Claude',
                    'value'         => 'anthropic',
                    'default_model' => 'claude-haiku-4-5',
                    'models'        => [
                        ['label' => 'Claude Haiku 4.5', 'value' => 'claude-haiku-4-5'],
                        ['label' => 'Claude Sonnet 4.6', 'value' => 'claude-sonnet-4-6'],
                        ['label' => 'Claude Opus 4.8', 'value' => 'claude-opus-4-8'],
                        ['label' => 'Claude Fable 5', 'value' => 'claude-fable-5'],
                    ],
                ],
            ],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'enabled'       => false,
            'provider'      => 'local',
            'default_model' => 'fleetbase-local-preview',
            'providers'     => [
                'openai' => [
                    'api_key'  => '',
                    'base_url' => OpenAIProvider::DEFAULT_BASE_URL,
                ],
                'anthropic' => [
                    'api_key'  => '',
                    'base_url' => AnthropicProvider::DEFAULT_BASE_URL,
                ],
            ],
        ];
    }

    public function normalizeConfig(array $config): array
    {
        $metadata = collect($this->metadata()['providers'])->keyBy('value');
        $provider = Arr::get($config, 'provider', 'local');

        if (!$metadata->has($provider)) {
            $provider = 'local';
        }

        $providerMetadata = $metadata->get($provider);
        $model            = Arr::get($config, 'default_model', Arr::get($providerMetadata, 'default_model'));
        $supportedModels  = collect(Arr::get($providerMetadata, 'models', []))->pluck('value')->all();

        if (!in_array($model, $supportedModels, true)) {
            $model = Arr::get($providerMetadata, 'default_model');
        }

        return array_replace_recursive($this->defaultConfig(), [
            'enabled'       => (bool) Arr::get($config, 'enabled', false),
            'provider'      => $provider,
            'default_model' => $model,
            'providers'     => Arr::get($config, 'providers', []),
        ]);
    }

    public function providerNameFor(array $config): string
    {
        if (!Arr::get($config, 'enabled', false)) {
            return 'local';
        }

        return Arr::get($this->normalizeConfig($config), 'provider', 'local');
    }

    public function modelFor(array $config): string
    {
        if (!Arr::get($config, 'enabled', false)) {
            return 'fleetbase-local-preview';
        }

        return Arr::get($this->normalizeConfig($config), 'default_model', 'fleetbase-local-preview');
    }
}
