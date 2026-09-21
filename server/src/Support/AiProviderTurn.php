<?php

namespace Fleetbase\Ai\Support;

/**
 * One model response in a provider-neutral shape.
 */
class AiProviderTurn
{
    public const STOP_END       = 'end';
    public const STOP_TOOLS     = 'tool_calls';
    public const STOP_TRUNCATED = 'truncated';
    public const STOP_REFUSAL   = 'refusal';

    /**
     * @param array $toolCalls list of `['id' => string, 'name' => string, 'arguments' => array]`
     * @param array $usage     `input_tokens`, `output_tokens`, `total_tokens`, and cache counters when reported
     * @param mixed $raw       provider-specific assistant content, replayed in the next request
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly string $stopReason = self::STOP_END,
        public readonly array $usage = [],
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly mixed $raw = null,
        public readonly array $metadata = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * The assistant message to append to the conversation for the next request.
     */
    public function toAssistantMessage(): array
    {
        return [
            'role'       => 'assistant',
            'content'    => $this->text,
            'tool_calls' => $this->toolCalls,
            'raw'        => $this->raw,
        ];
    }
}
