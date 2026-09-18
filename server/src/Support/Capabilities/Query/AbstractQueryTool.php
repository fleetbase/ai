<?php

namespace Fleetbase\Ai\Support\Capabilities\Query;

use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Capabilities\AbstractAIToolCapability;
use Illuminate\Support\Carbon;

/**
 * Shared behavior for tools that read company data through the allowlisted query registry.
 */
abstract class AbstractQueryTool extends AbstractAIToolCapability
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'null', 'not_null', 'false_or_null'];

    public function __construct(protected AiQueryRegistry $registry, protected AiQueryExecutor $executor, protected ?AiTemporalContext $temporalContext = null)
    {
    }

    public function module(): string
    {
        return 'core';
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $this->registry->all()->isNotEmpty();
    }

    /**
     * A catalog of queryable resources and their filterable fields, kept deterministic so the tool
     * definitions stay cacheable.
     */
    protected function catalog(): string
    {
        return $this->registry->all()
            ->sortBy('key')
            ->map(function (AiQueryableResource $resource) {
                $fields = collect($resource->fields)
                    ->map(function ($definition, $name) use ($resource) {
                        $enum = $resource->enumFor($name);

                        return $name . ' (' . $resource->typeFor($name) . ($enum ? ': ' . implode('|', $enum) : '') . ')';
                    })
                    ->implode(', ');

                return "- {$resource->key}: {$resource->label}" . ($resource->description ? " — {$resource->description}" : '') . ". Fields: {$fields}";
            })
            ->implode("\n");
    }

    protected function filterSchema(): array
    {
        return [
            'type'        => 'array',
            'description' => 'Conditions that must all match. Use only fields listed for the resource.',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'field'    => ['type' => 'string'],
                    'operator' => ['type' => 'string', 'enum' => static::OPERATORS],
                    'value'    => ['description' => 'Comparison value. Arrays for in/not_in, ISO 8601 for datetimes, omitted for null/not_null/false_or_null.'],
                ],
                'required' => ['field', 'operator'],
            ],
        ];
    }

    protected function resourceSchema(): array
    {
        return [
            'type' => 'string',
            'enum' => $this->registry->all()->pluck('key')->sort()->values()->all(),
        ];
    }

    /**
     * Resolve the resource and confirm the user may read it.
     *
     * @return array{0: ?AiQueryableResource, 1: ?array} the resource, or an error result
     */
    protected function resolveResource(string $key, AiToolContext $context): array
    {
        $resource = $this->registry->find($key);

        if (!$resource) {
            return [null, ['error' => "Unknown resource {$key}. Valid resources: " . $this->registry->all()->pluck('key')->sort()->implode(', ') . '.']];
        }

        if ($resource->permission && !$context->audience->can($resource->permission)) {
            return [null, ['authorized' => false, 'resource' => $resource->key, 'message' => "The user does not have permission to view {$resource->label}."]];
        }

        return [$resource, null];
    }

    /**
     * Validate filters and convert their values to what the database expects.
     *
     * @return array{0: array, 1: array} normalized filters and error messages
     */
    protected function prepareFilters(AiQueryableResource $resource, mixed $filters): array
    {
        $prepared = [];
        $errors   = [];

        foreach (is_array($filters) ? $filters : [] as $index => $filter) {
            $field    = is_array($filter) ? (string) ($filter['field'] ?? '') : '';
            $operator = is_array($filter) ? (string) ($filter['operator'] ?? '=') : '';
            $value    = is_array($filter) ? ($filter['value'] ?? null) : null;

            if (!$resource->hasField($field)) {
                $errors[] = "Filter {$index}: unknown field '{$field}' for {$resource->key}. Valid fields: " . implode(', ', array_keys($resource->fields)) . '.';
                continue;
            }

            if (!in_array($operator, static::OPERATORS, true)) {
                $errors[] = "Filter {$index}: unsupported operator '{$operator}'.";
                continue;
            }

            if (in_array($operator, ['null', 'not_null', 'false_or_null'], true)) {
                $prepared[] = ['field' => $field, 'operator' => $operator];
                continue;
            }

            [$normalized, $error] = $this->normalizeValue($resource, $field, $operator, $value);

            if ($error) {
                $errors[] = "Filter {$index}: {$error}";
                continue;
            }

            $prepared[] = ['field' => $field, 'operator' => $operator, 'value' => $normalized];
        }

        return [$prepared, $errors];
    }

    protected function normalizeValue(AiQueryableResource $resource, string $field, string $operator, mixed $value): array
    {
        if (in_array($operator, ['in', 'not_in'], true)) {
            $values = [];
            foreach ((array) $value as $item) {
                [$normalized, $error] = $this->normalizeValue($resource, $field, '=', $item);
                if ($error) {
                    return [null, $error];
                }
                $values[] = $normalized;
            }

            return empty($values) ? [null, "'{$field}' needs at least one value."] : [$values, null];
        }

        if ($value === null || is_array($value)) {
            return [null, "'{$field}' needs a single value."];
        }

        $enum = $resource->enumFor($field);
        if ($enum && !in_array((string) $value, array_map('strval', $enum), true)) {
            return [null, "'{$value}' is not a valid {$field}. Valid values: " . implode(', ', $enum) . '.'];
        }

        return match ($resource->typeFor($field)) {
            'boolean'  => [filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value, null],
            'datetime' => $this->normalizeDateTime($field, (string) $value),
            'integer'  => is_numeric($value) ? [(int) $value, null] : [null, "'{$field}' must be a number."],
            default    => [(string) $value, null],
        };
    }

    protected function normalizeDateTime(string $field, string $value): array
    {
        try {
            return [Carbon::parse($value, $this->timezone())->utc(), null];
        } catch (\Throwable) {
            return [null, "'{$value}' is not a valid datetime for {$field}; use ISO 8601."];
        }
    }

    protected function timezone(): string
    {
        try {
            return $this->temporalContext?->timezone() ?? 'UTC';
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    /**
     * Echo filters back with datetimes as strings so results are readable by the model.
     */
    protected function describeFilters(array $filters): array
    {
        return array_map(function ($filter) {
            if (($filter['value'] ?? null) instanceof \DateTimeInterface) {
                $filter['value'] = Carbon::instance($filter['value'])->toIso8601String();
            }

            return $filter;
        }, $filters);
    }
}
