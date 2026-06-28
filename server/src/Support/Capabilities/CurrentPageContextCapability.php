<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Contracts\AICapabilityInterface;

class CurrentPageContextCapability implements AICapabilityInterface
{
    public function key(): string
    {
        return 'core.current_page_context';
    }

    public function label(): string
    {
        return 'Current page context';
    }

    public function description(): string
    {
        return 'Allows Fleetbase AI to use the current route and URL context submitted with a prompt.';
    }

    public function module(): string
    {
        return 'core';
    }

    public function type(): string
    {
        return 'read';
    }

    public function mode(): string
    {
        return 'context';
    }

    public function permissions(): array
    {
        return [];
    }

    public function previewOnly(): bool
    {
        return true;
    }

    public function executable(): bool
    {
        return false;
    }

    public function toArray(): array
    {
        return [
            'key'          => $this->key(),
            'label'        => $this->label(),
            'description'  => $this->description(),
            'module'       => $this->module(),
            'type'         => $this->type(),
            'mode'         => $this->mode(),
            'permissions'  => $this->permissions(),
            'preview_only' => $this->previewOnly(),
            'executable'   => $this->executable(),
        ];
    }
}
