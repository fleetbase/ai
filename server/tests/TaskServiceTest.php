<?php

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Services\AiAttachmentResolver;
use Fleetbase\Ai\Services\AiContextResolver;
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Services\LocalAIProvider;
use Fleetbase\Ai\Support\AiCapabilityRegistry;

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
            $this->updates[] = $attributes;
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
            $this->updates[] = $attributes;
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
            $this->updates[] = $attributes;
            $this->attributes = array_merge($this->attributes, $attributes);

            return true;
        }
    };
}

function aiTaskServiceDouble(AiCapabilityRegistry $registry, array &$steps): AiTaskService
{
    return new class(
        new LocalAIProvider(),
        new AiContextResolver($registry),
        $registry,
        new AiAttachmentResolver(),
        new class() extends AiTemporalContext {
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
