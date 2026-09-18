<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\Knowledge\KnowledgeSearch;
use Fleetbase\Ai\Support\AiToolContext;

class ReadDocTool extends AbstractAIToolCapability
{
    public function __construct(protected KnowledgeSearch $search)
    {
    }

    public function key(): string
    {
        return 'core.read_doc';
    }

    public function label(): string
    {
        return 'Read Fleetbase documentation';
    }

    public function description(): string
    {
        return 'Reads a full Fleetbase documentation section or page found with the documentation search.';
    }

    public function module(): string
    {
        return 'core';
    }

    public function toolName(): string
    {
        return 'read_doc';
    }

    public function toolDescription(): string
    {
        return 'Read the full text of a Fleetbase documentation section or page. Pass a result id or url from search_docs. A url without an #anchor returns the whole page.';
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'reference' => [
                    'type'        => 'string',
                    'description' => 'A result id or url returned by search_docs.',
                ],
            ],
            'required'             => ['reference'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $reference = trim((string) ($arguments['reference'] ?? ''));
        if ($reference === '') {
            return ['error' => 'A documentation reference is required.'];
        }

        return $this->search->read($reference, $context->audience) ?? ['error' => 'That documentation section was not found or is not available to this user.'];
    }
}
