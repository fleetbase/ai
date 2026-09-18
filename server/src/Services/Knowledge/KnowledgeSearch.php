<?php

namespace Fleetbase\Ai\Services\Knowledge;

use Fleetbase\Ai\Models\AiKnowledgeChunk;
use Fleetbase\Ai\Models\AiKnowledgeDocument;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Searches indexed Fleetbase documentation on behalf of a user. Results never include content the
 * user's audience may not see, and system-administrator guidance is redacted for organization users.
 */
class KnowledgeSearch
{
    public const DEFAULT_LIMIT = 6;

    public const MAX_LIMIT = 10;

    /**
     * Maximum chunks returned from a single page, so one long page cannot crowd out others.
     */
    public const MAX_CHUNKS_PER_DOCUMENT = 2;

    public const EXCERPT_CHARACTERS = 900;

    public const READ_CHARACTERS = 12000;

    protected const STOPWORDS = ['the', 'and', 'for', 'with', 'how', 'what', 'where', 'can', 'does', 'into', 'from', 'this', 'that', 'you', 'your', 'are', 'fleetbase'];

    public function __construct(protected KnowledgeAudienceClassifier $classifier)
    {
    }

    /**
     * @return array{query: string, results: array<int, array>}
     */
    public function search(string $query, AiAudience $audience, ?string $module = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        $limit = min(max($limit, 1), static::MAX_LIMIT);
        $terms = $this->terms($query);

        if (empty($terms)) {
            return ['query' => $query, 'results' => []];
        }

        $candidates = $this->candidates($query, $terms, $this->allowedAudiences($audience), $module ? Str::lower($module) : null);

        $results = $candidates
            ->map(fn ($chunk) => ['chunk' => $chunk, 'score' => $this->score($chunk, $terms)])
            ->sortByDesc('score')
            ->groupBy(fn ($row) => $row['chunk']->ai_knowledge_document_uuid)
            ->flatMap(fn (Collection $rows) => $rows->take(static::MAX_CHUNKS_PER_DOCUMENT))
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn ($row) => $this->present($row['chunk'], $audience, static::EXCERPT_CHARACTERS))
            ->values()
            ->all();

