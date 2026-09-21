<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIConversationalProviderInterface;
use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiProviderTurn;
use Fleetbase\Ai\Support\AiSystemPrompt;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AnthropicProvider implements AIProviderInterface, AIConversationalProviderInterface
{
    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    public const API_VERSION = '2023-06-01';

    /**
     * Default output token ceiling for tool-calling turns.
     */
    public const DEFAULT_MAX_TOKENS = 16000;

    /**
     * Models that accept adaptive thinking. Claude Haiku 4.5 does not.
     */
    protected const ADAPTIVE_THINKING_MODELS = ['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5', 'claude-opus-4-8', 'claude-sonnet-5', 'claude-sonnet-4-6'];

    /**
     * Models whose safety classifiers can decline a request; a declined request is retried server-side
     * on Anthropic's recommended fallback model instead of returning the refusal.
     */
    protected const SERVER_FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5'];

    public const SERVER_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function supportsTools(array $config = []): bool
    {
        return true;
    }

    public function converse(string $system, array $messages, array $tools = [], array $options = []): AiProviderTurn
    {
        $config         = Arr::get($options, 'config', []);
        $providerConfig = Arr::get($config, 'providers.anthropic', []);
        $apiKey         = (string) Arr::get($providerConfig, 'api_key', '');
        $model          = (string) Arr::get($config, 'default_model', 'claude-haiku-4-5');

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('Anthropic API key is not configured.');
        }

        $response = Http::timeout((int) Arr::get($providerConfig, 'timeout', 120))
            ->withHeaders($this->headers($apiKey, $model))
            ->asJson()
            ->post($this->messagesUrl($providerConfig), $this->conversationPayload($model, $system, $messages, $tools, $options));

        $body = $response->json();

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status(), is_array($body) ? $body : []));
        }

        return $this->turnFromResponse(is_array($body) ? $body : [], $model);
    }

    /**
     * Build the Messages API request. The system prompt and tool definitions are stable across turns
     * and requests, so both carry a cache breakpoint.
     */
    public function conversationPayload(string $model, string $system, array $messages, array $tools, array $options = []): array
    {
        $config         = Arr::get($options, 'config', []);
        $providerConfig = Arr::get($config, 'providers.anthropic', []);

        $payload = [
            'model'      => $model,
            'max_tokens' => (int) Arr::get($providerConfig, 'max_tokens', static::DEFAULT_MAX_TOKENS),
            'system'     => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages'   => $this->toAnthropicMessages($messages),
        ];

        if (!empty($tools)) {
            $definitions = array_map(fn ($tool) => [
                'name'         => $tool['name'],
                'description'  => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], array_values($tools));
            $definitions[count($definitions) - 1]['cache_control'] = ['type' => 'ephemeral'];

            $payload['tools']       = $definitions;
            $payload['tool_choice'] = ['type' => Arr::get($options, 'tool_choice', 'auto') === 'none' ? 'none' : 'auto'];
        }

        if (in_array($model, static::ADAPTIVE_THINKING_MODELS, true)) {
            $payload['thinking'] = ['type' => 'adaptive'];

            if ($effort = Arr::get($providerConfig, 'effort')) {
                $payload['output_config'] = ['effort' => $effort];
            }
        }

        if (in_array($model, static::SERVER_FALLBACK_MODELS, true)) {
            $payload['fallbacks'] = 'default';
        }

        return $payload;
    }

    /**
     * Convert provider-neutral messages to Anthropic content blocks. Consecutive tool results are sent
     * together in a single user message, as the API expects for parallel tool calls.
     */
    public function toAnthropicMessages(array $messages): array
    {
        $converted = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ($role === 'tool') {
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $message['tool_call_id'],
                    'content'     => (string) ($message['content'] ?? ''),
                ];
                if (!empty($message['is_error'])) {
                    $block['is_error'] = true;
                }

                $last = count($converted) - 1;
                if ($last >= 0 && $converted[$last]['role'] === 'user' && is_array($converted[$last]['content']) && ($converted[$last]['content'][0]['type'] ?? null) === 'tool_result') {
                    $converted[$last]['content'][] = $block;
                } else {
                    $converted[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($role === 'assistant') {
                // Replay the provider's own blocks (including thinking) unchanged when available.
                if (is_array($message['raw'] ?? null) && !empty($message['raw'])) {
                    $converted[] = ['role' => 'assistant', 'content' => $message['raw']];
                    continue;
                }

                $blocks = [];
                if (trim((string) ($message['content'] ?? '')) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => (string) $message['content']];
                }
                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => (object) ($call['arguments'] ?? [])];
                }

                // The API rejects empty text blocks; an empty assistant turn is simply omitted.
                if (!empty($blocks)) {
                    $converted[] = ['role' => 'assistant', 'content' => $blocks];
                }
                continue;
            }

            $converted[] = ['role' => 'user', 'content' => (string) ($message['content'] ?? '')];
        }

        return $converted;
    }

    public function turnFromResponse(array $body, string $model): AiProviderTurn
    {
        $content    = (array) Arr::get($body, 'content', []);
        $stopReason = (string) Arr::get($body, 'stop_reason', 'end_turn');
        $text       = [];
        $toolCalls  = [];

        foreach ($content as $block) {
            $type = Arr::get($block, 'type');

            if ($type === 'text' && is_string(Arr::get($block, 'text'))) {
                $text[] = $block['text'];
            }

            if ($type === 'tool_use') {
                $toolCalls[] = [
                    'id'        => (string) Arr::get($block, 'id'),
                    'name'      => (string) Arr::get($block, 'name'),
                    'arguments' => (array) Arr::get($block, 'input', []),
                ];
            }
        }

        $stop = match ($stopReason) {
            'tool_use'   => AiProviderTurn::STOP_TOOLS,
            'max_tokens' => AiProviderTurn::STOP_TRUNCATED,
            'refusal'    => AiProviderTurn::STOP_REFUSAL,
            default      => AiProviderTurn::STOP_END,
        };

        // A truncated turn may end inside a tool call; never run a partial tool call.
        if ($stop !== AiProviderTurn::STOP_TOOLS) {
            $toolCalls = [];
        }

        $usage = $this->normalizeUsage((array) Arr::get($body, 'usage', []));

        return new AiProviderTurn(
            text: trim(implode("\n", $text)),
            toolCalls: $toolCalls,
            stopReason: $stop,
            usage: $usage,
            provider: 'anthropic',
            model: (string) Arr::get($body, 'model', $model),
            raw: $stop === AiProviderTurn::STOP_REFUSAL ? null : $content,
            metadata: array_filter([
                'response_id'  => Arr::get($body, 'id'),
                'stop_reason'  => $stopReason,
                'stop_details' => Arr::get($body, 'stop_details'),
            ]),
        );
    }

    protected function headers(string $apiKey, string $model): array
    {
        $headers = [
            'x-api-key'         => $apiKey,
            'anthropic-version' => static::API_VERSION,
            'accept'            => 'application/json',
        ];

        if (in_array($model, static::SERVER_FALLBACK_MODELS, true)) {
            $headers['anthropic-beta'] = static::SERVER_FALLBACK_BETA;
        }

        return $headers;
    }

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
                'system'     => $this->systemInstruction($options),
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

        // @codeCoverageIgnoreStart
        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status(), is_array($body) ? $body : []));
        }
        // @codeCoverageIgnoreEnd

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

    protected function systemInstruction(array $options = []): string
    {
        return (string) (Arr::get($options, 'system_prompt') ?: AiSystemPrompt::build(AiAudience::endUser()));
    }

    protected function userInstruction(AiTask $task, array $capabilityContext = []): string
    {
        return AiSystemPrompt::userMessage($task, $capabilityContext);
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

        return array_filter([
            'input_tokens'                => $inputTokens,
            'output_tokens'               => $outputTokens,
            'total_tokens'                => is_numeric($inputTokens) && is_numeric($outputTokens) ? $inputTokens + $outputTokens : null,
            'cache_read_input_tokens'     => Arr::get($usage, 'cache_read_input_tokens'),
            'cache_creation_input_tokens' => Arr::get($usage, 'cache_creation_input_tokens'),
        ], fn ($value, $key) => $value !== null || in_array($key, ['input_tokens', 'output_tokens', 'total_tokens'], true), ARRAY_FILTER_USE_BOTH);
    }

    protected function errorMessage(int $status, array $body): string
    {
        return Arr::get($body, 'error.message', "Anthropic request failed with status code: {$status}");
    }
}
