<?php

use Fleetbase\Ai\Http\Controllers\AiResourceController;
use Fleetbase\Ai\Http\Controllers\Internal\AiAdminController;
use Fleetbase\Ai\Http\Controllers\Internal\AiConfigController;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Illuminate\Support\Carbon;

function aiInvokeProtected(object $object, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $arguments);
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

test('task and session models define expected relationships', function () {
    $task    = new AiTask();
    $session = new AiSession();

    expect($task->steps()->getForeignKeyName())->toBe('ai_task_uuid')
        ->and($task->steps()->getLocalKeyName())->toBe('uuid')
        ->and($task->session()->getForeignKeyName())->toBe('ai_session_uuid')
        ->and($task->session()->getOwnerKeyName())->toBe('uuid')
        ->and($task->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($task->createdBy()->getForeignKeyName())->toBe('created_by_uuid')
        ->and($session->tasks()->getForeignKeyName())->toBe('ai_session_uuid')
        ->and($session->tasks()->getLocalKeyName())->toBe('uuid')
        ->and($session->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($session->createdBy()->getForeignKeyName())->toBe('created_by_uuid');
});

test('resource controller points fleetbase resources at the ai namespace', function () {
    expect((new AiResourceController())->namespace)->toBe('\Fleetbase\Ai');
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

test('admin controller serializes redacted sessions tasks steps and metadata summaries', function () {
    $controller = new AiAdminController();
    $timestamp  = Carbon::parse('2026-07-19 10:00:00', 'UTC');

    $session = new AiSession([
        'company_uuid'     => 'company-1',
        'created_by_uuid'  => 'user-1',
        'title'            => 'Operations review',
        'status'           => 'active',
        'last_message_at'  => $timestamp,
        'ended_at'         => null,
    ]);
    $session->id               = 12;
    $session->uuid             = 'session-uuid';
    $session->tasks_count      = 2;
    $session->total_tokens_sum = 99;
    $session->created_at       = $timestamp;
    $session->updated_at       = $timestamp;
    $session->setRelation('company', (object) ['uuid' => 'company-1', 'public_id' => 'C001', 'name' => 'Fleetbase']);
    $session->setRelation('createdBy', (object) ['uuid' => 'user-1', 'public_id' => 'U001', 'name' => 'Operator', 'email' => 'ops@example.test']);

    $step = new AiTaskStep([
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
    ]);
    $step->id         = 55;
    $step->uuid       = 'step-uuid';
    $step->created_at = $timestamp;

    $task = new AiTask([
        'ai_session_uuid'  => 'session-uuid',
        'company_uuid'     => 'company-1',
        'created_by_uuid'  => 'user-1',
        'task_type'        => 'prompt',
        'status'           => 'answered',
        'prompt'           => "Count active orders\nfor today",
        'response'         => str_repeat('Detailed response ', 20),
        'response_summary' => '',
        'provider'         => 'local',
        'model'            => 'fleetbase-local-preview',
        'input_tokens'     => 3,
        'output_tokens'    => 4,
        'total_tokens'     => 7,
        'context'          => ['route' => 'fleet-ops.orders'],
        'usage'            => ['total_tokens' => 7],
        'metadata'         => [
            'action_previews' => [['key' => 'demo']],
            'action_results'  => [['status' => 'ok']],
            'action_errors'   => [['message' => 'cancelled']],
            'attachments'     => [['id' => 'file-1']],
        ],
        'error'            => null,
        'started_at'       => $timestamp,
        'completed_at'     => $timestamp,
    ]);
    $task->id         = 44;
    $task->uuid       = 'task-uuid';
    $task->created_at = $timestamp;
    $task->updated_at = $timestamp;
    $task->setRelation('steps', collect([$step]));
    $task->setRelation('session', $session);
    $task->setRelation('company', null);
    $task->setRelation('createdBy', null);

    $serializedSession = aiInvokeProtected($controller, 'serializeSession', $session);
    $redactedTask      = aiInvokeProtected($controller, 'serializeTask', $task, false);
    $revealedTask      = aiInvokeProtected($controller, 'serializeTask', $task, true);

    expect($serializedSession['uuid'])->toBe('session-uuid')
        ->and($serializedSession['tasks_count'])->toBe(2)
        ->and($serializedSession['total_tokens'])->toBe(99)
        ->and($serializedSession['company'])->toBe(['uuid' => 'company-1', 'public_id' => 'C001', 'name' => 'Fleetbase'])
        ->and($serializedSession['created_by']['email'])->toBe('ops@example.test')
        ->and($redactedTask['prompt'])->toBeNull()
        ->and($redactedTask['response'])->toBeNull()
        ->and($redactedTask['context'])->toBeNull()
        ->and($redactedTask['content_redacted'])->toBeTrue()
        ->and($redactedTask['prompt_excerpt'])->toBe('Count active orders for today')
        ->and($redactedTask['metadata'])->toBe([
            'keys'                  => ['action_previews', 'action_results', 'action_errors', 'attachments'],
            'action_previews_count' => 1,
            'action_results_count'  => 1,
            'action_errors_count'   => 1,
            'attachments_count'     => 1,
        ])
        ->and($redactedTask['steps'][0]['input'])->toBeNull()
        ->and($redactedTask['steps'][0]['content_redacted'])->toBeTrue()
        ->and($redactedTask['session']['uuid'])->toBe('session-uuid')
        ->and($revealedTask['prompt'])->toBe("Count active orders\nfor today")
        ->and($revealedTask['context'])->toBe(['route' => 'fleet-ops.orders'])
        ->and($revealedTask['metadata']['attachments'][0]['id'])->toBe('file-1')
        ->and($revealedTask['steps'][0]['input'])->toBe(['prompt' => 'secret input']);
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
