<?php

namespace Fleetbase\Ai\Contracts;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;

/**
 * A capability the model can call as a tool during a Fleetbase AI turn.
 */
interface AIToolCapabilityInterface extends AICapabilityInterface
{
    /**
     * Tool name exposed to the model: lowercase letters, digits, and underscores.
     */
    public function toolName(): string;

    /**
     * What the tool does and when the model should use it.
     */
    public function toolDescription(): string;

    /**
     * JSON Schema (type object) describing the tool arguments.
     */
    public function toolParameters(): array;

    /**
     * Whether the tool is offered to this user at all.
     */
    public function availableFor(AiToolContext $context): bool;

    /**
     * Run the tool. The returned array is sent back to the model as the tool result.
     */
    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array;
}
