<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper;
use Fleetbase\Ai\Services\Knowledge\KnowledgeSearch;
use Fleetbase\Ai\Support\AiToolContext;

class SearchDocsTool extends AbstractAIToolCapability
{
    public function __construct(protected KnowledgeSearch $search, protected ?KnowledgeBootstrapper $bootstrapper = null)
    {
    }

    public function key(): string
    {
        return 'core.search_docs';
    }

    public function label(): string
    {
        return 'Search Fleetbase documentation';
    }

    public function description(): string
    {
        return 'Searches the official Fleetbase documentation for how-to, navigation, feature, and settings information.';
    }

    public function module(): string
    {
        return 'core';
    }

    public function toolName(): string
    {
        return 'search_docs';
    }

    public function toolDescription(): string
    {
        return 'Search the official Fleetbase documentation (fleetbase.io/docs). Use it for any question about how to do something in Fleetbase, where a screen or setting is, what a feature does, or whether a feature exists. '
            . 'Always write the query in English using Fleetbase terms (for example "invite user", "map provider settings", "import orders spreadsheet", "driver status"), even when the user writes in another language. '
            . 'Search again with different wording if the first results do not answer the question. Results are sections with a url; cite the url you used.';
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'English search query using Fleetbase terminology.',
                ],
                'module' => [
                    'type'        => 'string',
                    'description' => 'Optional documentation area to restrict results to.',
                    'enum'        => ['platform', 'fleet-ops', 'ledger', 'storefront', 'pallet', 'api', 'cli', 'extension-development', 'community'],
                ],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        $this->bootstrapper?->ensureIndexed();

        $result = $this->search->search($query, $context->audience, $arguments['module'] ?? null);

        if (empty($result['results'])) {
            $result['message'] = 'No documentation matched. Try different English wording, a broader query, or tell the user this is not covered in the Fleetbase docs.';
        }

        return $result;
    }
}
