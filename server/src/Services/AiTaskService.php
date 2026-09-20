<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Ai\Contracts\AIActionCapabilityInterface;
use Fleetbase\Ai\Contracts\AIConversationalProviderInterface;
use Fleetbase\Ai\Contracts\AIProviderInterface;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Models\AiTaskStep;
use Fleetbase\Ai\Support\AiActionPreview;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Ai\Support\AiSystemPrompt;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiTaskService
{
    /**
     * Maximum characters of each previous answer included in session history.
     */
    public const HISTORY_RESPONSE_LIMIT = 3000;

    public function __construct(protected AIProviderInterface $provider, protected AiContextResolver $contextResolver, protected AiCapabilityRegistry $registry, protected AiAttachmentResolver $attachmentResolver, protected AiTemporalContext $temporalContext)
    {
    }

    public function createFromRequest(Request $request): AiTask
    {
        $config      = $this->systemAiConfig();
        $provider    = $this->provider instanceof AiProviderManager ? $this->provider->providerNameFor($config) : 'local';
        $model       = $this->provider instanceof AiProviderManager ? $this->provider->modelFor($config) : 'fleetbase-local-preview';
        $session     = $this->resolveSessionForRequest($request);
        $attachments = $this->attachmentResolver->resolveFromRequest($request);

        $task = $this->createTask([
            'ai_session_uuid'  => $session->uuid,
            'company_uuid'     => session('company'),
            'created_by_uuid'  => optional($request->user())->uuid,
            'task_type'        => $request->input('task_type', 'prompt'),
            'status'           => 'running',
            'prompt'           => $request->input('prompt'),
            'provider'         => $provider,
            'model'            => $model,
            'context'          => $request->input('context', []),
            'metadata'         => ['attachments' => $attachments],
            'started_at'       => now(),
        ]);

        if ($this->usesTools($config)) {
            return $this->answerWithTools($request, $task, $session, $config, $attachments);
        }

        $audience          = $this->audienceFor($request);
        $temporalContext   = $this->temporalContext->context();
        $capabilityContext = $this->contextResolver->resolve($task);
        $degraded          = AiContextResolver::hasFailures($capabilityContext);
        $actionPreviews    = $this->resolveActionPreviews($task);
        $sessionContext    = $this->sessionContext($task);
        $attachmentContext = $this->attachmentResolver->contextFor($attachments);
        $providerContext   = array_values(array_filter(array_merge([$temporalContext], $sessionContext ? [$sessionContext] : [], $attachmentContext ? [$attachmentContext] : [], AiContextResolver::forProvider($capabilityContext))));
        $systemPrompt      = AiSystemPrompt::build($audience, [
            'page' => AiSystemPrompt::pageName(data_get($task->context, 'route')),
        ]);

        $task->update([
            'metadata' => array_merge((array) $task->metadata, ['temporal_context' => $temporalContext, 'audience' => $audience->toArray(), 'degraded' => $degraded]),
        ]);

        $this->recordStep($task, [
            'type'         => 'temporal_context',
            'status'       => 'completed',
            'output'       => $temporalContext,
            'completed_at' => now(),
        ]);

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
                'capability'  => 'fleetbase.ai.action_previews',
                'type'        => 'action_preview',
                'data'        => $actionPreviews,
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
            'input'      => ['prompt' => $task->prompt, 'context' => $task->context, 'system_prompt' => $systemPrompt, 'capability_context' => $providerContext],
            'started_at' => now(),
        ]);

        try {
            $result = $this->provider->complete($task, $providerContext, [
                'config'        => $config,
                'system_prompt' => $systemPrompt,
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
                'metadata'         => array_merge((array) Arr::get($result, 'metadata', []), ['attachments' => $attachments, 'temporal_context' => $temporalContext, 'capability_context' => $capabilityContext, 'action_previews' => $actionPreviews, 'audience' => $audience->toArray(), 'degraded' => $degraded]),
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

    /**
     * Whether this turn runs as a tool-calling conversation with a live provider.
     */
    public function usesTools(array $config): bool
    {
        return $this->provider instanceof AIConversationalProviderInterface && $this->provider->supportsTools($config);
    }

    /**
     * Answer a prompt with the tool-calling runtime: the model gathers Fleetbase documentation and data
     * through permission-checked tools instead of keyword-selected context.
     */
    protected function answerWithTools(Request $request, AiTask $task, AiSession $session, array $config, array $attachments): AiTask
    {
        $audience          = $this->audienceFor($request);
        $toolContext       = new AiToolContext($task, $audience);
        $runner            = $this->agentRunner();
        $tools             = $runner->toolsFor($toolContext);
        $temporalContext   = $this->temporalContext->context();
        $attachmentContext = $this->attachmentResolver->contextFor($attachments);
        $turnContext       = array_values(array_filter([$temporalContext, $attachmentContext]));
        $history           = $this->conversationHistory($task);
        $userMessage       = AiSystemPrompt::userMessage($task, $turnContext);
        $systemPrompt      = AiSystemPrompt::build($audience, [
            'page'     => AiSystemPrompt::pageName(data_get($task->context, 'route')),
            'has_docs' => isset($tools['search_docs']),
            'tools'    => !empty($tools),
            'commands' => isset($tools['propose_console_command']),
        ]);

        $task->update([
            'metadata' => array_merge((array) $task->metadata, ['temporal_context' => $temporalContext, 'audience' => $audience->toArray()]),
        ]);

        $this->recordStep($task, [
            'type'         => 'temporal_context',
            'status'       => 'completed',
            'output'       => $temporalContext,
            'completed_at' => now(),
        ]);

        if (!empty($attachments)) {
            $this->recordStep($task, [
                'type'         => 'attachment_context',
                'status'       => 'completed',
                'input'        => ['attachments' => $request->input('attachments', [])],
                'output'       => ['attachments' => $attachments],
                'completed_at' => now(),
            ]);
        }

        $step = $this->recordStep($task, [
            'type'       => 'provider_call',
            'status'     => 'running',
            'provider'   => $task->provider,
            'model'      => $task->model,
            'input'      => [
                'prompt'        => $task->prompt,
                'context'       => $task->context,
                'system_prompt' => $systemPrompt,
                'history'       => $history,
                'user_message'  => $userMessage,
                'tools'         => array_keys($tools),
            ],
            'started_at' => now(),
        ]);

        try {
            $result = $runner->run($task, $toolContext, $systemPrompt, $history, $userMessage, $config, fn (array $attributes) => $this->recordStep($task, $attributes));
            $usage  = Arr::get($result, 'usage', []);

            $task->update([
                'status'           => 'answered',
                'provider'         => Arr::get($result, 'provider', $task->provider),
                'model'            => Arr::get($result, 'model', $task->model),
                'response'         => Arr::get($result, 'content'),
                'response_summary' => Arr::get($result, 'summary'),
                'usage'            => $usage,
                'input_tokens'     => Arr::get($usage, 'input_tokens'),
                'output_tokens'    => Arr::get($usage, 'output_tokens'),
                'total_tokens'     => Arr::get($usage, 'total_tokens'),
                'metadata'         => array_merge((array) Arr::get($result, 'metadata', []), [
                    'attachments'      => $attachments,
                    'temporal_context' => $temporalContext,
                    'audience'         => $audience->toArray(),
                    'action_previews'  => $toolContext->actionPreviews,
                    'ui_actions'       => $toolContext->uiActions,
                ]),
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

    /**
     * Previous turns of the session as real conversation messages, oldest first.
     */
    protected function conversationHistory(AiTask $task): array
    {
        if (!$task->ai_session_uuid) {
            return [];
        }

        return $this->sessionHistoryForTask($task)
            ->whereNotNull('prompt')
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->flatMap(function (AiTask $turn) {
                $response = trim((string) ($turn->response ?: $turn->response_summary));

                return array_values(array_filter([
                    ['role' => 'user', 'content' => (string) $turn->prompt],
                    $response !== '' ? ['role' => 'assistant', 'content' => Str::limit($response, static::HISTORY_RESPONSE_LIMIT, '…')] : null,
                ]));
            })
            ->values()
            ->all();
    }

    protected function agentRunner(): AiAgentRunner
    {
        return new AiAgentRunner($this->provider, $this->registry);
    }

    public function apply(AiTask $task, ?string $actionKey = null, array $input = []): AiTask
    {
        $previewId = $input['preview_id'] ?? null;
        unset($input['preview_id']);

        $preview = AiActionPreview::find((array) data_get($task->metadata, 'action_previews', []), $previewId, $actionKey);
        $actionKey ??= is_array($preview) ? ($preview['key'] ?? null) : null;
        if ($previewId && is_array($preview)) {
            $actionKey = $preview['key'] ?? $actionKey;
        }

        $capability = $actionKey ? $this->registry->get($actionKey) : null;
        if (!$capability instanceof AIActionCapabilityInterface || ($previewId && !$preview)) {
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
            $result                     = array_merge($capability->apply($task, (array) $preview, $input), array_filter(['preview_id' => $preview['preview_id'] ?? null]));
            $metadata                   = (array) $task->metadata;
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
            $error                     = array_filter(['message' => $e->getMessage(), 'type' => get_class($e), 'action' => $capability->key(), 'preview_id' => $preview['preview_id'] ?? null]);
            $metadata                  = (array) $task->metadata;
            $metadata['action_errors'] = array_values(array_merge((array) data_get($metadata, 'action_errors', []), [$error]));

            $task->update(['status' => 'apply_failed', 'metadata' => $metadata]);
            $step->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);
        }

        return $task->fresh(['steps', 'session']);
    }

    /**
     * @codeCoverageIgnore
     */
    public function recordStep(AiTask $task, array $attributes): AiTaskStep
    {
        return AiTaskStep::create(array_merge([
            'ai_task_uuid'    => $task->uuid,
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
        $previewId = $input['preview_id'] ?? null;
        unset($input['preview_id']);

        $metadata = (array) $task->metadata;
        $existing = AiActionPreview::find((array) data_get($metadata, 'action_previews', []), $previewId, $actionKey);
        if ($previewId && $existing) {
            $actionKey = $existing['key'] ?? $actionKey;
        }

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

        // The capability refreshes the preview being edited, not whichever preview happens to be first.
        $capabilityInput = $existing && isset($existing['draft']) ? array_merge($input, ['existing_draft' => $existing['draft']]) : $input;

        $preview  = AiActionPreview::normalize($capability, $capability->preview($task, $capabilityInput), $existing['preview_id'] ?? null);
        $previews = collect((array) data_get($metadata, 'action_previews', []));
        $updated  = false;
        $previews = $previews->map(function ($current) use ($preview, &$updated) {
            if (!$updated && is_array($current) && AiActionPreview::same($current, $preview)) {
                $updated = true;

                return $preview;
            }

            return $current;
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
        return AiActionPreview::normalize($capability, $preview);
    }

    protected function resolveSessionForRequest(Request $request): AiSession
    {
        $userUuid    = optional($request->user())->uuid;
        $sessionUuid = $request->input('session_uuid');

        if ($sessionUuid) {
            $session = $this->sessionsForCurrentCompany()
                ->where('created_by_uuid', $userUuid)
                ->where(function ($query) use ($sessionUuid) {
                    // A UUID beginning with digits is cast to an integer by MySQL, so it would
                    // otherwise match an unrelated row by its numeric key.
                    $query->where('uuid', $sessionUuid)->when(ctype_digit($sessionUuid), fn ($query) => $query->orWhere('id', (int) $sessionUuid));
                })
                ->first();

            if ($session) {
                if ($session->status === 'ended') {
                    return $this->createSessionForPrompt($request);
                }

                return $session;
            }
        }

        $session = $this->sessionsForCurrentCompany()
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
        return $this->createSession([
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

        $turns = $this->sessionHistoryForTask($task)
            ->whereNotNull('prompt')
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->map(function (AiTask $turn) {
                // Send the answer itself, not the 140 character display summary, so follow-ups such as
                // "yes" or "2" can be resolved against what was actually offered.
                return [
                    'prompt'   => $turn->prompt,
                    'response' => Str::limit(trim((string) ($turn->response ?: $turn->response_summary)), static::HISTORY_RESPONSE_LIMIT, '…'),
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
            'instruction' => 'Recent Fleetbase AI chat history, oldest first. Use it to understand follow-ups: when the user replies to an earlier question or offer (for example "yes" or a number from a list), act on that offer. Do not repeat questions the user already answered.',
            'data'        => [
                'session_uuid' => $task->ai_session_uuid,
                'turns'        => $turns,
            ],
        ];
    }

    protected function audienceFor(Request $request): AiAudience
    {
        return AiAudience::forUser($request->user());
    }

    /**
     * @codeCoverageIgnore
     */
    protected function systemAiConfig(): array
    {
        return Setting::system('ai', []);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createTask(array $attributes): AiTask
    {
        return AiTask::create($attributes);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createSession(array $attributes): AiSession
    {
        return AiSession::create($attributes);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function sessionsForCurrentCompany(): Builder
    {
        return AiSession::where('company_uuid', session('company'));
    }

    /**
     * @codeCoverageIgnore
     */
    protected function sessionHistoryForTask(AiTask $task): Builder
    {
        return AiTask::where('company_uuid', $task->company_uuid)
            ->where('ai_session_uuid', $task->ai_session_uuid)
            ->where('uuid', '!=', $task->uuid);
    }
}
