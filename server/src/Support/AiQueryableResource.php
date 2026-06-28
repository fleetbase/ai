<?php

namespace Fleetbase\Ai\Support;

use Illuminate\Database\Eloquent\Builder;

class AiQueryableResource
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly string $modelClass,
        public readonly ?string $permission = null,
        public readonly string $companyColumn = 'company_uuid',
        public readonly array $aliases = [],
        public readonly array $fields = [],
        public readonly array $sampleFields = [],
        public readonly ?string $locationField = null,
        public readonly ?string $directivePermission = null,
        public readonly int $defaultLimit = 10,
        public readonly int $maxLimit = 100,
    ) {
    }

    public function query(): Builder
    {
        $query = $this->modelClass::query();

        if ($this->companyColumn) {
            $query->where($this->companyColumn, session('company'));
        }

        if ($this->directivePermission) {
            $query->applyDirectivesForPermissions($this->directivePermission);
        }

        return $query;
    }

    public function field(string $field): ?array
    {
        return $this->fields[$field] ?? null;
    }

    public function hasField(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function columnFor(string $field): ?string
    {
        return $this->fields[$field]['column'] ?? null;
    }
}
