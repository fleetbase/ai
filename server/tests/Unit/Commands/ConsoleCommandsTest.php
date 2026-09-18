<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Services\AiUiActionService;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Capabilities\FindConsoleCommandsTool;
use Fleetbase\Ai\Support\Capabilities\ProposeConsoleCommandTool;
use Fleetbase\Ai\Support\Commands\AiCommandRegistry;
use Fleetbase\Ai\Support\Commands\AiConsoleCommand;
use Fleetbase\Ai\Support\Commands\CoreConsoleCommands;

function aiCommandUser(array $permissions = [], bool $systemAdmin = false): AiAudience
{
    return new class($systemAdmin, $permissions) extends AiAudience {
        public function __construct(bool $isSystemAdmin, private array $granted)
        {
            parent::__construct($isSystemAdmin, 'user-uuid');
        }

        protected function checkPermission(string $permission): bool
        {
            return in_array($permission, $this->granted, true);
        }
    };
}

function aiCommandRegistry(): AiCommandRegistry
{
    return (new AiCommandRegistry())
        ->registerMany(CoreConsoleCommands::all())
        ->register(AiConsoleCommand::navigate('fleet-ops.orders.view', 'Open order', 'Fleet-Ops › Orders', 'Open an order.', 'console.fleet-ops.operations.orders.index.details', [
            'models'      => ['public_id'],
            'params'      => ['public_id' => ['type' => 'string', 'description' => 'Order public id']],
            'permissions' => ['fleet-ops see order'],
            'module'      => 'fleet-ops',
        ]));
}

/**
 * UI action service that records steps in memory.
 */
function aiUiActionService(AiCommandRegistry $registry, array &$steps): AiUiActionService
{
    return new class($registry, $steps) extends AiUiActionService {
        public function __construct(AiCommandRegistry $commands, private array &$steps)
        {
            parent::__construct($commands);
        }

        protected function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            $step          = aiStepDouble($attributes);
            $this->steps[] = $step;

            return $step;
        }
    };
}

test('core commands are well formed and system admin commands are admin only', function () {
    $commands = collect(CoreConsoleCommands::all());

    expect($commands->pluck('id')->duplicates())->toBeEmpty()
        ->and($commands->every(fn (AiConsoleCommand $command) => str_starts_with($command->steps[0]['route'] ?? '', 'console.')))->toBeTrue()
        ->and($commands->filter(fn ($command) => str_starts_with($command->id, 'admin.'))->every(fn ($command) => $command->audience === AiAudience::SYSTEM_ADMIN))->toBeTrue()
        ->and($commands->filter(fn ($command) => !str_starts_with($command->id, 'admin.'))->every(fn ($command) => $command->audience === AiAudience::END_USER))->toBeTrue()
        ->and(collect(CoreConsoleCommands::all())->firstWhere('id', 'iam.users.create')->steps)->toBe([
            ['type' => 'navigate', 'route' => 'console.iam.users.index'],
            ['type' => 'service', 'engine' => '@fleetbase/iam-engine', 'service' => 'user-actions', 'method' => 'modal.create'],
        ]);
});

test('registry filters by permission and audience and ranks matches', function () {
    $registry = aiCommandRegistry();
    $user     = aiCommandUser(['iam list user', 'iam create user']);
    $admin    = aiCommandUser([], true);

    $userIds  = $registry->availableTo($user)->pluck('id');
    $addUser  = $registry->search('add a new user', $user)->pluck('id')->all();
    $mapsKey  = $registry->search('google maps api key', $user)->pluck('id')->all();
    $adminKey = $registry->search('google maps api key', $admin)->pluck('id')->all();

    expect($userIds)->toContain('iam.users.create', 'iam.users.invite', 'settings.organization.open', 'extensions.explore.open')
        ->and($userIds)->not->toContain('iam.roles.create', 'admin.config.services.open', 'developers.api_keys.create')
        ->and($addUser[0])->toBe('iam.users.create')
        ->and($mapsKey)->not->toContain('admin.config.services.open')
        ->and($adminKey[0])->toBe('admin.config.services.open')
        ->and($registry->search('the', $user))->toBeEmpty()
        ->and($registry->get('nope'))->toBeNull();
});

