<?php

use Fleetbase\Ai\Contracts\AIConversationalProviderInterface;
use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Services\AiAgentRunner;
use Fleetbase\Ai\Services\AiAttachmentResolver;
use Fleetbase\Ai\Services\AiContextResolver;
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiProviderTurn;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Capabilities\AbstractAIToolCapability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

function aiTestTool(string $name, callable $handler, array $permissions = [], array $required = ['query']): AbstractAIToolCapability
{
    return new class($name, $handler, $permissions, $required) extends AbstractAIToolCapability {
        public function __construct(private string $name, private $handler, private array $requiredPermissions, private array $required)
        {
        }

        public function key(): string
        {
            return 'test.' . $this->name;
        }

        public function label(): string
        {
            return ucfirst($this->name);
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function module(): string
        {
            return 'test';
        }

        public function permissions(): array
        {
            return $this->requiredPermissions;
        }

        public function toolName(): string
        {
            return $this->name;
        }

        public function toolDescription(): string
        {
            return "The {$this->name} tool.";
        }

        public function toolParameters(): array
        {
            return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => $this->required];
        }

        public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
        {
            return ($this->handler)($arguments, $context);
        }
    };
}

function aiToolUser(array $permissions = []): AiAudience
{
    return new class(false, 'user-uuid', $permissions) extends AiAudience {
        public function __construct(bool $isSystemAdmin, ?string $userUuid, private array $granted)
        {
            parent::__construct($isSystemAdmin, $userUuid);
        }

        protected function checkPermission(string $permission): bool
        {
            return in_array($permission, $this->granted, true);
        }
    };
}

