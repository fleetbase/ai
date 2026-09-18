<?php

namespace Fleetbase\Ai\Support\Commands;

use Fleetbase\Ai\Support\AiAudience;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Console commands Fleetbase AI can propose. Extensions register their own from their service providers.
 */
class AiCommandRegistry
{
    protected array $commands = [];

    public function register(AiConsoleCommand $command): static
    {
        $this->commands[$command->id] = $command;

        return $this;
    }

    /**
     * @param array<int, AiConsoleCommand|array> $commands commands or array definitions (see AiConsoleCommand::fromArray)
     */
    public function registerMany(array $commands): static
    {
        foreach ($commands as $command) {
            $this->register(is_array($command) ? AiConsoleCommand::fromArray($command) : $command);
        }

        return $this;
    }

    public function get(string $id): ?AiConsoleCommand
    {
        return $this->commands[$id] ?? null;
    }

    public function all(): Collection
    {
        return collect($this->commands)->values();
    }

    /**
     * Commands this user may run, never including system-admin commands for organization users.
     */
    public function availableTo(AiAudience $audience): Collection
    {
        return $this->all()->filter(fn (AiConsoleCommand $command) => $command->availableTo($audience))->values();
    }

    /**
     * Rank available commands by how well their label, breadcrumb, description, and keywords match.
     */
    public function search(string $query, AiAudience $audience, int $limit = 8): Collection
    {
        $terms = collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($query)) ?: [])
            ->filter(fn ($term) => mb_strlen($term) >= 2 && !in_array($term, ['a', 'an', 'the', 'to', 'of', 'in', 'on', 'for', 'my', 'how', 'do', 'i', 'where', 'is'], true))
            ->map(fn ($term) => Str::singular($term))
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return collect();
        }

        $phrase = ' ' . Str::lower(preg_replace('/\s+/u', ' ', $query)) . ' ';

        return $this->availableTo($audience)
            ->map(function (AiConsoleCommand $command) use ($terms, $phrase) {
                $label    = Str::lower($command->label . ' ' . $command->breadcrumb);
                $keywords = Str::lower(implode(' ', $command->keywords) . ' ' . $command->description . ' ' . $command->id);
                $score    = 0;

                foreach ($terms as $term) {
                    $score += str_contains($label, $term) ? 2 : 0;
                    $score += str_contains($keywords, $term) ? 2 : 0;
                }

                // A multi-word keyword such as "google maps" or "api key" named in full is a strong signal.
                foreach ($command->keywords as $keyword) {
                    if (str_contains($keyword, ' ') && str_contains($phrase, ' ' . Str::lower($keyword) . ' ')) {
                        $score += 5;
                    }
                }

                return ['command' => $command, 'score' => $score];
            })
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('command')
            ->values();
    }
}
