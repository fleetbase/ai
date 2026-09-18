<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIContextCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiCapabilityRegistry;

class AiContextResolver
{
    public function __construct(protected AiCapabilityRegistry $registry)
    {
    }

    public function resolve(AiTask $task): array
    {
        $context = [];

        foreach ($this->registry->all() as $capability) {
            if (!$capability instanceof AIContextCapabilityInterface) {
                continue;
            }

            if (!$capability->shouldResolve($task)) {
                continue;
            }

            $failure = null;

            try {
                $result = $capability->resolve($task);
            } catch (\Throwable $e) {
                // The provider only learns that the capability was unavailable. Exception details
                // (which can include SQL and tenant identifiers) stay in the audit trail.
                $result  = ['error' => 'capability_unavailable'];
                $failure = [
                    'message' => $e->getMessage(),
                    'type'    => get_class($e),
                ];
                $this->reportFailure($e);
            }

            $entry = [
                'key'          => $capability->key(),
                'label'        => $capability->label(),
                'module'       => $capability->module(),
                'type'         => $capability->type(),
                'mode'         => $capability->mode(),
                'preview_only' => $capability->previewOnly(),
                'result'       => $result,
            ];

            if ($failure) {
                $entry['failure'] = $failure;
            }

            $context[] = $entry;
        }

        return $context;
    }

    /**
     * Remove internal failure details so resolved context can be sent to a provider.
     */
    public static function forProvider(array $context): array
    {
        return array_map(function ($entry) {
            unset($entry['failure']);

            return $entry;
        }, $context);
    }

    /**
     * Whether any capability failed while resolving context.
     */
    public static function hasFailures(array $context): bool
    {
        foreach ($context as $entry) {
            if (!empty($entry['failure'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function reportFailure(\Throwable $e): void
    {
        if (function_exists('report')) {
            try {
                report($e);
            } catch (\Throwable) {
            }
        }
    }
}
