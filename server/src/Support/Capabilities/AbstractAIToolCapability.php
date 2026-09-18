<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Contracts\AIToolCapabilityInterface;
use Fleetbase\Ai\Support\AiToolContext;

/**
 * Base class for tools: available to users holding every permission the tool declares.
 */
abstract class AbstractAIToolCapability extends AbstractAICapability implements AIToolCapabilityInterface
{
    public function mode(): string
    {
        return 'tool';
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $context->audience->canAll($this->permissions());
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'tool_name'       => $this->toolName(),
            'tool_parameters' => $this->toolParameters(),
        ]);
    }
}
