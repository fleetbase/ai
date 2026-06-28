<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Fleetbase\Ai\Services\AiProviderManager;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiTaskService
{
    public function __construct(protected AIProviderInterface $provider, protected AiContextResolver $contextResolver, protected AiCapabilityRegistry $registry, protected AiAttachmentResolver $attachmentResolver)
    {
    }

    public function createFromRequest(Request $request): AiTask
    {
        $config   = Setting::system('ai', []);
        $provider = $this->provider instanceof AiProviderManager ? $this->provider->providerNameFor($config) : 'local';
        $model    = $this->provider instanceof AiProviderManager ? $this->provider->modelFor($config) : 'fleetbase-local-preview';
        $session  = $this->resolveSessionForRequest($request);
        $attachments = $this->attachmentResolver->resolveFromRequest($request);

        $task = AiTask::create([
            'ai_session_uuid'  => $session->uuid,
            'company_uuid'    => session('company'),
            'created_by_uuid' => optional($request->user())->uuid,
            'task_type'       => $request->input('task_type', 'prompt'),
            'status'          => 'running',
            'prompt'          => $request->input('prompt'),
            'provider'        => $provider,
            'model'           => $model,
            'context'         => $request->input('context', []),
            'metadata'        => ['attachments' => $attachments],
            'started_at'      => now(),
        ]);

        $capabilityContext = $this->contextResolver->resolve($task);
        $actionPreviews    = $this->resolveActionPreviews($task);
        $sessionContext    = $this->sessionContext($task);
        $attachmentContext = $this->attachmentResolver->contextFor($attachments);
        $providerContext   = array_values(array_filter(array_merge($sessionContext ? [$sessionContext] : [], $attachmentContext ? [$attachmentContext] : [], $capabilityContext)));

        if (!empty($attachments)) {
            $this->recordStep($task, [
                'type'         => 'attachment_context',
                'status'       => 'completed',
                'input'        => ['attachments' => $request->input('attachments', [])],
                'output'       => ['attachments' => $attachments],
                'completed_at' => now(),
            ]);
        }

        if (!empty($actionPreviews)) {
            $task->update([
                'metadata' => array_merge((array) $task->metadata, ['action_previews' => $actionPreviews]),
            ]);

            $providerContext[] = [
                'capability' => 'fleetbase.ai.action_previews',
                'type'       => 'action_preview',
                'data'       => $actionPreviews,
                'instruction' => 'A Fleetbase action preview has already been prepared from the user prompt. Do not ask again for details already present in the preview draft. Do not say the action has been applied until Fleetbase returns an apply result.',
            ];

            $this->recordStep($task, [
                'type'         => 'action_preview',
                'status'       => 'completed',
                'input'        => ['prompt' => $task->prompt, 'context' => $task->context],
                'output'       => ['actions' => $actionPreviews],
                'completed_at' => now(),
            ]);
        }

        if (!empty($capabilityContext)) {
            $this->recordStep($task, [
                'type'         => 'capability_context',
                'status'       => 'completed',
                'input'        => ['prompt' => $task->prompt, 'context' => $task->context],
                'output'       => ['capabilities' => $capabilityContext],
                'completed_at' => now(),
            ]);
        }

        $step = $this->recordStep($task, [
            'type'       => 'provider_call',
            'status'     => 'running',
            'provider'   => $provider,
            'model'      => $model,
            'input'      => ['prompt' => $task->prompt, 'context' => $task->context, 'capability_context' => $providerContext],
            'started_at' => now(),
        ]);

        try {
            $result = $this->provider->complete($task, $providerContext, [
                'config' => $config,
            ]);

            $usage = Arr::get($result, 'usage', []);
            $task->update([
                'status'           => 'answered',
                'provider'         => Arr::get($result, 'provider', 'local'),
                'model'            => Arr::get($result, 'model', 'fleetbase-local-preview'),
                'response'         => Arr::get($result, 'content'),
                'response_summary' => Arr::get($result, 'summary'),
                'usage'            => $usage,
                'input_tokens'     => Arr::get($usage, 'input_tokens'),
                'output_tokens'    => Arr::get($usage, 'output_tokens'),
                'total_tokens'     => Arr::get($usage, 'total_tokens'),
                'metadata'         => array_merge((array) Arr::get($result, 'metadata', []), ['attachments' => $attachments, 'capability_context' => $capabilityContext, 'action_previews' => $actionPreviews]),
                'completed_at'     => now(),
            ]);

            $this->touchSessionForTask($session, $task);

            $step->update([
                'status'       => 'completed',
                'provider'     => $task->provider,
                'model'        => $task->model,
                'output'       => $result,
                'usage'        => $usage,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $error = ['message' => $e->getMessage(), 'type' => get_class($e)];
            $task->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);
            $this->touchSessionForTask($session, $task);
            $step->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);
        }

        return $task->fresh(['steps', 'session']);
    }

    public function apply(AiTask $task, ?string $actionKey = null, array $input = []): AiTask
    {
        $previews = collect((array) data_get($task->metadata, 'action_previews', []));
        $preview  = $actionKey
            ? $previews->firstWhere('key', $actionKey)
            : $previews->first();
        $actionKey ??= is_array($preview) ? ($preview['key'] ?? null) : null;

        $capability = $actionKey ? $this->registry->get($actionKey) : null;
        if (!$capability instanceof AIActionCapabilityInterface) {
            $this->recordStep($task, [
                'type'         => 'apply',
                'status'       => 'cancelled',
                'tool'         => $actionKey,
                'output'       => ['message' => 'No executable AI action is available for this task.'],
                'completed_at' => now(),
            ]);
            $task->update(['status' => 'previewed']);

            return $task->fresh(['steps', 'session']);
        }

        $step = $this->recordStep($task, [
            'type'       => 'apply',
            'status'     => 'running',
            'tool'       => $capability->key(),
            'input'      => ['preview' => $preview, 'input' => $input],
            'started_at' => now(),
        ]);

        try {
            $result   = $capability->apply($task, (array) $preview, $input);
            $metadata = (array) $task->metadata;
            $metadata['action_results'] = array_values(array_merge((array) data_get($metadata, 'action_results', []), [$result]));

            $task->update([
                'status'           => 'applied',
                'response_summary' => data_get($result, 'message', $task->response_summary),
                'metadata'         => $metadata,
            ]);

            $step->update([
                'status'       => 'completed',
                'output'       => $result,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $error    = ['message' => $e->getMessage(), 'type' => get_class($e)];
            $metadata = (array) $task->metadata;
            $metadata['action_errors'] = array_values(array_merge((array) data_get($metadata, 'action_errors', []), [$error]));

            $task->update(['status' => 'apply_failed', 'metadata' => $metadata]);
            $step->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);
        }

        return $task->fresh(['steps', 'session']);
    }

    public function recordStep(AiTask $task, array $attributes): AiTaskStep
    {
        return AiTaskStep::create(array_merge([
            'ai_task_uuid'   => $task->uuid,
            'company_uuid'    => $task->company_uuid,
            'created_by_uuid' => $task->created_by_uuid,
        ], $attributes));
    }

    protected function resolveActionPreviews(AiTask $task): array
    {
        return $this->registry
            ->all()
            ->filter(fn ($capability) => $capability instanceof AIActionCapabilityInterface && $capability->shouldPreview($task))
            ->map(fn (AIActionCapabilityInterface $capability) => $this->normalizeActionPreview($capability, $capability->preview($task)))
            ->values()
            ->all();
    }

    public function refreshPreview(AiTask $task, ?string $actionKey = null, array $input = []): AiTask
    {
        $capability = $actionKey ? $this->registry->get($actionKey) : null;
        if (!$capability instanceof AIActionCapabilityInterface) {
            $this->recordStep($task, [
                'type'         => 'preview_refresh',
                'status'       => 'failed',
                'tool'         => $actionKey,
                'error'        => ['message' => 'No executable AI action is available for preview refresh.'],
                'completed_at' => now(),
            ]);

            return $task->fresh(['steps', 'session']);
        }

        $preview  = $this->normalizeActionPreview($capability, $capability->preview($task, $input));
        $metadata = (array) $task->metadata;
        $previews = collect((array) data_get($metadata, 'action_previews', []));
        $updated  = false;
        $previews = $previews->map(function ($existing) use ($preview, &$updated) {
            if (($existing['key'] ?? $existing['action'] ?? null) === ($preview['key'] ?? $preview['action'] ?? null)) {
                $updated = true;

                return $preview;
            }

            return $existing;
        });

        if (!$updated) {
            $previews->push($preview);
        }

        $metadata['action_previews'] = $previews->values()->all();
        $task->update(['metadata' => $metadata, 'status' => $task->status === 'applied' ? 'answered' : $task->status]);

        $this->recordStep($task, [
            'type'         => 'preview_refresh',
            'status'       => 'completed',
            'tool'         => $capability->key(),
            'input'        => $input,
            'output'       => $preview,
            'completed_at' => now(),
        ]);

        return $task->fresh(['steps', 'session']);
    }

    protected function normalizeActionPreview(AIActionCapabilityInterface $capability, array $preview): array
    {
        return array_merge([
            'key'          => $capability->key(),
            'label'        => $capability->label(),
            'module'       => $capability->module(),
            'type'         => $capability->type(),
            'mode'         => $capability->mode(),
            'permissions'  => $capability->permissions(),
            'preview_only' => $capability->previewOnly(),
            'executable'   => $capability->executable(),
        ], $preview);
    }

    protected function resolveSessionForRequest(Request $request): AiSession
    {
        $userUuid    = optional($request->user())->uuid;
        $sessionUuid = $request->input('session_uuid');

        if ($sessionUuid) {
            $session = AiSession::where('company_uuid', session('company'))
                ->where('created_by_uuid', $userUuid)
                ->where(function ($query) use ($sessionUuid) {
                    $query->where('uuid', $sessionUuid)->orWhere('id', $sessionUuid);
                })
                ->first();

            if ($session) {
                if ($session->status === 'ended') {
                    return $this->createSessionForPrompt($request);
                }

                return $session;
            }
        }

        $session = AiSession::where('company_uuid', session('company'))
            ->where('created_by_uuid', $userUuid)
            ->where('status', 'active')
            ->latest('last_message_at')
            ->latest()
            ->first();

        if ($session) {
            return $session;
        }

        return $this->createSessionForPrompt($request);
    }

    protected function createSessionForPrompt(Request $request): AiSession
    {
        return AiSession::create([
            'company_uuid'    => session('company'),
            'created_by_uuid' => optional($request->user())->uuid,
            'title'           => $this->titleFromPrompt((string) $request->input('prompt')),
            'status'          => 'active',
            'last_message_at' => now(),
        ]);
    }

    protected function touchSessionForTask(AiSession $session, AiTask $task): void
    {
        $updates = [
            'last_message_at' => now(),
        ];

        if (!$session->title || $session->title === 'New AI chat') {
            $updates['title'] = $this->titleFromPrompt((string) $task->prompt);
        }

        if ($session->status === 'ended') {
            $updates['status']   = 'active';
            $updates['ended_at'] = null;
        }

        $session->update($updates);
    }

    protected function titleFromPrompt(string $prompt): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $prompt));

        return $title ? Str::limit($title, 64, '') : 'New AI chat';
    }

    protected function sessionContext(AiTask $task): ?array
    {
        if (!$task->ai_session_uuid) {
            return null;
        }

        $turns = AiTask::where('company_uuid', $task->company_uuid)
            ->where('ai_session_uuid', $task->ai_session_uuid)
            ->where('uuid', '!=', $task->uuid)
            ->whereNotNull('prompt')
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->map(function (AiTask $turn) {
                return [
                    'prompt'   => $turn->prompt,
                    'response' => $turn->response_summary ?: Str::limit(trim((string) $turn->response), 600, ''),
                    'status'   => $turn->status,
                ];
            })
            ->values()
            ->all();

        if (empty($turns)) {
            return null;
        }

        return [
            'capability'  => 'fleetbase.ai.session_context',
            'type'        => 'session_context',
            'instruction' => 'Use this recent Fleetbase AI chat history as conversation context. Do not repeat questions already answered by the user in earlier turns.',
            'data'        => [
                'session_uuid' => $task->ai_session_uuid,
                'turns'        => $turns,
            ],
        ];
    }

}
