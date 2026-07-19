<?php

use Fleetbase\Ai\Contracts\AICapabilityInterface;
use Fleetbase\Ai\Contracts\AIContextCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiContextResolver;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\AiRelativeDateResolver;
use Fleetbase\Ai\Support\Capabilities\AbstractAICapability;
use Fleetbase\Ai\Support\Capabilities\CurrentPageContextCapability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

function aiTestCapability(array $overrides = []): AICapabilityInterface
{
    return new class($overrides) implements AICapabilityInterface {
        public function __construct(private array $overrides)
        {
        }

        public function key(): string
        {
            return $this->overrides['key'] ?? 'fleetbase.test';
        }

        public function label(): string
        {
            return $this->overrides['label'] ?? 'Fleetbase test';
        }

        public function description(): string
        {
            return $this->overrides['description'] ?? 'Test capability.';
        }

        public function module(): string
        {
            return $this->overrides['module'] ?? 'ai';
        }

        public function type(): string
        {
            return $this->overrides['type'] ?? 'read';
        }

        public function mode(): string
        {
            return $this->overrides['mode'] ?? 'context';
        }

        public function permissions(): array
        {
            return $this->overrides['permissions'] ?? [];
        }

        public function previewOnly(): bool
        {
            return $this->overrides['preview_only'] ?? true;
        }

        public function executable(): bool
        {
            return $this->overrides['executable'] ?? false;
        }

        public function toArray(): array
        {
            return [
                'key'          => $this->key(),
                'label'        => $this->label(),
                'description'  => $this->description(),
                'module'       => $this->module(),
                'type'         => $this->type(),
                'mode'         => $this->mode(),
                'permissions'  => $this->permissions(),
                'preview_only' => $this->previewOnly(),
                'executable'   => $this->executable(),
            ];
        }
    };
}

function aiContextCapability(array $overrides = []): AIContextCapabilityInterface
{
    return new class($overrides) implements AIContextCapabilityInterface {
        public function __construct(private array $overrides)
        {
        }

        public function key(): string
        {
            return $this->overrides['key'] ?? 'fleetbase.resolve';
        }

        public function label(): string
        {
            return $this->overrides['label'] ?? 'Resolvable context';
        }

        public function description(): string
        {
            return $this->overrides['description'] ?? 'Adds test context.';
        }

        public function module(): string
        {
            return $this->overrides['module'] ?? 'ai';
        }

        public function type(): string
        {
            return $this->overrides['type'] ?? 'read';
        }

        public function mode(): string
        {
            return $this->overrides['mode'] ?? 'context';
        }

        public function permissions(): array
        {
            return $this->overrides['permissions'] ?? [];
        }

        public function previewOnly(): bool
        {
            return $this->overrides['preview_only'] ?? true;
        }

        public function executable(): bool
        {
            return $this->overrides['executable'] ?? false;
        }

        public function toArray(): array
        {
            return [];
        }

        public function shouldResolve(AiTask $task): bool
        {
            if (isset($this->overrides['should_resolve'])) {
                return (bool) $this->overrides['should_resolve'];
            }

            return $task->prompt === 'inspect route';
        }

        public function resolve(AiTask $task): array
        {
            if (($this->overrides['throws'] ?? false) === true) {
                throw new RuntimeException('Context source failed.');
            }

            return ['route' => data_get($task->context, 'route')];
        }
    };
}

function aiRecordingBuilder(): Builder
{
    return new class extends Builder {
        public array $calls = [];

        public function __construct()
        {
        }

        public function where($column, $operator = null, $value = null, $boolean = 'and')
        {
            $this->calls[] = ['where', $column, $operator, $value, $boolean];

            return $this;
        }

        public function applyDirectivesForPermissions(string $permission)
        {
            $this->calls[] = ['applyDirectivesForPermissions', $permission];

            return $this;
        }
    };
}

test('query registry stores resources and resolves case-insensitive aliases', function () {
    $resource = new AiQueryableResource(
        key: 'orders',
        label: 'Orders',
        module: 'fleet-ops',
        modelClass: stdClass::class,
        aliases: ['shipments', 'jobs'],
        fields: ['status' => ['column' => 'status']]
    );

    $registry = new AiQueryRegistry();

    expect($registry->all())->toHaveCount(0);

    $returned = $registry->register($resource);

    expect($returned)->toBe($registry)
        ->and($registry->all())->toHaveCount(1)
        ->and($registry->get('orders'))->toBe($resource)
        ->and($registry->find('ORDERS'))->toBe($resource)
        ->and($registry->find('Shipments'))->toBe($resource)
        ->and($registry->find('missing'))->toBeNull();
});

