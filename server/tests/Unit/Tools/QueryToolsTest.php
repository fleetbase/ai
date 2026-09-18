<?php

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Services\AiTemporalContext;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Capabilities\Query\CountRecordsTool;
use Fleetbase\Ai\Support\Capabilities\Query\GroupCountTool;
use Fleetbase\Ai\Support\Capabilities\Query\ListRecordsTool;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class AiQueryToolOrder extends EloquentModel
{
    protected $table   = 'ai_query_tool_orders';
    protected $guarded = [];
}

function aiQueryToolRegistry(): AiQueryRegistry
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'default');
    $capsule->getDatabaseManager()->setDefaultConnection('default');
    EloquentModel::setConnectionResolver($capsule->getDatabaseManager());

    $capsule->getConnection('default')->getSchemaBuilder()->create('ai_query_tool_orders', function ($table) {
        $table->increments('id');
        $table->string('public_id');
        $table->string('company_uuid');
        $table->string('status');
        $table->boolean('dispatched')->default(false);
        $table->string('driver_assigned_uuid')->nullable();
        $table->timestamps();
    });

    session(['company' => 'company-1']);
    $rows = [
        ['order_a', 'company-1', 'created', false, null, '2026-09-10 08:00:00'],
        ['order_b', 'company-1', 'dispatched', true, 'driver-1', '2026-09-15 23:30:00'],
        ['order_c', 'company-1', 'completed', true, 'driver-1', '2026-09-16 10:00:00'],
        ['order_d', 'company-1', 'canceled', false, null, '2026-09-16 12:00:00'],
        ['order_x', 'company-2', 'created', false, null, '2026-09-16 12:00:00'],
    ];
    foreach ($rows as [$publicId, $company, $status, $dispatched, $driver, $createdAt]) {
        AiQueryToolOrder::create(['public_id' => $publicId, 'company_uuid' => $company, 'status' => $status, 'dispatched' => $dispatched, 'driver_assigned_uuid' => $driver, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    return (new AiQueryRegistry())
        ->register(new AiQueryableResource(
            key: 'fleet-ops.orders',
            label: 'Fleet-Ops orders',
            module: 'fleet-ops',
            modelClass: AiQueryToolOrder::class,
            aliases: ['orders'],
            fields: [
                'status'               => ['column' => 'status', 'type' => 'string', 'enum' => ['created', 'dispatched', 'completed', 'canceled']],
                'dispatched'           => ['column' => 'dispatched', 'type' => 'boolean'],
                'driver_assigned_uuid' => ['column' => 'driver_assigned_uuid', 'type' => 'uuid'],
                'created_at'           => ['column' => 'created_at', 'type' => 'datetime'],
            ],
            sampleFields: ['public_id', 'status', 'driver_assigned_uuid'],
            description: 'Transport orders',
        ))
        ->register(new AiQueryableResource(
            key: 'fleet-ops.vehicles',
            label: 'Fleet-Ops vehicles',
            module: 'fleet-ops',
            modelClass: AiQueryToolOrder::class,
            permission: 'fleet-ops see vehicle',
            fields: ['status' => ['column' => 'status', 'type' => 'string']],
        ));
}

function aiQueryToolContext(): AiToolContext
{
    $audience = new class(false) extends AiAudience {
        protected function checkPermission(string $permission): bool
        {
            return false;
        }
    };

    return new AiToolContext(new AiTask(['prompt' => 'orders']), $audience);
}

function aiQueryTimezone(string $timezone): AiTemporalContext
{
    return new class($timezone) extends AiTemporalContext {
        public function __construct(private string $zone)
        {
        }

        public function timezone(): string
        {
            return $this->zone;
        }
    };
}

test('count records applies validated filters and converts datetimes from the user timezone', function () {
    $registry = aiQueryToolRegistry();
    $tool     = new CountRecordsTool($registry, new AiQueryExecutor($registry), aiQueryTimezone('Asia/Singapore'));
    $context  = aiQueryToolContext();

    // 2026-09-16 00:00 in Singapore is 2026-09-15 16:00 UTC, so order_b (23:30 UTC on the 15th) is included.
    $today = $tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'filters' => [
        ['field' => 'created_at', 'operator' => '>=', 'value' => '2026-09-16T00:00:00'],
        ['field' => 'status', 'operator' => 'not_in', 'value' => ['canceled']],
    ]], $context);

    expect($today['count'])->toBe(2)
        ->and($today['filters'][0]['value'])->toBe('2026-09-15T16:00:00+00:00')
        ->and($tool->invoke(new AiTask(), ['resource' => 'orders', 'filters' => [['field' => 'dispatched', 'operator' => '=', 'value' => 'true']]], $context)['count'])->toBe(2)
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'filters' => [['field' => 'driver_assigned_uuid', 'operator' => 'null']]], $context)['count'])->toBe(2)
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders'], $context)['count'])->toBe(4)
        ->and($tool->toolName())->toBe('count_records')
        ->and($tool->key())->toBe('core.count_records')
        ->and($tool->label())->toBe('Count records')
        ->and($tool->description())->toContain('Counts')
        ->and($tool->module())->toBe('core')
        ->and($tool->availableFor($context))->toBeTrue()
        ->and((new CountRecordsTool(new AiQueryRegistry(), new AiQueryExecutor(new AiQueryRegistry())))->availableFor($context))->toBeFalse()
        ->and($tool->toolParameters()['properties']['resource']['enum'])->toBe(['fleet-ops.orders', 'fleet-ops.vehicles'])
        ->and($tool->toolDescription())->toContain('- fleet-ops.orders: Fleet-Ops orders — Transport orders. Fields: status (string: created|dispatched|completed|canceled), dispatched (boolean)');
});

