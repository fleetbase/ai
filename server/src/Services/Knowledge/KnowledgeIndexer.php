<?php

namespace Fleetbase\Ai\Services\Knowledge;

use Fleetbase\Ai\Contracts\KnowledgeSourceInterface;
use Fleetbase\Ai\Models\AiKnowledgeChunk;
use Fleetbase\Ai\Models\AiKnowledgeDocument;
use Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier;
use Illuminate\Support\Str;

/**
 * Stores documentation from a knowledge source as audience-tagged, heading-delimited chunks.
 */
class KnowledgeIndexer
{
    /**
     * Longest chunk stored; longer sections are split on line boundaries.
     */
    public const MAX_CHUNK_CHARACTERS = 3000;

    /**
     * Stale documents are only pruned when at most this share of pages failed to fetch, so a partial
     * outage of the docs site never empties the knowledge base.
     */
    public const MAX_FAILURE_RATIO_FOR_PRUNE = 0.2;

    public function __construct(protected KnowledgeAudienceClassifier $classifier)
    {
    }

    /**
     * Index every document from a source.
     *
     * @return array{indexed: int, unchanged: int, failed: int, pruned: int}
     */
    public function index(KnowledgeSourceInterface $source, ?callable $onProgress = null): array
    {
        $stats = ['indexed' => 0, 'unchanged' => 0, 'failed' => 0, 'pruned' => 0];
        $seen  = [];

        foreach ($source->documents(function ($url, $status) use (&$stats, $onProgress) {
            if ($status === 'failed') {
                $stats['failed']++;
            }
            $onProgress && $onProgress($url, $status);
        }) as $document) {
            $seen[] = $document['url'];
            $this->store($source->key(), $document) ? $stats['indexed']++ : $stats['unchanged']++;
        }

        $total = count($seen) + $stats['failed'];
        if (count($seen) > 0 && $stats['failed'] / max($total, 1) <= static::MAX_FAILURE_RATIO_FOR_PRUNE) {
            $stats['pruned'] = $this->prune($source->key(), $seen);
        }

        return $stats;
    }

    /**
     * Store one parsed document. Returns false when its content is unchanged.
     */
    public function store(string $sourceKey, array $document, ?string $fetchedAt = null): bool
    {
        $path     = (string) ($document['path'] ?? '');
        $audience = $this->classifier->documentAudience($path);
        $hash     = $this->hash($document, $audience);
        $existing = $this->findDocument($document['url']);

        if ($existing && $existing->content_hash === $hash) {
            return false;
        }

        $model = $existing ?? $this->newDocument();
        $model->uuid ??= (string) Str::uuid();
        $model->fill([
            'source'       => $sourceKey,
            'url'          => $document['url'],
            'path'         => $path,
            'title'        => Str::limit((string) ($document['title'] ?? ''), 250, ''),
            'description'  => $document['description'] ?? null,
            'module'       => $document['module'] ?? null,
            'section'      => $document['section'] ?? null,
            'audience'     => $audience,
            'content_hash' => $hash,
            'fetched_at'   => $fetchedAt ?? now(),
        ]);

        $this->persist($model, $this->chunksFor($document, $audience));

        return true;
    }

    /**
     * Split a parsed document into chunk attribute arrays.
     */
    public function chunksFor(array $document, string $documentAudience): array
    {
        $title    = (string) ($document['title'] ?? '');
        $parent   = null;
        $chunks   = [];
        $position = 0;

        foreach ((array) ($document['sections'] ?? []) as $section) {
            $heading = $section['heading'] ?? null;
            $level   = (int) ($section['level'] ?? 2);

            if ($level <= 2) {
                $parent = $heading;
            }

            $trail       = array_filter([$title, $level === 3 ? $parent : null, $heading]);
            $headingPath = implode(' › ', array_unique($trail));
            $audience    = $this->classifier->sectionAudience($documentAudience, $heading, (string) $section['content']);
            $parts       = $this->split((string) $section['content']);

            foreach ($parts as $index => $content) {
                $chunks[] = [
                    'heading'      => Str::limit((string) ($heading ?? $title), 250, ''),
                    'heading_path' => Str::limit($headingPath . (count($parts) > 1 ? ' (part ' . ($index + 1) . ')' : ''), 495, ''),
                    'anchor'       => $section['anchor'] ?? null,
                    'audience'     => $audience,
                    'position'     => $position++,
                    'content'      => $content,
                ];
            }
        }

        return $chunks;
    }

