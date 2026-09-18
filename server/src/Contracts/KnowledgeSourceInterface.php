<?php

namespace Fleetbase\Ai\Contracts;

/**
 * A source of documentation that Fleetbase AI can index.
 */
interface KnowledgeSourceInterface
{
    /**
     * Stable identifier stored with each indexed document, e.g. `fleetbase-docs`.
     */
    public function key(): string;

    /**
     * Yield parsed documents. Each document is an array with `url`, `path`, `title`, `description`,
     * `module`, `section`, and `sections` (a list of `heading`, `anchor`, `level`, `content`).
     *
     * @param callable|null $onProgress called with (string $url, string $status) for each page
     */
    public function documents(?callable $onProgress = null): iterable;
}
