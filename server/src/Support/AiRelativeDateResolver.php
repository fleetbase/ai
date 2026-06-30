<?php

namespace Fleetbase\Ai\Support;

use Fleetbase\Ai\Services\AiTemporalContext;
use Illuminate\Support\Carbon;

class AiRelativeDateResolver
{
    public function __construct(protected ?AiTemporalContext $temporalContext = null)
    {
    }

    public function resolveDateTime(string $text, ?string $timezone = null, ?Carbon $now = null): ?Carbon
    {
        $text = $this->normalize($text);
        $now  = $this->now($timezone, $now);

        if (preg_match('/\b(?:in\s+)?(\d+)\s+(minute|minutes|hour|hours|day|days|week|weeks|month|months)\s*(?:from now|later)?\b/', $text, $matches)) {
            return $this->addUnit($now, (int) $matches[1], $matches[2]);
        }

        if (str_contains($text, 'tomorrow')) {
            return $now->copy()->addDay();
        }

        if (str_contains($text, 'yesterday')) {
            return $now->copy()->subDay();
        }

        if (str_contains($text, 'today')) {
            return $now->copy();
        }

        if (str_contains($text, 'next week')) {
            return $now->copy()->addWeek();
        }

        if (str_contains($text, 'last week')) {
            return $now->copy()->subWeek();
        }

        return null;
    }

    public function resolveWindow(string $text, ?string $timezone = null, ?Carbon $now = null): ?array
    {
        $text = $this->normalize($text);
        $now  = $this->now($timezone, $now);

        if (str_contains($text, 'last 30 days')) {
            return $this->window('last_30_days', $now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay());
        }

        if (str_contains($text, 'yesterday')) {
            return $this->window('yesterday', $now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay());
        }

        if (str_contains($text, 'tomorrow')) {
            return $this->window('tomorrow', $now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay());
        }

        if (str_contains($text, 'today')) {
            return $this->window('today', $now->copy()->startOfDay(), $now->copy()->endOfDay());
        }

        if (str_contains($text, 'last week')) {
            return $this->window('last_week', $now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek());
        }

        if (str_contains($text, 'next week')) {
            return $this->window('next_week', $now->copy()->addWeek()->startOfWeek(), $now->copy()->addWeek()->endOfWeek());
        }

        if (str_contains($text, 'this week')) {
            return $this->window('this_week', $now->copy()->startOfWeek(), $now->copy()->endOfWeek());
        }

        if (str_contains($text, 'last month')) {
            return $this->window('last_month', $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());
        }

        if (str_contains($text, 'next month')) {
            return $this->window('next_month', $now->copy()->addMonthNoOverflow()->startOfMonth(), $now->copy()->addMonthNoOverflow()->endOfMonth());
        }

        if (str_contains($text, 'this month')) {
            return $this->window('this_month', $now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        }

        return null;
    }

    protected function addUnit(Carbon $now, int $amount, string $unit): Carbon
    {
        return match (rtrim($unit, 's')) {
            'minute' => $now->copy()->addMinutes($amount),
            'hour'   => $now->copy()->addHours($amount),
            'day'    => $now->copy()->addDays($amount),
            'week'   => $now->copy()->addWeeks($amount),
            'month'  => $now->copy()->addMonthsNoOverflow($amount),
            default  => $now->copy(),
        };
    }

    protected function window(string $label, Carbon $start, Carbon $end): array
    {
        return [
            'label'    => $label,
            'timezone' => $start->getTimezone()->getName(),
            'start'    => $start,
            'end'      => $end,
        ];
    }

    protected function now(?string $timezone = null, ?Carbon $now = null): Carbon
    {
        $timezone ??= $this->temporalContext?->timezone() ?? (function_exists('config') ? config('app.timezone', date_default_timezone_get()) : date_default_timezone_get());

        return $now ? $now->copy()->setTimezone($timezone) : Carbon::now($timezone);
    }

    protected function normalize(string $text): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($text)));
    }
}
