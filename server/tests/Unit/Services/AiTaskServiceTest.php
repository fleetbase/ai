<?php

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Services\AiAttachmentResolver;
use Fleetbase\Ai\Services\AiContextResolver;
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Services\LocalAIProvider;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

function aiActionCapability(array $overrides = []): AIActionCapabilityInterface
{
    return new class($overrides) implements AIActionCapabilityInterface {
        public function __construct(private array $overrides)
        {
        }

        public function key(): string
        {
            return $this->overrides['key'] ?? 'fleetbase.action';
        }

        public function label(): string
        {
            return $this->overrides['label'] ?? 'Fleetbase action';
        }

        public function description(): string
        {
            return 'Action capability for task-service tests.';
        }

        public function module(): string
        {
            return $this->overrides['module'] ?? 'ai';
        }

        public function type(): string
        {
            return $this->overrides['type'] ?? 'write';
        }

        public function mode(): string
        {
            return $this->overrides['mode'] ?? 'action';
        }

        public function permissions(): array
        {
            return $this->overrides['permissions'] ?? ['ai apply actions'];
        }

        public function previewOnly(): bool
        {
            return $this->overrides['preview_only'] ?? false;
        }

        public function executable(): bool
        {
            return $this->overrides['executable'] ?? true;
        }

        public function toArray(): array
        {
            return [];
        }

        public function shouldPreview(AiTask $task): bool
        {
            return $this->overrides['should_preview'] ?? true;
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }

        public function preview(AiTask $task, array $input = []): array
        {
            return $this->overrides['preview'] ?? ['draft' => ['prompt' => $task->prompt, 'input' => $input]];
        }

        public function apply(AiTask $task, array $preview = [], array $input = []): array
        {
            if (($this->overrides['throws'] ?? false) === true) {
                throw new RuntimeException('Apply failed.');
            }

            return $this->overrides['result'] ?? [
                'status'  => 'applied',
                'message' => 'Action applied.',
                'preview' => $preview,
                'input'   => $input,
            ];
        }
    };
}

function aiTaskDouble(array $attributes = []): AiTask
{
    return new class($attributes) extends AiTask {
        protected $attributes = [];

        public array $updates = [];

        public function __construct(array $attributes = [])
        {
            $this->attributes = array_merge(['uuid' => 'task-uuid'], $attributes);
        }

        public function __get($key)
        {
            return $this->attributes[$key] ?? null;
        }

        public function __set($key, $value): void
        {
            $this->attributes[$key] = $value;
        }

        public function update(array $attributes = [], array $options = [])
        {
            $this->updates[]  = $attributes;
            $this->attributes = array_merge($this->attributes, $attributes);

            return true;
        }

        public function fresh($with = [])
        {
            return $this;
        }
    };
}

function aiStepDouble(array $attributes = []): AiTaskStep
{
    return new class($attributes) extends AiTaskStep {
        protected $attributes = [];

        public array $updates = [];

        public function __construct(array $attributes = [])
        {
            $this->attributes = $attributes;
        }

        public function __get($key)
        {
            return $this->attributes[$key] ?? null;
        }

        public function __set($key, $value): void
        {
            $this->attributes[$key] = $value;
        }

        public function update(array $attributes = [], array $options = [])
        {
            $this->updates[]  = $attributes;
            $this->attributes = array_merge($this->attributes, $attributes);

            return true;
        }
    };
}

function aiSessionDouble(array $attributes = []): AiSession
{
    return new class($attributes) extends AiSession {
        protected $attributes = [];

        public array $updates = [];

        public function __construct(array $attributes = [])
        {
            $this->attributes = array_merge(['uuid' => 'session-uuid'], $attributes);
        }

        public function __get($key)
        {
            return $this->attributes[$key] ?? null;
        }

        public function __set($key, $value): void
        {
            $this->attributes[$key] = $value;
        }

        public function update(array $attributes = [], array $options = [])
        {
            $this->updates[]  = $attributes;
            $this->attributes = array_merge($this->attributes, $attributes);

            return true;
        }
    };
}

