<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiTask;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AnthropicProvider implements AIProviderInterface
{
    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    public const API_VERSION = '2023-06-01';

    public function complete(AiTask $task, array $messages = [], array $options = []): array
    {
        $config         = Arr::get($options, 'config', []);
        $providerConfig = Arr::get($config, 'providers.anthropic', []);
        $apiKey         = (string) Arr::get($providerConfig, 'api_key', '');
        $model          = (string) Arr::get($config, 'default_model', 'claude-haiku-4-5');

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Anthropic API key is not configured.');
        }

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => static::API_VERSION,
                'accept'            => 'application/json',
            ])
            ->asJson()
            ->post($this->messagesUrl($providerConfig), [
                'model'      => $model,
                'system'     => $this->systemInstruction(),
                'max_tokens' => (int) Arr::get($providerConfig, 'max_tokens', 2048),
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $this->userInstruction($task, $messages),
                    ],
                ],
            ]);

        $body = $response->json();

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status(), is_array($body) ? $body : []));
        }

        $content = $this->extractText(is_array($body) ? $body : []);

        return [
            'provider' => 'anthropic',
            'model'    => $model,
            'content'  => $content,
            'summary'  => Str::limit(preg_replace('/\s+/', ' ', trim($content ?: (string) $task->prompt)), 140, ''),
            'usage'    => $this->normalizeUsage(is_array($body) ? Arr::get($body, 'usage', []) : []),
            'metadata' => [
                'response_id' => Arr::get($body, 'id'),
                'stop_reason' => Arr::get($body, 'stop_reason'),
            ],
        ];
    }

    public function test(array $config = []): array
    {
        $providerConfig = Arr::get($config, 'providers.anthropic', []);
        $apiKey         = (string) Arr::get($providerConfig, 'api_key', '');
        $model          = (string) Arr::get($config, 'default_model', 'claude-haiku-4-5');

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Anthropic API key is not configured.');
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => static::API_VERSION,
                'accept'            => 'application/json',
            ])
            ->asJson()
            ->post($this->messagesUrl($providerConfig), [
                'model'      => $model,
                'system'     => 'You are testing Fleetbase AI provider connectivity.',
                'max_tokens' => 32,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => 'Reply with: Fleetbase AI provider test OK.',
                    ],
                ],
            ]);

        $body = $response->json();

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status(), is_array($body) ? $body : []));
        }

        return [
            'status'   => 'success',
            'message'  => 'Claude provider test completed.',
            'provider' => 'anthropic',
            'model'    => $model,
            'response' => $this->extractText(is_array($body) ? $body : []),
        ];
    }

    protected function messagesUrl(array $providerConfig): string
    {
        return rtrim((string) Arr::get($providerConfig, 'base_url', static::DEFAULT_BASE_URL), '/') . '/messages';
    }

    protected function systemInstruction(): string
    {
        return 'You are Fleetbase AI, an operations copilot inside Fleetbase. Answer concisely, focus on logistics and operational actions, use Fleetbase capability context as grounded system data, use Fleetbase temporal context for all relative dates, and never claim that an action has been executed unless Fleetbase provided a tool result confirming it.';
    }

    protected function userInstruction(AiTask $task, array $capabilityContext = []): string
    {
        $routeContext = $task->context ? json_encode($task->context, JSON_PRETTY_PRINT) : '{}';
        $aiContext    = $capabilityContext ? json_encode($capabilityContext, JSON_PRETTY_PRINT) : '[]';

        return trim((string) $task->prompt) . "\n\nFleetbase route context:\n" . $routeContext . "\n\nFleetbase capability context:\n" . $aiContext;
    }

    protected function extractText(array $body): string
    {
        $chunks = [];
        foreach (Arr::get($body, 'content', []) as $content) {
            $text = Arr::get($content, 'text');
            if (is_string($text) && $text !== '') {
                $chunks[] = $text;
            }
        }

        return trim(implode("\n", $chunks));
    }

    protected function normalizeUsage(array $usage): array
    {
        $inputTokens  = Arr::get($usage, 'input_tokens');
        $outputTokens = Arr::get($usage, 'output_tokens');

        return [
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens'  => is_numeric($inputTokens) && is_numeric($outputTokens) ? $inputTokens + $outputTokens : null,
        ];
    }

    protected function errorMessage(int $status, array $body): string
    {
        return Arr::get($body, 'error.message', "Anthropic request failed with status code: {$status}");
    }
}
