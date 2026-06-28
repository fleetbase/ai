<?php

namespace Fleetbase\Ai\Contracts;

use Fleetbase\Ai\Models\AiTask;

interface AIProviderInterface
{
    public function complete(AiTask $task, array $messages = [], array $options = []): array;

    public function test(array $config = []): array;
}
