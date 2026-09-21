<?php

namespace Fleetbase\Ai\Console\Commands;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\Eval\AiEvalGrader;
use Fleetbase\Ai\Support\Eval\AiEvalRunner;
use Fleetbase\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReplayAiTask extends Command
{
    protected $signature = 'ai:replay
        {task : The uuid of a recorded AI task}
        {--audience= : end_user or system_admin (defaults to the recorded user type)}
        {--permission=* : Permissions for an end user replay}
        {--model= : Override the configured model}
        {--yes : Skip the confirmation (the replay calls the real provider)}';

    protected $description = 'Re-run a recorded AI turn through the current runtime and compare the answers';

    public function handle(AIProviderInterface $provider, AiCapabilityRegistry $registry, AiTemporalContext $temporalContext): int
    {
        $task = $this->findTask($this->argument('task'));

        if (!$task) {
            $this->error('AI task not found.');

            return self::FAILURE;
        }

        $config = array_merge($this->systemConfig(), array_filter(['enabled' => true, 'default_model' => $this->option('model')]));

        if (!method_exists($provider, 'supportsTools') || !$provider->supportsTools($config)) {
            $this->error('Replays need an enabled OpenAI or Claude provider with tool calling.');

            return self::FAILURE;
        }

        if (!$this->option('yes') && !$this->confirm('Replay this turn against the configured provider? This is billed.')) {
            return self::FAILURE;
        }

        session(['company' => $task->company_uuid]);

        $case = [
            'id'          => $task->uuid,
            'prompt'      => (string) $task->prompt,
            'route'       => data_get($task->context, 'route'),
            'history'     => $this->history($task),
            'audience'    => $this->option('audience') ?: ($task->relationLoaded('createdBy') && $task->createdBy?->type === 'admin' ? 'system_admin' : 'end_user'),
            'permissions' => (array) $this->option('permission'),
        ];

        $result = (new AiEvalRunner($provider, $registry, $temporalContext, new AiEvalGrader()))->run($case, $config);

        $this->line('<comment>Prompt</comment>');
        $this->line($case['prompt']);
        $this->newLine();
        $this->line("<comment>Recorded answer ({$task->provider} {$task->model})</comment>");
        $this->line((string) $task->response);
        $this->newLine();
        $this->line('<comment>Replayed answer</comment>');
        $this->line($result['error'] ?? $result['response']);
        $this->newLine();
        $this->line('<comment>Tools called:</comment> ' . (implode(', ', $result['tools']) ?: 'none'));
        $this->line('<comment>Console actions proposed:</comment> ' . (implode(', ', $result['ui_commands']) ?: 'none'));
        foreach (array_filter($result['failures'], fn ($failure) => !str_starts_with($failure, 'provider error')) as $failure) {
            $this->warn($failure);
        }

        return isset($result['error']) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Earlier turns of the same session, oldest first.
     */
    protected function history(AiTask $task): array
    {
        return $this->previousTurns($task)
            ->flatMap(fn (AiTask $turn) => array_values(array_filter([
                ['role' => 'user', 'content' => (string) $turn->prompt],
                $turn->response ? ['role' => 'assistant', 'content' => Str::limit((string) $turn->response, 3000, '…')] : null,
            ])))
            ->values()
            ->all();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function findTask(string $uuid): ?AiTask
    {
        return AiTask::withTrashed()->with('createdBy')->where('uuid', $uuid)->first();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function previousTurns(AiTask $task)
    {
        return AiTask::withTrashed()
            ->where('ai_session_uuid', $task->ai_session_uuid)
            ->where('id', '<', $task->id)
            ->whereNotNull('prompt')
            ->latest('id')
            ->limit(8)
            ->get()
            ->reverse();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function systemConfig(): array
    {
        return Setting::system('ai', []);
    }
}
