<?php

namespace Fleetbase\Ai\Support\Capabilities\Query;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;

class ListRecordsTool extends AbstractQueryTool
{
    public const MAX_LIMIT = 25;

    public function key(): string
    {
        return 'core.list_records';
    }

    public function label(): string
    {
        return 'List records';
    }

    public function description(): string
    {
        return 'Lists the newest company records that match filters.';
    }

    public function toolName(): string
    {
        return 'list_records';
    }

    public function toolDescription(): string
    {
        return 'List the newest records of the current organization that match all filters (at most ' . static::MAX_LIMIT . "). The result includes total_matching; when truncated is true, say the list is partial. Resources and fields are the same as count_records:\n" . $this->catalog();
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'resource' => $this->resourceSchema(),
                'filters'  => $this->filterSchema(),
                'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => static::MAX_LIMIT],
            ],
            'required'             => ['resource'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        [$resource, $error] = $this->resolveResource((string) ($arguments['resource'] ?? ''), $context);
        if ($error) {
            return $error;
        }

        [$filters, $errors] = $this->prepareFilters($resource, $arguments['filters'] ?? []);
        if ($errors) {
            return ['error' => 'Invalid filters. Nothing was listed.', 'details' => $errors];
        }

        $limit  = min(max((int) ($arguments['limit'] ?? 10), 1), static::MAX_LIMIT);
        $result = $this->executor->listRecords($resource->key, $filters, $limit);

        return array_merge($result, ['filters' => $this->describeFilters($filters)]);
    }
}
