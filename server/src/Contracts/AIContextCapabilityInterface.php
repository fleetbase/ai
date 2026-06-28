<?php

namespace Fleetbase\Ai\Contracts;

use Fleetbase\Ai\Models\AiTask;

interface AIContextCapabilityInterface extends AICapabilityInterface
{
    public function shouldResolve(AiTask $task): bool;

    public function resolve(AiTask $task): array;
}
