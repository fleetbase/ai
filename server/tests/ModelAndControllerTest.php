<?php

use Fleetbase\Ai\Http\Controllers\AiResourceController;
use Fleetbase\Ai\Http\Controllers\Internal\AiAdminController;
use Fleetbase\Ai\Http\Controllers\Internal\AiConfigController;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Illuminate\Support\Carbon;

if (!function_exists('aiInvokeProtected')) {
    function aiInvokeProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
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