test('agent runner executes requested tools, records each call, and returns the final answer', function () {
    $registry = (new AiCapabilityRegistry())
        ->register(aiTestTool('search_docs', fn ($arguments) => ['results' => [['title' => 'Users', 'query' => $arguments['query']]]]))
        ->register(aiTestTool('read_doc', fn () => ['content' => str_repeat('x', AiAgentRunner::MAX_TOOL_RESULT_CHARACTERS + 10)], [], ['reference']))
        ->register(aiTestTool('admin_only', fn () => ['secret' => true], ['admin see things']));

    $provider = aiScriptedProvider([
        new AiProviderTurn(text: 'Searching.', toolCalls: [
            ['id' => 'c1', 'name' => 'search_docs', 'arguments' => ['query' => 'add user']],
            ['id' => 'c2', 'name' => 'read_doc', 'arguments' => ['reference' => 'https://fleetbase.io/docs/users']],
        ], stopReason: AiProviderTurn::STOP_TOOLS, usage: ['input_tokens' => 100, 'output_tokens' => 10], provider: 'anthropic', model: 'claude-haiku-4-5', raw: [['type' => 'text', 'text' => 'Searching.']]),
        new AiProviderTurn(text: 'Go to **IAM → Users** and click **New**.', usage: ['input_tokens' => 150, 'output_tokens' => 30, 'cache_read_input_tokens' => 90], provider: 'anthropic', model: 'claude-haiku-4-5', metadata: ['response_id' => 'msg_2']),
    ]);

    $steps   = [];
    $task    = new AiTask(['prompt' => 'Where do I add a user?']);
    $context = new AiToolContext($task, aiToolUser());
    $runner  = new AiAgentRunner($provider, $registry);
    $history = [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello!']];

    $result = $runner->run($task, $context, 'System prompt', $history, 'Where do I add a user?', ['max_tool_iterations' => 4], function ($attributes) use (&$steps) {
        $steps[] = $attributes;
    });

    $second = $provider->requests[1]['messages'];

    expect(array_keys($runner->toolsFor($context)))->toBe(['search_docs', 'read_doc'])
        ->and(array_keys($runner->toolsFor(new AiToolContext($task, new AiAudience(true)))))->toBe(['search_docs', 'read_doc', 'admin_only'])
        ->and($provider->requests[0]['tools'])->toHaveCount(2)
        ->and($provider->requests[0]['system'])->toBe('System prompt')
        ->and($provider->requests[0]['options']['tool_choice'])->toBe('auto')
        ->and($provider->requests[0]['messages'])->toBe(array_merge($history, [['role' => 'user', 'content' => 'Where do I add a user?']]))
        ->and($second[3]['role'])->toBe('assistant')
        ->and($second[3]['raw'])->toBe([['type' => 'text', 'text' => 'Searching.']])
        ->and($second[4])->toMatchArray(['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'search_docs', 'is_error' => false])
        ->and(json_decode($second[4]['content'], true))->toBe(['results' => [['title' => 'Users', 'query' => 'add user']]])
        ->and($second[5]['content'])->toContain('[truncated: the result was too long')
        ->and($steps)->toHaveCount(2)
        ->and($steps[0])->toMatchArray(['type' => 'tool_call', 'status' => 'completed', 'tool' => 'test.search_docs'])
        ->and($steps[0]['input'])->toBe(['name' => 'search_docs', 'arguments' => ['query' => 'add user'], 'iteration' => 1])
        ->and($steps[1]['metadata'])->toBe(['tool_call_id' => 'c2', 'truncated' => true])
        ->and($result['content'])->toBe('Go to **IAM → Users** and click **New**.')
        ->and($result['provider'])->toBe('anthropic')
        ->and($result['usage'])->toBe(['input_tokens' => 250, 'output_tokens' => 40, 'cache_read_input_tokens' => 90])
        ->and($result['metadata'])->toMatchArray(['mode' => 'tool_calling', 'iterations' => 2, 'tool_calls' => 2, 'stop_reason' => 'end', 'truncated' => false, 'refused' => false, 'response_id' => 'msg_2']);
});

test('agent runner rejects unknown and unavailable tools, validates arguments, and hides tool exceptions', function () {
    $registry = (new AiCapabilityRegistry())
        ->register(aiTestTool('search_docs', fn () => throw new RuntimeException("SQLSTATE[42S22]: Unknown column 'sensor_type'")))
        ->register(aiTestTool('admin_only', fn () => ['secret' => true], ['admin see things']));

    $provider = aiScriptedProvider([
        new AiProviderTurn(toolCalls: [
            ['id' => 'a', 'name' => 'made_up_tool', 'arguments' => []],
            ['id' => 'b', 'name' => 'admin_only', 'arguments' => ['query' => 'x']],
            ['id' => 'c', 'name' => 'search_docs', 'arguments' => ['query' => '']],
            ['id' => 'd', 'name' => 'search_docs', 'arguments' => ['query' => 'users']],
        ], stopReason: AiProviderTurn::STOP_TOOLS, provider: 'openai', model: 'gpt-5.4'),
        new AiProviderTurn(text: 'That information is unavailable right now.', provider: 'openai', model: 'gpt-5.4'),
    ]);

    $steps  = [];
    $result = (new AiAgentRunner($provider, $registry))->run(new AiTask(['prompt' => 'status']), new AiToolContext(new AiTask(), aiToolUser()), 'S', [], 'status', [], function ($attributes) use (&$steps) {
        $steps[] = $attributes;
    });

    $toolMessages = array_values(array_filter($provider->requests[1]['messages'], fn ($message) => $message['role'] === 'tool'));

    expect($toolMessages[0]['content'])->toContain('Unknown tool made_up_tool')
        ->and($toolMessages[1]['content'])->toContain('Unknown tool admin_only')
        ->and($toolMessages[2]['content'])->toContain('Missing required arguments: query')
        ->and($toolMessages[3]['content'])->toContain('tool_failed')
        ->and($toolMessages[3]['content'])->not->toContain('SQLSTATE')
        ->and(collect($toolMessages)->every(fn ($message) => $message['is_error'] === true))->toBeTrue()
        ->and($steps[3]['status'])->toBe('failed')
        ->and($steps[3]['error']['message'])->toContain('SQLSTATE')
        ->and($steps[0]['tool'])->toBe('made_up_tool')
        ->and($result['content'])->toBe('That information is unavailable right now.');
});

test('agent runner forces a final answer on the last iteration and handles refusals and truncation', function () {
    $registry = (new AiCapabilityRegistry())->register(aiTestTool('search_docs', fn () => ['results' => []]));
    $loop     = fn () => new AiProviderTurn(toolCalls: [['id' => uniqid(), 'name' => 'search_docs', 'arguments' => ['query' => 'again']]], stopReason: AiProviderTurn::STOP_TOOLS, provider: 'anthropic', model: 'm');
    $provider = aiScriptedProvider([$loop(), $loop(), new AiProviderTurn(text: '', stopReason: AiProviderTurn::STOP_REFUSAL, provider: 'anthropic', model: 'm')]);

    $result = (new AiAgentRunner($provider, $registry))->run(new AiTask(['prompt' => 'loop']), new AiToolContext(new AiTask(), aiToolUser()), 'S', [], 'loop', ['max_tool_iterations' => 3], fn () => null);

    $truncatedProvider = aiScriptedProvider([new AiProviderTurn(text: 'Partial', stopReason: AiProviderTurn::STOP_TRUNCATED, provider: 'openai', model: 'gpt')]);
    $truncated         = (new AiAgentRunner($truncatedProvider, new AiCapabilityRegistry()))->run(new AiTask(['prompt' => 'long']), new AiToolContext(new AiTask(), aiToolUser()), 'S', [], 'long', ['max_tool_iterations' => 0], fn () => null);

    expect(array_column(array_column($provider->requests, 'options'), 'tool_choice'))->toBe(['auto', 'auto', 'none'])
        ->and($result['content'])->toBe('I can’t help with that request.')
        ->and($result['metadata'])->toMatchArray(['iterations' => 3, 'tool_calls' => 2, 'refused' => true])
        ->and($truncatedProvider->requests[0]['tools'])->toBe([])
        ->and($truncatedProvider->requests[0]['options']['tool_choice'])->toBe('none')
        ->and($truncated['metadata'])->toMatchArray(['truncated' => true, 'iterations' => 1])
        ->and($truncated['summary'])->toBe('Partial');
});

test('task service answers through the tool runtime with conversation history and records the audit trail', function () {
    session(['company' => 'company-uuid']);

    $registry = (new AiCapabilityRegistry())->register(aiTestTool('search_docs', fn ($arguments) => ['results' => [['title' => 'Users']]]));
    $provider = aiScriptedProvider([
        new AiProviderTurn(toolCalls: [['id' => 't1', 'name' => 'search_docs', 'arguments' => ['query' => 'add user']]], stopReason: AiProviderTurn::STOP_TOOLS, provider: 'anthropic', model: 'claude-haiku-4-5'),
        new AiProviderTurn(text: 'Open **IAM → Users** and click **New**.', usage: ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15], provider: 'anthropic', model: 'claude-haiku-4-5'),
    ]);

    $steps   = [];
    $history = [
        aiTaskDouble(['prompt' => 'Hello', 'response' => 'Hi! How can I help?', 'status' => 'answered']),
        aiTaskDouble(['prompt' => 'Broken turn', 'response' => null, 'response_summary' => null, 'status' => 'failed']),
    ];

    $service = new class($provider, $registry, $steps, $history) extends AiTaskService {
        public function __construct($provider, AiCapabilityRegistry $registry, private array &$steps, private array $history)
        {
            parent::__construct(
                $provider,
                new class($registry) extends AiContextResolver {
                    public function resolve(AiTask $task): array
                    {
                        throw new RuntimeException('Keyword context must not run in tool mode.');
                    }
                },
                $registry,
                new class extends AiAttachmentResolver {
                    public function resolveFromRequest(Request $request): array
                    {
                        return [['id' => 'file-1', 'preview' => 'manifest']];
                    }
                },
                new class extends AiTemporalContext {
                    public function context(): array
                    {
                        return ['capability' => 'fleetbase.ai.temporal_context', 'data' => ['timezone' => 'UTC']];
                    }
                },
            );
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $step          = aiStepDouble($attributes);
            $this->steps[] = $step;

            return $step;
        }

        protected function audienceFor(Request $request): AiAudience
        {
            return aiToolUser();
        }

        protected function systemAiConfig(): array
        {
            return ['enabled' => true, 'provider' => 'anthropic', 'default_model' => 'claude-haiku-4-5'];
        }

        protected function createTask(array $attributes): AiTask
        {
            return aiTaskDouble(array_merge($attributes, ['uuid' => 'tool-task']));
        }

        protected function createSession(array $attributes): AiSession
        {
            return aiSessionDouble(array_merge($attributes, ['uuid' => 'tool-session']));
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            $rows = [];

            return aiTaskServiceQueryBuilder($rows);
        }

        protected function sessionHistoryForTask(AiTask $task): Builder
        {
            $rows = [];

            return aiTaskServiceQueryBuilder($rows, array_reverse($this->history));
        }
    };

    $task = $service->createFromRequest(aiCreateRequest([
        'prompt'      => 'Where do I add a user?',
        'context'     => ['route' => 'console.fleet-ops.operations.orders.index'],
        'attachments' => ['file-1'],
    ]));

    $firstRequest = $provider->requests[0];
    $providerStep = collect($steps)->firstWhere('type', 'provider_call');

    expect($service->usesTools(['enabled' => true, 'provider' => 'anthropic']))->toBeTrue()
        ->and($task->status)->toBe('answered')
        ->and($task->response)->toBe('Open **IAM → Users** and click **New**.')
        ->and($task->metadata['mode'])->toBe('tool_calling')
        ->and($task->metadata['tool_calls'])->toBe(1)
        ->and($task->metadata['audience'])->toBe(['is_system_admin' => false])
        ->and($task->metadata['action_previews'])->toBe([])
        ->and($task->metadata['ui_actions'])->toBe([])
        ->and($firstRequest['messages'])->toHaveCount(4)
        ->and($firstRequest['messages'][0])->toBe(['role' => 'user', 'content' => 'Hello'])
        ->and($firstRequest['messages'][1])->toBe(['role' => 'assistant', 'content' => 'Hi! How can I help?'])
        ->and($firstRequest['messages'][2])->toBe(['role' => 'user', 'content' => 'Broken turn'])
        ->and($firstRequest['messages'][3]['content'])->toStartWith("<user_request>\nWhere do I add a user?\n</user_request>")
        ->and($firstRequest['messages'][3]['content'])->toContain('fleetbase.ai.temporal_context')
        ->and($firstRequest['messages'][3]['content'])->toContain('fleetbase.ai.attachments')
        ->and($task->metadata['attachments'])->toBe([['id' => 'file-1', 'preview' => 'manifest']])
        ->and($firstRequest['system'])->toContain('## Tools')
        ->and($firstRequest['system'])->toContain('search the documentation first')
        ->and($firstRequest['system'])->toContain('The user is currently on: Fleet-Ops › Operations › Orders')
        ->and(array_map(fn ($step) => $step->type, $steps))->toBe(['temporal_context', 'attachment_context', 'provider_call', 'tool_call'])
        ->and(collect($steps)->firstWhere('type', 'attachment_context')->input)->toBe(['attachments' => ['file-1']])
        ->and($providerStep->status)->toBe('completed')
        ->and($providerStep->input['tools'])->toBe(['search_docs'])
        ->and($providerStep->input['history'])->toHaveCount(3);
});

test('task service marks tool mode turns failed when the provider errors', function () {
    session(['company' => 'company-uuid']);

    $provider = new class implements AIConversationalProviderInterface, AIProviderInterface {
        public function supportsTools(array $config = []): bool
        {
            return true;
        }

        public function converse(string $system, array $messages, array $tools = [], array $options = []): AiProviderTurn
        {
            throw new RuntimeException('Anthropic request failed with status code: 529');
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

    $steps   = [];
    $service = new class($provider, $steps) extends AiTaskService {
        public function __construct($provider, private array &$steps)
        {
            $registry = new AiCapabilityRegistry();
            parent::__construct($provider, new AiContextResolver($registry), $registry, new AiAttachmentResolver(), new class extends AiTemporalContext {
                public function context(): array
                {
                    return ['capability' => 'fleetbase.ai.temporal_context'];
                }
            });
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $step          = aiStepDouble($attributes);
            $this->steps[] = $step;

            return $step;
        }

        protected function audienceFor(Request $request): AiAudience
        {
            return aiToolUser();
        }

        protected function systemAiConfig(): array
        {
            return ['enabled' => true, 'provider' => 'anthropic'];
        }

        protected function createTask(array $attributes): AiTask
        {
            return aiTaskDouble(array_merge($attributes, ['uuid' => 'failing-task', 'ai_session_uuid' => null]));
        }

        protected function createSession(array $attributes): AiSession
        {
            return aiSessionDouble(array_merge($attributes, ['uuid' => 'session']));
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            $rows = [];

            return aiTaskServiceQueryBuilder($rows);
        }
    };

    $task = $service->createFromRequest(aiCreateRequest(['prompt' => 'hello', 'attachments' => []]));

    expect($task->status)->toBe('failed')
        ->and($task->error['message'])->toContain('529')
        ->and(collect($steps)->firstWhere('type', 'provider_call')->status)->toBe('failed');
});

test('agent runner reports capabilities that no tool definition can reach', function () {
    // A capability registered without a tool definition: nothing offers it to the model, so a shipped
    // feature would otherwise disappear silently.
    $legacy = new class extends Fleetbase\Ai\Support\Capabilities\AbstractAICapability {
        public function key(): string
        {
            return 'fleet-ops.import_orders_preview';
        }

        public function label(): string
        {
            return 'Import orders';
        }

        public function description(): string
        {
            return 'Legacy capability without a tool definition.';
        }

        public function module(): string
        {
            return 'fleet-ops';
        }
    };

    $registry = (new AiCapabilityRegistry())
        ->register(aiTestTool('search_docs', fn () => ['results' => []]))
        ->register($legacy);

    $provider = aiScriptedProvider([
        new AiProviderTurn(text: 'Done.', provider: 'anthropic', model: 'claude-haiku-4-5'),
    ]);

    $steps   = [];
    $task    = new AiTask(['prompt' => 'Import my orders']);
    $runner  = new AiAgentRunner($provider, $registry);
    $result  = $runner->run($task, new AiToolContext($task, aiToolUser()), 'System', [], 'Import my orders', [], function ($attributes) use (&$steps) {
        $steps[] = $attributes;
    });

    $reported = array_values(array_filter($steps, fn ($step) => $step['type'] === 'capabilities_unreachable'));

    expect($runner->unreachableCapabilities())->toBe(['fleet-ops.import_orders_preview'])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]['output'])->toBe(['capabilities' => ['fleet-ops.import_orders_preview']])
        ->and($reported[0]['status'])->toBe('completed')
        ->and($result['metadata']['unreachable_capabilities'])->toBe(['fleet-ops.import_orders_preview']);
});

test('agent runner omits the unreachable report when every capability is tool callable', function () {
    $registry = (new AiCapabilityRegistry())->register(aiTestTool('search_docs', fn () => ['results' => []]));
    $provider = aiScriptedProvider([
        new AiProviderTurn(text: 'Done.', provider: 'anthropic', model: 'claude-haiku-4-5'),
    ]);

    $steps  = [];
    $task   = new AiTask(['prompt' => 'Hello']);
    $runner = new AiAgentRunner($provider, $registry);
    $result = $runner->run($task, new AiToolContext($task, aiToolUser()), 'System', [], 'Hello', [], function ($attributes) use (&$steps) {
        $steps[] = $attributes;
    });

    expect($runner->unreachableCapabilities())->toBe([])
        ->and(array_filter($steps, fn ($step) => $step['type'] === 'capabilities_unreachable'))->toBe([])
        ->and($result['metadata'])->not->toHaveKey('unreachable_capabilities');
});
