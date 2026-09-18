<?php

use Fleetbase\Ai\Console\Commands\EvaluateAi;
use Fleetbase\Ai\Console\Commands\ReplayAiTask;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiProviderTurn;
use Fleetbase\Ai\Support\Capabilities\ProposeConsoleCommandTool;
use Fleetbase\Ai\Support\Commands\AiCommandRegistry;
use Fleetbase\Ai\Support\Commands\CoreConsoleCommands;
use Fleetbase\Ai\Support\Eval\AiEvalGrader;
use Fleetbase\Ai\Support\Eval\AiEvalRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function aiEvalTemporal(): AiTemporalContext
{
    return new class extends AiTemporalContext {
        public function context(): array
        {
            return ['capability' => 'fleetbase.ai.temporal_context', 'data' => ['timezone' => 'UTC']];
        }
    };
}

function aiEvalCommand(string $class, $provider, array $input, array $overrides = []): array
{
    $command = new $class($overrides);
    $command->setLaravel(new Illuminate\Container\Container());
    $buffer = new BufferedOutput();
    $args   = new ArrayInput($input, $command->getDefinition());
    $command->setInput($args);
    $command->setOutput(new Illuminate\Console\OutputStyle($args, $buffer));

    $registry = (new AiCapabilityRegistry())->register(new ProposeConsoleCommandTool((new AiCommandRegistry())->registerMany(CoreConsoleCommands::all())));

    return [$command->handle($provider, $registry, aiEvalTemporal()), $buffer->fetch()];
}

test('grader checks tools, console actions, phrases, and leaked route names', function () {
    $grader = new AiEvalGrader();
    $expect = [
        'required_tools'           => ['search_docs'],
        'forbidden_tools'          => ['list_records'],
        'min_tool_calls'           => ['propose_create_order' => 2],
        'required_ui_commands_any' => ['iam.users.create'],
        'forbidden_ui_commands'    => ['admin.config.services.open'],
        'must_contain_all_of_any'  => [['IAM > Users'], ['New', 'Invite']],
        'must_not_contain'         => ['Admin >'],
        'must_not_match'           => ['/\\bAPI key\\b/i'],
    ];

    $pass = $grader->grade($expect, [
        'response'    => 'Open **IAM → Users** and click **New**.',
        'tools'       => ['search_docs', 'propose_create_order', 'propose_create_order'],
        'ui_commands' => ['iam.users.create'],
    ]);

    $fail = $grader->grade($expect, [
        'response'    => 'Go to Admin › Config and paste your API key at console.admin.config.services.',
        'tools'       => ['list_records', 'propose_create_order'],
        'ui_commands' => ['admin.config.services.open'],
    ]);

    expect($pass)->toBe(['passed' => true, 'failures' => []])
        ->and($fail['passed'])->toBeFalse()
        ->and($fail['failures'])->toBe([
            'did not call search_docs',
            'called forbidden tool list_records',
            'called propose_create_order 1 times, expected at least 2',
            'did not propose any of: iam.users.create',
            'proposed forbidden command admin.config.services.open',
            'answer does not mention any of: IAM > Users',
            'answer does not mention any of: New | Invite',
            'answer contains "Admin >"',
            'answer matches ' . AiEvalGrader::ROUTE_NAME_PATTERN . ' ("console.admin.config.services")',
            'answer matches /\\bAPI key\\b/i ("API key")',
        ])
        ->and($grader->normalize("**Fleet-Ops** › Settings\n→  Map"))->toBe('fleet-ops > settings > map');
});

test('packaged golden cases load and only use supported expectations', function () {
    $cases     = AiEvalRunner::loadCases(EvaluateAi::defaultCasesPath());
    $supported = ['required_tools', 'forbidden_tools', 'min_tool_calls', 'required_ui_commands_any', 'forbidden_ui_commands', 'must_contain_all_of_any', 'must_not_contain', 'must_not_match'];
    $ids       = array_column($cases, 'id');

    expect(count($cases))->toBeGreaterThanOrEqual(15)
        ->and(array_unique($ids))->toBe($ids)
        ->and(collect($cases)->every(fn ($case) => empty(array_diff(array_keys($case['expect'] ?? []), $supported))))->toBeTrue()
        ->and(collect($cases)->every(fn ($case) => in_array($case['audience'] ?? 'end_user', ['end_user', 'system_admin'], true)))->toBeTrue()
        ->and($ids)->toContain('add-user-where', 'view-maps-api-key-end-user', 'view-maps-api-key-system-admin', 'dummy-orders')
        ->and(AiEvalRunner::loadCases(EvaluateAi::defaultCasesPath(), ['add-user-where']))->toHaveCount(1)
        ->and(fn () => AiEvalRunner::loadCases('/missing/cases.json'))->toThrow(InvalidArgumentException::class, 'not found');

    $invalid = sys_get_temp_dir() . '/ai-eval-invalid-' . uniqid() . '.json';
    file_put_contents($invalid, json_encode(['cases' => [['id' => 'no-prompt']]]));

    expect(fn () => AiEvalRunner::loadCases($invalid))->toThrow(InvalidArgumentException::class, 'id and a prompt');
});

