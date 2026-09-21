<?php

namespace Fleetbase\Ai\Support\Capabilities\Query;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;

class GroupCountTool extends AbstractQueryTool
{
    public function key(): string
    {
        return 'core.group_count';
    }

    public function label(): string
    {
        return 'Count records by field';
    }

    public function description(): string
    {
        return 'Counts company records grouped by a field, such as orders by status.';
    }

    public function toolName(): string
    {
        return 'group_count';
    }

    public function toolDescription(): string
    {
        return "Count the current organization's records that match all filters, grouped by one field (for example orders by status). Use a string or boolean field for group_by. Resources and fields are the same as count_records:\n" . $this->catalog();
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'resource' => $this->resourceSchema(),
                'group_by' => ['type' => 'string', 'description' => 'Field to group by.'],
                'filters'  => $this->filterSchema(),
            ],
            'required'             => ['resource', 'group_by'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        [$resource, $error] = $this->resolveResource((string) ($arguments['resource'] ?? ''), $context);
        if ($error) {
            return $error;
        }

        $groupBy = (string) ($arguments['group_by'] ?? '');
        if (!$resource->hasField($groupBy) || in_array($resource->typeFor($groupBy), ['datetime', 'uuid'], true)) {
            return ['error' => "'{$groupBy}' cannot be used to group {$resource->key}. Use a string or boolean field."];
        }

        [$filters, $errors] = $this->prepareFilters($resource, $arguments['filters'] ?? []);
        if ($errors) {
            return ['error' => 'Invalid filters. Nothing was counted.', 'details' => $errors];
        }

        $result = $this->executor->countsBy($resource->key, $groupBy, $filters);

        return array_merge($result, ['filters' => $this->describeFilters($filters)]);
    }
}
