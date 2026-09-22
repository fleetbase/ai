<?php

namespace Fleetbase\Ai\Support\Eval;

use Fleetbase\Ai\Contracts\AIConversationalProviderInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiAgentRunner;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiSystemPrompt;
use Fleetbase\Ai\Support\AiToolContext;

/**
 * Runs golden cases through the same tool-calling runtime users get, without saving tasks, and grades
 * each answer.
 */
class AiEvalRunner
{
    public function __construct(
        protected AIConversationalProviderInterface $provider,
        protected AiCapabilityRegistry $registry,
        protected AiTemporalContext $temporalContext,
        protected AiEvalGrader $grader,
    ) {
    }

    /**
     * @return array{id: string, passed: bool, failures: string[], response: string, tools: string[], ui_commands: string[], usage: array, error?: string}
     */
    public function run(array $case, array $config): array
    {
        $task     = new AiTask(['prompt' => $case['prompt'], 'context' => ['route' => $case['route'] ?? null], 'metadata' => []]);
        $audience = $this->audienceFor($case);
        $context  = new AiToolContext($task, $audience);
        $runner   = new AiAgentRunner($this->provider, $this->registry);
        $tools    = $runner->toolsFor($context);
        $system   = AiSystemPrompt::build($audience, [
            'page'     => AiSystemPrompt::pageName($case['route'] ?? null),
            'has_docs' => isset($tools['search_docs']),
            'tools'    => !empty($tools),
            'commands' => isset($tools['propose_console_command']),
        ]);
        $steps = [];

        try {
            $result = $runner->run($task, $context, $system, (array) ($case['history'] ?? []), AiSystemPrompt::userMessage($task, [$this->temporalContext->context()]), $config, function (array $step) use (&$steps) {
                $steps[] = $step;
            });
        } catch (\Throwable $e) {
            return ['id' => $case['id'], 'passed' => false, 'failures' => ['provider error: ' . $e->getMessage()], 'response' => '', 'tools' => [], 'ui_commands' => [], 'usage' => [], 'error' => $e->getMessage()];
        }

        $outcome = [
            'response'    => (string) $result['content'],
            'tools'       => array_values(array_map(fn ($step) => (string) data_get($step, 'input.name'), array_filter($steps, fn ($step) => ($step['type'] ?? null) === 'tool_call'))),
            'ui_commands' => array_values(array_map(fn ($action) => $action['command_id'], $context->uiActions)),
        ];

        return array_merge(['id' => $case['id'], 'usage' => $result['usage'] ?? []], $outcome, $this->grader->grade((array) ($case['expect'] ?? []), $outcome));
    }

    /**
     * The case's audience: a system admin, or an organization user holding exactly the listed permissions.
     */
    public function audienceFor(array $case): AiAudience
    {
        $permissions = (array) ($case['permissions'] ?? []);

        return new class(($case['audience'] ?? AiAudience::END_USER) === AiAudience::SYSTEM_ADMIN, $permissions) extends AiAudience {
            public function __construct(bool $isSystemAdmin, private array $granted)
            {
                parent::__construct($isSystemAdmin, null);
            }

            protected function checkPermission(string $permission): bool
            {
                return in_array($permission, $this->granted, true);
            }
        };
    }

    /**
     * @return array<int, array>
     */
    public static function loadCases(string $path, array $only = []): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Eval cases not found at {$path}.");
        }

        $cases = (array) (json_decode((string) file_get_contents($path), true)['cases'] ?? []);

        foreach ($cases as $case) {
            if (empty($case['id']) || empty($case['prompt'])) {
                throw new \InvalidArgumentException('Every eval case needs an id and a prompt.');
            }
        }

        return array_values($only ? array_filter($cases, fn ($case) => in_array($case['id'], $only, true)) : $cases);
    }
}
