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

class OpenAIProvider implements AIProviderInterface, AIConversationalProviderInterface
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public const DEFAULT_MAX_OUTPUT_TOKENS = 16000;

    public function supportsTools(array $config = []): bool
    {
        return true;
    }

    public function converse(string $system, array $messages, array $tools = [], array $options = []): AiProviderTurn
    {
        $config         = Arr::get($options, 'config', []);
        $providerConfig = Arr::get($config, 'providers.openai', []);
        $apiKey         = (string) Arr::get($providerConfig, 'api_key', '');
        $model          = (string) Arr::get($config, 'default_model', 'gpt-5.4-mini');

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('OpenAI API key is not configured.');
        }

        $response = Http::timeout((int) Arr::get($providerConfig, 'timeout', 120))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post($this->responsesUrl($providerConfig), $this->conversationPayload($model, $system, $messages, $tools, $options));

        $body = $response->json();

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status(), is_array($body) ? $body : []));
        }

        return $this->turnFromResponse(is_array($body) ? $body : [], $model);
    }

    /**
     * Build a stateless Responses API request. Nothing is stored at OpenAI; reasoning is carried between
     * turns as encrypted reasoning items.
     */
    public function conversationPayload(string $model, string $system, array $messages, array $tools, array $options = []): array
    {
        $providerConfig = Arr::get($options, 'config.providers.openai', []);

        $payload = [
            'model'             => $model,
            'instructions'      => $system,
            'input'             => $this->toResponsesInput($messages),
            'max_output_tokens' => (int) Arr::get($providerConfig, 'max_output_tokens', static::DEFAULT_MAX_OUTPUT_TOKENS),
            'store'             => false,
        ];

        if ($this->isReasoningModel($model)) {
            $payload['include'] = ['reasoning.encrypted_content'];

            if ($effort = Arr::get($providerConfig, 'reasoning_effort')) {
                $payload['reasoning'] = ['effort' => $effort];
            }
        }

        if (!empty($tools)) {
            $payload['tools'] = array_map(fn ($tool) => [
                'type'        => 'function',
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $tool['parameters'],
                'strict'      => false,
            ], array_values($tools));
            $payload['tool_choice'] = Arr::get($options, 'tool_choice', 'auto') === 'none' ? 'none' : 'auto';
        }

        return $payload;
    }

    public function toResponsesInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            if ($role === 'tool') {
                $input[] = [
                    'type'    => 'function_call_output',
                    'call_id' => $message['tool_call_id'],
                    'output'  => (string) ($message['content'] ?? ''),
                ];
                continue;
            }

            if ($role === 'assistant') {
                foreach ((array) ($message['raw'] ?? []) as $item) {
                    if (($item['type'] ?? null) === 'reasoning') {
                        $input[] = $item;
                    }
                }

                if (trim((string) ($message['content'] ?? '')) !== '') {
                    $input[] = ['role' => 'assistant', 'content' => (string) $message['content']];
                }

                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $input[] = [
                        'type'      => 'function_call',
                        'call_id'   => $call['id'],
                        'name'      => $call['name'],
                        'arguments' => json_encode((object) ($call['arguments'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }
                continue;
            }

            $input[] = ['role' => 'user', 'content' => (string) ($message['content'] ?? '')];
        }

        return $input;
    }

    public function turnFromResponse(array $body, string $model): AiProviderTurn
    {
        $text      = [];
        $toolCalls = [];
        $reasoning = [];
        $refused   = false;

        foreach ((array) Arr::get($body, 'output', []) as $item) {
            $type = Arr::get($item, 'type');

            if ($type === 'reasoning') {
                $reasoning[] = $item;
            }

            if ($type === 'message') {
                foreach ((array) Arr::get($item, 'content', []) as $content) {
                    if (Arr::get($content, 'type') === 'refusal') {
                        $refused = true;
                    } elseif (is_string(Arr::get($content, 'text'))) {
                        $text[] = $content['text'];
                    }
                }
            }

            if ($type === 'function_call') {
                $arguments   = json_decode((string) Arr::get($item, 'arguments', '{}'), true);
                $toolCalls[] = [
                    'id'        => (string) Arr::get($item, 'call_id'),
                    'name'      => (string) Arr::get($item, 'name'),
                    'arguments' => is_array($arguments) ? $arguments : [],
                ];
            }
        }

        $incomplete = Arr::get($body, 'status') === 'incomplete';
        $stop       = match (true) {
            $refused || Arr::get($body, 'incomplete_details.reason') === 'content_filter'   => AiProviderTurn::STOP_REFUSAL,
            $incomplete                                                                     => AiProviderTurn::STOP_TRUNCATED,
            !empty($toolCalls)                                                              => AiProviderTurn::STOP_TOOLS,
            default                                                                         => AiProviderTurn::STOP_END,
        };

        $usage = $this->normalizeUsage((array) Arr::get($body, 'usage', []));
        if ($cached = Arr::get($body, 'usage.input_tokens_details.cached_tokens')) {
            $usage['cache_read_input_tokens'] = $cached;
        }

        return new AiProviderTurn(
            text: trim(implode("\n", $text)),
            toolCalls: $stop === AiProviderTurn::STOP_TOOLS ? $toolCalls : [],
            stopReason: $stop,
            usage: $usage,
            provider: 'openai',
            model: (string) Arr::get($body, 'model', $model),
            raw: $reasoning,
            metadata: array_filter([
                'response_id' => Arr::get($body, 'id'),
                'status'      => Arr::get($body, 'status'),
            ]),
        );
    }

    protected function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o\d)/', $model);
    }

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
                        'content' => $this->systemInstruction($options),
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
