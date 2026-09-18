<?php

namespace Fleetbase\Ai\Support\Capabilities\Query;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;

class CountRecordsTool extends AbstractQueryTool
{
    public function key(): string
    {
        return 'core.count_records';
    }

    public function label(): string
    {
        return 'Count records';
    }

    public function description(): string
    {
        return 'Counts the company records that match filters.';
    }

    public function toolName(): string
    {
        return 'count_records';
    }

    public function toolDescription(): string
    {
        return "Count the current organization's records that match all filters. Use this for \"how many\" questions. Datetime values are ISO 8601; relative dates must be resolved using the temporal context. Resources:\n" . $this->catalog();
    }

    public function toolParameters(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['resource' => $this->resourceSchema(), 'filters' => $this->filterSchema()],
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
            return ['error' => 'Invalid filters. Nothing was counted.', 'details' => $errors];
        }

        $result = $this->executor->count($resource->key, $filters);

        return array_merge($result, ['filters' => $this->describeFilters($filters)]);
    }
}
