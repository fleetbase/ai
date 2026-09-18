<?php

namespace Fleetbase\Ai\Support\Eval;

use Illuminate\Support\Str;

/**
 * Grades one Fleetbase AI answer against a golden case.
 *
 * Supported expectations:
 * - `required_tools`: tool names that must be called
 * - `forbidden_tools`: tool names that must not be called
 * - `min_tool_calls`: tool name => minimum number of calls
 * - `required_ui_commands_any`: at least one of these console commands must be proposed
 * - `forbidden_ui_commands`: console commands that must not be proposed
 * - `must_contain_all_of_any`: each group lists alternatives; every group needs one match (case-insensitive)
 * - `must_not_contain`: phrases that must not appear (case-insensitive)
 * - `must_not_match`: regular expressions that must not match
 *
 * Every answer is also checked for leaked internal route names.
 */
class AiEvalGrader
{
    public const ROUTE_NAME_PATTERN = '/\bconsole\.[a-z0-9-]+(?:\.[a-z0-9-]+)+/i';

    /**
     * @param array $expect  the case expectations
     * @param array $outcome `response` (string), `tools` (called tool names), `ui_commands` (proposed command ids)
     *
     * @return array{passed: bool, failures: string[]}
     */
    public function grade(array $expect, array $outcome): array
    {
        $response = $this->normalize((string) ($outcome['response'] ?? ''));
        $tools    = array_count_values((array) ($outcome['tools'] ?? []));
        $commands = (array) ($outcome['ui_commands'] ?? []);
        $failures = [];

        foreach ((array) ($expect['required_tools'] ?? []) as $tool) {
            if (!isset($tools[$tool])) {
                $failures[] = "did not call {$tool}";
            }
        }

        foreach ((array) ($expect['forbidden_tools'] ?? []) as $tool) {
            if (isset($tools[$tool])) {
                $failures[] = "called forbidden tool {$tool}";
            }
        }

        foreach ((array) ($expect['min_tool_calls'] ?? []) as $tool => $minimum) {
            if (($tools[$tool] ?? 0) < $minimum) {
                $failures[] = "called {$tool} " . ($tools[$tool] ?? 0) . " times, expected at least {$minimum}";
            }
        }

        $anyCommands = (array) ($expect['required_ui_commands_any'] ?? []);
        if ($anyCommands && !array_intersect($anyCommands, $commands)) {
            $failures[] = 'did not propose any of: ' . implode(', ', $anyCommands);
        }

        foreach ((array) ($expect['forbidden_ui_commands'] ?? []) as $command) {
            if (in_array($command, $commands, true)) {
                $failures[] = "proposed forbidden command {$command}";
            }
        }

        foreach ((array) ($expect['must_contain_all_of_any'] ?? []) as $group) {
            $alternatives = array_map(fn ($phrase) => $this->normalize((string) $phrase), (array) $group);
            if (!collect($alternatives)->contains(fn ($phrase) => str_contains($response, $phrase))) {
                $failures[] = 'answer does not mention any of: ' . implode(' | ', (array) $group);
            }
        }

        foreach ((array) ($expect['must_not_contain'] ?? []) as $phrase) {
            if (str_contains($response, $this->normalize((string) $phrase))) {
                $failures[] = "answer contains \"{$phrase}\"";
            }
        }

        foreach (array_merge([static::ROUTE_NAME_PATTERN], (array) ($expect['must_not_match'] ?? [])) as $pattern) {
            if (preg_match($pattern, (string) ($outcome['response'] ?? ''), $match)) {
                $failures[] = "answer matches {$pattern} (\"{$match[0]}\")";
            }
        }

        return ['passed' => empty($failures), 'failures' => $failures];
    }

    /**
     * Lowercase, drop markdown emphasis, and unify navigation arrows so "IAM → Users" and "**IAM** › Users" compare equal.
     */
    public function normalize(string $text): string
    {
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = str_replace(['→', '›', '->', '»'], '>', $text);

        return Str::lower(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
