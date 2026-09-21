<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\Commands\AiCommandRegistry;
use Illuminate\Support\Str;

/**
 * Tracks console actions proposed by Fleetbase AI. An action only runs in the console after the user
 * confirms it here, and the server re-checks that the user may still run it at that moment.
 */
class AiUiActionService
{
    public const PENDING   = 'pending';
    public const CONFIRMED = 'confirmed';
    public const DISMISSED = 'dismissed';
    public const FAILED    = 'failed';

    public function __construct(protected AiCommandRegistry $commands)
    {
    }

    /**
     * @return array{status: int, message?: string, action?: array}
     */
    public function confirm(AiTask $task, string $actionId, AiAudience $audience): array
    {
        [$index, $action] = $this->find($task, $actionId);

        if ($action === null) {
            return ['status' => 404, 'message' => 'This AI action was not found.'];
        }

        if (($action['status'] ?? null) !== static::PENDING) {
            return ['status' => 409, 'message' => 'This AI action was already ' . ($action['status'] ?? 'handled') . '.', 'action' => $action];
        }

        $command = $this->commands->get((string) ($action['command_id'] ?? ''));

        if (!$command || !$command->availableTo($audience)) {
            $this->update($task, $index, array_merge($action, ['status' => static::FAILED, 'error' => 'not_authorized', 'completed_at' => now()->toIso8601String()]), 'failed');

            return ['status' => 403, 'message' => 'You are not allowed to run this action.'];
        }

        $confirmed = array_merge($action, [
            'status'       => static::CONFIRMED,
            'confirmed_at' => now()->toIso8601String(),
            // Steps are resolved from the registry at confirmation time, never from stored data.
            'steps'        => $command->resolvedSteps((array) ($action['params'] ?? [])),
        ]);

        $this->update($task, $index, $confirmed, 'completed');

        return ['status' => 200, 'action' => $confirmed];
    }

    public function dismiss(AiTask $task, string $actionId): array
    {
        return $this->close($task, $actionId, static::DISMISSED);
    }

    /**
     * Record that a confirmed action failed while running in the console.
     */
    public function fail(AiTask $task, string $actionId, ?string $error = null): array
    {
        [$index, $action] = $this->find($task, $actionId);

        if ($action === null) {
            return ['status' => 404, 'message' => 'This AI action was not found.'];
        }

        if (($action['status'] ?? null) !== static::CONFIRMED) {
            return ['status' => 409, 'message' => 'Only confirmed actions can be marked as failed.', 'action' => $action];
        }

        $failed = array_merge($action, ['status' => static::FAILED, 'error' => Str::limit((string) $error, 500, ''), 'completed_at' => now()->toIso8601String()]);
        $this->update($task, $index, $failed, 'failed');

        return ['status' => 200, 'action' => $failed];
    }

    protected function close(AiTask $task, string $actionId, string $status): array
    {
        [$index, $action] = $this->find($task, $actionId);

        if ($action === null) {
            return ['status' => 404, 'message' => 'This AI action was not found.'];
        }

        if (($action['status'] ?? null) !== static::PENDING) {
            return ['status' => 409, 'message' => 'This AI action was already ' . ($action['status'] ?? 'handled') . '.', 'action' => $action];
        }

        $closed = array_merge($action, ['status' => $status, 'completed_at' => now()->toIso8601String()]);
        $this->update($task, $index, $closed, 'completed');

        return ['status' => 200, 'action' => $closed];
    }

    /**
     * @return array{0: ?int, 1: ?array}
     */
    protected function find(AiTask $task, string $actionId): array
    {
        foreach ((array) data_get($task->metadata, 'ui_actions', []) as $index => $action) {
            if (is_array($action) && ($action['id'] ?? null) === $actionId) {
                return [$index, $action];
            }
        }

        return [null, null];
    }

    protected function update(AiTask $task, int $index, array $action, string $stepStatus): void
    {
        $metadata                       = (array) $task->metadata;
        $metadata['ui_actions'][$index] = $action;
        $task->update(['metadata' => $metadata]);

        $this->recordStep($task, [
            'type'         => 'ui_action',
            'status'       => $stepStatus,
            'tool'         => $action['command_id'] ?? null,
            'input'        => ['action_id' => $action['id'] ?? null, 'params' => $action['params'] ?? []],
            'output'       => ['status' => $action['status']],
            'error'        => isset($action['error']) ? ['message' => $action['error']] : null,
            'completed_at' => now(),
        ]);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function recordStep(AiTask $task, array $attributes): AiTaskStep
    {
        return AiTaskStep::create(array_merge([
            'ai_task_uuid'    => $task->uuid,
            'company_uuid'    => $task->company_uuid,
            'created_by_uuid' => $task->created_by_uuid,
        ], $attributes));
    }
}
