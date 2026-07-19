<?php

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
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
            return 'Action capability for tests.';
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

function aiFakeTask(array $attributes = []): AiTask
{
    return new class($attributes) extends AiTask {
        public array $updates = [];

        public function __construct(array $attributes = [])
        {
            parent::__construct($attributes);
            $this->uuid = $attributes['uuid'] ?? 'task-uuid';
        }

        public function update(array $attributes = [], array $options = [])
        {
            $this->updates[] = $attributes;
            foreach ($attributes as $key => $value) {
                $this->{$key} = $value;
            }

            return true;
        }

        public function fresh($with = [])
        {
            return $this;
        }
    };
}

function aiFakeStep(array $attributes = []): AiTaskStep
{
    return new class($attributes) extends AiTaskStep {
        public array $updates = [];

        public function update(array $attributes = [], array $options = [])
        {
            $this->updates[] = $attributes;
            foreach ($attributes as $key => $value) {
                $this->{$key} = $value;
            }

            return true;
        }
    };
}

function aiTaskService(AiCapabilityRegistry $registry, array &$recordedSteps): AiTaskService
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
        $recordedSteps
    ) extends AiTaskService {
        public function __construct($provider, $contextResolver, $registry, $attachmentResolver, $temporalContext, private array &$recordedSteps)
        {
            parent::__construct($provider, $contextResolver, $registry, $attachmentResolver, $temporalContext);
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $step = aiFakeStep($attributes);
            $this->recordedSteps[] = $step;

            return $step;
        }
    };
}

test('task service cancels apply when no executable action exists', function () {
    $registry = new AiCapabilityRegistry();
    $steps    = [];
    $task     = aiFakeTask([
        'uuid'         => 'task-uuid',
        'company_uuid' => 'company-1',
        'metadata'     => ['action_previews' => [['key' => 'missing.action']]],
        'status'       => 'answered',
    ]);

    $result = aiTaskService($registry, $steps)->apply($task, 'missing.action');

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
    $task  = aiFakeTask([
        'uuid'             => 'task-uuid',
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

    $result = aiTaskService($registry, $steps)->apply($task, 'fleetbase.dispatch', ['confirm' => true]);

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
    $task  = aiFakeTask([
        'uuid'         => 'task-uuid',
        'company_uuid' => 'company-1',
        'metadata'     => [
            'action_previews' => [
                ['key' => 'fleetbase.failure'],
            ],
        ],
        'status'       => 'answered',
    ]);

    aiTaskService($registry, $steps)->apply($task, 'fleetbase.failure');

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
    $task  = aiFakeTask([
        'uuid'         => 'task-uuid',
        'company_uuid' => 'company-1',
        'metadata'     => [
            'action_previews' => [
                ['key' => 'fleetbase.refresh', 'draft' => ['updated' => false]],
            ],
        ],
        'status'       => 'applied',
    ]);

    aiTaskService($registry, $steps)->refreshPreview($task, 'fleetbase.refresh', ['quantity' => 2]);

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

    aiTaskService($registry, $steps)->refreshPreview($task, 'fleetbase.appended');

    expect($task->metadata['action_previews'])->toHaveCount(2)
        ->and($task->metadata['action_previews'][1]['key'])->toBe('fleetbase.appended');
});

test('task service records failed preview refresh for missing action capability', function () {
    $registry = new AiCapabilityRegistry();
    $steps    = [];
    $task     = aiFakeTask([
        'uuid'         => 'task-uuid',
        'company_uuid' => 'company-1',
        'metadata'     => [],
        'status'       => 'answered',
    ]);

    aiTaskService($registry, $steps)->refreshPreview($task, 'missing.action');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->type)->toBe('preview_refresh')
        ->and($steps[0]->status)->toBe('failed')
        ->and($steps[0]->tool)->toBe('missing.action')
        ->and($steps[0]->error['message'])->toBe('No executable AI action is available for preview refresh.');
});
