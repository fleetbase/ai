<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Commands\AiCommandRegistry;
use Illuminate\Support\Str;

class ProposeConsoleCommandTool extends AbstractAIToolCapability
{
    public const MAX_PROPOSALS_PER_TURN = 3;

    public function __construct(protected AiCommandRegistry $commands)
    {
    }

    public function key(): string
    {
        return 'core.propose_console_command';
    }

    public function label(): string
    {
        return 'Propose console action';
    }

    public function description(): string
    {
        return 'Shows the user a confirmation button for a console action, such as opening a page or a create dialog.';
    }

    public function module(): string
    {
        return 'core';
    }

    public function type(): string
    {
        return 'ui';
    }

    public function toolName(): string
    {
        return 'propose_console_command';
    }

    public function toolDescription(): string
    {
        return 'Offer a console action from find_console_commands. The user sees a confirmation card and nothing happens until they confirm it. '
            . 'Never say you navigated or opened anything; say you can take them there once they confirm.';
    }

    public function toolParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'command_id' => ['type' => 'string', 'description' => 'An id returned by find_console_commands.'],
                'params'     => ['type' => 'object', 'description' => 'Parameters the command requires, if any.'],
            ],
            'required'             => ['command_id'],
            'additionalProperties' => false,
        ];
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $this->commands->availableTo($context->audience)->isNotEmpty();
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $id      = (string) ($arguments['command_id'] ?? '');
        $params  = is_array($arguments['params'] ?? null) ? $arguments['params'] : [];
        $command = $this->commands->get($id);

        // Unknown and unauthorized commands look the same, so ids cannot be probed.
        if (!$command || !$command->availableTo($context->audience)) {
            return ['error' => "Console action {$id} is not available. Use an id from find_console_commands."];
        }

        if ($error = $command->validateParams($params)) {
            return ['error' => $error];
        }

        if (count($context->uiActions) >= static::MAX_PROPOSALS_PER_TURN) {
            return ['error' => 'Too many actions proposed in one reply. Offer only the most relevant ones.'];
        }

        foreach ($context->uiActions as $existing) {
            if ($existing['command_id'] === $command->id && $existing['params'] === $params) {
                return ['status' => 'awaiting_user_confirmation', 'action_id' => $existing['id'], 'message' => 'This action is already offered to the user.'];
            }
        }

        $action = [
            'id'         => (string) Str::uuid(),
            'command_id' => $command->id,
            'label'      => $command->label,
            'breadcrumb' => $command->breadcrumb,
            'params'     => $params,
            'status'     => 'pending',
        ];

        $context->addUiAction($action);

        return [
            'status'    => 'awaiting_user_confirmation',
            'action_id' => $action['id'],
            'message'   => "A confirmation card for \"{$command->label}\" ({$command->breadcrumb}) is shown to the user. It runs only if they confirm it.",
        ];
    }
}
