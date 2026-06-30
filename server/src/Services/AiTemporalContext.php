<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Support\Auth;
use Illuminate\Support\Carbon;

class AiTemporalContext
{
    public function timezone(): string
    {
        return Auth::getUserTimezone();
    }

    public function context(): array
    {
        $timezone = $this->timezone();
        $now      = Carbon::now($timezone);

        return [
            'capability'  => 'fleetbase.ai.temporal_context',
            'type'        => 'temporal_context',
            'instruction' => 'Use this Fleetbase temporal context as the source of truth for relative dates. Do not infer today, tomorrow, yesterday, weeks, or months from model/server assumptions when this context is available.',
            'data'        => [
                'timezone'   => $timezone,
                'utc_offset' => $now->format('P'),
                'now'        => $now->toIso8601String(),
                'now_utc'    => $now->copy()->utc()->toIso8601String(),
                'today'      => $this->dayWindow($now->copy()),
                'tomorrow'   => $this->dayWindow($now->copy()->addDay()),
                'yesterday'  => $this->dayWindow($now->copy()->subDay()),
                'week'       => [
                    'this' => $this->rangeWindow($now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
                    'last' => $this->rangeWindow($now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()),
                    'next' => $this->rangeWindow($now->copy()->addWeek()->startOfWeek(), $now->copy()->addWeek()->endOfWeek()),
                ],
                'month'      => [
                    'this' => $this->rangeWindow($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
                    'last' => $this->rangeWindow($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()),
                    'next' => $this->rangeWindow($now->copy()->addMonthNoOverflow()->startOfMonth(), $now->copy()->addMonthNoOverflow()->endOfMonth()),
                ],
            ],
        ];
    }

    protected function dayWindow(Carbon $date): array
    {
        return [
            'date'  => $date->toDateString(),
            'start' => $date->copy()->startOfDay()->toIso8601String(),
            'end'   => $date->copy()->endOfDay()->toIso8601String(),
        ];
    }

    protected function rangeWindow(Carbon $start, Carbon $end): array
    {
        return [
            'start' => $start->toIso8601String(),
            'end'   => $end->toIso8601String(),
        ];
    }
}
