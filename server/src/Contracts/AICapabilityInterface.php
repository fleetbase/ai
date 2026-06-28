<?php

namespace Fleetbase\Ai\Contracts;

interface AICapabilityInterface
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    public function module(): string;

    public function type(): string;

    public function mode(): string;

    public function permissions(): array;

    public function previewOnly(): bool;

    public function executable(): bool;

    public function toArray(): array;
}
