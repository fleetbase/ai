<?php

use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Illuminate\Database\Eloquent\Builder;

function aiQueryResourceWithBuilder(Builder $builder, array $overrides = []): AiQueryableResource
{
    return new class($builder, $overrides) extends AiQueryableResource {
        public function __construct(private Builder $builder, array $overrides = [])
        {
            parent::__construct(
                key: $overrides['key'] ?? 'orders',
                label: $overrides['label'] ?? 'Orders',
                module: $overrides['module'] ?? 'fleet-ops',
                modelClass: $overrides['modelClass'] ?? stdClass::class,
                permission: $overrides['permission'] ?? null,
                companyColumn: $overrides['companyColumn'] ?? '',
                aliases: $overrides['aliases'] ?? [],
                fields: $overrides['fields'] ?? [
                    'status' => ['column' => 'status'],
                    'city'   => ['column' => 'city'],
                ],
                sampleFields: $overrides['sampleFields'] ?? ['public_id', 'status', 'empty_value'],
                locationField: $overrides['locationField'] ?? null,
                directivePermission: $overrides['directivePermission'] ?? null,
                defaultLimit: $overrides['defaultLimit'] ?? 10,
                maxLimit: $overrides['maxLimit'] ?? 25,
            );
        }

        public function query(): Builder
        {
            return $this->builder;
        }
    };
}

test('query executor applies supported filters and skips invalid filters', function () {
    $resource = new AiQueryableResource(
        key: 'orders',
        label: 'Orders',
        module: 'fleet-ops',
        modelClass: stdClass::class,
        fields: [
            'status'     => ['column' => 'status'],
            'deleted_at' => ['column' => 'deleted_at'],
            'type'       => ['column' => 'type'],
            'driver'     => ['column' => 'driver_uuid'],
        ]
    );
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['where'])
        ->addMethods(['whereNull', 'whereIn', 'whereNotIn'])
        ->getMock();

    $query->expects($this->once())
        ->method('where')
        ->with('status', '=', 'active')
        ->willReturnSelf();
    $query->expects($this->once())
        ->method('whereNull')
        ->with('deleted_at')
        ->willReturnSelf();
    $query->expects($this->once())
        ->method('whereIn')
        ->with('type', ['pickup', 'dropoff'])
        ->willReturnSelf();
    $query->expects($this->once())
        ->method('whereNotIn')
        ->with('driver_uuid', ['driver-1'])
        ->willReturnSelf();

    $result = (new AiQueryExecutor(new AiQueryRegistry()))->applyFilters($resource, $query, [
        ['field' => 'status', 'operator' => '=', 'value' => 'active'],
        ['field' => 'deleted_at', 'operator' => 'null'],
        ['field' => 'type', 'operator' => 'in', 'value' => ['pickup', 'dropoff']],
        ['field' => 'driver', 'operator' => 'not_in', 'value' => ['driver-1']],
        ['field' => 'missing', 'operator' => '=', 'value' => 'ignored'],
        ['field' => 'status', 'operator' => 'unsupported', 'value' => 'ignored'],
    ]);

    expect($result)->toBe($query);
});

test('query executor returns counts and grouped counts for registered resources', function () {
    $countQuery = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->addMethods(['count'])
        ->getMock();
    $countQuery->expects($this->once())->method('count')->willReturn(7);

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($countQuery, ['key' => 'orders']));

    $count = (new AiQueryExecutor($registry))->count('orders');

    $groupQuery = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['pluck'])
        ->addMethods(['selectRaw', 'groupBy'])
        ->getMock();
    $groupQuery->expects($this->exactly(1))->method('selectRaw')->with('status, count(*) as aggregate')->willReturnSelf();
    $groupQuery->expects($this->once())->method('groupBy')->with('status')->willReturnSelf();
    $groupQuery->expects($this->once())->method('pluck')->with('aggregate', 'status')->willReturn(collect(['active' => 5, 'pending' => 2]));

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($groupQuery, ['key' => 'orders']));

    $countsBy = (new AiQueryExecutor($registry))->countsBy('orders', 'status');

    expect($count)->toMatchArray([
        'authorized' => true,
        'resource'   => 'orders',
        'metric'     => 'count',
        'count'      => 7,
    ])
        ->and($countsBy)->toMatchArray([
            'authorized' => true,
            'resource'   => 'orders',
            'metric'     => 'counts_by',
            'group_by'   => 'status',
            'counts'     => ['active' => 5, 'pending' => 2],
        ])
        ->and((new AiQueryExecutor(new AiQueryRegistry()))->count('missing'))->toBe([
            'authorized' => false,
            'error'      => 'Unknown query resource.',
        ])
        ->and((new AiQueryExecutor($registry))->countsBy('orders', 'missing'))->toBe([
            'authorized' => false,
            'error'      => 'Unknown resource or field.',
        ]);
});

