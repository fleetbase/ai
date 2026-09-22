<?php

namespace Fleetbase\Ai\Support;

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Illuminate\Support\Str;

/**
 * Helpers for action previews stored on a task. A task can hold several previews of the same action
 * (for example several draft orders), so each preview carries its own `preview_id`.
 */
class AiActionPreview
{
    public static function normalize(AIActionCapabilityInterface $capability, array $preview, ?string $previewId = null): array
    {
        $normalized = array_merge([
            'preview_id'   => $preview['preview_id'] ?? (string) Str::uuid(),
            'key'          => $capability->key(),
            'label'        => $capability->label(),
            'module'       => $capability->module(),
            'type'         => $capability->type(),
            'mode'         => $capability->mode(),
            'permissions'  => $capability->permissions(),
            'preview_only' => $capability->previewOnly(),
            'executable'   => $capability->executable(),
        ], $preview);

        if ($previewId) {
            $normalized['preview_id'] = $previewId;
        }

        return $normalized;
    }

    /**
     * Find a preview by preview id, falling back to the action key (or legacy `action`) for older tasks.
     */
    public static function find(array $previews, ?string $previewId = null, ?string $actionKey = null): ?array
    {
        $previews = array_values(array_filter($previews, 'is_array'));

        if ($previewId) {
            foreach ($previews as $preview) {
                if (($preview['preview_id'] ?? null) === $previewId) {
                    return $preview;
                }
            }

            return null;
        }

        if ($actionKey) {
            foreach ($previews as $preview) {
                if (($preview['key'] ?? $preview['action'] ?? null) === $actionKey) {
                    return $preview;
                }
            }

            return null;
        }

        return $previews[0] ?? null;
    }

    /**
     * Whether two stored previews refer to the same preview.
     */
    public static function same(array $a, array $b): bool
    {
        if (!empty($a['preview_id']) && !empty($b['preview_id'])) {
            return $a['preview_id'] === $b['preview_id'];
        }

        return ($a['key'] ?? $a['action'] ?? null) === ($b['key'] ?? $b['action'] ?? null);
    }
}