test('commands validate required params and resolve route models', function () {
    $command = aiCommandRegistry()->get('fleet-ops.orders.view');

    expect($command->validateParams([]))->toBe('Missing command parameters: public_id.')
        ->and($command->validateParams(['public_id' => 'order_1']))->toBeNull()
        ->and($command->resolvedSteps(['public_id' => 'order_1']))->toBe([['type' => 'navigate', 'route' => 'console.fleet-ops.operations.orders.index.details', 'models' => ['order_1']]])
        ->and($command->summary())->toMatchArray(['id' => 'fleet-ops.orders.view', 'breadcrumb' => 'Fleet-Ops › Orders'])
        ->and($command->summary())->not->toHaveKey('docs_url');
});

test('find and propose tools only offer commands the user may run and never run them', function () {
    $registry = aiCommandRegistry();
    $task     = new AiTask(['prompt' => 'how do I add a user']);
    $context  = new AiToolContext($task, aiCommandUser(['iam list user', 'iam create user']));
    $find     = new FindConsoleCommandsTool($registry);
    $propose  = new ProposeConsoleCommandTool($registry);

    $found    = $find->invoke($task, ['query' => 'create user'], $context);
    $proposed = $propose->invoke($task, ['command_id' => 'iam.users.create'], $context);
    $again    = $propose->invoke($task, ['command_id' => 'iam.users.create'], $context);

    expect($found['commands'][0]['id'])->toBe('iam.users.create')
        ->and($find->invoke($task, ['query' => 'teleport'], $context)['message'])->toContain('No matching')
        ->and($proposed['status'])->toBe('awaiting_user_confirmation')
        ->and($proposed['message'])->toContain('runs only if they confirm')
        ->and($again['action_id'])->toBe($proposed['action_id'])
        ->and($context->uiActions)->toHaveCount(1)
        ->and($context->uiActions[0])->toMatchArray(['command_id' => 'iam.users.create', 'label' => 'Create user', 'breadcrumb' => 'IAM › Users', 'params' => [], 'status' => 'pending'])
        ->and($context->uiActions[0])->not->toHaveKey('steps')
        ->and($propose->invoke($task, ['command_id' => 'admin.config.services.open'], $context)['error'])->toContain('is not available')
        ->and($propose->invoke($task, ['command_id' => 'made.up'], $context)['error'])->toContain('is not available')
        ->and($find->toolName())->toBe('find_console_commands')
        ->and($find->key())->toBe('core.find_console_commands')
        ->and($find->label())->toContain('console')
        ->and($find->description())->toContain('console')
        ->and($find->module())->toBe('core')
        ->and($find->toolDescription())->toContain('in English')
        ->and($find->toolParameters()['required'])->toBe(['query'])
        ->and($find->availableFor($context))->toBeTrue()
        ->and($find->availableFor(new AiToolContext($task, aiCommandUser())))->toBeTrue()
        ->and((new FindConsoleCommandsTool(new AiCommandRegistry()))->availableFor($context))->toBeFalse()
        ->and($propose->toolName())->toBe('propose_console_command')
        ->and($propose->key())->toBe('core.propose_console_command')
        ->and($propose->label())->toContain('console')
        ->and($propose->description())->toContain('confirmation')
        ->and($propose->module())->toBe('core')
        ->and($propose->type())->toBe('ui')
        ->and($propose->toolDescription())->toContain('Never say you navigated')
        ->and($propose->toolParameters()['required'])->toBe(['command_id'])
        ->and($propose->availableFor($context))->toBeTrue();
});

test('propose tool validates params and limits proposals per reply', function () {
    $registry = aiCommandRegistry();
    $task     = new AiTask();
    $context  = new AiToolContext($task, aiCommandUser(['fleet-ops see order'], true));
    $propose  = new ProposeConsoleCommandTool($registry);

    expect($propose->invoke($task, ['command_id' => 'fleet-ops.orders.view'], $context)['error'])->toContain('public_id');

    foreach (['order_1', 'order_2', 'order_3'] as $publicId) {
        expect($propose->invoke($task, ['command_id' => 'fleet-ops.orders.view', 'params' => ['public_id' => $publicId]], $context)['status'])->toBe('awaiting_user_confirmation');
    }

    expect($propose->invoke($task, ['command_id' => 'admin.branding.open'], $context)['error'])->toContain('Too many actions');
});

