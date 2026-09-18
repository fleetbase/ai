<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiActionPreview;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiToolContext;

test('action previews are found by preview id first and by action key for older tasks', function () {
    $previews = [
        ['preview_id' => 'p1', 'key' => 'fleet-ops.create_order', 'draft' => ['n' => 1]],
        'not-a-preview',
        ['preview_id' => 'p2', 'key' => 'fleet-ops.create_order', 'draft' => ['n' => 2]],
        ['action' => 'legacy.action'],
    ];

    expect(AiActionPreview::find($previews, 'p2')['draft'])->toBe(['n' => 2])
        ->and(AiActionPreview::find($previews, 'missing'))->toBeNull()
        ->and(AiActionPreview::find($previews, null, 'fleet-ops.create_order')['preview_id'])->toBe('p1')
        ->and(AiActionPreview::find($previews, null, 'legacy.action'))->toBe(['action' => 'legacy.action'])
        ->and(AiActionPreview::find($previews, null, 'unknown'))->toBeNull()
        ->and(AiActionPreview::find($previews)['preview_id'])->toBe('p1')
        ->and(AiActionPreview::find([]))->toBeNull()
        ->and(AiActionPreview::same(['preview_id' => 'a', 'key' => 'k'], ['preview_id' => 'b', 'key' => 'k']))->toBeFalse()
        ->and(AiActionPreview::same(['key' => 'k'], ['preview_id' => 'b', 'key' => 'k']))->toBeTrue();
});

test('normalized previews keep an existing preview id and tool contexts collect them', function () {
    $capability = aiActionCapability(['key' => 'fleet-ops.create_order', 'label' => 'Create order']);
    $context    = new AiToolContext(new AiTask(), AiAudience::endUser());

    $first  = $context->addActionPreview($capability, ['ready' => true]);
    $second = $context->addActionPreview($capability, ['ready' => false]);

    expect($first['preview_id'])->not->toBe($second['preview_id'])
        ->and($first)->toMatchArray(['key' => 'fleet-ops.create_order', 'label' => 'Create order', 'ready' => true])
        ->and($context->actionPreviews)->toHaveCount(2)
        ->and(AiActionPreview::normalize($capability, ['preview_id' => 'kept'])['preview_id'])->toBe('kept')
        ->and(AiActionPreview::normalize($capability, ['preview_id' => 'ignored'], 'forced')['preview_id'])->toBe('forced');

    $context->addUiAction(['command_id' => 'iam.users.create']);
    expect($context->uiActions)->toBe([['command_id' => 'iam.users.create']]);
});

test('task service applies and refreshes a specific preview when several share an action', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability([
        'key'    => 'fleet-ops.create_order',
        'result' => ['action' => 'fleet-ops.create_order', 'status' => 'completed', 'message' => 'Order created.'],
    ]));
    $steps = [];
    $task  = aiTaskDouble([
        'company_uuid' => 'company-1',
        'status'       => 'answered',
        'metadata'     => [
            'action_previews' => [
                ['preview_id' => 'first', 'key' => 'fleet-ops.create_order', 'draft' => ['pickup' => 'A']],
                ['preview_id' => 'second', 'key' => 'fleet-ops.create_order', 'draft' => ['pickup' => 'B']],
            ],
        ],
    ]);

    $service = aiTaskServiceDouble($registry, $steps);
    $service->apply($task, 'fleet-ops.create_order', ['preview_id' => 'second']);

    expect($task->status)->toBe('applied')
        ->and($task->metadata['action_results'][0])->toMatchArray(['preview_id' => 'second', 'message' => 'Order created.'])
        ->and($steps[0]->input['preview']['draft'])->toBe(['pickup' => 'B'])
        ->and($steps[0]->input['input'])->toBe([]);

    $service->refreshPreview($task, 'fleet-ops.create_order', ['preview_id' => 'second', 'draft' => ['pickup' => 'C']]);

    $previews = $task->metadata['action_previews'];

    expect($previews)->toHaveCount(2)
        ->and($previews[0]['draft'])->toBe(['pickup' => 'A'])
        ->and($previews[1]['preview_id'])->toBe('second')
        ->and($previews[1]['draft']['input'])->toBe(['draft' => ['pickup' => 'C'], 'existing_draft' => ['pickup' => 'B']])
        ->and(end($steps)->input)->toBe(['draft' => ['pickup' => 'C']]);

    $service->apply($task, 'fleet-ops.create_order', ['preview_id' => 'does-not-exist']);

    expect($task->status)->toBe('previewed')
        ->and(end($steps)->status)->toBe('cancelled');
});

test('apply errors record which preview failed', function () {
    $registry = new AiCapabilityRegistry();
    $registry->register(aiActionCapability(['key' => 'fleet-ops.create_order', 'throws' => true]));
    $steps = [];
    $task  = aiTaskDouble([
        'company_uuid' => 'company-1',
        'metadata'     => ['action_previews' => [['preview_id' => 'only', 'key' => 'fleet-ops.create_order']]],
    ]);

    aiTaskServiceDouble($registry, $steps)->apply($task, null, ['preview_id' => 'only']);

    expect($task->metadata['action_errors'][0])->toMatchArray(['action' => 'fleet-ops.create_order', 'preview_id' => 'only']);
});
