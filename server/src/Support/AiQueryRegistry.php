<?php

namespace Fleetbase\Ai\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiQueryRegistry
{
    protected array $resources = [];

    public function register(AiQueryableResource $resource): static
    {
        $this->resources[$resource->key] = $resource;

        return $this;
    }

    public function all(): Collection
    {
        return collect($this->resources)->values();
    }

    public function get(string $key): ?AiQueryableResource
    {
        return $this->resources[$key] ?? null;
    }

    public function find(string $resourceOrAlias): ?AiQueryableResource
    {
        $resourceOrAlias = Str::lower($resourceOrAlias);

        foreach ($this->resources as $resource) {
            if ($resource->key === $resourceOrAlias || in_array($resourceOrAlias, $resource->aliases, true)) {
                return $resource;
            }
        }

        return null;
    }
}
