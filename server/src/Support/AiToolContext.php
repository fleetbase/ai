<?php

namespace Fleetbase\Ai\Support;

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;

/**
 * Per-turn context shared with tools: who the user is, and what the tools produced for the UI.
 */
class AiToolContext
{
    /**
     * Action previews prepared by tools during the turn.
     */
    public array $actionPreviews = [];

    /**
     * Console actions proposed by tools during the turn. They only run after the user confirms them.
     */
    public array $uiActions = [];

    public function __construct(public readonly AiTask $task, public readonly AiAudience $audience)
    {
    }

    /**
     * Store a preview prepared by an action capability. Nothing is applied until the user confirms it.
     *
     * @return array the stored preview, including its preview_id
     */
    public function addActionPreview(AIActionCapabilityInterface $capability, array $preview): array
    {
        $normalized             = AiActionPreview::normalize($capability, $preview);
        $this->actionPreviews[] = $normalized;

        return $normalized;
    }

    public function addUiAction(array $action): void
    {
        $this->uiActions[] = $action;
    }
}