test('confirming an action re-checks permission, returns resolved steps, and can only happen once', function () {
    $registry = aiCommandRegistry();
    $steps    = [];
    $service  = aiUiActionService($registry, $steps);
    $task     = aiTaskDouble(['metadata' => ['ui_actions' => [
        ['id' => 'a1', 'command_id' => 'iam.users.create', 'params' => [], 'status' => 'pending'],
        ['id' => 'a2', 'command_id' => 'admin.config.services.open', 'params' => [], 'status' => 'pending'],
        ['id' => 'a3', 'command_id' => 'iam.users.open', 'params' => [], 'status' => 'pending'],
        ['id' => 'a4', 'command_id' => 'fleet-ops.orders.view', 'params' => ['public_id' => 'order_9'], 'status' => 'pending'],
    ]]]);
    $user = aiCommandUser(['iam list user', 'iam create user', 'fleet-ops see order']);

    $confirmed = $service->confirm($task, 'a1', $user);
    $twice     = $service->confirm($task, 'a1', $user);
    $forbidden = $service->confirm($task, 'a2', $user);
    $dismissed = $service->dismiss($task, 'a3');
    $withModel = $service->confirm($task, 'a4', $user);

    expect($confirmed['status'])->toBe(200)
        ->and($confirmed['action']['status'])->toBe('confirmed')
        ->and($confirmed['action']['steps'][1])->toBe(['type' => 'service', 'engine' => '@fleetbase/iam-engine', 'service' => 'user-actions', 'method' => 'modal.create'])
        ->and($twice['status'])->toBe(409)
        ->and($forbidden['status'])->toBe(403)
        ->and($task->metadata['ui_actions'][1]['status'])->toBe('failed')
        ->and($dismissed['action']['status'])->toBe('dismissed')
        ->and($service->dismiss($task, 'a3')['status'])->toBe(409)
        ->and($withModel['action']['steps'][0]['models'])->toBe(['order_9'])
        ->and($service->confirm($task, 'missing', $user)['status'])->toBe(404)
        ->and($service->dismiss($task, 'missing')['status'])->toBe(404)
        ->and(array_map(fn ($step) => [$step->type, $step->status, $step->tool], $steps))->toBe([
            ['ui_action', 'completed', 'iam.users.create'],
            ['ui_action', 'failed', 'admin.config.services.open'],
            ['ui_action', 'completed', 'iam.users.open'],
            ['ui_action', 'completed', 'fleet-ops.orders.view'],
        ]);
});

test('confirmed actions that fail in the console are recorded', function () {
    $steps   = [];
    $service = aiUiActionService(aiCommandRegistry(), $steps);
    $task    = aiTaskDouble(['metadata' => ['ui_actions' => [
        ['id' => 'c1', 'command_id' => 'iam.users.create', 'params' => [], 'status' => 'confirmed'],
        ['id' => 'p1', 'command_id' => 'iam.users.create', 'params' => [], 'status' => 'pending'],
    ]]]);

    $failed = $service->fail($task, 'c1', 'Engine failed to load');

    expect($failed['action'])->toMatchArray(['status' => 'failed', 'error' => 'Engine failed to load'])
        ->and($steps[0]->error)->toBe(['message' => 'Engine failed to load'])
        ->and($service->fail($task, 'p1')['status'])->toBe(409)
        ->and($service->fail($task, 'nope')['status'])->toBe(404);
});

