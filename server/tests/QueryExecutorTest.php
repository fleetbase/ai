<?php

use Fleetbase\Ai\Services\AiQueryExecutor;
use Fleetbase\Ai\Support\AiQueryableResource;
use Fleetbase\Ai\Support\AiQueryRegistry;
use Illuminate\Database\Eloquent\Builder;

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