test('query executor denies permissioned resources before running queries', function () {
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->addMethods(['count'])
        ->getMock();
    $query->expects($this->never())->method('count');

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($query, [
        'key'        => 'secure-orders',
        'permission' => 'orders view secure',
    ]));

    $executor = new class($registry) extends AiQueryExecutor {
        protected function userFromSession()
        {
            return null;
        }

        protected function canPermission(string $permission): bool
        {
            return false;
        }
    };

    expect($executor->count('secure-orders'))->toBe([
        'authorized' => false,
        'resource'   => 'secure-orders',
    ])
        ->and($executor->countsBy('secure-orders', 'status'))->toBe([
            'authorized' => false,
            'resource'   => 'secure-orders',
        ])
        ->and($executor->samples('secure-orders'))->toBe([
            'authorized' => false,
            'resource'   => 'secure-orders',
        ]);
});

test('query executor allows permissioned resources for admins and delegated permissions', function () {
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->addMethods(['count'])
        ->getMock();
    $query->expects($this->exactly(2))->method('count')->willReturn(3);

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($query, [
        'key'        => 'secure-orders',
        'permission' => 'orders view secure',
    ]));

    $adminExecutor = new class($registry) extends AiQueryExecutor {
        protected function userFromSession()
        {
            return new class {
                public function isAdmin(): bool
                {
                    return true;
                }
            };
        }
    };

    $permissionExecutor = new class($registry) extends AiQueryExecutor {
        protected function userFromSession()
        {
            return new class {
                public function isAdmin(): bool
                {
                    return false;
                }
            };
        }

        protected function canPermission(string $permission): bool
        {
            return $permission === 'orders view secure';
        }
    };

    expect($adminExecutor->count('secure-orders')['authorized'])->toBeTrue()
        ->and($permissionExecutor->count('secure-orders')['authorized'])->toBeTrue();
});

test('query executor samples sanitize records and clamps requested limits', function () {
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['latest', 'get'])
        ->addMethods(['limit'])
        ->getMock();
    $query->expects($this->once())->method('latest')->willReturnSelf();
    $query->expects($this->once())->method('limit')->with(3)->willReturnSelf();
    $query->expects($this->once())->method('get')->willReturn(collect([
        (object) ['public_id' => 'ORD-1', 'status' => 'active', 'empty_value' => ''],
        (object) ['public_id' => 'ORD-2', 'status' => null, 'empty_value' => null],
    ]));

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($query, [
        'key'       => 'orders',
        'maxLimit'  => 3,
    ]));

    $samples = (new AiQueryExecutor($registry))->samples('orders', [], 99);

    expect($samples['authorized'])->toBeTrue()
        ->and($samples['limit'])->toBe(3)
        ->and($samples['records'])->toBe([
            ['public_id' => 'ORD-1', 'status' => 'active'],
            ['public_id' => 'ORD-2'],
        ]);
});

test('query executor returns unknown and unauthorized location and sample responses', function () {
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->getMock();

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($query, [
        'key'           => 'secure-vehicles',
        'permission'    => 'vehicles view secure',
        'locationField' => 'location',
    ]));

    $executor = new class($registry) extends AiQueryExecutor {
        protected function userFromSession()
        {
            return null;
        }

        protected function canPermission(string $permission): bool
        {
            return false;
        }
    };

    expect((new AiQueryExecutor(new AiQueryRegistry()))->samples('missing'))->toBe([
        'authorized' => false,
        'error'      => 'Unknown query resource.',
    ])
        ->and($executor->locationSummary('secure-vehicles'))->toBe([
            'authorized' => false,
            'resource'   => 'secure-vehicles',
        ]);
});