test('query tools reject invalid filters, unknown resources, and resources the user cannot see', function () {
    $registry = aiQueryToolRegistry();
    $tool     = new CountRecordsTool($registry, new AiQueryExecutor($registry));
    $context  = aiQueryToolContext();

    $invalid = $tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'filters' => [
        ['field' => 'city', 'operator' => '=', 'value' => 'Paris'],
        ['field' => 'status', 'operator' => 'like', 'value' => 'x'],
        ['field' => 'status', 'operator' => '=', 'value' => 'cancelled'],
        ['field' => 'created_at', 'operator' => '>=', 'value' => 'last tuesday-ish?'],
        ['field' => 'status', 'operator' => 'in', 'value' => []],
        ['field' => 'status', 'operator' => '=', 'value' => ['a', 'b']],
        ['field' => 'status', 'operator' => 'in', 'value' => ['created', 'bogus']],
        'not-a-filter',
    ]], $context);

    expect($invalid['error'])->toBe('Invalid filters. Nothing was counted.')
        ->and($invalid['details'][0])->toContain("unknown field 'city'")
        ->and($invalid['details'][1])->toContain("unsupported operator 'like'")
        ->and($invalid['details'][2])->toContain("'cancelled' is not a valid status. Valid values: created, dispatched, completed, canceled.")
        ->and($invalid['details'][3])->toContain('not a valid datetime')
        ->and($invalid['details'][4])->toContain('needs at least one value')
        ->and($invalid['details'][5])->toContain('needs a single value')
        ->and($invalid['details'][6])->toContain("'bogus' is not a valid status")
        ->and($invalid['details'][7])->toContain("unknown field ''")
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.pets'], $context)['error'])->toContain('Unknown resource fleet-ops.pets')
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.vehicles'], $context))->toMatchArray(['authorized' => false, 'resource' => 'fleet-ops.vehicles']);
});

test('group count groups by allowed fields only', function () {
    $registry = aiQueryToolRegistry();
    $tool     = new GroupCountTool($registry, new AiQueryExecutor($registry));
    $context  = aiQueryToolContext();

    $result = $tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'group_by' => 'status'], $context);

    expect($result['counts'])->toEqual(['canceled' => 1, 'completed' => 1, 'created' => 1, 'dispatched' => 1])
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'group_by' => 'created_at'], $context)['error'])->toContain('cannot be used to group')
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'group_by' => 'status', 'filters' => [['field' => 'nope', 'operator' => '=']]], $context)['error'])->toContain('Invalid filters')
        ->and($tool->invoke(new AiTask(), ['resource' => 'nope', 'group_by' => 'status'], $context))->toHaveKey('error')
        ->and($tool->toolName())->toBe('group_count')
        ->and($tool->key())->toBe('core.group_count')
        ->and($tool->label())->toContain('by field')
        ->and($tool->description())->toContain('grouped')
        ->and($tool->toolParameters()['required'])->toBe(['resource', 'group_by'])
        ->and($tool->toolDescription())->toContain('grouped by one field');
});

test('list records returns the newest matches with the total and a truncation flag', function () {
    $registry = aiQueryToolRegistry();
    $tool     = new ListRecordsTool($registry, new AiQueryExecutor($registry));
    $context  = aiQueryToolContext();

    $result = $tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'limit' => 2, 'filters' => [['field' => 'status', 'operator' => '!=', 'value' => 'created']]], $context);
    $all    = $tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'limit' => 500], $context);

    expect($result)->toMatchArray(['authorized' => true, 'total_matching' => 3, 'returned' => 2, 'truncated' => true])
        ->and(array_column($result['records'], 'public_id'))->toBe(['order_d', 'order_c'])
        ->and($result['records'][1])->toBe(['public_id' => 'order_c', 'status' => 'completed', 'driver_assigned_uuid' => 'driver-1'])
        ->and($all['returned'])->toBe(4)
        ->and($all['truncated'])->toBeFalse()
        ->and($tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'filters' => [['field' => 'x', 'operator' => '=']]], $context)['error'])->toContain('Nothing was listed')
        ->and($tool->invoke(new AiTask(), ['resource' => 'nope'], $context))->toHaveKey('error')
        ->and((new AiQueryExecutor(new AiQueryRegistry()))->listRecords('nope'))->toBe(['authorized' => false, 'error' => 'Unknown query resource.'])
        ->and($tool->toolName())->toBe('list_records')
        ->and($tool->key())->toBe('core.list_records')
        ->and($tool->label())->toBe('List records')
        ->and($tool->description())->toContain('newest')
        ->and($tool->toolParameters()['properties']['limit']['maximum'])->toBe(ListRecordsTool::MAX_LIMIT)
        ->and($tool->toolDescription())->toContain('total_matching');
});

test('query tools fall back to UTC when the timezone cannot be resolved', function () {
    $registry = aiQueryToolRegistry();
    $failing  = new class extends AiTemporalContext {
        public function timezone(): string
        {
            throw new RuntimeException('No session user.');
        }
    };
    $tool = new CountRecordsTool($registry, new AiQueryExecutor($registry), $failing);

    $result = $tool->invoke(new AiTask(), ['resource' => 'fleet-ops.orders', 'filters' => [['field' => 'created_at', 'operator' => '>=', 'value' => '2026-09-16T00:00:00']]], aiQueryToolContext());

    expect($result['filters'][0]['value'])->toBe('2026-09-16T00:00:00+00:00')
        ->and($result['count'])->toBe(2);
});
