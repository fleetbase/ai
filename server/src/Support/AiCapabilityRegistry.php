<?php

namespace Fleetbase\Ai\Support;

use Fleetbase\Ai\Contracts\AICapabilityInterface;
use Illuminate\Support\Collection;

class AiCapabilityRegistry
{
    protected array $capabilities = [];

    public function register(AICapabilityInterface $capability): static
    {
        $this->capabilities[$capability->key()] = $capability;

        return $this;
    }

    public function all(): Collection
    {
        return collect($this->capabilities)->values();
    }

    public function list(): array
    {
        return $this->all()->map(fn (AICapabilityInterface $capability) => $capability->toArray())->values()->all();
    }

    public function has(string $key): bool
    {
        return isset($this->capabilities[$key]);
    }

    public function get(string $key): ?AICapabilityInterface
    {
        return $this->capabilities[$key] ?? null;
    }
}