test('query executor builds location summaries with bounded coordinate samples', function () {
    $point = new class {
        public function getLat(): float
        {
            return 1.234567;
        }

        public function getLng(): float
        {
            return 103.987654;
        }
    };
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['latest', 'get'])
        ->addMethods(['whereNotNull', 'whereRaw', 'limit'])
        ->getMock();
    $query->expects($this->once())->method('whereNotNull')->with('location')->willReturnSelf();
    $query->expects($this->once())->method('whereRaw')->willReturnSelf();
    $query->expects($this->once())->method('latest')->willReturnSelf();
    $query->expects($this->once())->method('limit')->with(2)->willReturnSelf();
    $query->expects($this->once())->method('get')->willReturn(collect([
        (object) ['public_id' => 'ORD-1', 'status' => 'active', 'city' => 'Singapore', 'country' => 'SG', 'location' => $point],
        (object) ['public_id' => 'ORD-2', 'status' => 'active', 'city' => 'Singapore', 'country' => 'SG', 'location' => $point],
    ]));

    $registry = new AiQueryRegistry();
    $registry->register(aiQueryResourceWithBuilder($query, [
        'key'           => 'orders',
        'locationField' => 'location',
        'maxLimit'      => 2,
    ]));

    $summary = (new AiQueryExecutor($registry))->locationSummary('orders', [], 99);

    expect($summary['authorized'])->toBeTrue()
        ->and($summary['valid_location_count'])->toBe(2)
        ->and($summary['majority_by_city'])->toBe(['Singapore' => 2])
        ->and($summary['majority_by_country'])->toBe(['SG' => 2])
        ->and($summary['coordinate_samples'][0])->toMatchArray([
            'public_id' => 'ORD-1',
            'latitude'  => 1.23457,
            'longitude' => 103.98765,
        ])
        ->and((new AiQueryExecutor(new AiQueryRegistry()))->locationSummary('missing'))->toBe([
            'authorized' => false,
            'error'      => 'Resource has no registered location field.',
        ]);
});

test('query executor applies not-null false-or-null and comparison filters', function () {
    $resource = new AiQueryableResource(
        key: 'vehicles',
        label: 'Vehicles',
        module: 'fleet-ops',
        modelClass: stdClass::class,
        fields: [
            'online'   => ['column' => 'online'],
            'odometer' => ['column' => 'odometer'],
            'location' => ['column' => 'location'],
        ]
    );
    $query  = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['where'])
        ->addMethods(['whereNotNull'])
        ->getMock();
    $nested = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['where'])
        ->addMethods(['orWhereNull'])
        ->getMock();

    $query->expects($this->exactly(2))
        ->method('where')
        ->willReturnCallback(function (...$arguments) use ($query, $nested) {
            if (is_callable($arguments[0] ?? null)) {
                $nested->expects($this->once())
                    ->method('where')
                    ->with('online', false)
                    ->willReturnSelf();
                $nested->expects($this->once())
                    ->method('orWhereNull')
                    ->with('online')
                    ->willReturnSelf();
                $arguments[0]($nested);

                return $query;
            }

            expect($arguments)->toBe(['odometer', '>=', 1000, 'and']);

            return $query;
        });
    $query->expects($this->once())
        ->method('whereNotNull')
        ->with('location')
        ->willReturnSelf();

    (new AiQueryExecutor(new AiQueryRegistry()))->applyFilters($resource, $query, [
        ['field' => 'online', 'operator' => 'false_or_null'],
        ['field' => 'odometer', 'operator' => '>=', 'value' => 1000],
        ['field' => 'location', 'operator' => 'not_null'],
    ]);
});

test('query executor constrains valid point locations', function () {
    $query = $this->getMockBuilder(Builder::class)
        ->disableOriginalConstructor()
        ->addMethods(['whereNotNull', 'whereRaw'])
        ->getMock();

    $query->expects($this->once())
        ->method('whereNotNull')
        ->with('last_location')
        ->willReturnSelf();
    $query->expects($this->once())
        ->method('whereRaw')
        ->with($this->callback(fn (string $sql) => str_contains($sql, 'ST_Y(`last_location`) BETWEEN -90 AND 90') && str_contains($sql, 'ST_X(`last_location`) BETWEEN -180 AND 180')))
        ->willReturnSelf();

    $result = (new AiQueryExecutor(new AiQueryRegistry()))->whereValidLocation($query, 'last_location');

    expect($result)->toBe($query);
});
