<?php

use Fleetbase\Ai\Http\Controllers\AiResourceController;
use Fleetbase\Ai\Http\Controllers\Internal\AiAdminController;
use Fleetbase\Ai\Http\Controllers\Internal\AiConfigController;
use Fleetbase\Ai\Http\Controllers\Internal\AiSessionController;
use Fleetbase\Ai\Http\Controllers\Internal\AiTaskController;
use Fleetbase\Ai\Providers\AiServiceProvider;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Providers\CoreServiceProvider;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

if (!function_exists('aiInvokeProtected')) {
    function aiInvokeProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}

if (!function_exists('aiJsonPayload')) {
    function aiJsonPayload(mixed $response): array
    {
        if (is_object($response) && method_exists($response, 'getData')) {
            return $response->getData(true);
        }

        return is_object($response) && property_exists($response, 'data') ? $response->data : [];
    }
}

test('ai models expose backend table fillable searchable and cast contracts', function () {
    $task    = new AiTask();
    $session = new AiSession();
    $step    = new AiTaskStep();
    $log     = new AiAdminAccessLog();

    expect($task->getTable())->toBe('ai_tasks')
        ->and($task->getFillable())->toContain('ai_session_uuid', 'prompt', 'response', 'metadata', 'completed_at')
        ->and($task->getCasts())->toHaveKeys(['context', 'usage', 'metadata', 'error', 'started_at', 'completed_at'])
        ->and($session->getTable())->toBe('ai_sessions')
        ->and($session->getFillable())->toContain('title', 'status', 'metadata', 'last_message_at', 'ended_at')
        ->and($session->getCasts())->toHaveKeys(['metadata', 'last_message_at', 'ended_at'])
        ->and($step->getTable())->toBe('ai_task_steps')
        ->and($step->getFillable())->toContain('type', 'status', 'provider', 'tool', 'input', 'output', 'error')
        ->and($step->getCasts())->toHaveKeys(['input', 'output', 'usage', 'metadata', 'error', 'started_at', 'completed_at'])
        ->and($log->getTable())->toBe('ai_admin_access_logs')
        ->and($log->getFillable())->toContain('company_uuid', 'ai_session_uuid', 'ai_task_uuid', 'viewed_by_uuid', 'metadata')
        ->and($log->getCasts())->toHaveKey('metadata');
});