function aiTaskServiceQueryBuilder(array &$firstRows = [], array $getRows = []): Builder
{
    return new class($firstRows, $getRows) extends Builder {
        public array $calls = [];

        public function __construct(private array &$firstRows, private array $getRows)
        {
        }

        public function __clone()
        {
        }

        public function where($column, $operator = null, $value = null, $boolean = 'and')
        {
            if (is_callable($column)) {
                $nested = aiTaskServiceQueryBuilder($this->firstRows);
                $column($nested);
                $this->calls[] = ['where_nested', $nested->calls];

                return $this;
            }

            $this->calls[] = ['where', $column, $operator, $value, $boolean];

            return $this;
        }

        public function orWhere($column, $operator = null, $value = null)
        {
            $this->calls[] = ['orWhere', $column, $operator, $value];

            return $this;
        }

        public function whereNotNull($columns, $boolean = 'and', $not = false)
        {
            $this->calls[] = ['whereNotNull', $columns, $boolean, $not];

            return $this;
        }

        public function latest($column = null)
        {
            $this->calls[] = ['latest', $column];

            return $this;
        }

        public function limit($value)
        {
            $this->calls[] = ['limit', $value];

            return $this;
        }

        public function first($columns = ['*'])
        {
            $this->calls[] = ['first', $columns];

            return array_shift($this->firstRows);
        }

        public function get($columns = ['*'])
        {
            $this->calls[] = ['get', $columns];

            return collect($this->getRows);
        }
    };
}

function aiProviderDouble(array $result = [], ?Throwable $throwable = null): AIProviderInterface
{
    return new class($result, $throwable) implements AIProviderInterface {
        public array $calls = [];

        public function __construct(private array $result, private ?Throwable $throwable)
        {
        }

        public function complete(AiTask $task, array $messages = [], array $options = []): array
        {
            $this->calls[] = ['complete', $task, $messages, $options];

            if ($this->throwable) {
                throw $this->throwable;
            }

            return $this->result ?: [
                'provider' => 'local',
                'model'    => 'fleetbase-local-preview',
                'content'  => 'AI response',
                'summary'  => 'AI summary',
                'usage'    => ['input_tokens' => 4, 'output_tokens' => 6, 'total_tokens' => 10],
                'metadata' => ['provider_meta' => true],
            ];
        }

        public function test(array $config = []): array
        {
            return ['ok' => true];
        }
    };
}

function aiTaskServiceDouble(AiCapabilityRegistry $registry, array &$steps): AiTaskService
{
    return new class(new LocalAIProvider(), new AiContextResolver($registry), $registry, new AiAttachmentResolver(), new class extends AiTemporalContext {
        public function timezone(): string
        {
            return 'UTC';
        }
    },
        $steps
    ) extends AiTaskService {
        public function __construct($provider, $contextResolver, $registry, $attachmentResolver, $temporalContext, private array &$steps)
        {
            parent::__construct($provider, $contextResolver, $registry, $attachmentResolver, $temporalContext);
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $step          = aiStepDouble($attributes);
            $this->steps[] = $step;

            return $step;
        }
    };
}

function aiCreateRequest(array $input): Request
{
    $request = Request::create('/ai/tasks', 'POST', $input);
    $request->setUserResolver(fn () => new class {
        public string $uuid = 'user-uuid';
    });

    return $request;
}

