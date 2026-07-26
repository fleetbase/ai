<?php

use Fleetbase\Ai\Http\Controllers\AiResourceController;
use Fleetbase\Ai\Http\Controllers\Internal\AiAdminController;
use Fleetbase\Ai\Http\Controllers\Internal\AiConfigController;
use Fleetbase\Ai\Http\Controllers\Internal\AiSessionController;
use Fleetbase\Ai\Http\Controllers\Internal\AiTaskController;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Providers\AiServiceProvider;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Fleetbase\Providers\CoreServiceProvider;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

if (!function_exists('aiAdminRequestDouble')) {
    function aiAdminRequestDouble(array $input = [], bool $admin = false): Fleetbase\Http\Requests\AdminRequest
    {
        return new class($input, $admin) extends Fleetbase\Http\Requests\AdminRequest {
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
                    public string $uuid = 'admin-user-uuid';

                    public function __construct(private bool $admin)
                    {
                    }

                    public function isAdmin(): bool
                    {
                        return $this->admin;
                    }
                };
            }

            public function ip()
            {
                return $this->input('ip', '127.0.0.1');
            }

            public function userAgent()
            {
                return $this->input('user_agent', 'Fleetbase AI test browser');
            }
        };
    }
}

if (!function_exists('aiAdminFilterBuilder')) {
    function aiAdminFilterBuilder(): Builder
    {
        return new class extends Builder {
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

if (!function_exists('aiSessionControllerBuilder')) {
    function aiSessionControllerBuilder(array $rows): Builder
    {
        return new class($rows) extends Builder {
            public array $calls = [];

            public function __construct(private array $rows)
            {
            }

            public function withCount($relations)
            {
                $this->calls[] = ['withCount', $relations];

                return $this;
            }

            public function with($relations, $callback = null)
            {
                $this->calls[] = ['with', $relations];

                return $this;
            }

            public function latest($column = null)
            {
                $this->calls[] = ['latest', $column];

                return $this;
            }

            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                if (is_callable($column)) {
                    $nested = aiSessionControllerBuilder([]);
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

            public function firstOrFail($columns = ['*'])
            {
                $this->calls[] = ['firstOrFail', $columns];

                return $this->rows[0] ?? new AiSession();
            }
        };
    }
}

if (!function_exists('aiAdminAnalyticsBuilder')) {
    function aiAdminAnalyticsBuilder(): Builder
    {
        return new class extends Builder {
            public array $calls = [];

            public function __construct()
            {
            }

            public function __clone()
            {
            }

            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                $this->calls[] = ['where', $column, $operator, $value, $boolean];

                return $this;
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

            public function first($columns = ['*'])
            {
                $this->calls[] = ['first', $columns];

                return (object) [
                    'task_count'      => '5',
                    'input_tokens'    => '100',
                    'output_tokens'   => '75',
                    'total_tokens'    => '175',
                    'failed_count'    => '1',
                    'completed_count' => '4',
                ];
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

            public function orderBy($column, $direction = 'asc')
            {
                $this->calls[] = ['orderBy', $column, $direction];

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

                if (collect($this->calls)->contains(fn ($call) => $call[0] === 'orderBy' && $call[1] === 'day')) {
                    return collect([
                        (object) ['day' => '2026-07-19', 'task_count' => '2', 'total_tokens' => '70'],
                    ]);
                }

                return collect([
                    (object) [
                        'provider'        => 'openai',
                        'company_uuid'    => null,
                        'created_by_uuid' => null,
                        'model'           => null,
                        'status'          => null,
                        'task_count'      => '3',
                        'input_tokens'    => '10',
                        'output_tokens'   => '20',
                        'total_tokens'    => '30',
                    ],
                ]);
            }
        };
    }
}

if (!function_exists('aiAdminEndpointBuilder')) {
    function aiAdminEndpointBuilder(array $rows): Builder
    {
        return new class($rows) extends Builder {
            public array $calls = [];

            public function __construct(private array $rows)
            {
            }

            public function __clone()
            {
            }

            public function select($columns = ['*'])
            {
                $this->calls[] = ['select', $columns];

                return $this;
            }

            public function orderBy($column, $direction = 'asc')
            {
                $this->calls[] = ['orderBy', $column, $direction];

                return $this;
            }

            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                if (is_callable($column)) {
                    $nested = aiAdminEndpointBuilder([]);
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
                $nested = aiAdminEndpointBuilder([]);

                if (is_callable($callback)) {
                    $callback($nested);
                }

                $this->calls[] = ['whereHas', $relation, $nested->calls, $operator, $count];

                return $this;
            }

            public function with($relations, $callback = null)
            {
                $this->calls[] = ['with', $relations];

                return $this;
            }

            public function withCount($relations)
            {
                $this->calls[] = ['withCount', $relations];

                return $this;
            }

            public function withSum($relation, $column)
            {
                $this->calls[] = ['withSum', $relation, $column];

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

            public function get($columns = ['*'])
            {
                $this->calls[] = ['get', $columns];

                return collect($this->rows);
            }

            public function firstOrFail($columns = ['*'])
            {
                $this->calls[] = ['firstOrFail', $columns];

                return $this->rows[0];
            }
        };
    }
}

if (!function_exists('aiProviderManagerDouble')) {
    function aiProviderManagerDouble(): AiProviderManager
    {
        return new class extends AiProviderManager {
            public function __construct()
            {
            }

            public function defaultConfig(): array
            {
                return ['enabled' => false, 'provider' => 'local', 'providers' => ['openai' => ['api_key' => '']]];
            }

            public function normalizeConfig(array $config): array
            {
                return array_replace_recursive($this->defaultConfig(), $config);
            }

            public function metadata(): array
            {
                return ['providers' => [['label' => 'Local Preview', 'value' => 'local']]];
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
    $capsule    = new Capsule();
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
    $app = new class {
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
            Fleetbase\Ai\Contracts\AIProviderInterface::class   => AiProviderManager::class,
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

test('config controller status show and store use normalized masked settings', function () {
    $controller = new class extends AiConfigController {
        public array $settings = [
            'enabled'   => true,
            'provider'  => 'openai',
            'providers' => [
                'openai' => ['api_key' => 'sk-existing', 'base_url' => 'https://api.openai.test'],
            ],
        ];
        public ?array $configured = null;

        protected function systemAiSetting(array $default = []): array
        {
            return $this->settings ?: $default;
        }

        protected function configureSystemAi(array $config): void
        {
            $this->configured = $config;
            $this->settings   = $config;
        }
    };
    $providers = aiProviderManagerDouble();

    $status = aiJsonPayload($controller->status(Request::create('/'), $providers));
    $show   = aiJsonPayload($controller->show(aiAdminRequestDouble(), $providers));
    $store  = aiJsonPayload($controller->store(aiAdminRequestDouble([
        'config' => [
            'enabled'   => false,
            'provider'  => 'openai',
            'providers' => [
                'openai' => [
                    'api_key'  => '********',
                    'base_url' => 'https://override.openai.test',
                ],
            ],
        ],
    ]), $providers));

    expect($status)->toBe(['enabled' => true])
        ->and($show['config']['providers']['openai']['api_key'])->toBe('********')
        ->and($show['metadata']['providers'][0]['value'])->toBe('local')
        ->and($controller->configured['enabled'])->toBeFalse()
        ->and($controller->configured['providers']['openai']['api_key'])->toBe('sk-existing')
        ->and($controller->configured['providers']['openai']['base_url'])->toBe('https://override.openai.test')
        ->and($store['status'])->toBe('OK')
        ->and($store['config']['providers']['openai']['api_key'])->toBe('********');
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
    $session = new class extends AiSession {
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

test('session controller protected lookup and default query helper build scoped queries', function () {
    session(['company' => 'company-uuid']);

    $request = Request::create('/ai/sessions/session-uuid', 'GET');
    $request->setUserResolver(fn () => new class {
        public string $uuid = 'user-uuid';
    });
    app()->instance('request', $request);

    $session = new AiSession();
    $session->setRawAttributes(['uuid' => 'session-uuid'], true);
    $query = aiSessionControllerBuilder([$session]);

    $controller = new class($query) extends AiSessionController {
        public function __construct(public Builder $query)
        {
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            return $this->query;
        }
    };

    expect(aiInvokeProtected($controller, 'findSession', 'session-uuid'))->toBe($session)
        ->and($query->calls[0])->toBe(['where', 'created_by_uuid', null, null, 'and'])
        ->and($query->calls[1])->toBe(['where_nested', [
            ['where', 'uuid', 'session-uuid', null, 'and'],
            ['orWhere', 'id', 'session-uuid', null],
        ]]);
});

test('session controller indexes and stores sessions through overridable query helpers', function () {
    session(['company' => 'company-uuid']);

    $session = new class extends AiSession {
        public array $loaded = [];

        public function __construct()
        {
            $this->setRawAttributes([
                'uuid'            => 'session-uuid',
                'company_uuid'    => 'company-uuid',
                'created_by_uuid' => 'user-uuid',
                'title'           => 'New AI chat',
                'status'          => 'active',
            ], true);
        }

        public function load($relations)
        {
            $this->loaded[] = $relations;

            return $this;
        }
    };
    $query = aiSessionControllerBuilder([$session]);

    $controller = new class($query, $session) extends AiSessionController {
        public array $created = [];

        public function __construct(private Builder $query, private AiSession $session)
        {
        }

        protected function sessionsForCurrentCompany(): Builder
        {
            return $this->query;
        }

        protected function createSession(array $attributes): AiSession
        {
            $this->created[] = $attributes;

            return $this->session;
        }
    };

    $indexRequest = Request::create('/', 'GET', ['mine' => '1', 'status' => 'active', 'limit' => 2]);
    $indexRequest->setUserResolver(fn () => (object) ['uuid' => 'user-uuid']);

    $storeRequest = Request::create('/', 'POST', ['title' => '   ']);
    $storeRequest->setUserResolver(fn () => (object) ['uuid' => 'user-uuid']);

    $index = aiJsonPayload($controller->index($indexRequest));
    $store = aiJsonPayload($controller->store($storeRequest));

    expect($index['sessions'])->toHaveCount(1)
        ->and($query->calls)->toBe([
            ['withCount', 'tasks'],
            ['latest', 'last_message_at'],
            ['latest', null],
            ['where', 'created_by_uuid', 'user-uuid', null, 'and'],
            ['where', 'status', 'active', null, 'and'],
            ['limit', 2],
            ['get', ['*']],
        ])
        ->and($controller->created[0]['company_uuid'])->toBe('company-uuid')
        ->and($controller->created[0]['created_by_uuid'])->toBe('user-uuid')
        ->and($controller->created[0]['title'])->toBe('New AI chat')
        ->and($controller->created[0]['status'])->toBe('active')
        ->and($controller->created[0]['last_message_at'])->not->toBeNull()
        ->and($store['session']['uuid'])->toBe('session-uuid')
        ->and($session->loaded[0])->toHaveKey('tasks');
});

test('task controller shows and cancels found tasks', function () {
    $task = new class extends AiTask {
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

    $service = new class extends AiTaskService {
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

test('task controller indexes tasks through overridable query helpers', function () {
    $task = new AiTask();
    $task->setRawAttributes([
        'uuid'            => 'task-uuid',
        'company_uuid'    => 'company-uuid',
        'created_by_uuid' => 'user-uuid',
        'status'          => 'answered',
    ], true);
    $query = aiSessionControllerBuilder([$task]);

    $controller = new class($query) extends AiTaskController {
        public function __construct(private Builder $query)
        {
        }

        protected function tasksForCurrentCompany(): Builder
        {
            return $this->query;
        }
    };

    $request = Request::create('/', 'GET', ['mine' => '1', 'limit' => 3]);
    $request->setUserResolver(fn () => (object) ['uuid' => 'user-uuid']);

    $response = aiJsonPayload($controller->index($request));

    expect($response['tasks'])->toHaveCount(1)
        ->and($query->calls)->toBe([
            ['with', ['steps', 'session']],
            ['latest', null],
            ['where', 'created_by_uuid', 'user-uuid', null, 'and'],
            ['limit', 3],
            ['get', ['*']],
        ]);
});

test('task controller previews and applies found tasks through the task service', function () {
    $task = new class extends AiTask {
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

test('task controller stores tasks validates input and checks ai enabled state', function () {
    $task = new AiTask();
    $task->setRawAttributes(['uuid' => 'created-task-uuid', 'status' => 'answered'], true);

    $request = new class(['prompt' => 'Plan dispatch', 'session_uuid' => 'session-uuid', 'attachments' => ['file-1']]) extends Request {
        public array $validated = [];

        public function __construct(private array $values)
        {
        }

        public function input($key = null, $default = null)
        {
            if ($key === null) {
                return $this->values;
            }

            return data_get($this->values, $key, $default);
        }

        public function validate(array $rules, ...$params)
        {
            $this->validated = $rules;

            return $this->values;
        }
    };

    $service = new class($task) extends AiTaskService {
        public array $calls = [];

        public function __construct(private AiTask $task)
        {
        }

        public function createFromRequest(Request $request): AiTask
        {
            $this->calls[] = ['createFromRequest', $request->input()];

            return $this->task;
        }
    };

    $controller = new class extends AiTaskController {
        protected function systemAiConfig(): array
        {
            return ['enabled' => true];
        }
    };

    $response = aiJsonPayload($controller->store($request, $service));

    expect($response['task']['uuid'])->toBe('created-task-uuid')
        ->and($request->validated)->toHaveKeys(['prompt', 'session_uuid', 'attachments', 'attachments.*'])
        ->and($service->calls)->toBe([
            ['createFromRequest', [
                'prompt'       => 'Plan dispatch',
                'session_uuid' => 'session-uuid',
                'attachments'  => ['file-1'],
            ]],
        ]);

    $disabled = new class extends AiTaskController {
        protected function systemAiConfig(): array
        {
            return ['enabled' => false];
        }
    };

    aiInvokeProtected($controller, 'abortIfAiDisabled');

    expect(fn () => aiInvokeProtected($disabled, 'abortIfAiDisabled'))
        ->toThrow(RuntimeException::class, 'Fleetbase AI is disabled.');
});

test('task controller find task builds scoped lookup query', function () {
    $task = new AiTask();
    $task->setRawAttributes(['uuid' => 'task-uuid'], true);
    $query = aiSessionControllerBuilder([$task]);

    $controller = new class($query) extends AiTaskController {
        public function __construct(private Builder $query)
        {
        }

        protected function tasksForCurrentCompany(): Builder
        {
            return $this->query;
        }
    };

    $found = aiInvokeProtected($controller, 'findTask', 'task-uuid');

    expect($found->uuid)->toBe('task-uuid')
        ->and($query->calls)->toBe([
            ['where', 'created_by_uuid', null, null, 'and'],
            ['where_nested', [
                ['where', 'uuid', 'task-uuid', null, 'and'],
                ['orWhere', 'id', 'task-uuid', null],
            ]],
            ['firstOrFail', ['*']],
        ]);
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

test('admin controller lists company and user filter options through query helpers', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'      => 'company-uuid',
        'public_id' => 'COMP-1',
        'name'      => 'Fleetbase',
        'status'    => 'active',
    ], true);

    $globalUser = new User();
    $globalUser->setRawAttributes([
        'uuid'         => 'user-uuid',
        'public_id'    => 'USR-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ops Admin',
        'email'        => 'ops@example.test',
        'status'       => 'active',
    ], true);

    $companyUser = new CompanyUser();
    $companyUser->setRelation('user', $globalUser);

    $companiesQuery    = aiAdminEndpointBuilder([$company]);
    $usersQuery        = aiAdminEndpointBuilder([$globalUser]);
    $companyUsersQuery = aiAdminEndpointBuilder([$companyUser]);

    $controller = new class($companiesQuery, $usersQuery, $companyUsersQuery) extends AiAdminController {
        public function __construct(private Builder $companies, private Builder $users, private Builder $companyUsers)
        {
        }

        protected function companiesQuery(): Builder
        {
            return $this->companies;
        }

        protected function usersQuery(): Builder
        {
            return $this->users;
        }

        protected function companyUsersForCompany(string $companyUuid): Builder
        {
            $this->companyUsers->calls[] = ['company_uuid', $companyUuid];

            return $this->companyUsers;
        }
    };

    $companies = aiJsonPayload($controller->companies(aiAdminRequestDouble([
        'query' => 'fleet',
        'limit' => 200,
    ], true)));
    $users = aiJsonPayload($controller->users(aiAdminRequestDouble([
        'search' => 'ops',
        'limit'  => 2,
    ], true)));
    $companyUsers = aiJsonPayload($controller->users(aiAdminRequestDouble([
        'company_uuid' => 'company-uuid',
        'query'        => 'ops',
        'limit'        => 0,
    ], true)));

    expect($companies[0])->toBe([
        'id'        => 'company-uuid',
        'uuid'      => 'company-uuid',
        'public_id' => 'COMP-1',
        'name'      => 'Fleetbase',
        'status'    => 'active',
    ])
        ->and($companiesQuery->calls[0])->toBe(['select', ['uuid', 'public_id', 'name', 'status', 'created_at']])
        ->and($companiesQuery->calls)->toContain(['orderBy', 'name', 'asc'])
        ->and($companiesQuery->calls)->toContain(['limit', 50])
        ->and($companiesQuery->calls[2][0])->toBe('where_nested')
        ->and($users[0]['email'])->toBe('ops@example.test')
        ->and($usersQuery->calls)->toContain(['select', ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'status']])
        ->and($usersQuery->calls)->toContain(['orderBy', 'name', 'asc'])
        ->and($usersQuery->calls)->toContain(['limit', 2])
        ->and($usersQuery->calls[2][0])->toBe('where_nested')
        ->and($companyUsers[0]['uuid'])->toBe('user-uuid')
        ->and($companyUsersQuery->calls[0])->toBe(['company_uuid', 'company-uuid'])
        ->and($companyUsersQuery->calls[1][0])->toBe('whereHas')
        ->and($companyUsersQuery->calls[2][0])->toBe('with')
        ->and($companyUsersQuery->calls[3][0])->toBe('whereHas')
        ->and($companyUsersQuery->calls)->toContain(['limit', 1]);
});

test('admin controller lists sessions and returns session and task detail payloads', function () {
    $timestamp = Carbon::parse('2026-07-19 10:00:00', 'UTC');

    $session = new class($timestamp) extends AiSession {
        public array $loaded = [];

        public function __construct(Carbon $timestamp)
        {
            $this->setRawAttributes([
                'id'               => 10,
                'uuid'             => 'session-uuid',
                'company_uuid'     => 'company-uuid',
                'created_by_uuid'  => 'user-uuid',
                'title'            => 'Dispatch planning',
                'status'           => 'active',
                'tasks_count'      => 1,
                'total_tokens_sum' => 12,
                'last_message_at'  => $timestamp,
                'created_at'       => $timestamp,
                'updated_at'       => $timestamp,
            ], true);
            $this->setRelation('company', (object) ['uuid' => 'company-uuid', 'public_id' => 'COMP-1', 'name' => 'Fleetbase']);
            $this->setRelation('createdBy', (object) ['uuid' => 'user-uuid', 'public_id' => 'USR-1', 'name' => 'Ops', 'email' => 'ops@example.test']);
            $this->setRelation('tasks', collect());
        }

        public function load($relations)
        {
            $this->loaded[] = ['load', $relations];

            return $this;
        }

        public function loadCount($relations)
        {
            $this->loaded[] = ['loadCount', $relations];

            return $this;
        }

        public function loadSum($relations, $column)
        {
            $this->loaded[] = ['loadSum', $relations, $column];

            return $this;
        }
    };

    $task = new class($timestamp, $session) extends AiTask {
        public array $loaded = [];

        public function __construct(Carbon $timestamp, AiSession $session)
        {
            $this->setRawAttributes([
                'id'               => 20,
                'uuid'             => 'task-uuid',
                'ai_session_uuid'  => 'session-uuid',
                'company_uuid'     => 'company-uuid',
                'created_by_uuid'  => 'user-uuid',
                'task_type'        => 'chat',
                'status'           => 'answered',
                'provider'         => 'local',
                'model'            => 'fleetbase-local-preview',
                'prompt'           => 'Plan route',
                'response_summary' => 'Route planned',
                'metadata'         => [],
                'created_at'       => $timestamp,
                'updated_at'       => $timestamp,
            ], true);
            $this->setRelation('steps', collect());
            $this->setRelation('session', $session);
            $this->setRelation('company', (object) ['uuid' => 'company-uuid', 'public_id' => 'COMP-1', 'name' => 'Fleetbase']);
            $this->setRelation('createdBy', (object) ['uuid' => 'user-uuid', 'public_id' => 'USR-1', 'name' => 'Ops', 'email' => 'ops@example.test']);
        }

        public function load($relations)
        {
            $this->loaded[] = $relations;

            return $this;
        }
    };
    $session->setRelation('tasks', collect([$task]));

    $sessionsQuery = aiAdminEndpointBuilder([$session]);

    $controller = new class($sessionsQuery, $session, $task) extends AiAdminController {
        public function __construct(private Builder $sessions, private AiSession $session, private AiTask $task)
        {
        }

        protected function sessionsQuery(): Builder
        {
            return $this->sessions;
        }

        protected function findSession(string $id): AiSession
        {
            return $this->session;
        }

        protected function findTask(string $id): AiTask
        {
            return $this->task;
        }
    };

    $list         = aiJsonPayload($controller->sessions(aiAdminRequestDouble(['limit' => 500], true)));
    $detail       = aiJsonPayload($controller->session('session-uuid', aiAdminRequestDouble([], true)));
    $taskResponse = aiJsonPayload($controller->task('task-uuid', aiAdminRequestDouble([], true)));

    expect($list['sessions'][0]['uuid'])->toBe('session-uuid')
        ->and($list['meta']['can_reveal_content'])->toBeTrue()
        ->and($sessionsQuery->calls)->toContain(['withCount', 'tasks'])
        ->and($sessionsQuery->calls)->toContain(['withSum', 'tasks as total_tokens_sum', 'total_tokens'])
        ->and($sessionsQuery->calls)->toContain(['limit', 100])
        ->and($detail['session']['tasks'][0]['uuid'])->toBe('task-uuid')
        ->and($detail['meta']['can_reveal_content'])->toBeTrue()
        ->and($session->loaded[0][0])->toBe('load')
        ->and($session->loaded[1])->toBe(['loadCount', 'tasks'])
        ->and($session->loaded[2])->toBe(['loadSum', 'tasks as total_tokens_sum', 'total_tokens'])
        ->and($taskResponse['task']['uuid'])->toBe('task-uuid')
        ->and($taskResponse['task']['content_redacted'])->toBeTrue()
        ->and($taskResponse['meta']['can_reveal_content'])->toBeTrue()
        ->and($task->loaded[0])->toBe(['steps', 'session', 'company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email']);
});

test('admin controller protected lookup and query helpers build expected queries', function () {
    $session = new AiSession();
    $session->setRawAttributes(['uuid' => 'session-uuid'], true);

    $task = new AiTask();
    $task->setRawAttributes(['uuid' => 'task-uuid'], true);

    $sessionQuery = aiAdminEndpointBuilder([$session]);
    $taskQuery    = aiAdminEndpointBuilder([$task]);

    $controller = new class($sessionQuery, $taskQuery) extends AiAdminController {
        public function __construct(private Builder $sessions, private Builder $tasks)
        {
        }

        protected function sessionsQuery(): Builder
        {
            return $this->sessions;
        }

        protected function tasksQuery(): Builder
        {
            return $this->tasks;
        }
    };

    expect(aiInvokeProtected($controller, 'findSession', 'session-uuid'))->toBe($session)
        ->and($sessionQuery->calls[0])->toBe(['where_nested', [
            ['where', 'uuid', 'session-uuid', null, 'and'],
            ['orWhere', 'id', 'session-uuid', null],
        ]])
        ->and(aiInvokeProtected($controller, 'findTask', 'task-uuid'))->toBe($task)
        ->and($taskQuery->calls[0])->toBe(['where_nested', [
            ['where', 'uuid', 'task-uuid', null, 'and'],
            ['orWhere', 'id', 'task-uuid', null],
        ]]);
});

test('admin controller reveals task content and records access log metadata', function () {
    $task = new class extends AiTask {
        public array $loaded = [];

        public function __construct()
        {
            $this->setRawAttributes([
                'id'               => 30,
                'uuid'             => 'task-uuid',
                'ai_session_uuid'  => 'session-uuid',
                'company_uuid'     => 'company-uuid',
                'created_by_uuid'  => 'user-uuid',
                'task_type'        => 'chat',
                'status'           => 'answered',
                'provider'         => 'openai',
                'model'            => 'gpt-5-mini',
                'prompt'           => 'Plan the route',
                'response'         => 'Route planned',
                'metadata'         => ['attachments' => []],
            ], true);
            $this->setRelation('steps', collect());
            $this->setRelation('company', (object) ['uuid' => 'company-uuid', 'public_id' => 'COMP-1', 'name' => 'Fleetbase']);
            $this->setRelation('createdBy', (object) ['uuid' => 'user-uuid', 'public_id' => 'USR-1', 'name' => 'Ops', 'email' => 'ops@example.test']);
        }

        public function load($relations)
        {
            $this->loaded[] = $relations;

            return $this;
        }
    };

    $controller = new class($task) extends AiAdminController {
        public array $logs = [];

        public function __construct(private AiTask $task)
        {
        }

        protected function findTask(string $id): AiTask
        {
            return $this->task;
        }

        protected function createAccessLog(array $attributes): AiAdminAccessLog
        {
            $this->logs[] = $attributes;

            $log = new AiAdminAccessLog();
            $log->setRawAttributes($attributes, true);

            return $log;
        }
    };

    $response = aiJsonPayload($controller->revealTaskContent('task-uuid', aiAdminRequestDouble([
        'ip'         => '203.0.113.10',
        'user_agent' => str_repeat('A', 1200),
    ], true)));

    expect($response['task']['prompt'])->toBe('Plan the route')
        ->and($response['task']['response'])->toBe('Route planned')
        ->and($response['task']['content_redacted'])->toBeFalse()
        ->and($controller->logs)->toHaveCount(1)
        ->and($controller->logs[0]['company_uuid'])->toBe('company-uuid')
        ->and($controller->logs[0]['ai_session_uuid'])->toBe('session-uuid')
        ->and($controller->logs[0]['ai_task_uuid'])->toBe('task-uuid')
        ->and($controller->logs[0]['viewed_by_uuid'])->toBe('admin-user-uuid')
        ->and($controller->logs[0]['action'])->toBe('view_task_content')
        ->and($controller->logs[0]['ip_address'])->toBe('203.0.113.10')
        ->and(strlen($controller->logs[0]['user_agent']))->toBe(1000)
        ->and($controller->logs[0]['metadata'])->toBe([
            'task_status' => 'answered',
            'provider'    => 'openai',
            'model'       => 'gpt-5-mini',
        ]);
});

test('admin controller usage endpoint summarizes filtered analytics', function () {
    $base = aiAdminAnalyticsBuilder();

    $controller = new class($base) extends AiAdminController {
        public function __construct(private Builder $base)
        {
        }

        protected function tasksQuery(): Builder
        {
            return $this->base;
        }

        protected function dateRaw(string $column)
        {
            return "DATE({$column})";
        }
    };

    $response = aiJsonPayload($controller->usage(aiAdminRequestDouble([
        'company_uuid'    => 'company-uuid',
        'created_by_uuid' => 'user-uuid',
        'status'          => 'completed',
        'provider'        => 'openai',
        'model'           => 'gpt-5-mini',
        'from'            => '2026-07-01',
        'to'              => '2026-07-19',
    ], true)));

    expect($base->calls[0])->toBe(['where', 'company_uuid', 'company-uuid', null, 'and'])
        ->and($base->calls[4])->toBe(['where', 'model', 'gpt-5-mini', null, 'and'])
        ->and($base->calls[5][1])->toBe('created_at')
        ->and($base->calls[5][2])->toBe('>=')
        ->and($base->calls[5][3]->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($base->calls[6][2])->toBe('<=')
        ->and($response['summary'])->toBe([
            'task_count'      => 5,
            'input_tokens'    => 100,
            'output_tokens'   => 75,
            'total_tokens'    => 175,
            'failed_count'    => 1,
            'completed_count' => 4,
        ])
        ->and($response['by_provider'][0])->toMatchArray([
            'key'          => 'openai',
            'label'        => 'openai',
            'task_count'   => 3,
            'total_tokens' => 30,
        ])
        ->and($response['by_day'][0])->toBe([
            'day'          => '2026-07-19',
            'task_count'   => 2,
            'total_tokens' => 70,
        ]);
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

test('admin controller resolves usage company and user labels through query helpers', function () {
    $company = new Company();
    $company->setRawAttributes([
        'uuid'      => 'company-uuid',
        'public_id' => 'COMP-1',
        'name'      => 'Fleetbase',
    ], true);

    $user = new User();
    $user->setRawAttributes([
        'uuid'      => 'user-uuid',
        'public_id' => 'USR-1',
        'name'      => null,
        'email'     => 'ops@example.test',
    ], true);

    $companyQuery = aiAdminEndpointBuilder([$company]);
    $userQuery    = aiAdminEndpointBuilder([$user]);

    $controller = new class($companyQuery, $userQuery) extends AiAdminController {
        public array $labelLookups = [];

        public function __construct(private Builder $companies, private Builder $users)
        {
        }

        protected function companiesForLabels(array $ids): Builder
        {
            $this->labelLookups[] = ['companies', $ids];

            return $this->companies;
        }

        protected function usersForLabels(array $ids): Builder
        {
            $this->labelLookups[] = ['users', $ids];

            return $this->users;
        }
    };

    expect(aiInvokeProtected($controller, 'usageLabels', 'company', ['company-uuid']))->toBe([
        'company-uuid' => 'Fleetbase',
    ])
        ->and(aiInvokeProtected($controller, 'usageLabels', 'user', ['user-uuid']))->toBe([
            'user-uuid' => 'ops@example.test',
        ])
        ->and($controller->labelLookups)->toBe([
            ['companies', ['company-uuid']],
            ['users', ['user-uuid']],
        ]);
});