test('ai models expose expected relationship contracts', function () {
    $capsule = new Capsule();
    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    $capsule->addConnection($connection);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $task    = new AiTask();
    $session = new AiSession();

    expect($task->steps())->toBeInstanceOf(HasMany::class)
        ->and($task->steps()->getRelated())->toBeInstanceOf(AiTaskStep::class)
        ->and($task->steps()->getForeignKeyName())->toBe('ai_task_uuid')
        ->and($task->steps()->getLocalKeyName())->toBe('uuid')
        ->and($task->session())->toBeInstanceOf(BelongsTo::class)
        ->and($task->session()->getRelated())->toBeInstanceOf(AiSession::class)
        ->and($task->session()->getForeignKeyName())->toBe('ai_session_uuid')
        ->and($task->session()->getOwnerKeyName())->toBe('uuid')
        ->and($task->company())->toBeInstanceOf(BelongsTo::class)
        ->and($task->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($task->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($task->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($task->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($task->createdBy()->getForeignKeyName())->toBe('created_by_uuid')
        ->and($session->tasks())->toBeInstanceOf(HasMany::class)
        ->and($session->tasks()->getRelated())->toBeInstanceOf(AiTask::class)
        ->and($session->tasks()->getForeignKeyName())->toBe('ai_session_uuid')
        ->and($session->tasks()->getLocalKeyName())->toBe('uuid')
        ->and($session->company())->toBeInstanceOf(BelongsTo::class)
        ->and($session->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($session->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($session->createdBy())->toBeInstanceOf(BelongsTo::class)
        ->and($session->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($session->createdBy()->getForeignKeyName())->toBe('created_by_uuid');
});

test('resource controller points fleetbase resources at the ai namespace', function () {
    $defaults = (new ReflectionClass(AiResourceController::class))->getDefaultProperties();

    expect($defaults['namespace'])->toBe('\Fleetbase\Ai');
});

test('ai service provider registers bindings and boots package resources', function () {
    $app = new class() {
        public array $registered = [];
        public array $singletons = [];

        public function register(string $provider): void
        {
            $this->registered[] = $provider;
        }

        public function singleton(string $abstract, ?string $concrete = null): void
        {
            $this->singletons[$abstract] = $concrete ?? $abstract;
        }
    };

    $provider = new class($app) extends AiServiceProvider {
        public array $booted = [];
        public AiCapabilityRegistry $registry;

        public function registerObservers(): void
        {
            $this->booted[] = 'observers';
        }

        public function callAfterResolving($name, $callback)
        {
            $this->registry = new AiCapabilityRegistry();
            $callback($this->registry);
            $this->booted[] = ['after_resolving', $name];
        }

        public function registerExpansionsFrom($from = null, $namespace = null): void
        {
            $this->booted[] = ['expansions', $from, $namespace];
        }

        public function loadRoutesFrom($path)
        {
            $this->booted[] = ['routes', $path];
        }

        public function loadMigrationsFrom($paths)
        {
            $this->booted[] = ['migrations', $paths];
        }
    };

    $provider->register();
    $provider->boot();

    expect($app->registered)->toBe([CoreServiceProvider::class])
        ->and($app->singletons)->toMatchArray([
            \Fleetbase\Ai\Contracts\AIProviderInterface::class => AiProviderManager::class,
            AiCapabilityRegistry::class                         => AiCapabilityRegistry::class,
            AiQueryRegistry::class                              => AiQueryRegistry::class,
            AiQueryExecutor::class                              => AiQueryExecutor::class,
            AiTemporalContext::class                            => AiTemporalContext::class,
        ])
        ->and($provider->registry->get('core.current_page_context'))->toBeInstanceOf(CurrentPageContextCapability::class)
        ->and($provider->booted[0])->toBe('observers')
        ->and($provider->booted[1])->toBe(['after_resolving', AiCapabilityRegistry::class])
        ->and($provider->booted[2][0])->toBe('expansions')
        ->and($provider->booted[3][0])->toBe('routes')
        ->and($provider->booted[4][0])->toBe('migrations');
});

test('config controller masks and preserves provider secrets', function () {
    $controller = new AiConfigController();

    $masked = aiInvokeProtected($controller, 'maskSecrets', [
        'providers' => [
            'openai' => [
                'api_key' => 'sk-real',
                'token'   => 'tok-real',
            ],
            'local'  => [
                'api_key' => '',
            ],
        ],
    ]);

    $preserved = aiInvokeProtected($controller, 'preserveMaskedSecrets', [
        'providers' => [
            'openai'    => [
                'api_key' => '********',
                'token'   => 'replacement-token',
            ],
            'anthropic' => [
                'secret' => '********',
            ],
        ],
    ], [
        'providers' => [
            'openai'    => [
                'api_key' => 'sk-existing',
                'token'   => 'old-token',
            ],
            'anthropic' => [
                'secret' => 'existing-secret',
            ],
        ],
    ]);

    expect($masked['providers']['openai']['api_key'])->toBe('********')
        ->and($masked['providers']['openai']['token'])->toBe('********')
        ->and($masked['providers']['local']['api_key'])->toBe('')
        ->and($preserved['providers']['openai']['api_key'])->toBe('sk-existing')
        ->and($preserved['providers']['openai']['token'])->toBe('replacement-token')
        ->and($preserved['providers']['anthropic']['secret'])->toBe('existing-secret');
});

test('admin controller serializes redacted steps and metadata summaries', function () {
    $controller = new AiAdminController();
    $timestamp  = Carbon::parse('2026-07-19 10:00:00', 'UTC');

    $step = (object) [
        'id'           => 55,
        'uuid'         => 'step-uuid',
        'type'         => 'provider_call',
        'status'       => 'completed',
        'provider'     => 'local',
        'model'        => 'fleetbase-local-preview',
        'tool'         => null,
        'input'        => ['prompt' => 'secret input'],
        'output'       => ['answer' => 'secret output'],
        'usage'        => ['total_tokens' => 7],
        'metadata'     => ['source' => 'test'],
        'error'        => null,
        'started_at'   => $timestamp,
        'completed_at' => $timestamp,
        'created_at'   => $timestamp,
    ];

    $redactedStep = aiInvokeProtected($controller, 'serializeStep', $step, false);
    $revealedStep = aiInvokeProtected($controller, 'serializeStep', $step, true);
    $summary      = aiInvokeProtected($controller, 'metadataSummary', [
        'action_previews' => [['key' => 'demo']],
        'action_results'  => [['status' => 'ok']],
        'action_errors'   => [['message' => 'cancelled']],
        'attachments'     => [['id' => 'file-1']],
    ]);

    expect($redactedStep['input'])->toBeNull()
        ->and($redactedStep['output'])->toBeNull()
        ->and($redactedStep['metadata']['keys'])->toBe(['source'])
        ->and($redactedStep['content_redacted'])->toBeTrue()
        ->and($revealedStep['input'])->toBe(['prompt' => 'secret input'])
        ->and($revealedStep['output'])->toBe(['answer' => 'secret output'])
        ->and($revealedStep['metadata'])->toBe(['source' => 'test'])
        ->and($revealedStep['content_redacted'])->toBeFalse()
        ->and($summary)->toBe([
            'keys'                  => ['action_previews', 'action_results', 'action_errors', 'attachments'],
            'action_previews_count' => 1,
            'action_results_count'  => 1,
            'action_errors_count'   => 1,
            'attachments_count'     => 1,
        ]);
});

test('session controller shows ends and deletes found sessions', function () {
    $session = new class() extends AiSession {
        public bool $deleted = false;

        public function __construct()
        {
            $this->setRawAttributes([
                'id'              => 10,
                'uuid'            => 'session-uuid',
                'company_uuid'    => 'company-uuid',
                'created_by_uuid' => 'user-uuid',
                'title'           => 'Dispatch planning',
                'status'          => 'active',
            ], true);
            $this->setRelation('tasks', collect());
        }

        public function load($relations)
        {
            return $this;
        }

        public function fresh($with = [])
        {
            return $this;
        }

        public function update(array $attributes = [], array $options = [])
        {
            $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

            return true;
        }

        public function delete()
        {
            $this->deleted = true;

            return true;
        }
    };

    $controller = new class($session) extends AiSessionController {
        public function __construct(private AiSession $session)
        {
        }

        protected function findSession(string $id): AiSession
        {
            return $this->session;
        }
    };

    $show = aiJsonPayload($controller->show('session-uuid'));
    $end  = aiJsonPayload($controller->end('session-uuid'));
    $gone = aiJsonPayload($controller->destroy('session-uuid'));

    expect($show['session']['uuid'])->toBe('session-uuid')
        ->and($end['session']['status'])->toBe('ended')
        ->and($end['session']['ended_at'])->not->toBeNull()
        ->and($gone)->toBe(['deleted' => true])
        ->and($session->deleted)->toBeTrue();
});

test('task controller shows and cancels found tasks', function () {
    $task = new class() extends AiTask {
        public array $updates = [];

        public function __construct()
        {
            $this->setRawAttributes([
                'id'              => 30,
                'uuid'            => 'task-uuid',
                'company_uuid'    => 'company-uuid',
                'created_by_uuid' => 'user-uuid',
                'status'          => 'answered',
                'metadata'        => ['action_previews' => [['key' => 'fleetbase.dispatch']]],
            ], true);
        }

        public function load($relations)
        {
            return $this;
        }

        public function fresh($with = [])
        {
            return $this;
        }

        public function update(array $attributes = [], array $options = [])
        {
            $this->updates[] = $attributes;

            foreach ($attributes as $key => $value) {
                $this->setRawAttributes(array_merge($this->getAttributes(), [$key => $value]), true);
            }

            return true;
        }
    };

    $service = new class() extends AiTaskService {
        public array $recorded = [];

        public function __construct()
        {
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $this->recorded[] = $attributes;

            return new class($attributes) extends AiTaskStep {
                public function __construct(array $attributes)
                {
                    $this->setRawAttributes($attributes, true);
                }
            };
        }
    };

    $controller = new class($task) extends AiTaskController {
        public function __construct(private AiTask $task)
        {
        }

        protected function findTask(string $id): AiTask
        {
            return $this->task;
        }
    };

    $show   = aiJsonPayload($controller->show('task-uuid'));
    $cancel = aiJsonPayload($controller->cancel('task-uuid', $service));

    expect($show['task']['uuid'])->toBe('task-uuid')
        ->and($cancel['task']['status'])->toBe('cancelled')
        ->and($task->updates[0]['metadata']['action_errors'][0]['action'])->toBe('fleetbase.dispatch')
        ->and($service->recorded)->toHaveCount(1)
        ->and($service->recorded[0]['type'])->toBe('cancel')
        ->and($service->recorded[0]['tool'])->toBe('fleetbase.dispatch');
});

test('admin controller summarizes metadata and nullable related records', function () {
    $controller = new AiAdminController();

    expect(aiInvokeProtected($controller, 'metadataSummary', null))->toBe([
        'keys'                  => [],
        'action_previews_count' => 0,
        'action_results_count'  => 0,
        'action_errors_count'   => 0,
        'attachments_count'     => 0,
    ])
        ->and(aiInvokeProtected($controller, 'serializeCompany', null))->toBeNull()
        ->and(aiInvokeProtected($controller, 'serializeUser', null))->toBeNull()
        ->and(aiInvokeProtected($controller, 'excerpt', null))->toBeNull()
        ->and(aiInvokeProtected($controller, 'excerpt', "  Multi\n line\tvalue  ", 20))->toBe('Multi line value');
});

test('admin controller serializes sessions tasks relations and user options', function () {
    $controller = new AiAdminController();
    $timestamp  = Carbon::parse('2026-07-19 10:00:00', 'UTC');

    $session = new AiSession();
    $session->setRawAttributes([
        'id'               => 10,
        'uuid'             => 'session-uuid',
        'company_uuid'     => 'company-uuid',
        'created_by_uuid'  => 'user-uuid',
        'title'            => 'Dispatch planning',
        'status'           => 'active',
        'tasks_count'      => 2,
        'total_tokens_sum' => 44,
        'last_message_at'  => $timestamp,
        'created_at'       => $timestamp,
        'updated_at'       => $timestamp,
    ], true);
    $session->setRelation('company', (object) [
        'uuid'      => 'company-uuid',
        'public_id' => 'COMP-1',
        'name'      => 'Fleetbase',
    ]);
    $session->setRelation('createdBy', (object) [
        'uuid'      => 'user-uuid',
        'public_id' => 'USR-1',
        'name'      => 'Ops Admin',
        'email'     => 'ops@example.test',
    ]);

    $step = new AiTaskStep();
    $step->setRawAttributes([
        'id'           => 20,
        'uuid'         => 'step-uuid',
        'type'         => 'provider_call',
        'status'       => 'completed',
        'provider'     => 'local',
        'model'        => 'fleetbase-local-preview',
        'input'        => ['prompt' => 'Plan dispatch'],
        'output'       => ['content' => 'Dispatch planned'],
        'metadata'     => ['source' => 'test'],
        'started_at'   => $timestamp,
        'completed_at' => $timestamp,
        'created_at'   => $timestamp,
    ], true);

    $task = new AiTask();
    $task->setRawAttributes([
        'id'               => 30,
        'uuid'             => 'task-uuid',
        'ai_session_uuid'  => 'session-uuid',
        'company_uuid'     => 'company-uuid',
        'created_by_uuid'  => 'user-uuid',
        'task_type'        => 'chat',
        'status'           => 'completed',
        'provider'         => 'local',
        'model'            => 'fleetbase-local-preview',
        'input_tokens'     => 5,
        'output_tokens'    => 7,
        'total_tokens'     => 12,
        'prompt'           => "  Plan\n dispatch for delayed orders  ",
        'response'         => 'Dispatch plan response body',
        'response_summary' => null,
        'context'          => ['route' => 'fleet-ops.operations'],
        'usage'            => ['total_tokens' => 12],
        'metadata'         => ['attachments' => [['id' => 'file-1']]],
        'started_at'       => $timestamp,
        'completed_at'     => $timestamp,
        'created_at'       => $timestamp,
        'updated_at'       => $timestamp,
    ], true);
    $task->setRelation('steps', collect([$step]));
    $task->setRelation('session', $session);
    $task->setRelation('company', $session->company);
    $task->setRelation('createdBy', $session->createdBy);

    $redactedSession = aiInvokeProtected($controller, 'serializeSession', $session);
    $redactedTask    = aiInvokeProtected($controller, 'serializeTask', $task, false);
    $revealedTask    = aiInvokeProtected($controller, 'serializeTask', $task, true);

    expect($redactedSession['tasks_count'])->toBe(2)
        ->and($redactedSession['total_tokens'])->toBe(44)
        ->and($redactedSession['company']['name'])->toBe('Fleetbase')
        ->and($redactedSession['created_by']['email'])->toBe('ops@example.test')
        ->and($redactedTask['prompt'])->toBeNull()
        ->and($redactedTask['response'])->toBeNull()
        ->and($redactedTask['metadata']['attachments_count'])->toBe(1)
        ->and($redactedTask['steps'])->toHaveCount(1)
        ->and($redactedTask['steps'][0]['input'])->toBeNull()
        ->and($redactedTask['session']['uuid'])->toBe('session-uuid')
        ->and($revealedTask['prompt'])->toBe("  Plan\n dispatch for delayed orders  ")
        ->and($revealedTask['response'])->toBe('Dispatch plan response body')
        ->and($revealedTask['context'])->toBe(['route' => 'fleet-ops.operations'])
        ->and($revealedTask['metadata'])->toBe(['attachments' => [['id' => 'file-1']]]);
});