test('task service creates tasks from requests with provider context attachments and action previews', function () {
    session(['company' => 'company-uuid']);

    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability([
        'key'     => 'fleetbase.create_order',
        'preview' => ['draft' => ['order' => 'ORD-1']],
    ]));

    $steps           = [];
    $createdTasks    = [];
    $createdSessions = [];
    $sessionRows     = [aiSessionDouble(['status' => 'ended', 'title' => 'Old chat'])];
    $provider        = aiProviderDouble();

    $service = new class($provider, $registry, $steps, $createdTasks, $createdSessions, $sessionRows) extends AiTaskService {
        public function __construct(
            public AIProviderInterface $providerDouble,
            AiCapabilityRegistry $registry,
            private array &$steps,
            public array &$createdTasks,
            public array &$createdSessions,
            private array &$sessionRows,
        ) {
            parent::__construct(
                $providerDouble,
                new class($registry) extends AiContextResolver {
                    public function resolve(AiTask $task): array
                    {
                        return [['capability' => 'fleetbase.ai.context', 'result' => ['screen' => 'orders']]];
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
                        return ['capability' => 'fleetbase.ai.temporal', 'timezone' => 'UTC'];
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

        protected function systemAiConfig(): array
        {
            return ['enabled' => true, 'provider' => 'local'];
        }

        protected function createTask(array $attributes): AiTask
        {
            $task                 = aiTaskDouble(array_merge($attributes, ['uuid' => 'created-task-uuid']));
            $this->createdTasks[] = $attributes;

            return $task;
        }

        protected function createSession(array $attributes): AiSession
        {
            $session                 = aiSessionDouble(array_merge($attributes, ['uuid' => 'created-session-uuid']));
            $this->createdSessions[] = $attributes;

            return $session;
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            return aiTaskServiceQueryBuilder($this->sessionRows);
        }

        protected function sessionHistoryForTask(AiTask $task): Builder
        {
            $rows = [];

            return aiTaskServiceQueryBuilder($rows);
        }
    };

    $task = $service->createFromRequest(aiCreateRequest([
        'session_uuid' => 'ended-session',
        'task_type'    => 'dispatch',
        'prompt'       => 'Create order from attachment',
        'context'      => ['route' => 'orders.index'],
        'attachments'  => ['file-1'],
    ]));

    expect($createdSessions[0])->toMatchArray([
        'company_uuid'    => 'company-uuid',
        'created_by_uuid' => 'user-uuid',
        'title'           => 'Create order from attachment',
        'status'          => 'active',
    ])
        ->and($createdTasks[0])->toMatchArray([
            'ai_session_uuid' => 'created-session-uuid',
            'company_uuid'    => 'company-uuid',
            'created_by_uuid' => 'user-uuid',
            'task_type'       => 'dispatch',
            'status'          => 'running',
            'prompt'          => 'Create order from attachment',
            'provider'        => 'local',
            'model'           => 'fleetbase-local-preview',
        ])
        ->and($task->status)->toBe('answered')
        ->and($task->response)->toBe('AI response')
        ->and($task->response_summary)->toBe('AI summary')
        ->and($task->metadata['attachments'])->toBe([['id' => 'file-1', 'preview' => 'manifest']])
        ->and($task->metadata['temporal_context']['timezone'])->toBe('UTC')
        ->and($task->metadata['capability_context'][0]['capability'])->toBe('fleetbase.ai.context')
        ->and($task->metadata['action_previews'][0])->toMatchArray(['key' => 'fleetbase.create_order', 'draft' => ['order' => 'ORD-1']])
        ->and($steps)->toHaveCount(5)
        ->and(array_map(fn ($step) => $step->type, $steps))->toBe(['temporal_context', 'attachment_context', 'action_preview', 'capability_context', 'provider_call'])
        ->and($steps[4]->status)->toBe('completed')
        ->and($steps[4]->input['capability_context'])->toHaveCount(4)
        ->and($service->providerDouble->calls[0][3]['config'])->toBe(['enabled' => true, 'provider' => 'local']);
});

test('task service marks created task failed when provider completion throws', function () {
    session(['company' => 'company-uuid']);

    $registry        = new AiCapabilityRegistry();
    $steps           = [];
    $createdSessions = [];
    $sessionRows     = [];

    $service = new class(aiProviderDouble([], new RuntimeException('Provider unavailable.')), $registry, $steps, $createdSessions, $sessionRows) extends AiTaskService {
        public function __construct(AIProviderInterface $provider, AiCapabilityRegistry $registry, private array &$steps, public array &$createdSessions, private array &$sessionRows)
        {
            parent::__construct(
                $provider,
                new AiContextResolver($registry),
                $registry,
                new class extends AiAttachmentResolver {
                    public function resolveFromRequest(Request $request): array
                    {
                        return [];
                    }
                },
                new class extends AiTemporalContext {
                    public function context(): array
                    {
                        return ['capability' => 'fleetbase.ai.temporal'];
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

        protected function createTask(array $attributes): AiTask
        {
            return aiTaskDouble(array_merge($attributes, ['uuid' => 'failed-task-uuid']));
        }

        protected function systemAiConfig(): array
        {
            return ['enabled' => true, 'provider' => 'local'];
        }

        protected function createSession(array $attributes): AiSession
        {
            $session                 = aiSessionDouble(array_merge($attributes, ['uuid' => 'new-session-uuid']));
            $this->createdSessions[] = $attributes;

            return $session;
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            return aiTaskServiceQueryBuilder($this->sessionRows);
        }

        protected function sessionHistoryForTask(AiTask $task): Builder
        {
            $rows = [];

            return aiTaskServiceQueryBuilder($rows);
        }
    };

    $task = $service->createFromRequest(aiCreateRequest(['prompt' => 'Summarize route health']));

    expect($task->status)->toBe('failed')
        ->and($task->error['message'])->toBe('Provider unavailable.')
        ->and($task->error['type'])->toBe(RuntimeException::class)
        ->and($steps)->toHaveCount(2)
        ->and($steps[1]->type)->toBe('provider_call')
        ->and($steps[1]->status)->toBe('failed')
        ->and($steps[1]->error['message'])->toBe('Provider unavailable.')
        ->and($createdSessions[0]['title'])->toBe('Summarize route health');
});

test('task service cancels apply when no executable action exists', function () {
    $registry = new AiCapabilityRegistry();
    $steps    = [];
    $task     = aiTaskDouble([
        'company_uuid' => 'company-1',
        'metadata'     => ['action_previews' => [['key' => 'missing.action']]],
        'status'       => 'answered',
    ]);

    $result = aiTaskServiceDouble($registry, $steps)->apply($task, 'missing.action');

    expect($result)->toBe($task)
        ->and($task->status)->toBe('previewed')
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]->type)->toBe('apply')
        ->and($steps[0]->status)->toBe('cancelled')
        ->and($steps[0]->tool)->toBe('missing.action')
        ->and($steps[0]->output['message'])->toBe('No executable AI action is available for this task.');
});

test('task service applies executable preview and records action results', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability([
        'key'    => 'fleetbase.dispatch',
        'result' => ['status' => 'ok', 'message' => 'Dispatch created.'],
    ]));
    $steps = [];
    $task  = aiTaskDouble([
        'company_uuid'     => 'company-1',
        'created_by_uuid'  => 'user-1',
        'response_summary' => 'Old summary',
        'metadata'         => [
            'action_previews' => [
                ['key' => 'fleetbase.dispatch', 'draft' => ['order' => 'ORD-1']],
            ],
        ],
        'status'           => 'answered',
    ]);

    $result = aiTaskServiceDouble($registry, $steps)->apply($task, 'fleetbase.dispatch', ['confirm' => true]);

    expect($result)->toBe($task)
        ->and($task->status)->toBe('applied')
        ->and($task->response_summary)->toBe('Dispatch created.')
        ->and($task->metadata['action_results'])->toBe([['status' => 'ok', 'message' => 'Dispatch created.']])
        ->and($steps)->toHaveCount(1)
        ->and($steps[0]->status)->toBe('completed')
        ->and($steps[0]->tool)->toBe('fleetbase.dispatch')
        ->and($steps[0]->output)->toBe(['status' => 'ok', 'message' => 'Dispatch created.']);
});

test('task service applies the first preview when no action key is provided', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability([
        'key'    => 'fleetbase.first',
        'result' => ['status' => 'ok', 'message' => 'First preview applied.'],
    ]));
    $steps = [];
    $task  = aiTaskDouble([
        'company_uuid' => 'company-1',
        'metadata'     => [
            'action_previews' => [
                ['key' => 'fleetbase.first', 'draft' => ['id' => 'ORD-1']],
            ],
        ],
        'status'       => 'answered',
    ]);

    aiTaskServiceDouble($registry, $steps)->apply($task, null, ['confirm' => true]);

    expect($task->status)->toBe('applied')
        ->and($task->response_summary)->toBe('First preview applied.')
        ->and($steps[0]->tool)->toBe('fleetbase.first')
        ->and($steps[0]->input)->toBe([
            'preview' => ['key' => 'fleetbase.first', 'draft' => ['id' => 'ORD-1']],
            'input'   => ['confirm' => true],
        ]);
});

test('task service stores apply errors when executable action throws', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability(['key' => 'fleetbase.failure', 'throws' => true]));
    $steps = [];
    $task  = aiTaskDouble([
        'company_uuid' => 'company-1',
        'metadata'     => [
            'action_previews' => [
                ['key' => 'fleetbase.failure'],
            ],
        ],
        'status'       => 'answered',
    ]);

    aiTaskServiceDouble($registry, $steps)->apply($task, 'fleetbase.failure');

    expect($task->status)->toBe('apply_failed')
        ->and($task->metadata['action_errors'][0]['message'])->toBe('Apply failed.')
        ->and($task->metadata['action_errors'][0]['type'])->toBe(RuntimeException::class)
        ->and($steps[0]->status)->toBe('failed')
        ->and($steps[0]->error['message'])->toBe('Apply failed.');
});

