<?php

namespace Fleetbase\Ai\Services\Knowledge;

use Illuminate\Support\Facades\Cache;

/**
 * Makes sure documentation is available: when nothing has been indexed yet (a fresh install, or an
 * instance without internet access) the documentation snapshot shipped with the package is loaded.
 */
class KnowledgeBootstrapper
{
    public function __construct(protected KnowledgeSearch $search, protected KnowledgeIndexer $indexer, protected ?string $snapshotPath = null)
    {
    }

    public static function defaultSnapshotPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/ai-knowledge/docs-snapshot.json.gz';
    }

    public function snapshotPath(): string
    {
        return $this->snapshotPath ?: static::defaultSnapshotPath();
    }

    /**
     * Returns true when documentation is indexed after the call.
     *
     * @codeCoverageIgnore
     */
    public function ensureIndexed(): bool
    {
        if (!$this->search->isEmpty()) {
            return true;
        }

        if (!is_file($this->snapshotPath())) {
            return false;
        }

        try {
            Cache::lock('fleetbase-ai-knowledge-bootstrap', 300)->block(60, function () {
                if ($this->search->isEmpty()) {
                    $this->indexer->importSnapshot($this->snapshotPath());
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }

        return !$this->search->isEmpty();
    }
}
