<?php

use Fleetbase\Ai\Console\Commands\SyncAiDocs;
use Fleetbase\Ai\Contracts\KnowledgeSourceInterface;
use Fleetbase\Ai\Services\Knowledge\DocsSiteSource;
use Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper;
use Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer;
use Fleetbase\Ai\Services\Knowledge\KnowledgeSearch;
use Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function aiSyncDocsIndexer(): KnowledgeIndexer
{
    return new class(new KnowledgeAudienceClassifier()) extends KnowledgeIndexer {
        public array $calls       = [];
        public ?Throwable $throws = null;

        public function index(KnowledgeSourceInterface $source, ?callable $onProgress = null): array
        {
            $this->calls[] = 'index';
            $onProgress && $onProgress('https://fleetbase.io/docs/a', 'fetched');
            $onProgress && $onProgress('https://fleetbase.io/docs/b', 'failed');
            if ($this->throws) {
                throw $this->throws;
            }

            return ['indexed' => 3, 'unchanged' => 2, 'failed' => 1, 'pruned' => 0];
        }

        public function importSnapshot(string $path): array
        {
            $this->calls[] = ['snapshot', $path];

            return ['indexed' => 5, 'unchanged' => 0, 'failed' => 0, 'pruned' => 1];
        }

        public function writeSnapshot(KnowledgeSourceInterface $source, string $path, ?callable $onProgress = null): array
        {
            $this->calls[] = ['write', $path];

            return ['written' => str_contains($path, 'empty') ? 0 : 7, 'failed' => 0];
        }
    };
}

function aiRunSyncDocs(KnowledgeIndexer $indexer, array $input, string $snapshotPath = '/packaged/docs.json.gz'): array
{
    $command = new SyncAiDocs();
    $command->setLaravel(new Illuminate\Container\Container());
    $output = new BufferedOutput();
    $input  = new ArrayInput($input, $command->getDefinition());

    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));

    $bootstrapper = new KnowledgeBootstrapper(new KnowledgeSearch(new KnowledgeAudienceClassifier()), $indexer, $snapshotPath);
    $code         = $command->handle($indexer, new DocsSiteSource(), $bootstrapper);

    return [$code, $output->fetch()];
}

test('sync docs crawls and indexes the documentation site by default', function () {
    $indexer         = aiSyncDocsIndexer();
    [$code, $output] = aiRunSyncDocs($indexer, []);

    expect($code)->toBe(0)
        ->and($indexer->calls)->toBe(['index'])
        ->and($output)->toContain('Failed: https://fleetbase.io/docs/b')
        ->and($output)->toContain('Documentation indexed: 3 updated, 2 unchanged, 1 failed, 0 removed.');
});

test('sync docs imports the packaged or a given snapshot', function () {
    $indexer = aiSyncDocsIndexer();

    [$packaged]        = aiRunSyncDocs($indexer, ['--snapshot' => null]);
    [$custom, $output] = aiRunSyncDocs($indexer, ['--snapshot' => '/tmp/custom.json']);

    expect($packaged)->toBe(0)
        ->and($custom)->toBe(0)
        ->and($indexer->calls)->toBe([['snapshot', '/packaged/docs.json.gz'], ['snapshot', '/tmp/custom.json']])
        ->and($output)->toContain('5 updated');
});

test('sync docs writes snapshots and reports failures', function () {
    $indexer = aiSyncDocsIndexer();

    [$written, $output] = aiRunSyncDocs($indexer, ['--write-snapshot' => '/tmp/docs.json.gz']);
    [$empty]            = aiRunSyncDocs($indexer, ['--write-snapshot' => '/tmp/empty.json.gz']);

    $indexer->throws    = new RuntimeException('Unable to fetch the Fleetbase documentation sitemap.');
    [$failed, $failure] = aiRunSyncDocs($indexer, []);

    expect($written)->toBe(0)
        ->and($output)->toContain('Wrote 7 documentation pages to /tmp/docs.json.gz')
        ->and($empty)->toBe(1)
        ->and($failed)->toBe(1)
        ->and($failure)->toContain('Unable to fetch the Fleetbase documentation sitemap.');
});

test('bootstrapper resolves the packaged snapshot path', function () {
    $bootstrapper = new KnowledgeBootstrapper(new KnowledgeSearch(new KnowledgeAudienceClassifier()), new KnowledgeIndexer(new KnowledgeAudienceClassifier()));

    expect($bootstrapper->snapshotPath())->toBe(KnowledgeBootstrapper::defaultSnapshotPath())
        ->and(KnowledgeBootstrapper::defaultSnapshotPath())->toEndWith('server/resources/ai-knowledge/docs-snapshot.json.gz')
        ->and(is_file(KnowledgeBootstrapper::defaultSnapshotPath()))->toBeTrue();
});
