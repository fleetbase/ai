<?php

namespace Fleetbase\Ai\Jobs;

use Fleetbase\Ai\Services\Knowledge\DocsSiteSource;
use Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper;
use Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes the Fleetbase AI documentation index from the docs site or the packaged snapshot.
 */
class SyncAiKnowledge implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $source = 'site')
    {
    }

    public function handle(KnowledgeIndexer $indexer, DocsSiteSource $site, KnowledgeBootstrapper $bootstrapper): array
    {
        return $this->source === 'snapshot'
            ? $indexer->importSnapshot($bootstrapper->snapshotPath())
            : $indexer->index($site);
    }
}
