<?php

namespace Fleetbase\Ai\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes AI tasks with their steps as JSON Lines or CSV, for audits and for replaying real conversations
 * in evaluations.
 */
class AiLogExporter
{
    public const FORMATS = ['jsonl', 'csv'];

    public const CSV_COLUMNS = [
        'uuid', 'ai_session_uuid', 'company_uuid', 'created_by_uuid', 'status', 'provider', 'model', 'prompt', 'response',
        'feedback_rating', 'feedback_comment', 'input_tokens', 'output_tokens', 'total_tokens', 'tool_calls', 'degraded', 'truncated', 'created_at', 'completed_at',
    ];

    /**
     * Stream every task matching the query to a writable resource.
     *
     * @return int the number of tasks written
     */
    public function write(Builder $tasks, $handle, string $format = 'jsonl'): int
    {
        $count = 0;

        if ($format === 'csv') {
            fputcsv($handle, static::CSV_COLUMNS);
        }

        $tasks->with(['steps' => fn ($steps) => $steps->orderBy('id'), 'session'])
            ->chunkById(200, function ($chunk) use ($handle, $format, &$count) {
                foreach ($chunk as $task) {
                    $format === 'csv' ? fputcsv($handle, $this->csvRow($task)) : fwrite($handle, json_encode($this->record($task), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $count++;
                }
            }, 'id');

        return $count;
    }

    public function record(Model $task): array
    {
        $record             = $task->attributesToArray();
        $record['session']  = $task->relationLoaded('session') && $task->session ? $task->session->attributesToArray() : null;
        $record['steps']    = $task->relationLoaded('steps') ? $task->steps->map->attributesToArray()->values()->all() : [];

        return $record;
    }

    public function csvRow(Model $task): array
    {
        $metadata = (array) $task->metadata;

        return array_map(fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value, [
            $task->uuid,
            $task->ai_session_uuid,
            $task->company_uuid,
            $task->created_by_uuid,
            $task->status,
            $task->provider,
            $task->model,
            $task->prompt,
            $task->response,
            $task->feedback_rating,
            $task->feedback_comment,
            $task->input_tokens,
            $task->output_tokens,
            $task->total_tokens,
            $metadata['tool_calls'] ?? null,
            (bool) ($metadata['degraded'] ?? false),
            (bool) ($metadata['truncated'] ?? false),
            optional($task->created_at)->toIso8601String(),
            optional($task->completed_at)->toIso8601String(),
        ]);
    }
}
