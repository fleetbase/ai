<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class AiQueryExecutor
{
    protected const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'null', 'not_null', 'false_or_null'];

    public function __construct(protected AiQueryRegistry $registry)
    {
    }

    public function count(string $resourceKey, array $filters = []): array
    {
        $resource = $this->resource($resourceKey);
        if (!$resource) {
            return ['authorized' => false, 'error' => 'Unknown query resource.'];
        }

        if (!$this->can($resource)) {
            return ['authorized' => false, 'resource' => $resource->key];
        }

        $query = $this->applyFilters($resource, $resource->query(), $filters);

        return [
            'authorized' => true,
            'resource'   => $resource->key,
            'metric'     => 'count',
            'filters'    => $filters,
            'count'      => $query->count(),
        ];
    }

    public function countsBy(string $resourceKey, string $field, array $filters = []): array
    {
        $resource = $this->resource($resourceKey);
        if (!$resource || !$resource->hasField($field)) {
            return ['authorized' => false, 'error' => 'Unknown resource or field.'];
        }

        if (!$this->can($resource)) {
            return ['authorized' => false, 'resource' => $resource->key];
        }

        $column = $resource->columnFor($field);
        $query  = $this->applyFilters($resource, $resource->query(), $filters);

        return [
            'authorized' => true,
            'resource'   => $resource->key,
            'metric'     => 'counts_by',
            'group_by'   => $field,
            'filters'    => $filters,
            'counts'     => $query
                ->selectRaw($column . ', count(*) as aggregate')
                ->groupBy($column)
                ->pluck('aggregate', $column)
                ->all(),
        ];
    }

    public function samples(string $resourceKey, array $filters = [], int $limit = 10): array
    {
        $resource = $this->resource($resourceKey);
        if (!$resource) {
            return ['authorized' => false, 'error' => 'Unknown query resource.'];
        }

        if (!$this->can($resource)) {
            return ['authorized' => false, 'resource' => $resource->key];
        }

        $limit   = min(max($limit, 1), $resource->maxLimit);
        $records = $this->applyFilters($resource, $resource->query(), $filters)
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'authorized' => true,
            'resource'   => $resource->key,
            'metric'     => 'samples',
            'filters'    => $filters,
            'limit'      => $limit,
            'records'    => $records->map(fn ($record) => $this->sanitizeRecord($resource, $record))->values()->all(),
        ];
    }

    public function locationSummary(string $resourceKey, array $filters = [], int $limit = 100): array
    {
        $resource = $this->resource($resourceKey);
        if (!$resource || !$resource->locationField) {
            return ['authorized' => false, 'error' => 'Resource has no registered location field.'];
        }

        if (!$this->can($resource)) {
            return ['authorized' => false, 'resource' => $resource->key];
        }

        $query = $this->applyFilters($resource, $resource->query(), $filters);
        $this->whereValidLocation($query, $resource->locationField);

        $records = $query->latest()->limit(min(max($limit, 1), $resource->maxLimit))->get();

        return [
            'authorized'            => true,
            'resource'              => $resource->key,
            'metric'                => 'location_summary',
            'filters'               => $filters,
            'valid_location_count'  => $records->count(),
            'majority_by_city'      => $records->pluck('city')->filter()->countBy()->sortDesc()->take(5)->all(),
            'majority_by_country'   => $records->pluck('country')->filter()->countBy()->sortDesc()->take(5)->all(),
            'coordinate_samples'    => $records->take(25)->map(fn ($record) => $this->sanitizeRecord($resource, $record, true))->values()->all(),
        ];
    }

    public function applyFilters(AiQueryableResource $resource, Builder $query, array $filters = []): Builder
    {
        foreach ($filters as $filter) {
            $field    = Arr::get($filter, 'field');
            $operator = Arr::get($filter, 'operator', '=');
            $value    = Arr::get($filter, 'value');

            if (!$field || !$resource->hasField($field) || !in_array($operator, self::OPERATORS, true)) {
                continue;
            }

            $column = $resource->columnFor($field);

            match ($operator) {
                'null'          => $query->whereNull($column),
                'not_null'      => $query->whereNotNull($column),
                'false_or_null' => $query->where(function (Builder $nested) use ($column) {
                    $nested->where($column, false)->orWhereNull($column);
                }),
                'in'       => $query->whereIn($column, (array) $value),
                'not_in'   => $query->whereNotIn($column, (array) $value),
                default    => $query->where($column, $operator, $value),
            };
        }

        return $query;
    }

    public function whereValidLocation(Builder $query, string $column = 'location'): Builder
    {
        return $query->whereNotNull($column)->whereRaw("
            ST_Y(`{$column}`) BETWEEN -90 AND 90
            AND ST_X(`{$column}`) BETWEEN -180 AND 180
            AND NOT (ST_X(`{$column}`) = 0 AND ST_Y(`{$column}`) = 0)
        ");
    }

    protected function resource(string $resourceKey): ?AiQueryableResource
    {
        return $this->registry->find($resourceKey);
    }

    protected function can(AiQueryableResource $resource): bool
    {
        if (!$resource->permission) {
            return true;
        }

        $user = Auth::getUserFromSession();
        if ($user?->isAdmin()) {
            return true;
        }

        return Auth::can($resource->permission);
    }

    protected function sanitizeRecord(AiQueryableResource $resource, $record, bool $includeLocation = false): array
    {
        $output = [];

        foreach ($resource->sampleFields as $field) {
            $output[$field] = data_get($record, $field);
        }

        if ($includeLocation && $resource->locationField) {
            $point = data_get($record, $resource->locationField);
            if (is_object($point) && method_exists($point, 'getLat') && method_exists($point, 'getLng')) {
                $output['latitude']  = round((float) $point->getLat(), 5);
                $output['longitude'] = round((float) $point->getLng(), 5);
            }
        }

        return array_filter($output, fn ($value) => $value !== null && $value !== '');
    }
}
