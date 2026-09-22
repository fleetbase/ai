<?php

namespace Fleetbase\Ai\Support\Capabilities;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Commands\AiCommandRegistry;
use Fleetbase\Ai\Support\Commands\AiConsoleCommand;

class FindConsoleCommandsTool extends AbstractAIToolCapability
{
    public function __construct(protected AiCommandRegistry $commands)
    {
    }

    public function key(): string
    {
        return 'core.find_console_commands';
    }

    public function label(): string
    {
        return 'Find console actions';
    }

    public function description(): string
    {
        return 'Finds console pages and dialogs Fleetbase AI can offer to open for the user.';
    }

    public function module(): string
    {
        return 'core';
    }

    public function toolName(): string
    {
        return 'find_console_commands';
    }

    public function toolDescription(): string
    {
        return 'Find console actions available to this user: pages to go to and dialogs to open (for example "create user" or "open map settings"). '
            . 'Use it when the user wants to go somewhere or start creating something, or when offering to take them to the screen you described. Write the query in English.';
    }

    public function toolParameters(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['query' => ['type' => 'string', 'description' => 'What the user wants to open or do, in English.']],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function availableFor(AiToolContext $context): bool
    {
        return $this->commands->availableTo($context->audience)->isNotEmpty();
    }

    public function invoke(AiTask $task, array $arguments, AiToolContext $context): array
    {
        $commands = $this->commands->search((string) ($arguments['query'] ?? ''), $context->audience);

        return [
            'commands' => $commands->map(fn (AiConsoleCommand $command) => $command->summary())->all(),
            'message'  => $commands->isEmpty() ? 'No matching console action is available to this user.' : 'Offer an action with propose_console_command. It only runs after the user confirms it.',
        ];
    }
}
