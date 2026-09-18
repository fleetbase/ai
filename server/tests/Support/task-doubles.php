<?php

/*
 * Test doubles for AI tasks, steps, sessions, and query builders shared across test files.
 */

use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

if (!function_exists('aiTaskDouble')) {
    function aiTaskDouble(array $attributes = []): AiTask
    {
        return new class($attributes) extends AiTask {
            protected $attributes = [];

            public array $updates = [];

            public function __construct(array $attributes = [])
            {
                $this->attributes = array_merge(['uuid' => 'task-uuid'], $attributes);
            }

            public function __get($key)
            {
                return $this->attributes[$key] ?? null;
            }

            public function __set($key, $value): void
            {
                $this->attributes[$key] = $value;
            }

            public function update(array $attributes = [], array $options = [])
            {
                $this->updates[]  = $attributes;
                $this->attributes = array_merge($this->attributes, $attributes);

                return true;
            }

            public function fresh($with = [])
            {
                return $this;
            }
        };
    }
}

if (!function_exists('aiStepDouble')) {
    function aiStepDouble(array $attributes = []): AiTaskStep
    {
        return new class($attributes) extends AiTaskStep {
            protected $attributes = [];

            public array $updates = [];

            public function __construct(array $attributes = [])
            {
                $this->attributes = $attributes;
            }

            public function __get($key)
            {
                return $this->attributes[$key] ?? null;
            }

            public function __set($key, $value): void
            {
                $this->attributes[$key] = $value;
            }

            public function update(array $attributes = [], array $options = [])
            {
                $this->updates[]  = $attributes;
                $this->attributes = array_merge($this->attributes, $attributes);

                return true;
            }
        };
    }
}

if (!function_exists('aiSessionDouble')) {
    function aiSessionDouble(array $attributes = []): AiSession
    {
        return new class($attributes) extends AiSession {
            protected $attributes = [];

            public array $updates = [];

            public function __construct(array $attributes = [])
            {
                $this->attributes = array_merge(['uuid' => 'session-uuid'], $attributes);
            }

            public function __get($key)
            {
                return $this->attributes[$key] ?? null;
            }

            public function __set($key, $value): void
            {
                $this->attributes[$key] = $value;
            }

            public function update(array $attributes = [], array $options = [])
            {
                $this->updates[]  = $attributes;
                $this->attributes = array_merge($this->attributes, $attributes);

                return true;
            }
        };
    }
}

if (!function_exists('aiTaskServiceQueryBuilder')) {
    function aiTaskServiceQueryBuilder(array &$firstRows = [], array $getRows = []): Builder
    {
        return new class($firstRows, $getRows) extends Builder {
            public array $calls = [];

            public function __construct(private array &$firstRows, private array $getRows)
            {
            }

            public function __clone()
            {
            }

            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                if (is_callable($column)) {
                    $nested = aiTaskServiceQueryBuilder($this->firstRows);
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

            public function whereNotNull($columns, $boolean = 'and', $not = false)
            {
                $this->calls[] = ['whereNotNull', $columns, $boolean, $not];

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

            public function first($columns = ['*'])
            {
                $this->calls[] = ['first', $columns];

                return array_shift($this->firstRows);
            }

            public function get($columns = ['*'])
            {
                $this->calls[] = ['get', $columns];

                return collect($this->getRows);
            }
        };
    }
}

if (!function_exists('aiCreateRequest')) {
    function aiCreateRequest(array $input): Request
    {
        $request = Request::create('/ai/tasks', 'POST', $input);
        $request->setUserResolver(fn () => new class {
            public string $uuid = 'user-uuid';
        });

        return $request;
    }
}
