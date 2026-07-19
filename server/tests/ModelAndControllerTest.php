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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
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

if (!function_exists('aiAdminRequestDouble')) {
    function aiAdminRequestDouble(array $input = [], bool $admin = false): \Fleetbase\Http\Requests\AdminRequest
    {
        return new class($input, $admin) extends \Fleetbase\Http\Requests\AdminRequest {
            public function __construct(private array $values, private bool $admin)
            {
            }

            public function input($key = null, $default = null)
            {
                if ($key === null) {
                    return $this->values;
                }

                return data_get($this->values, $key, $default);
            }

            public function filled($key)
            {
                $value = $this->input($key);

                return $value !== null && $value !== '';
            }

            public function searchQuery()
            {
                return $this->input('search');
            }

            public function user($guard = null)
            {
                return new class($this->admin) {
                    public function __construct(private bool $admin)
                    {
                    }

                    public function isAdmin(): bool
                    {
                        return $this->admin;
                    }
                };
            }
        };
    }
}

if (!function_exists('aiAdminFilterBuilder')) {
    function aiAdminFilterBuilder(): Builder
    {
        return new class() extends Builder {
            public array $calls = [];

            public function __construct()
            {
            }

            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                if (is_callable($column)) {
                    $nested = aiAdminFilterBuilder();
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

            public function whereHas($relation, $callback = null, $operator = '>=', $count = 1)
            {
                $nested = aiAdminFilterBuilder();

                if (is_callable($callback)) {
                    $callback($nested);
                }

                $this->calls[] = ['whereHas', $relation, $nested->calls, $operator, $count];

                return $this;
            }

            public function orWhereHas($relation, $callback = null, $operator = '>=', $count = 1)
            {
                $nested = aiAdminFilterBuilder();

                if (is_callable($callback)) {
                    $callback($nested);
                }

                $this->calls[] = ['orWhereHas', $relation, $nested->calls, $operator, $count];

                return $this;
            }
        };
    }
}

if (!function_exists('aiAdminUsageBuilder')) {
    function aiAdminUsageBuilder(array $rows): Builder
    {
        return new class($rows) extends Builder {
            public array $calls = [];

            public function __construct(private array $rows)
            {
            }

            public function select($columns = ['*'])
            {
                $this->calls[] = ['select', $columns];

                return $this;
            }

            public function selectRaw($expression, array $bindings = [])
            {
                $this->calls[] = ['selectRaw', $expression, $bindings];

                return $this;
            }

            public function groupBy(...$groups)
            {
                $this->calls[] = ['groupBy', $groups];

                return $this;
            }

            public function orderByDesc($column)
            {
                $this->calls[] = ['orderByDesc', $column];

                return $this;
            }

            public function limit($value)
            {
                $this->calls[] = ['limit', $value];

                return $this;
            }

            public function get($columns = ['*'])
            {
                $this->calls[] = ['get', $columns];

                return collect($this->rows);
            }
        };
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

test('task controller previews and applies found tasks through the task service', function () {
    $task = new class() extends AiTask {
        public function __construct()
        {
            $this->setRawAttributes([
                'uuid'         => 'task-uuid',
                'company_uuid' => 'company-uuid',
                'status'       => 'answered',
            ], true);
        }

        public function load($relations)
        {
            $this->setRawAttributes(array_merge($this->getAttributes(), ['loaded_relations' => $relations]), true);

            return $this;
        }
    };

    $service = new class($task) extends AiTaskService {
        public array $calls = [];

        public function __construct(private AiTask $task)
        {
        }

        public function refreshPreview(AiTask $task, ?string $actionKey = null, array $input = []): AiTask
        {
            $this->calls[] = ['refreshPreview', $task->uuid, $actionKey, $input];
            $task->setRawAttributes(array_merge($task->getAttributes(), ['status' => 'preview_refreshed']), true);

            return $this->task;
        }

        public function apply(AiTask $task, ?string $actionKey = null, array $input = []): AiTask
        {
            $this->calls[] = ['apply', $task->uuid, $actionKey, $input];
            $task->setRawAttributes(array_merge($task->getAttributes(), ['status' => 'applied']), true);

            return $this->task;
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

        protected function abortIfAiDisabled(): void
        {
        }
    };

    $previewRequest = Request::create('/', 'POST', ['action_key' => 'fleetbase.preview', 'input' => ['count' => 2]]);
    $applyRequest   = Request::create('/', 'POST', ['action_key' => 'fleetbase.apply', 'input' => ['confirm' => true]]);

    $preview = aiJsonPayload($controller->preview('task-uuid', $previewRequest, $service));
    $apply   = aiJsonPayload($controller->apply('task-uuid', $applyRequest, $service));

    expect($preview['task']['status'])->toBe('preview_refreshed')
        ->and($apply['task']['status'])->toBe('applied')
        ->and($service->calls)->toBe([
            ['refreshPreview', 'task-uuid', 'fleetbase.preview', ['count' => 2]],
            ['apply', 'task-uuid', 'fleetbase.apply', ['confirm' => true]],
        ])
        ->and($task->loaded_relations)->toBe('session');
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

test('admin controller applies session task and user filters', function () {
    $controller = new AiAdminController();
    $request    = aiAdminRequestDouble([
        'company_uuid'    => 'company-uuid',
        'created_by_uuid' => 'user-uuid',
        'status'          => 'answered',
        'from'            => '2026-07-01',
        'to'              => '2026-07-19',
        'search'          => 'delayed route',
        'provider'        => 'openai',
        'model'           => 'gpt-5-mini',
    ]);

    $sessionQuery = aiAdminFilterBuilder();
    aiInvokeProtected($controller, 'applySessionFilters', $sessionQuery, $request);

    $taskQuery = aiAdminFilterBuilder();
    aiInvokeProtected($controller, 'applyTaskFilters', $taskQuery, $request);

    $userQuery = aiAdminFilterBuilder();
    aiInvokeProtected($controller, 'applyUserSearch', $userQuery, 'ops@example.test');

    expect($sessionQuery->calls[0])->toBe(['where', 'company_uuid', 'company-uuid', null, 'and'])
        ->and($sessionQuery->calls[1])->toBe(['where', 'created_by_uuid', 'user-uuid', null, 'and'])
        ->and($sessionQuery->calls[2])->toBe(['where', 'status', 'answered', null, 'and'])
        ->and($sessionQuery->calls[3][0])->toBe('where')
        ->and($sessionQuery->calls[3][1])->toBe('created_at')
        ->and($sessionQuery->calls[3][2])->toBe('>=')
        ->and($sessionQuery->calls[3][3]->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($sessionQuery->calls[4][0])->toBe('where')
        ->and($sessionQuery->calls[4][2])->toBe('<=')
        ->and($sessionQuery->calls[4][3]->toDateTimeString())->toBe('2026-07-19 23:59:59')
        ->and($sessionQuery->calls[5][0])->toBe('where_nested')
        ->and($sessionQuery->calls[5][1][0])->toBe(['where', 'title', 'like', '%delayed route%', 'and'])
        ->and($sessionQuery->calls[5][1][1])->toBe(['orWhere', 'uuid', 'delayed route', null])
        ->and($sessionQuery->calls[5][1][2][0])->toBe('orWhereHas')
        ->and($sessionQuery->calls[6][0])->toBe('whereHas')
        ->and($sessionQuery->calls[6][1])->toBe('tasks')
        ->and($sessionQuery->calls[6][2][0])->toBe(['where', 'provider', 'openai', null, 'and'])
        ->and($sessionQuery->calls[6][2][1])->toBe(['where', 'model', 'gpt-5-mini', null, 'and'])
        ->and($taskQuery->calls[0])->toBe(['where', 'company_uuid', 'company-uuid', null, 'and'])
        ->and($taskQuery->calls[4])->toBe(['where', 'model', 'gpt-5-mini', null, 'and'])
        ->and($taskQuery->calls[5][1])->toBe('created_at')
        ->and($taskQuery->calls[6][2])->toBe('<=')
        ->and($userQuery->calls[0][0])->toBe('where_nested')
        ->and($userQuery->calls[0][1])->toBe([
            ['where', 'name', 'like', '%ops@example.test%', 'and'],
            ['orWhere', 'email', 'like', '%ops@example.test%'],
            ['orWhere', 'public_id', 'like', '%ops@example.test%'],
            ['orWhere', 'uuid', 'ops@example.test', null],
        ]);
});

test('admin controller groups usage rows without optional labels', function () {
    $controller = new AiAdminController();
    $query      = aiAdminUsageBuilder([
        (object) [
            'provider'      => 'openai',
            'task_count'    => '3',
            'input_tokens'  => '10',
            'output_tokens' => '20',
            'total_tokens'  => '30',
        ],
        (object) [
            'provider'      => null,
            'task_count'    => null,
            'input_tokens'  => null,
            'output_tokens' => null,
            'total_tokens'  => null,
        ],
    ]);

    $grouped = aiInvokeProtected($controller, 'usageGroup', $query, 'provider');

    expect($query->calls)->toBe([
        ['select', 'provider'],
        ['selectRaw', 'COUNT(*) as task_count', []],
        ['selectRaw', 'COALESCE(SUM(input_tokens), 0) as input_tokens', []],
        ['selectRaw', 'COALESCE(SUM(output_tokens), 0) as output_tokens', []],
        ['selectRaw', 'COALESCE(SUM(total_tokens), 0) as total_tokens', []],
        ['groupBy', ['provider']],
        ['orderByDesc', 'total_tokens'],
        ['limit', 50],
        ['get', ['*']],
    ])
        ->and($grouped->all())->toBe([
            [
                'key'           => 'openai',
                'label'         => 'openai',
                'task_count'    => 3,
                'input_tokens'  => 10,
                'output_tokens' => 20,
                'total_tokens'  => 30,
            ],
            [
                'key'           => 'unknown',
                'label'         => 'unknown',
                'task_count'    => 0,
                'input_tokens'  => 0,
                'output_tokens' => 0,
                'total_tokens'  => 0,
            ],
        ])
        ->and(aiInvokeProtected($controller, 'usageLabels', null, ['openai']))->toBe([])
        ->and(aiInvokeProtected($controller, 'usageLabels', 'provider', []))->toBe([])
        ->and(aiInvokeProtected($controller, 'usageLabels', 'provider', ['openai']))->toBe([]);
});