test('task service refreshes previews by updating existing and appending new actions', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability([
        'key'     => 'fleetbase.refresh',
        'preview' => ['draft' => ['updated' => true]],
    ]));
    $steps = [];
    $task  = aiTaskDouble([
        'company_uuid' => 'company-1',
        'metadata'     => [
            'action_previews' => [
                ['key' => 'fleetbase.refresh', 'draft' => ['updated' => false]],
            ],
        ],
        'status'       => 'applied',
    ]);

    aiTaskServiceDouble($registry, $steps)->refreshPreview($task, 'fleetbase.refresh', ['quantity' => 2]);

    expect($task->status)->toBe('answered')
        ->and($task->metadata['action_previews'])->toHaveCount(1)
        ->and($task->metadata['action_previews'][0]['draft'])->toBe(['updated' => true])
        ->and($steps[0]->type)->toBe('preview_refresh')
        ->and($steps[0]->status)->toBe('completed')
        ->and($steps[0]->input)->toBe(['quantity' => 2]);

    $registry->register(aiActionCapability([
        'key'     => 'fleetbase.appended',
        'preview' => ['action' => 'fleetbase.appended', 'draft' => ['new' => true]],
    ]));

    aiTaskServiceDouble($registry, $steps)->refreshPreview($task, 'fleetbase.appended');

    expect($task->metadata['action_previews'])->toHaveCount(2)
        ->and($task->metadata['action_previews'][1]['key'])->toBe('fleetbase.appended');
});

