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
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
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

if (!function_exists('aiUseSqliteDatabase')) {
    function aiUseSqliteDatabase(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}

if (!function_exists('aiCreateControllerTables')) {
    function aiCreateControllerTables(): void
    {
        $schema = Capsule::schema();

        foreach (['ai_task_steps', 'ai_tasks', 'ai_sessions'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('ai_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('created_by_uuid')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('ai_tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('ai_session_uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('created_by_uuid')->nullable();
            $table->string('task_type')->nullable();
            $table->string('status')->nullable();
            $table->text('prompt')->nullable();
            $table->text('response')->nullable();
            $table->string('response_summary')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->text('context')->nullable();
            $table->text('usage')->nullable();
            $table->text('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('ai_task_steps', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('ai_task_uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('created_by_uuid')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('tool')->nullable();
            $table->text('input')->nullable();
            $table->text('output')->nullable();
            $table->text('usage')->nullable();
            $table->text('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
}

if (!function_exists('aiSeedControllerRecords')) {
    function aiSeedControllerRecords(): void
    {
        $timestamp = Carbon::parse('2026-07-19 10:00:00', 'UTC')->toDateTimeString();

        Capsule::table('ai_sessions')->insert([
            'uuid'            => 'session-uuid',
            'company_uuid'    => 'company-1',
            'created_by_uuid' => null,
            'title'           => 'Dispatch planning',
            'status'          => 'active',
            'metadata'        => json_encode(['source' => 'test']),
            'last_message_at' => $timestamp,
            'created_at'      => $timestamp,
            'updated_at'      => $timestamp,
        ]);

        Capsule::table('ai_tasks')->insert([
            'uuid'            => 'task-uuid',
            'ai_session_uuid' => 'session-uuid',
            'company_uuid'    => 'company-1',
            'created_by_uuid' => null,
            'task_type'       => 'chat',
            'status'          => 'answered',
            'prompt'          => 'Plan delayed orders',
            'response'        => 'Delayed order plan',
            'response_summary' => 'Plan summary',
            'provider'        => 'local',
            'model'           => 'fleetbase-local-preview',
            'input_tokens'    => 4,
            'output_tokens'   => 6,
            'total_tokens'    => 10,
            'context'         => json_encode(['route' => 'fleet-ops.operations']),
            'usage'           => json_encode(['total_tokens' => 10]),
            'metadata'        => json_encode(['action_previews' => [['key' => 'fleetbase.dispatch']]]),
            'started_at'      => $timestamp,
            'created_at'      => $timestamp,
            'updated_at'      => $timestamp,
        ]);

        Capsule::table('ai_task_steps')->insert([
            'uuid'         => 'step-uuid',
            'ai_task_uuid' => 'task-uuid',
            'company_uuid' => 'company-1',
            'type'         => 'provider_call',
            'status'       => 'completed',
            'provider'     => 'local',
            'model'        => 'fleetbase-local-preview',
            'input'        => json_encode(['prompt' => 'Plan delayed orders']),
            'output'       => json_encode(['content' => 'Delayed order plan']),
            'created_at'   => $timestamp,
            'updated_at'   => $timestamp,
        ]);
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
    aiUseSqliteDatabase();

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

test('session controller lists creates shows ends and deletes scoped sessions', function () {
    aiUseSqliteDatabase();
    aiCreateControllerTables();
    aiSeedControllerRecords();
    session(['company' => 'company-1']);

    $controller = new AiSessionController();

    $index = aiJsonPayload($controller->index(Request::create('/', 'GET', ['status' => 'active', 'limit' => 10])));
    $store = aiJsonPayload($controller->store(Request::create('/', 'POST', ['title' => '  '])));
    $show  = aiJsonPayload($controller->show('session-uuid'));
    $end   = aiJsonPayload($controller->end('session-uuid'));
    $gone  = aiJsonPayload($controller->destroy('session-uuid'));

    expect($index['sessions'])->toHaveCount(1)
        ->and($index['sessions'][0]['uuid'])->toBe('session-uuid')
        ->and($store['session']['title'])->toBe('New AI chat')
        ->and($store['session']['status'])->toBe('active')
        ->and($show['session']['tasks'])->toHaveCount(1)
        ->and($end['session']['status'])->toBe('ended')
        ->and($end['session']['ended_at'])->not->toBeNull()
        ->and($gone)->toBe(['deleted' => true]);
});

test('task controller lists shows and cancels scoped tasks', function () {
    aiUseSqliteDatabase();
    aiCreateControllerTables();
    aiSeedControllerRecords();
    session(['company' => 'company-1']);

    $service = new class() extends AiTaskService {
        public array $recorded = [];

        public function __construct()
        {
        }

        public function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $this->recorded[] = $attributes;

            return new AiTaskStep($attributes);
        }
    };

    $controller = new AiTaskController();

    $index  = aiJsonPayload($controller->index(Request::create('/', 'GET', ['limit' => 5])));
    $show   = aiJsonPayload($controller->show('task-uuid'));
    $cancel = aiJsonPayload($controller->cancel('task-uuid', $service));

    expect($index['tasks'])->toHaveCount(1)
        ->and($index['tasks'][0]['uuid'])->toBe('task-uuid')
        ->and($show['task']['steps'])->toHaveCount(1)
        ->and($show['task']['session']['uuid'])->toBe('session-uuid')
        ->and($cancel['task']['status'])->toBe('cancelled')
        ->and($cancel['task']['metadata']['action_errors'][0]['action'])->toBe('fleetbase.dispatch')
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

    $user = new User();
    $user->setRawAttributes([
        'uuid'         => 'user-uuid',
        'public_id'    => 'USR-1',
        'company_uuid' => 'company-uuid',
        'name'         => 'Ops Admin',
        'email'        => 'ops@example.test',
        'status'       => 'active',
    ], true);

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
        ->and($revealedTask['metadata'])->toBe(['attachments' => [['id' => 'file-1']]])
        ->and(aiInvokeProtected($controller, 'serializeUserOption', $user))->toBe([
            'id'           => 'user-uuid',
            'uuid'         => 'user-uuid',
            'public_id'    => 'USR-1',
            'company_uuid' => 'company-uuid',
            'name'         => 'Ops Admin',
            'email'        => 'ops@example.test',
            'status'       => 'active',
        ]);
});
