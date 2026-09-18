<?php

use Illuminate\Database\Eloquent\Builder;

/*
 * Test doubles for admin requests and filter query builders shared across test files.
 */

if (!function_exists('aiAdminRequestDouble')) {
    function aiAdminRequestDouble(array $input = [], bool $admin = false): Fleetbase\Http\Requests\AdminRequest
    {
        return new class($input, $admin) extends Fleetbase\Http\Requests\AdminRequest {
            public function __construct(private array $values, private bool $admin)
            {
            }

            public function input($key = null, $default = null)
            {
                if ($key === null) {
                    return $this->values;
                }

                return data_get($this->values, $key, $default);
            }

            public function filled($key)
            {
                $value = $this->input($key);

                return $value !== null && $value !== '';
            }

            public function searchQuery()
            {
                return $this->input('search');
            }

            public function user($guard = null)
            {
                return new class($this->admin) {
                    public string $uuid = 'admin-user-uuid';

                    public function __construct(private bool $admin)
                    {
                    }

                    public function isAdmin(): bool
                    {
                        return $this->admin;
                    }
                };
            }

            public function ip()
            {
                return $this->input('ip', '127.0.0.1');
            }

            public function userAgent()
            {
                return $this->input('user_agent', 'Fleetbase AI test browser');
            }
        };
    }
}

if (!function_exists('aiAdminFilterBuilder')) {
    function aiAdminFilterBuilder(): Builder
    {
        return new class extends Builder {
            public array $calls = [];

            public function __construct()
            {
            }

            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                if (is_callable($column)) {
                    $nested = aiAdminFilterBuilder();
                    $column($nested);
                    $this->calls[] = ['where_nested', $nested->calls];

                    return $this;
                }

                $this->calls[] = ['where', $column, $operator, $value, $boolean];

                return $this;
            }

            public function latest($column = null)
            {
                $this->calls[] = ['latest', $column];

                return $this;
            }

            public function orWhere($column, $operator = null, $value = null)
            {
                $this->calls[] = ['orWhere', $column, $operator, $value];

                return $this;
            }

            public function whereHas($relation, $callback = null, $operator = '>=', $count = 1)
            {
                $nested = aiAdminFilterBuilder();

                if (is_callable($callback)) {
                    $callback($nested);
                }

                $this->calls[] = ['whereHas', $relation, $nested->calls, $operator, $count];

                return $this;
            }

            public function orWhereHas($relation, $callback = null, $operator = '>=', $count = 1)
            {
                $nested = aiAdminFilterBuilder();

                if (is_callable($callback)) {
                    $callback($nested);
                }

                $this->calls[] = ['orWhereHas', $relation, $nested->calls, $operator, $count];

                return $this;
            }
        };
    }
}
