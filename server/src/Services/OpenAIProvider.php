<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiTask;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenAIProvider implements AIProviderInterface
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function complete(AiTask $task, array $messages = [], array $options = []): array
    {
        $config         = Arr::get($options, 'config', []);
        $providerConfig = Arr::get($config, 'providers.openai', []);
        $apiKey         = (string) Arr::get($providerConfig, 'api_key', '');
        $model          = (string) Arr::get($config, 'default_model', 'gpt-5.4-mini');

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('OpenAI API key is not configured.');
        }

        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post($this->responsesUrl($providerConfig), [
                'model' => $model,
                'input' => [
                    [
                        'role'    => 'system',
                        'content' => $this->systemInstruction(),
                    ],
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
            'provider' => 'openai',
            'model'    => $model,
            'content'  => $content,
            'summary'  => Str::limit(preg_replace('/\s+/', ' ', trim($content ?: (string) $task->prompt)), 140, ''),
            'usage'    => $this->normalizeUsage(is_array($body) ? Arr::get($body, 'usage', []) : []),
            'metadata' => [
                'response_id' => Arr::get($body, 'id'),
                'status'      => Arr::get($body, 'status'),
            ],
        ];
    }

    public function test(array $config = []): array
    {
        $providerConfig = Arr::get($config, 'providers.openai', []);
        $apiKey         = (string) Arr::get($providerConfig, 'api_key', '');
        $model          = (string) Arr::get($config, 'default_model', 'gpt-5.4-mini');

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('OpenAI API key is not configured.');
        }

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post($this->responsesUrl($providerConfig), [
                'model'             => $model,
                'input'             => 'Reply with: Fleetbase AI provider test OK.',
                'max_output_tokens' => 32,
            ]);

        $body = $response->json();

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status(), is_array($body) ? $body : []));
        }

        return [
            'status'   => 'success',
            'message'  => 'OpenAI provider test completed.',
            'provider' => 'openai',
            'model'    => $model,
            'response' => $this->extractText(is_array($body) ? $body : []),
        ];
    }

    protected function responsesUrl(array $providerConfig): string
    {
        return rtrim((string) Arr::get($providerConfig, 'base_url', static::DEFAULT_BASE_URL), '/') . '/responses';
    }

    protected function systemInstruction(): string
    {
        return 'You are Fleetbase AI, an operations copilot inside Fleetbase. Answer concisely, focus on logistics and operational actions, use Fleetbase capability context as grounded system data, and never claim that an action has been executed unless Fleetbase provided a tool result confirming it.';
    }

    protected function userInstruction(AiTask $task, array $capabilityContext = []): string
    {
        $routeContext = $task->context ? json_encode($task->context, JSON_PRETTY_PRINT) : '{}';
        $aiContext    = $capabilityContext ? json_encode($capabilityContext, JSON_PRETTY_PRINT) : '[]';

        return trim((string) $task->prompt) . "\n\nFleetbase route context:\n" . $routeContext . "\n\nFleetbase capability context:\n" . $aiContext;
    }

    protected function extractText(array $body): string
    {
        $outputText = Arr::get($body, 'output_text');
        if (is_string($outputText) && $outputText !== '') {
            return $outputText;
        }

        $chunks = [];
        foreach (Arr::get($body, 'output', []) as $output) {
            foreach (Arr::get($output, 'content', []) as $content) {
                $text = Arr::get($content, 'text');
                if (is_string($text) && $text !== '') {
                    $chunks[] = $text;
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    protected function normalizeUsage(array $usage): array
    {
        return [
            'input_tokens'  => Arr::get($usage, 'input_tokens'),
            'output_tokens' => Arr::get($usage, 'output_tokens'),
            'total_tokens'  => Arr::get($usage, 'total_tokens'),
        ];
    }

    protected function errorMessage(int $status, array $body): string
    {
        return Arr::get($body, 'error.message', "OpenAI request failed with status code: {$status}");
    }
}
