<?php

namespace Fleetbase\Ai\Console\Commands;

use Carbon\Carbon;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiLogExporter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ExportAiLogs extends Command
{
    protected $signature = 'ai:export-logs
        {--output= : File to write (defaults to storage/app/ai-logs-<date>.<format>)}
        {--format=jsonl : jsonl or csv}
        {--from= : Only tasks created on or after this date}
        {--to= : Only tasks created on or before this date}
        {--company= : Only tasks for this company uuid}
        {--with-deleted : Include deleted tasks}';

    protected $description = 'Export Fleetbase AI tasks and their steps for auditing or evaluation';

    public function handle(AiLogExporter $exporter): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, AiLogExporter::FORMATS, true)) {
            $this->error('Format must be jsonl or csv.');

            return self::FAILURE;
        }

        $path   = $this->option('output') ?: $this->defaultPath($format);
        $handle = @fopen($path, 'w');

        if (!$handle) {
            $this->error("Unable to write to {$path}.");

            return self::FAILURE;
        }

        try {
            $count = $exporter->write($this->query(), $handle, $format);
        } finally {
            fclose($handle);
        }

        $this->info("Exported {$count} AI tasks to {$path}.");
        $this->warn('The export contains user prompts and responses. Store and share it securely.');

        return self::SUCCESS;
    }

    protected function query(): Builder
    {
        $query = $this->baseQuery();

        if ($this->option('with-deleted')) {
            $query->withTrashed();
        }

        if ($from = $this->option('from')) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $this->option('to')) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        if ($company = $this->option('company')) {
            $query->where('company_uuid', $company);
        }

        return $query;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function baseQuery(): Builder
    {
        return AiTask::query();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function defaultPath(string $format): string
    {
        return storage_path('app/ai-logs-' . now()->format('Y-m-d-His') . '.' . $format);
    }
}