test('queryable resource exposes field metadata and registered columns', function () {
    $resource = new AiQueryableResource(
        key: 'vehicles',
        label: 'Vehicles',
        module: 'fleet-ops',
        modelClass: stdClass::class,
        fields: [
            'status' => ['column' => 'status'],
            'city'   => ['column' => 'meta_city'],
        ],
        sampleFields: ['public_id', 'status'],
        locationField: 'location',
        defaultLimit: 5,
        maxLimit: 25
    );

    expect($resource->field('status'))->toBe(['column' => 'status'])
        ->and($resource->hasField('city'))->toBeTrue()
        ->and($resource->hasField('missing'))->toBeFalse()
        ->and($resource->columnFor('city'))->toBe('meta_city')
        ->and($resource->columnFor('missing'))->toBeNull()
        ->and($resource->sampleFields)->toBe(['public_id', 'status'])
        ->and($resource->locationField)->toBe('location')
        ->and($resource->defaultLimit)->toBe(5)
        ->and($resource->maxLimit)->toBe(25);
});

test('queryable resource builds scoped model queries with optional directives', function () {
    session(['company' => 'company-uuid']);

    $builder    = aiRecordingBuilder();
    $modelClass = get_class(new class {
        public static Builder $builder;

        public static function query(): Builder
        {
            return static::$builder;
        }
    });
    $modelClass::$builder = $builder;

    $resource = new AiQueryableResource(
        key: 'orders',
        label: 'Orders',
        module: 'fleet-ops',
        modelClass: $modelClass,
        directivePermission: 'orders view'
    );

    expect($resource->query())->toBe($builder)
        ->and($builder->calls)->toBe([
            ['where', 'company_uuid', 'company-uuid', null, 'and'],
            ['applyDirectivesForPermissions', 'orders view'],
        ]);

    $unscopedBuilder         = aiRecordingBuilder();
    $modelClass::$builder    = $unscopedBuilder;
    $unscopedResource        = new AiQueryableResource(
        key: 'global',
        label: 'Global',
        module: 'ai',
        modelClass: $modelClass,
        companyColumn: ''
    );

    expect($unscopedResource->query())->toBe($unscopedBuilder)
        ->and($unscopedBuilder->calls)->toBe([]);
});