test('eval runner uses the case audience, history, and page, and grades the answer', function () {
    $provider = aiScriptedProvider([
        new AiProviderTurn(toolCalls: [['id' => 't1', 'name' => 'propose_console_command', 'arguments' => ['command_id' => 'iam.users.create']]], stopReason: AiProviderTurn::STOP_TOOLS, provider: 'anthropic', model: 'claude-haiku-4-5'),
        new AiProviderTurn(text: 'You can add users in **IAM → Users**. Confirm below to open the form.', usage: ['input_tokens' => 20, 'output_tokens' => 10], provider: 'anthropic', model: 'claude-haiku-4-5'),
    ]);
    $registry = (new AiCapabilityRegistry())->register(new ProposeConsoleCommandTool((new AiCommandRegistry())->registerMany(CoreConsoleCommands::all())));
    $runner   = new AiEvalRunner($provider, $registry, aiEvalTemporal(), new AiEvalGrader());

    $result = $runner->run([
        'id'          => 'add-user',
        'prompt'      => 'Where do I add a user?',
        'route'       => 'console.fleet-ops.operations.orders.index',
        'history'     => [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello']],
        'audience'    => 'end_user',
        'permissions' => ['iam list user', 'iam create user'],
        'expect'      => ['required_ui_commands_any' => ['iam.users.create'], 'must_contain_all_of_any' => [['IAM > Users']]],
    ], ['max_tool_iterations' => 3]);

    $failing = aiScriptedProvider([]);
    $broken  = new class implements Fleetbase\Ai\Contracts\AIConversationalProviderInterface {
        public function supportsTools(array $config = []): bool
        {
            return true;
        }

        public function converse(string $system, array $messages, array $tools = [], array $options = []): AiProviderTurn
        {
            throw new RuntimeException('Overloaded');
        }
    };

    expect($result)->toMatchArray(['id' => 'add-user', 'passed' => true, 'failures' => [], 'tools' => ['propose_console_command'], 'ui_commands' => ['iam.users.create']])
        ->and($provider->requests[0]['system'])->toContain('The user is currently on: Fleet-Ops › Operations › Orders')
        ->and($provider->requests[0]['system'])->toContain('not a Fleetbase system administrator')
        ->and($provider->requests[0]['messages'][0])->toBe(['role' => 'user', 'content' => 'Hi'])
        ->and($runner->audienceFor(['audience' => 'system_admin'])->isSystemAdmin)->toBeTrue()
        ->and($runner->audienceFor(['permissions' => ['iam list user']])->can('iam list user'))->toBeTrue()
        ->and($runner->audienceFor(['permissions' => ['iam list user']])->can('iam create user'))->toBeFalse()
        ->and((new AiEvalRunner($broken, $registry, aiEvalTemporal(), new AiEvalGrader()))->run(['id' => 'x', 'prompt' => 'hi'], []))->toMatchArray(['passed' => false, 'error' => 'Overloaded']);
});

test('ai:eval runs selected cases, reports the pass rate, and writes json results', function () {
    $provider = new class implements Fleetbase\Ai\Contracts\AIConversationalProviderInterface, Fleetbase\Ai\Contracts\AIProviderInterface {
        public function supportsTools(array $config = []): bool
        {
            return ($config['provider'] ?? null) === 'anthropic';
        }

        public function converse(string $system, array $messages, array $tools = [], array $options = []): AiProviderTurn
        {
            return new AiProviderTurn(text: 'Go to **IAM → Users** and click **New**.', usage: ['input_tokens' => 5, 'output_tokens' => 5], provider: 'anthropic', model: 'claude-sonnet-5');
        }

        public function complete(AiTask $task, array $messages = [], array $options = []): array
        {
            return [];
        }

        public function test(array $config = []): array
        {
            return [];
        }
    };

    $command = new class extends EvaluateAi {
        public function __construct(array $overrides = [])
        {
            parent::__construct();
        }

        protected function systemConfig(): array
        {
            return ['enabled' => true, 'provider' => 'anthropic', 'default_model' => 'claude-haiku-4-5'];
        }
    };

    $json = sys_get_temp_dir() . '/ai-eval-results-' . uniqid() . '.json';

    [$code, $output]                   = aiEvalCommand(get_class($command), $provider, ['--case' => ['add-user-where', 'view-maps-api-key-end-user'], '--model' => 'claude-sonnet-5', '--json' => $json, '--company' => 'company-1', '--yes' => true]);
    [$unsupported, $unsupportedOutput] = aiEvalCommand(get_class($command), $provider, ['--provider' => 'openai', '--yes' => true]);
    [$missing, $missingOutput]         = aiEvalCommand(get_class($command), $provider, ['file' => '/missing/cases.json', '--yes' => true]);

    $results = json_decode(file_get_contents($json), true);

    expect($code)->toBe(1)
        ->and($output)->toContain('FAIL add-user-where')
        ->and($output)->toContain('did not call search_docs')
        ->and($output)->toContain('0/2 cases passed (0%) with anthropic claude-sonnet-5, 20 tokens.')
        ->and($results['model'])->toBe('claude-sonnet-5')
        ->and($results['total'])->toBe(2)
        ->and(session('company'))->toBe('company-1')
        ->and($unsupported)->toBe(1)
        ->and($unsupportedOutput)->toContain('need an enabled OpenAI or Claude provider')
        ->and($missing)->toBe(1)
        ->and($missingOutput)->toContain('not found');
});

test('ai:replay re-runs a recorded turn with its history and shows both answers', function () {
    $provider = aiScriptedProvider([new AiProviderTurn(text: 'Open **Fleet-Ops › Settings › Map** and choose a map provider.', provider: 'anthropic', model: 'claude-haiku-4-5')]);

    $task = aiTaskDouble([
        'uuid'            => 'task-9',
        'id'              => 9,
        'company_uuid'    => 'company-9',
        'ai_session_uuid' => 'session-9',
        'prompt'          => 'how do i view maps? i see there is an api key required?',
        'response'        => 'Go to Settings > Integrations and add your Google Maps API key.',
        'provider'        => 'openai',
        'model'           => 'gpt-5.4-mini',
        'context'         => ['route' => 'console.fleet-ops.operations.orders.index'],
    ]);

    $command = new class($task) extends ReplayAiTask {
        public function __construct(private $recorded = null)
        {
            parent::__construct();
        }

        protected function findTask(string $uuid): ?AiTask
        {
            return $uuid === 'task-9' ? $this->recorded : null;
        }

        protected function previousTurns(AiTask $task)
        {
            return collect([aiTaskDouble(['prompt' => 'hello', 'response' => 'Hi!']), aiTaskDouble(['prompt' => 'still there?', 'response' => null])]);
        }

        protected function systemConfig(): array
        {
            return ['enabled' => true, 'provider' => 'anthropic', 'default_model' => 'claude-haiku-4-5'];
        }
    };

    $run = function (array $input) use ($command, $provider) {
        $command->setLaravel(new Illuminate\Container\Container());
        $buffer = new BufferedOutput();
        $args   = new ArrayInput($input, $command->getDefinition());
        $command->setInput($args);
        $command->setOutput(new Illuminate\Console\OutputStyle($args, $buffer));

        return [$command->handle($provider, new AiCapabilityRegistry(), aiEvalTemporal()), $buffer->fetch()];
    };

    [$code, $output]             = $run(['task' => 'task-9', '--yes' => true, '--permission' => ['fleet-ops view map-settings']]);
    [$notFound, $notFoundOutput] = $run(['task' => 'nope', '--yes' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Recorded answer (openai gpt-5.4-mini)')
        ->and($output)->toContain('Go to Settings > Integrations')
        ->and($output)->toContain('Open **Fleet-Ops › Settings › Map**')
        ->and($output)->toContain('Tools called: none')
        ->and($provider->requests[0]['messages'])->toHaveCount(4)
        ->and($provider->requests[0]['messages'][2])->toBe(['role' => 'user', 'content' => 'still there?'])
        ->and(session('company'))->toBe('company-9')
        ->and($notFound)->toBe(1)
        ->and($notFoundOutput)->toContain('AI task not found');
});