test('task service records failed preview refresh for missing action capability', function () {
    $registry = new AiCapabilityRegistry();
    $steps    = [];
    $task     = aiTaskDouble([
        'company_uuid' => 'company-1',
        'metadata'     => [],
        'status'       => 'answered',
    ]);

    aiTaskServiceDouble($registry, $steps)->refreshPreview($task, 'missing.action');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->type)->toBe('preview_refresh')
        ->and($steps[0]->status)->toBe('failed')
        ->and($steps[0]->tool)->toBe('missing.action')
        ->and($steps[0]->error['message'])->toBe('No executable AI action is available for preview refresh.');
});

test('task service normalizes action previews and derives compact prompt titles', function () {
    $registry   = new AiCapabilityRegistry();
    $steps      = [];
    $service    = aiTaskServiceDouble($registry, $steps);
    $capability = aiActionCapability([
        'key'          => 'fleetbase.preview',
        'label'        => 'Preview action',
        'module'       => 'fleet-ops',
        'permissions'  => ['orders update'],
        'preview_only' => false,
        'executable'   => true,
    ]);

    $preview = aiInvokeProtected($service, 'normalizeActionPreview', $capability, ['draft' => ['id' => 'ORD-1']]);

    expect($preview)->toMatchArray([
        'key'          => 'fleetbase.preview',
        'label'        => 'Preview action',
        'module'       => 'fleet-ops',
        'type'         => 'write',
        'mode'         => 'action',
        'permissions'  => ['orders update'],
        'preview_only' => false,
        'executable'   => true,
        'draft'        => ['id' => 'ORD-1'],
    ])
        ->and(aiInvokeProtected($service, 'titleFromPrompt', "  Create\nroute for urgent shipment  "))->toBe('Create route for urgent shipment')
        ->and(aiInvokeProtected($service, 'titleFromPrompt', ''))->toBe('New AI chat')
        ->and(strlen(aiInvokeProtected($service, 'titleFromPrompt', str_repeat('A', 100))))->toBe(64);
});