test('capability registry stores capabilities by key and lists metadata', function () {
    $first  = aiTestCapability(['key' => 'fleetbase.first', 'label' => 'First']);
    $second = new CurrentPageContextCapability();

    $registry = new AiCapabilityRegistry();
    $registry->register($first)->register($second);

    expect($registry->has('fleetbase.first'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and($registry->get('fleetbase.first'))->toBe($first)
        ->and($registry->get('missing'))->toBeNull()
        ->and($registry->all())->toHaveCount(2)
        ->and(collect($registry->list())->pluck('key')->all())->toBe([
            'fleetbase.first',
            'core.current_page_context',
        ]);
});

test('abstract capability provides default metadata and optional input schema', function () {
    $capability = new class extends AbstractAICapability {
        public function key(): string
        {
            return 'fleetbase.abstract';
        }

        public function label(): string
        {
            return 'Abstract test';
        }

        public function description(): string
        {
            return 'Covers default capability metadata.';
        }

        public function module(): string
        {
            return 'ai';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }
    };

    expect($capability->type())->toBe('read')
        ->and($capability->mode())->toBe('context')
        ->and($capability->permissions())->toBe([])
        ->and($capability->previewOnly())->toBeTrue()
        ->and($capability->executable())->toBeFalse()
        ->and($capability->toArray())->toMatchArray([
            'key'          => 'fleetbase.abstract',
            'label'        => 'Abstract test',
            'module'       => 'ai',
            'type'         => 'read',
            'mode'         => 'context',
            'preview_only' => true,
            'executable'   => false,
            'input_schema' => ['type' => 'object'],
        ]);
});

test('context resolver includes only resolvable context capabilities and captures errors', function () {
    $resolving = aiContextCapability();
    $failing   = aiContextCapability(['key' => 'fleetbase.failing', 'throws' => true]);
    $skipped   = aiContextCapability(['key' => 'fleetbase.skipped', 'should_resolve' => false]);

    $registry = new AiCapabilityRegistry();
    $registry->register(aiTestCapability(['key' => 'fleetbase.plain']))
        ->register($resolving)
        ->register($failing)
        ->register($skipped);

    $context = (new AiContextResolver($registry))->resolve(new AiTask([
        'prompt'  => 'inspect route',
        'context' => ['route' => 'fleet-ops.orders.index'],
    ]));

    expect($context)->toHaveCount(2)
        ->and($context[0]['key'])->toBe('fleetbase.resolve')
        ->and($context[0]['result'])->toBe(['route' => 'fleet-ops.orders.index'])
        ->and($context[1]['key'])->toBe('fleetbase.failing')
        ->and($context[1]['result']['error']['message'])->toBe('Context source failed.')
        ->and($context[1]['result']['error']['type'])->toBe(RuntimeException::class);
});

test('relative date resolver covers units and named date windows', function () {
    $now      = Carbon::parse('2026-07-19 10:30:00', 'Asia/Ulaanbaatar');
    $resolver = new AiRelativeDateResolver();

    expect($resolver->resolveDateTime('in 45 minutes', 'Asia/Ulaanbaatar', $now)->toIso8601String())->toBe('2026-07-19T11:15:00+08:00')
        ->and($resolver->resolveDateTime('2 hours later', 'Asia/Ulaanbaatar', $now)->toIso8601String())->toBe('2026-07-19T12:30:00+08:00')
        ->and($resolver->resolveDateTime('in 3 days', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-07-22')
        ->and($resolver->resolveDateTime('in 2 weeks', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-08-02')
        ->and($resolver->resolveDateTime('in 1 month', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-08-19')
        ->and($resolver->resolveDateTime('tomorrow', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-07-20')
        ->and($resolver->resolveDateTime('yesterday', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-07-18')
        ->and($resolver->resolveDateTime('today', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-07-19')
        ->and($resolver->resolveDateTime('next week', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-07-26')
        ->and($resolver->resolveDateTime('last week', 'Asia/Ulaanbaatar', $now)->toDateString())->toBe('2026-07-12')
        ->and($resolver->resolveDateTime('no date here', 'Asia/Ulaanbaatar', $now))->toBeNull();

    $lastThirtyDays = $resolver->resolveWindow('Show usage for the last 30 days', 'Asia/Ulaanbaatar', $now);
    $yesterday      = $resolver->resolveWindow('Show usage yesterday', 'Asia/Ulaanbaatar', $now);
    $tomorrow       = $resolver->resolveWindow('Show usage tomorrow', 'Asia/Ulaanbaatar', $now);
    $today          = $resolver->resolveWindow('Show usage today', 'Asia/Ulaanbaatar', $now);
    $lastWeek       = $resolver->resolveWindow('Show usage last week', 'Asia/Ulaanbaatar', $now);
    $nextWeek       = $resolver->resolveWindow('Show usage next week', 'Asia/Ulaanbaatar', $now);
    $thisWeek       = $resolver->resolveWindow('Show usage this week', 'Asia/Ulaanbaatar', $now);
    $lastMonth      = $resolver->resolveWindow('Show usage last month', 'Asia/Ulaanbaatar', $now);
    $thisMonth      = $resolver->resolveWindow('Show usage this month', 'Asia/Ulaanbaatar', $now);
    $nextMonth      = $resolver->resolveWindow('Show usage next month', 'Asia/Ulaanbaatar', $now);

    expect($lastThirtyDays['label'])->toBe('last_30_days')
        ->and($lastThirtyDays['start']->toIso8601String())->toBe('2026-06-19T00:00:00+08:00')
        ->and($lastThirtyDays['end']->toIso8601String())->toBe('2026-07-19T23:59:59+08:00')
        ->and($yesterday['label'])->toBe('yesterday')
        ->and($yesterday['start']->toDateString())->toBe('2026-07-18')
        ->and($tomorrow['label'])->toBe('tomorrow')
        ->and($tomorrow['end']->toDateString())->toBe('2026-07-20')
        ->and($today['label'])->toBe('today')
        ->and($today['start']->toDateString())->toBe('2026-07-19')
        ->and($lastWeek['label'])->toBe('last_week')
        ->and($lastWeek['start']->toDateString())->toBe('2026-07-06')
        ->and($nextWeek['label'])->toBe('next_week')
        ->and($nextWeek['end']->toDateString())->toBe('2026-07-26')
        ->and($thisWeek['label'])->toBe('this_week')
        ->and($thisWeek['start']->toDateString())->toBe('2026-07-13')
        ->and($lastMonth['label'])->toBe('last_month')
        ->and($lastMonth['end']->toDateString())->toBe('2026-06-30')
        ->and($thisMonth['label'])->toBe('this_month')
        ->and($thisMonth['start']->toDateString())->toBe('2026-07-01')
        ->and($thisMonth['end']->toDateString())->toBe('2026-07-31')
        ->and($nextMonth['label'])->toBe('next_month')
        ->and($nextMonth['start']->toDateString())->toBe('2026-08-01')
        ->and($resolver->resolveWindow('without a relative period', 'Asia/Ulaanbaatar', $now))->toBeNull();
});

test('temporal context builds grounded day week and month ranges in user timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-19 10:30:00', 'Asia/Ulaanbaatar'));

    try {
        $context = (new class extends AiTemporalContext {
            public function timezone(): string
            {
                return 'Asia/Ulaanbaatar';
            }
        })->context();
    } finally {
        Carbon::setTestNow();
    }

    expect($context['capability'])->toBe('fleetbase.ai.temporal_context')
        ->and($context['data']['timezone'])->toBe('Asia/Ulaanbaatar')
        ->and($context['data']['today']['date'])->toBe('2026-07-19')
        ->and($context['data']['tomorrow']['date'])->toBe('2026-07-20')
        ->and($context['data']['yesterday']['date'])->toBe('2026-07-18')
        ->and($context['data']['week']['this']['start'])->toBe('2026-07-13T00:00:00+08:00')
        ->and($context['data']['week']['next']['end'])->toBe('2026-07-26T23:59:59+08:00')
        ->and($context['data']['month']['last']['start'])->toBe('2026-06-01T00:00:00+08:00')
        ->and($context['data']['month']['next']['end'])->toBe('2026-08-31T23:59:59+08:00');
});
