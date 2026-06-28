<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiTask;

class LocalAIProvider implements AIProviderInterface
{
    public function complete(AiTask $task, array $messages = [], array $options = []): array
    {
        $prompt  = trim((string) $task->prompt);
        $summary = $prompt ? substr($prompt, 0, 120) : 'AI task';

        return [
            'provider' => 'local',
            'model'    => 'fleetbase-local-preview',
            'content'  => "I recorded this AI task and prepared it for the Fleetbase AI runtime. Configure a live provider in Admin > Config > AI to enable model-backed responses.\n\nPrompt: {$summary}",
            'summary'  => $summary,
            'usage'    => [
                'input_tokens'  => str_word_count($prompt),
                'output_tokens' => 36,
                'total_tokens'  => str_word_count($prompt) + 36,
            ],
            'metadata' => [
                'mode' => 'local-preview',
            ],
        ];
    }

    public function test(array $config = []): array
    {
        return [
            'status'  => 'success',
            'message' => 'Local AI preview provider is available.',
        ];
    }
}