test('task service filters preview capabilities and handles session helper branches', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability([
        'key'     => 'fleetbase.previewable',
        'preview' => ['draft' => ['ready' => true]],
    ]));
    $registry->register(aiActionCapability([
        'key'            => 'fleetbase.hidden',
        'should_preview' => false,
        'preview'        => ['draft' => ['ready' => false]],
    ]));

    $steps   = [];
    $service = aiTaskServiceDouble($registry, $steps);
    $task    = aiTaskDouble([
        'uuid'             => 'task-uuid',
        'company_uuid'     => 'company-uuid',
        'ai_session_uuid'  => null,
        'prompt'           => 'Create an urgent dispatch route',
    ]);

    $previews = aiInvokeProtected($service, 'resolveActionPreviews', $task);

    expect($previews)->toHaveCount(1)
        ->and($previews[0])->toMatchArray([
            'key'   => 'fleetbase.previewable',
            'draft' => ['ready' => true],
        ])
        ->and(aiInvokeProtected($service, 'sessionContext', $task))->toBeNull();

    $session = aiSessionDouble([
        'title'    => 'New AI chat',
        'status'   => 'ended',
        'ended_at' => '2026-07-19 10:00:00',
    ]);

    aiInvokeProtected($service, 'touchSessionForTask', $session, $task);

    expect($session->updates)->toHaveCount(1)
        ->and($session->updates[0]['title'])->toBe('Create an urgent dispatch route')
        ->and($session->updates[0]['status'])->toBe('active')
        ->and($session->updates[0]['ended_at'])->toBeNull()
        ->and($session->updates[0]['last_message_at'])->not->toBeNull();

    $sessionWithTitle = aiSessionDouble([
        'title'  => 'Existing planning thread',
        'status' => 'active',
    ]);

    aiInvokeProtected($service, 'touchSessionForTask', $sessionWithTitle, $task);

    expect($sessionWithTitle->updates[0])->toHaveKey('last_message_at')
        ->and($sessionWithTitle->updates[0])->not->toHaveKey('title')
        ->and($sessionWithTitle->updates[0])->not->toHaveKey('status')
        ->and($sessionWithTitle->updates[0])->not->toHaveKey('ended_at');
});

