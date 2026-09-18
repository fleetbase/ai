<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIConversationalProviderInterface;
use Fleetbase\Ai\Contracts\AIToolCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiProviderTurn;
use Fleetbase\Ai\Support\AiToolContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Runs one Fleetbase AI turn as a tool-calling loop: the model decides which tools to call, every call is
 * permission-checked, executed, and recorded, and the loop ends when the model answers.
 */
class AiAgentRunner
{
    public const DEFAULT_MAX_ITERATIONS = 8;

    /**
     * Longest serialized tool result sent back to the model.
     */
    public const MAX_TOOL_RESULT_CHARACTERS = 20000;

    public function __construct(protected AIConversationalProviderInterface $provider, protected AiCapabilityRegistry $registry)
    {
    }

    /**
     * @param array    $history    previous conversation turns in provider-neutral message shape
     * @param callable $recordStep receives step attributes for the audit trail
     *
     * @return array a completion result: provider, model, content, summary, usage, metadata
     */
    public function run(AiTask $task, AiToolContext $context, string $system, array $history, string $userMessage, array $config, callable $recordStep): array
    {
        $tools         = $this->toolsFor($context);
        $definitions   = array_values(array_map(fn (AIToolCapabilityInterface $tool) => [
            'name'        => $tool->toolName(),
            'description' => $tool->toolDescription(),
            'parameters'  => $tool->toolParameters(),
        ], $tools));
        $messages      = array_merge($history, [['role' => 'user', 'content' => $userMessage]]);
        $maxIterations = max(1, (int) Arr::get($config, 'max_tool_iterations', static::DEFAULT_MAX_ITERATIONS));
        $usage         = [];
        $toolCallCount = 0;
        $turn          = null;

        for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
            // On the last iteration the model must answer with what it has gathered.
            $toolChoice = $iteration === $maxIterations ? 'none' : 'auto';
            $turn       = $this->provider->converse($system, $messages, $definitions, ['config' => $config, 'tool_choice' => $toolChoice]);
            $usage      = $this->addUsage($usage, $turn->usage);

            if (!$turn->hasToolCalls()) {
                break;
            }

            $messages[] = $turn->toAssistantMessage();

            foreach ($turn->toolCalls as $call) {
                $toolCallCount++;
                $messages[] = $this->runTool($task, $context, $tools, $call, $iteration, $recordStep);
            }
        }

        $content = $turn->text;
        if ($turn->stopReason === AiProviderTurn::STOP_REFUSAL && trim($content) === '') {
            $content = 'I can’t help with that request.';
        }

        return [
            'provider' => $turn->provider,
            'model'    => $turn->model,
            'content'  => $content,
            'summary'  => Str::limit(preg_replace('/\s+/', ' ', trim($content ?: (string) $task->prompt)), 140, ''),
            'usage'    => $usage,
            'metadata' => array_merge($turn->metadata, [
                'mode'        => 'tool_calling',
                'iterations'  => $iteration > $maxIterations ? $maxIterations : $iteration,
                'tool_calls'  => $toolCallCount,
                'stop_reason' => $turn->stopReason,
                'truncated'   => $turn->stopReason === AiProviderTurn::STOP_TRUNCATED,
                'refused'     => $turn->stopReason === AiProviderTurn::STOP_REFUSAL,
            ]),
        ];
    }

    /**
     * Tools offered to this user, keyed by tool name.
     *
     * @return array<string, AIToolCapabilityInterface>
     */
    public function toolsFor(AiToolContext $context): array
    {
        return $this->registry->all()
            ->filter(fn ($capability) => $capability instanceof AIToolCapabilityInterface && $capability->availableFor($context))
            ->mapWithKeys(fn (AIToolCapabilityInterface $tool) => [$tool->toolName() => $tool])
            ->all();
    }

    protected function runTool(AiTask $task, AiToolContext $context, array $tools, array $call, int $iteration, callable $recordStep): array
    {
        $name      = (string) ($call['name'] ?? '');
        $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
        $tool      = $tools[$name] ?? null;
        $startedAt = now();
        $failure   = null;

        if (!$tool) {
            $output = ['error' => "Unknown tool {$name}. Use only the tools provided."];
        } elseif ($missing = $this->missingArguments($tool->toolParameters(), $arguments)) {
            $output = ['error' => 'Missing required arguments: ' . implode(', ', $missing) . '.'];
        } else {
            try {
                $output = $tool->invoke($task, $arguments, $context);
            } catch (\Throwable $e) {
                // The model learns the tool failed; exception details stay in the audit trail.
                $output  = ['error' => 'tool_failed', 'message' => 'This tool could not complete. Tell the user the information is unavailable right now.'];
                $failure = ['message' => $e->getMessage(), 'type' => get_class($e)];
                $this->report($e);
            }
        }

        $isError    = isset($output['error']);
        $serialized = $this->serialize($output);

        $recordStep([
            'type'         => 'tool_call',
            'status'       => $failure ? 'failed' : 'completed',
            'tool'         => $tool ? $tool->key() : $name,
            'input'        => ['name' => $name, 'arguments' => $arguments, 'iteration' => $iteration],
            'output'       => $output,
            'error'        => $failure,
            'metadata'     => ['tool_call_id' => $call['id'] ?? null, 'truncated' => mb_strlen($serialized['full']) > static::MAX_TOOL_RESULT_CHARACTERS],
            'started_at'   => $startedAt,
            'completed_at' => now(),
        ]);

        return [
            'role'         => 'tool',
            'tool_call_id' => (string) ($call['id'] ?? ''),
            'name'         => $name,
            'content'      => $serialized['sent'],
            'is_error'     => $isError,
        ];
    }

    /**
     * @return array{full: string, sent: string}
     */
    protected function serialize(array $output): array
    {
        $full = (string) json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (mb_strlen($full) <= static::MAX_TOOL_RESULT_CHARACTERS) {
            return ['full' => $full, 'sent' => $full];
        }

        return [
            'full' => $full,
            'sent' => mb_substr($full, 0, static::MAX_TOOL_RESULT_CHARACTERS) . "\n[truncated: the result was too long; narrow the request for complete data]",
        ];
    }

    protected function missingArguments(array $schema, array $arguments): array
    {
        return array_values(array_filter(
            (array) ($schema['required'] ?? []),
            fn ($key) => !array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === ''
        ));
    }

    protected function addUsage(array $total, array $usage): array
    {
        foreach ($usage as $key => $value) {
            if (is_numeric($value)) {
                $total[$key] = ($total[$key] ?? 0) + $value;
            }
        }

        return $total;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function report(\Throwable $e): void
    {
        if (function_exists('report')) {
            try {
                report($e);
            } catch (\Throwable) {
            }
        }
    }
}