        return ['query' => $query, 'results' => $results];
    }

    /**
     * Read a documentation section by chunk id or URL (with an optional #anchor), or a whole page by URL.
     */
    public function read(string $reference, AiAudience $audience): ?array
    {
        $reference = trim($reference);
        $allowed   = $this->allowedAudiences($audience);

        if (Str::isUuid($reference)) {
            $chunk = $this->chunkByUuid($reference, $allowed);

            return $chunk ? $this->present($chunk, $audience, static::READ_CHARACTERS) + ['page_sections' => $this->pageSections($chunk->document, $allowed)] : null;
        }

        $url      = Str::before($reference, '#');
        $anchor   = str_contains($reference, '#') ? Str::after($reference, '#') : null;
        $document = $this->documentByUrl(rtrim($url, '/'), $allowed);

        if (!$document) {
            return null;
        }

        $chunks = $this->allowedChunks($document, $allowed);

        if ($anchor) {
            $chunk = $chunks->firstWhere('anchor', $anchor);

            return $chunk ? $this->present($chunk, $audience, static::READ_CHARACTERS) + ['page_sections' => $this->pageSections($document, $allowed)] : null;
        }

        $content = $chunks
            ->map(fn (AiKnowledgeChunk $chunk) => ($chunk->heading && $chunk->heading !== $document->title ? '## ' . $chunk->heading . "\n" : '') . $this->classifier->redact($chunk->content, $chunk->audience, $audience))
            ->implode("\n\n");

        return [
            'title'       => $document->title,
            'description' => $document->description,
            'section'     => $document->section,
            'module'      => $document->module,
            'url'         => $document->url,
            'content'     => Str::limit($content, static::READ_CHARACTERS, "\n…"),
            'truncated'   => mb_strlen($content) > static::READ_CHARACTERS,
        ];
    }

    /**
     * Whether any documentation has been indexed.
     *
     * @codeCoverageIgnore
     */
    public function isEmpty(): bool
    {
        return !AiKnowledgeDocument::query()->exists();
    }

    public function allowedAudiences(AiAudience $audience): array
    {
        return array_values(array_filter(
            [AiAudience::END_USER, AiAudience::DEVELOPER, AiAudience::SYSTEM_ADMIN],
            fn ($value) => $audience->allows($value)
        ));
    }

    /**
     * Distinct search terms of three or more characters.
     */
    public function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-_]{2,}/u', Str::lower($query), $matches);

        return collect($matches[0] ?? [])
            ->reject(fn ($term) => in_array($term, static::STOPWORDS, true))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Rank a chunk: full-text relevance when available, plus boosts when terms appear in the page
     * title or section heading, which is where Fleetbase docs name screens and features.
     */
    public function score($chunk, array $terms): float
    {
        $title   = Str::lower((string) data_get($chunk, 'document.title'));
        $heading = Str::lower((string) $chunk->heading_path);
        $content = Str::lower((string) $chunk->content);
        $score   = (float) ($chunk->relevance ?? 0);

        foreach ($terms as $term) {
            $score += str_contains($title, $term) ? 3 : 0;
            $score += str_contains($heading, $term) ? 2 : 0;
            $score += min(substr_count($content, $term), 5) * 0.5;
        }

        return round($score, 4);
    }

    protected function present(AiKnowledgeChunk $chunk, AiAudience $audience, int $characters): array
    {
        $document = $chunk->document;
        $content  = $this->classifier->redact((string) $chunk->content, (string) $chunk->audience, $audience);

        return [
            'id'        => $chunk->uuid,
            'title'     => $document?->title,
            'section'   => $chunk->heading_path,
            'module'    => $document?->module,
            'url'       => $document ? $document->url . ($chunk->anchor ? '#' . $chunk->anchor : '') : null,
            'content'   => Str::limit($content, $characters, ' …'),
            'truncated' => mb_strlen($content) > $characters,
        ];
    }

    protected function pageSections(?AiKnowledgeDocument $document, array $allowed): array
    {
        if (!$document) {
            return [];
        }

        return $this->allowedChunks($document, $allowed)
            ->pluck('heading')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function candidates(string $query, array $terms, array $allowedAudiences, ?string $module): Collection
    {
        $builder = AiKnowledgeChunk::query()
            ->with('document')
            ->whereIn('audience', $allowedAudiences)
            ->whereHas('document', function (Builder $document) use ($allowedAudiences, $module) {
                $document->whereIn('audience', $allowedAudiences);
                if ($module) {
                    $document->where('module', $module);
                }
            });

        if (in_array($builder->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $builder
                ->select('ai_knowledge_chunks.*')
                ->selectRaw('MATCH(heading_path, content) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance', [$query])
                ->whereRaw('MATCH(heading_path, content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$query])
                ->orderByDesc('relevance')
                ->limit(60)
                ->get();
        }

        return $builder
            ->where(function (Builder $where) use ($terms) {
                foreach ($terms as $term) {
                    $where->orWhere('content', 'like', '%' . $term . '%')->orWhere('heading_path', 'like', '%' . $term . '%');
                }
            })
            ->limit(200)
            ->get();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function chunkByUuid(string $uuid, array $allowed): ?AiKnowledgeChunk
    {
        return AiKnowledgeChunk::with('document')
            ->where('uuid', $uuid)
            ->whereIn('audience', $allowed)
            ->whereHas('document', fn (Builder $document) => $document->whereIn('audience', $allowed))
            ->first();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function documentByUrl(string $url, array $allowed): ?AiKnowledgeDocument
    {
        return AiKnowledgeDocument::whereIn('url', [$url, $url . '/'])->whereIn('audience', $allowed)->first();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function allowedChunks(AiKnowledgeDocument $document, array $allowed): Collection
    {
        return $document->chunks()->whereIn('audience', $allowed)->get()->each(fn ($chunk) => $chunk->setRelation('document', $document));
    }
}
