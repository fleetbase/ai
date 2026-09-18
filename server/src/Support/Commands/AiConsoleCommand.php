<?php

namespace Fleetbase\Ai\Support\Commands;

use Fleetbase\Ai\Support\AiAudience;

/**
 * A console action Fleetbase AI may propose, such as opening a page or a create dialog.
 *
 * Commands never run on the server. The model proposes one, the user confirms it, and the console
 * runs its steps: `navigate` transitions to a route, `service` calls an allowlisted method on an engine
 * resource-action service (e.g. `user-actions` → `modal.create`).
 */
class AiConsoleCommand
{
    /**
     * @param array $steps  list of `['type' => 'navigate', 'route' => string, 'models' => [param names]]` or
     *                      `['type' => 'service', 'engine' => string, 'service' => string, 'method' => string]`
     * @param array $params parameter names mapped to `['type' => 'string', 'description' => string]`
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $breadcrumb,
        public readonly string $description,
        public readonly array $steps,
        public readonly array $permissions = [],
        public readonly string $audience = AiAudience::END_USER,
        public readonly array $params = [],
        public readonly array $keywords = [],
        public readonly ?string $docsUrl = null,
        public readonly string $module = 'core',
    ) {
    }

    /**
     * Build a command from an array definition, so extensions can register commands without
     * depending on this class. Keys: id, label, breadcrumb, description, steps, and optionally
     * permissions, audience, params, keywords, docs_url, module.
     */
    public static function fromArray(array $definition): static
    {
        foreach (['id', 'label', 'breadcrumb', 'description', 'steps'] as $key) {
            if (empty($definition[$key])) {
                throw new \InvalidArgumentException("AI console command definitions require '{$key}'.");
            }
        }

        foreach ((array) $definition['steps'] as $step) {
            $valid = match ($step['type'] ?? null) {
                'navigate' => !empty($step['route']),
                'service'  => !empty($step['engine']) && !empty($step['service']) && preg_match('/^[a-zA-Z][a-zA-Z0-9]*(\.[a-zA-Z][a-zA-Z0-9]*)?$/', (string) ($step['method'] ?? '')),
                default    => false,
            };

            if (!$valid) {
                throw new \InvalidArgumentException("AI console command {$definition['id']} has an invalid step.");
            }
        }

        return new static(
            id: $definition['id'],
            label: $definition['label'],
            breadcrumb: $definition['breadcrumb'],
            description: $definition['description'],
            steps: array_values((array) $definition['steps']),
            permissions: (array) ($definition['permissions'] ?? []),
            audience: $definition['audience'] ?? AiAudience::END_USER,
            params: (array) ($definition['params'] ?? []),
            keywords: (array) ($definition['keywords'] ?? []),
            docsUrl: $definition['docs_url'] ?? null,
            module: $definition['module'] ?? 'core',
        );
    }

    public static function navigate(string $id, string $label, string $breadcrumb, string $description, string $route, array $options = []): static
    {
        return new static(
            id: $id,
            label: $label,
            breadcrumb: $breadcrumb,
            description: $description,
            steps: [array_filter(['type' => 'navigate', 'route' => $route, 'models' => $options['models'] ?? null])],
            permissions: $options['permissions'] ?? [],
            audience: $options['audience'] ?? AiAudience::END_USER,
            params: $options['params'] ?? [],
            keywords: $options['keywords'] ?? [],
            docsUrl: $options['docs_url'] ?? null,
            module: $options['module'] ?? 'core',
        );
    }

    /**
     * A command that opens a page, then calls a resource-action service method there.
     */
    public static function dialog(string $id, string $label, string $breadcrumb, string $description, string $route, string $engine, string $service, string $method, array $options = []): static
    {
        return new static(
            id: $id,
            label: $label,
            breadcrumb: $breadcrumb,
            description: $description,
            steps: [
                ['type' => 'navigate', 'route' => $route],
                ['type' => 'service', 'engine' => $engine, 'service' => $service, 'method' => $method],
            ],
            permissions: $options['permissions'] ?? [],
            audience: $options['audience'] ?? AiAudience::END_USER,
            params: $options['params'] ?? [],
            keywords: $options['keywords'] ?? [],
            docsUrl: $options['docs_url'] ?? null,
            module: $options['module'] ?? 'core',
        );
    }

    public function availableTo(AiAudience $audience): bool
    {
        return $audience->allows($this->audience) && $audience->canAll($this->permissions);
    }

    /**
     * Returns an error message when required parameters are missing, otherwise null.
     */
    public function validateParams(array $params): ?string
    {
        $missing = array_values(array_filter(array_keys($this->params), fn ($name) => blank($params[$name] ?? null)));

        return $missing ? 'Missing command parameters: ' . implode(', ', $missing) . '.' : null;
    }

    /**
     * Steps with parameter references resolved to values, as sent to the console.
     */
    public function resolvedSteps(array $params = []): array
    {
        return array_map(function ($step) use ($params) {
            if (isset($step['models'])) {
                $step['models'] = array_map(fn ($name) => (string) ($params[$name] ?? ''), (array) $step['models']);
            }

            return $step;
        }, $this->steps);
    }

    public function summary(): array
    {
        return array_filter([
            'id'          => $this->id,
            'label'       => $this->label,
            'breadcrumb'  => $this->breadcrumb,
            'description' => $this->description,
            'params'      => $this->params ?: null,
            'docs_url'    => $this->docsUrl,
        ]);
    }
}
