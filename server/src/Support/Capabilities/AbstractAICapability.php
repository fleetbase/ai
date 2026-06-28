<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Contracts\AICapabilityInterface;

abstract class AbstractAICapability implements AICapabilityInterface
{
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
        $metadata = [
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

        if (method_exists($this, 'inputSchema')) {
            $metadata['input_schema'] = $this->inputSchema();
        }

        return $metadata;
    }
}
