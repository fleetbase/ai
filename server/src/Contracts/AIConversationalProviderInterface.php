<?php

namespace Fleetbase\Ai\Contracts;

use Fleetbase\Ai\Support\AiProviderTurn;

/**
 * A provider that can hold a multi-turn conversation with tool calling.
 *
 * Messages use a provider-neutral shape:
 * - `['role' => 'user', 'content' => string]`
 * - `['role' => 'assistant', 'content' => string, 'tool_calls' => [['id', 'name', 'arguments' => array]], 'raw' => mixed]`
 *   where `raw` is the provider's own representation of the turn, replayed verbatim when present
 * - `['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string, 'is_error' => bool]`
 *
 * Tools are `['name' => string, 'description' => string, 'parameters' => array]` (JSON Schema).
 */
interface AIConversationalProviderInterface
{
    public function supportsTools(array $config = []): bool;

    /**
     * @param array $options supports `config` (system AI config) and `tool_choice` (`auto` or `none`)
     */
    public function converse(string $system, array $messages, array $tools = [], array $options = []): AiProviderTurn;
}
