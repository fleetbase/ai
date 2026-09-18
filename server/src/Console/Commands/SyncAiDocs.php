<?php

namespace Fleetbase\Ai\Console\Commands;

use Fleetbase\Ai\Services\Knowledge\DocsSiteSource;
use Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper;
use Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer;
use Illuminate\Console\Command;

class SyncAiDocs extends Command
{
    protected $signature = 'ai:sync-docs
        {--snapshot= : Import documentation from a snapshot file instead of the docs site (defaults to the packaged snapshot)}
        {--write-snapshot= : Crawl the docs site and write a snapshot file without touching the database}';

    protected $description = 'Index the official Fleetbase documentation for Fleetbase AI answers';

    public function handle(KnowledgeIndexer $indexer, DocsSiteSource $source, KnowledgeBootstrapper $bootstrapper): int
    {
        $progress = function (string $url, string $status) {
            if ($status === 'failed') {
                $this->warn("Failed: {$url}");
            } elseif ($this->output->isVerbose()) {
                $this->line("Fetched: {$url}");
            }
        };

        try {
            if ($path = $this->option('write-snapshot')) {
                $result = $indexer->writeSnapshot($source, $path, $progress);
                $this->info("Wrote {$result['written']} documentation pages to {$path} ({$result['failed']} failed).");

                return $result['written'] > 0 ? self::SUCCESS : self::FAILURE;
            }

            if ($this->input->hasParameterOption('--snapshot')) {
                $path  = $this->option('snapshot') ?: $bootstrapper->snapshotPath();
                $stats = $indexer->importSnapshot($path);
            } else {
                $stats = $indexer->index($source, $progress);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Documentation indexed: %d updated, %d unchanged, %d failed, %d removed.',
            $stats['indexed'],
            $stats['unchanged'],
            $stats['failed'],
            $stats['pruned']
        ));

        return self::SUCCESS;
    }
}