test('task service reuses requested and fallback active sessions before creating new ones', function () {
    $registry = new AiCapabilityRegistry();
    $active   = aiSessionDouble(['uuid' => 'active-session', 'status' => 'active']);

    $requestWithSession = aiCreateRequest([
        'session_uuid' => 'active-session',
        'prompt'       => 'Continue this chat',
    ]);
    $requestedRows = [$active];

    $requestedService = new class($registry, $requestedRows) extends AiTaskService {
        public int $created = 0;

        public function __construct(AiCapabilityRegistry $registry, private array &$rows)
        {
            parent::__construct(new LocalAIProvider(), new AiContextResolver($registry), $registry, new AiAttachmentResolver(), new AiTemporalContext());
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            return aiTaskServiceQueryBuilder($this->rows);
        }

        protected function createSession(array $attributes): AiSession
        {
            $this->created++;

            return aiSessionDouble($attributes);
        }
    };

    $fallback        = aiSessionDouble(['uuid' => 'fallback-session', 'status' => 'active']);
    $fallbackRows    = [$fallback];
    $fallbackService = new class($registry, $fallbackRows) extends AiTaskService {
        public int $created = 0;

        public function __construct(AiCapabilityRegistry $registry, private array &$rows)
        {
            parent::__construct(new LocalAIProvider(), new AiContextResolver($registry), $registry, new AiAttachmentResolver(), new AiTemporalContext());
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            return aiTaskServiceQueryBuilder($this->rows);
        }

        protected function createSession(array $attributes): AiSession
        {
            $this->created++;

            return aiSessionDouble($attributes);
        }
    };

    expect(aiInvokeProtected($requestedService, 'resolveSessionForRequest', $requestWithSession))->toBe($active)
        ->and($requestedService->created)->toBe(0)
        ->and(aiInvokeProtected($fallbackService, 'resolveSessionForRequest', aiCreateRequest(['prompt' => 'Start from latest active chat'])))->toBe($fallback)
        ->and($fallbackService->created)->toBe(0);
});

test('task service builds bounded session context from previous turns', function () {
    $registry = new AiCapabilityRegistry();
    $steps    = [];
    $history  = [
        aiTaskDouble([
            'prompt'           => 'First prompt',
            'response_summary' => 'Short response',
            'response'         => 'Long response ignored',
            'status'           => 'answered',
        ]),
        aiTaskDouble([
            'prompt'           => 'Second prompt',
            'response_summary' => null,
            'response'         => str_repeat('R', 700),
            'status'           => 'failed',
        ]),
    ];

    $service = new class($registry, $steps, $history) extends AiTaskService {
        public function __construct(AiCapabilityRegistry $registry, private array &$steps, private array $history)
        {
            parent::__construct(new LocalAIProvider(), new AiContextResolver($registry), $registry, new AiAttachmentResolver(), new AiTemporalContext());
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $step          = aiStepDouble($attributes);
            $this->steps[] = $step;

            return $step;
        }

        protected function sessionHistoryForTask(AiTask $task): Builder
        {
            $rows = [];

            return aiTaskServiceQueryBuilder($rows, $this->history);
        }
    };

    $context = aiInvokeProtected($service, 'sessionContext', aiTaskDouble([
        'uuid'            => 'current-task',
        'company_uuid'    => 'company-uuid',
        'ai_session_uuid' => 'session-uuid',
    ]));

    expect($context['capability'])->toBe('fleetbase.ai.session_context')
        ->and($context['data']['session_uuid'])->toBe('session-uuid')
        ->and($context['data']['turns'])->toHaveCount(2)
        ->and($context['data']['turns'][0])->toBe([
            'prompt'   => 'Second prompt',
            'response' => str_repeat('R', 600),
            'status'   => 'failed',
        ])
        ->and($context['data']['turns'][1])->toBe([
            'prompt'   => 'First prompt',
            'response' => 'Short response',
            'status'   => 'answered',
        ]);
});
