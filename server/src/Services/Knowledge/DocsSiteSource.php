<?php

namespace Fleetbase\Ai\Services\Knowledge;

use Fleetbase\Ai\Contracts\KnowledgeSourceInterface;
use Fleetbase\Ai\Support\Knowledge\DocsHtmlParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads the official Fleetbase documentation site: pages are discovered from the sitemap and parsed
 * from their rendered HTML.
 */
class DocsSiteSource implements KnowledgeSourceInterface
{
    public const KEY = 'fleetbase-docs';

    protected const USER_AGENT = 'FleetbaseAI-DocsSync/1.0 (+https://fleetbase.io)';

    /**
     * URLs that failed to fetch or parse during the last run.
     */
    public array $failures = [];

    public function __construct(protected array $config = [], protected ?DocsHtmlParser $parser = null)
    {
        $this->parser ??= new DocsHtmlParser();
    }

    public function key(): string
    {
        return static::KEY;
    }

    public function documents(?callable $onProgress = null): iterable
    {
        $this->failures = [];

        foreach ($this->pageUrls() as $index => $url) {
            if ($index > 0) {
                $this->pause();
            }

            $html   = $this->fetch($url);
            $parsed = $html !== null ? $this->parser->parse($html) : null;

            if (!$parsed || empty($parsed['sections'])) {
                $this->failures[] = $url;
                $onProgress && $onProgress($url, 'failed');
                continue;
            }

            $path = $this->relativePath($url);
            $onProgress && $onProgress($url, 'fetched');

            yield [
                'url'         => $url,
                'path'        => $path,
                'title'       => $parsed['title'] ?: Str::title(str_replace('-', ' ', basename($path))),
                'description' => $parsed['description'],
                'module'      => $this->moduleFor($path),
                'section'     => $this->sectionFor($path),
                'sections'    => $parsed['sections'],
            ];
        }
    }

    /**
     * Documentation page URLs from the sitemap, excluding configured paths.
     */
    public function pageUrls(): array
    {
        $xml = $this->fetch((string) ($this->config['sitemap_url'] ?? 'https://fleetbase.io/sitemap.xml'));
        if (!$xml) {
            throw new \RuntimeException('Unable to fetch the Fleetbase documentation sitemap.');
        }

        preg_match_all('/<loc>\s*([^<\s]+)\s*<\/loc>/i', $xml, $matches);
        $basePath = '/' . trim((string) ($this->config['base_path'] ?? '/docs'), '/');

        return collect($matches[1] ?? [])
            ->map(fn ($url) => html_entity_decode(trim($url)))
            ->filter(function ($url) use ($basePath) {
                $path = (string) parse_url($url, PHP_URL_PATH);

                return ($path === $basePath || str_starts_with($path, $basePath . '/')) && !$this->isExcluded($this->relativePath($url));
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Path relative to the docs base, e.g. `platform/identity-and-access/users`.
     */
    public function relativePath(string $url): string
    {
        $basePath = trim((string) ($this->config['base_path'] ?? '/docs'), '/');
        $path     = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return trim(Str::after($path, $basePath), '/');
    }

    public function moduleFor(string $path): string
    {
        return Str::before($path, '/') ?: 'overview';
    }

    /**
     * Readable section trail for a page path, e.g. `Platform › Identity & Access`.
     */
    public function sectionFor(string $path): ?string
    {
        $segments = array_slice(explode('/', trim($path, '/')), 0, -1);
        if (empty($segments)) {
            return null;
        }

        return collect($segments)
            ->map(fn ($segment) => match ($segment) {
                'fleet-ops' => 'Fleet-Ops',
                'api'       => 'API',
                'cli'       => 'CLI',
                default     => str_replace(' And ', ' & ', Str::title(str_replace('-', ' ', $segment))),
            })
            ->implode(' › ');
    }

    protected function isExcluded(string $path): bool
    {
        foreach ((array) ($this->config['exclude'] ?? []) as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function pause(): void
    {
        $delay = (int) ($this->config['request_delay_ms'] ?? 250);
        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => static::USER_AGENT, 'Accept' => 'text/html,application/xml'])
                ->timeout((int) ($this->config['timeout'] ?? 20))
                ->retry(2, 500, throw: false)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }
}