    /**
     * Crawl a source and write its parsed documents to a snapshot file (gzipped when the path ends in .gz).
     * Snapshots ship with the package so instances without internet access still have documentation.
     *
     * @return array{written: int, failed: int}
     */
    public function writeSnapshot(KnowledgeSourceInterface $source, string $path, ?callable $onProgress = null): array
    {
        $failed    = 0;
        $documents = [];

        foreach ($source->documents(function ($url, $status) use (&$failed, $onProgress) {
            if ($status === 'failed') {
                $failed++;
            }
            $onProgress && $onProgress($url, $status);
        }) as $document) {
            $documents[] = $document;
        }

        $payload = json_encode([
            'source'       => $source->key(),
            'generated_at' => now()->toIso8601String(),
            'documents'    => $documents,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, str_ends_with($path, '.gz') ? gzencode($payload, 9) : $payload);

        return ['written' => count($documents), 'failed' => $failed];
    }

    /**
     * Load a snapshot written by writeSnapshot().
     *
     * @return array{indexed: int, unchanged: int, failed: int, pruned: int}
     */
    public function importSnapshot(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Knowledge snapshot not found at {$path}.");
        }

        $raw      = file_get_contents($path);
        $json     = str_ends_with($path, '.gz') ? gzdecode($raw) : $raw;
        $snapshot = json_decode((string) $json, true);

        if (!is_array($snapshot) || !isset($snapshot['documents']) || !is_array($snapshot['documents'])) {
            throw new \InvalidArgumentException('Knowledge snapshot is not valid.');
        }

        $source = (string) ($snapshot['source'] ?? DocsSiteSource::KEY);
        $stats  = ['indexed' => 0, 'unchanged' => 0, 'failed' => 0, 'pruned' => 0];
        $seen   = [];

        foreach ($snapshot['documents'] as $document) {
            if (empty($document['url']) || empty($document['sections'])) {
                $stats['failed']++;
                continue;
            }

            $seen[] = $document['url'];
            $this->store($source, $document, $snapshot['generated_at'] ?? null) ? $stats['indexed']++ : $stats['unchanged']++;
        }

        if (!empty($seen)) {
            $stats['pruned'] = $this->prune($source, $seen);
        }

        return $stats;
    }

    public function hash(array $document, string $audience): string
    {
        return hash('sha256', json_encode([
            $document['title'] ?? null,
            $document['description'] ?? null,
            $document['sections'] ?? [],
            $audience,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Split long content into chunks on line boundaries.
     */
    protected function split(string $content): array
    {
        if (mb_strlen($content) <= static::MAX_CHUNK_CHARACTERS) {
            return [$content];
        }

        $parts   = [];
        $current = '';

        foreach (explode("\n", $content) as $line) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) + 1 > static::MAX_CHUNK_CHARACTERS) {
                $parts[] = $current;
                $current = '';
            }

            $current = $current === '' ? mb_substr($line, 0, static::MAX_CHUNK_CHARACTERS) : $current . "\n" . $line;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function findDocument(string $url): ?AiKnowledgeDocument
    {
        return AiKnowledgeDocument::where('url', $url)->first();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function newDocument(): AiKnowledgeDocument
    {
        return new AiKnowledgeDocument();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function persist(AiKnowledgeDocument $document, array $chunks): void
    {
        $document->getConnection()->transaction(function () use ($document, $chunks) {
            $document->save();
            AiKnowledgeChunk::where('ai_knowledge_document_uuid', $document->uuid)->delete();

            foreach ($chunks as $chunk) {
                AiKnowledgeChunk::create(array_merge(['uuid' => (string) Str::uuid()], $chunk, ['ai_knowledge_document_uuid' => $document->uuid]));
            }
        });
    }

    /**
     * @codeCoverageIgnore
     */
    protected function prune(string $sourceKey, array $keepUrls): int
    {
        $stale = AiKnowledgeDocument::where('source', $sourceKey)->whereNotIn('url', $keepUrls)->pluck('uuid');

        if ($stale->isEmpty()) {
            return 0;
        }

        AiKnowledgeChunk::whereIn('ai_knowledge_document_uuid', $stale)->delete();

        return AiKnowledgeDocument::whereIn('uuid', $stale)->delete();
    }
}