test('task controller confirms, dismisses, and fails ui actions with status codes from the action service', function () {
    $task = aiTaskDouble(['uuid' => 'task-uuid', 'metadata' => ['ui_actions' => [
        ['id' => 'ok', 'command_id' => 'iam.users.open', 'params' => [], 'status' => 'pending'],
        ['id' => 'admin', 'command_id' => 'admin.branding.open', 'params' => [], 'status' => 'pending'],
        ['id' => 'later', 'command_id' => 'iam.users.open', 'params' => [], 'status' => 'pending'],
    ]]]);

    $controller = new class($task) extends Fleetbase\Ai\Http\Controllers\Internal\AiTaskController {
        public function __construct(private AiTask $task)
        {
        }

        protected function findTask(string $id): AiTask
        {
            return $this->task;
        }

        protected function systemAiConfig(): array
        {
            return ['enabled' => true];
        }
    };

    $steps   = [];
    $actions = new class(aiCommandRegistry(), $steps) extends AiUiActionService {
        public function __construct(AiCommandRegistry $commands, private array &$steps)
        {
            parent::__construct($commands);
        }

        public function confirm(AiTask $task, string $actionId, AiAudience $audience): array
        {
            // Swap the session audience for a permission-free organization user.
            return parent::confirm($task, $actionId, aiCommandUser(['iam list user']));
        }

        protected function recordStep(AiTask $task, array $attributes): AiTaskStep
        {
            return aiStepDouble($attributes);
        }
    };

    $request = Illuminate\Http\Request::create('/', 'POST', ['error' => 'Route not found']);
    $request->setUserResolver(fn () => (object) ['uuid' => 'user-uuid', 'type' => 'user']);

    $confirmed = $controller->confirmUiAction('task-uuid', 'ok', $request, $actions);
    $forbidden = $controller->confirmUiAction('task-uuid', 'admin', $request, $actions);
    $dismissed = $controller->dismissUiAction('task-uuid', 'later', $actions);
    $failed    = $controller->failUiAction('task-uuid', 'ok', $request, $actions);
    $missing   = $controller->dismissUiAction('task-uuid', 'missing', $actions);

    expect($confirmed->getStatusCode())->toBe(200)
        ->and(aiJsonPayload($confirmed)['action']['steps'][0]['route'])->toBe('console.iam.users.index')
        ->and(aiJsonPayload($confirmed)['task'])->toHaveKey('uuid')
        ->and($forbidden->getStatusCode())->toBe(403)
        ->and(aiJsonPayload($forbidden)['message'])->toContain('not allowed')
        ->and($dismissed->getStatusCode())->toBe(200)
        ->and($failed->getStatusCode())->toBe(200)
        ->and(aiJsonPayload($failed)['action']['error'])->toBe('Route not found')
        ->and($missing->getStatusCode())->toBe(404);
});

test('commands can be registered from array definitions and invalid definitions are rejected', function () {
    $registry = (new AiCommandRegistry())->registerMany([[
        'id'          => 'fleet-ops.drivers.create',
        'label'       => 'Create driver',
        'breadcrumb'  => 'Fleet-Ops › Drivers',
        'description' => 'Open the new driver form.',
        'steps'       => [['type' => 'navigate', 'route' => 'console.fleet-ops.management.drivers.index.new']],
        'permissions' => ['fleet-ops create driver'],
        'keywords'    => ['driver'],
        'module'      => 'fleet-ops',
    ]]);

    $command = $registry->get('fleet-ops.drivers.create');

    expect($command)->toBeInstanceOf(AiConsoleCommand::class)
        ->and($command->module)->toBe('fleet-ops')
        ->and($command->audience)->toBe(AiAudience::END_USER)
        ->and(fn () => AiConsoleCommand::fromArray(['id' => 'x', 'label' => 'X', 'breadcrumb' => 'X', 'description' => 'X']))->toThrow(InvalidArgumentException::class, "require 'steps'")
        ->and(fn () => AiConsoleCommand::fromArray(['id' => 'x', 'label' => 'X', 'breadcrumb' => 'X', 'description' => 'X', 'steps' => [['type' => 'service', 'engine' => 'e', 'service' => 's', 'method' => 'constructor.prototype.x']]]))->toThrow(InvalidArgumentException::class, 'invalid step')
        ->and(fn () => AiConsoleCommand::fromArray(['id' => 'x', 'label' => 'X', 'breadcrumb' => 'X', 'description' => 'X', 'steps' => [['type' => 'eval']]]))->toThrow(InvalidArgumentException::class, 'invalid step');
});
