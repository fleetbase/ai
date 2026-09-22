<?php

namespace Fleetbase\Ai\Http\Controllers\Internal;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Fleetbase\Ai\Jobs\SyncAiKnowledge;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiKnowledgeChunk;
use Fleetbase\Ai\Models\AiKnowledgeDocument;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiLogExporter;
use Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiAdminController extends Controller
{
    public function companies(AdminRequest $request)
    {
        abort_unless($this->canUseAdminFilters($request), 403, 'You are not authorized to use AI admin filters.');

        $query  = $this->companiesQuery()->select(['uuid', 'public_id', 'name', 'status', 'created_at'])->orderBy('name');
        $search = $request->searchQuery() ?: $request->input('query');

        if ($search) {
            $query->where(function (Builder $query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhere('uuid', $search);
            });
        }

        return response()->json($query->limit(min(max((int) $request->input('limit', 25), 1), 50))->get()->map(fn (Company $company) => [
            'id'        => $company->uuid,
            'uuid'      => $company->uuid,
            'public_id' => $company->public_id,
            'name'      => $company->name,
            'status'    => $company->status,
        ]));
    }

    public function users(AdminRequest $request)
    {
        abort_unless($this->canUseAdminFilters($request), 403, 'You are not authorized to use AI admin filters.');

        $search = $request->searchQuery() ?: $request->input('query');
        $limit  = min(max((int) $request->input('limit', 25), 1), 50);

        if ($request->filled('company_uuid')) {
            $usersQuery = $this->companyUsersForCompany($request->input('company_uuid'))
                ->whereHas('user')
                ->with(['user' => fn ($query) => $query->select(['uuid', 'public_id', 'company_uuid', 'name', 'email', 'status'])]);

            if ($search) {
                $usersQuery->whereHas('user', function (Builder $query) use ($search) {
                    $this->applyUserSearch($query, $search);
                });
            }

            return response()->json(
                $usersQuery->limit($limit)->get()->pluck('user')->filter()->values()->map(fn (User $user) => $this->serializeUserOption($user))
            );
        }

        $query = $this->usersQuery()->select(['uuid', 'public_id', 'company_uuid', 'name', 'email', 'status'])->orderBy('name');

        if ($search) {
            $this->applyUserSearch($query, $search);
        }

        return response()->json($query->limit($limit)->get()->map(fn (User $user) => $this->serializeUserOption($user)));
    }

    public function sessions(AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view audit logs'), 403, 'You are not authorized to view AI audit logs.');

        $query = $this->sessionsQuery()
            ->with(['company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email'])
            ->withCount([
                'tasks',
                // Signals the list shows so reviewers can spot conversations that went wrong.
                'tasks as negative_feedback_count' => fn ($query) => $query->where('feedback_rating', '<', 0),
                'tasks as failed_count'            => fn ($query) => $query->whereIn('status', ['failed', 'apply_failed']),
                'tasks as flagged_count'           => fn ($query) => $query->where(fn ($query) => $query->where('metadata->degraded', true)->orWhere('metadata->truncated', true)),
            ])
            ->withSum('tasks as total_tokens_sum', 'total_tokens')
            ->latest('last_message_at')
            ->latest();

        $this->applySessionFilters($query, $request);

        $limit    = min(max((int) $request->input('limit', 30), 1), 100);
        $page     = max((int) $request->input('page', 1), 1);
        $sessions = $query->offset(($page - 1) * $limit)->limit($limit + 1)->get();

        return response()->json([
            'sessions' => $sessions->take($limit)->map(fn (AiSession $session) => $this->serializeSession($session))->values(),
            'meta'     => [
                'page'     => $page,
                'limit'    => $limit,
                'has_more' => $sessions->count() > $limit,
            ],
        ]);
    }

    public function session(string $id, AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view audit logs'), 403, 'You are not authorized to view AI audit logs.');

        $session = $this->findSession($id)
            ->load([
                'company:uuid,public_id,name',
                'createdBy:uuid,public_id,name,email',
                // Steps carry the full prompts sent to the model, so they load per turn on demand.
                'tasks' => fn ($query) => $query->withCount('steps')->with(['company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email'])->oldest(),
            ])
            ->loadCount('tasks')
            ->loadSum('tasks as total_tokens_sum', 'total_tokens');

        return response()->json([
            'session' => array_merge($this->serializeSession($session), [
                'tasks' => $session->tasks->map(fn (AiTask $task) => $this->serializeTask($task)),
            ]),
        ]);
    }

    public function task(string $id, AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view audit logs'), 403, 'You are not authorized to view AI audit logs.');

        $task = $this->findTask($id)->load(['steps', 'session', 'company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email']);

        return response()->json(['task' => $this->serializeTask($task)]);
    }

    public function usage(AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view usage analytics'), 403, 'You are not authorized to view AI usage analytics.');

        $base = $this->tasksQuery();
        $this->applyTaskFilters($base, $request);

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as task_count')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status IN ('answered', 'applied') THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw('COUNT(DISTINCT ai_session_uuid) as session_count')
            ->selectRaw('SUM(CASE WHEN feedback_rating < 0 THEN 1 ELSE 0 END) as negative_feedback_count')
            ->selectRaw('SUM(CASE WHEN feedback_rating > 0 THEN 1 ELSE 0 END) as positive_feedback_count')
            ->first();

        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->toDateString() : null;
        $to   = $request->filled('to') ? Carbon::parse($request->input('to'))->toDateString() : null;

        return response()->json([
            'summary'  => [
                'task_count'      => (int) ($summary->task_count ?? 0),
                'input_tokens'    => (int) ($summary->input_tokens ?? 0),
                'output_tokens'   => (int) ($summary->output_tokens ?? 0),
                'total_tokens'    => (int) ($summary->total_tokens ?? 0),
                'failed_count'    => (int) ($summary->failed_count ?? 0),
                'completed_count' => (int) ($summary->completed_count ?? 0),
                // JSON flags are counted separately so the query stays portable across databases.
                'session_count'           => (int) ($summary->session_count ?? 0),
                'negative_feedback_count' => (int) ($summary->negative_feedback_count ?? 0),
                'positive_feedback_count' => (int) ($summary->positive_feedback_count ?? 0),
                'degraded_count'          => (clone $base)->where('metadata->degraded', true)->count(),
                'truncated_count'         => (clone $base)->where('metadata->truncated', true)->count(),
            ],
            'from'       => $from,
            'to'         => $to,
            'by_company' => $this->usageGroup(clone $base, 'company_uuid', 'company'),
            'by_user'    => $this->usageGroup(clone $base, 'created_by_uuid', 'user'),
            'by_provider'=> $this->usageGroup(clone $base, 'provider'),
            'by_model'   => $this->usageGroup(clone $base, 'model'),
            'by_status'  => $this->usageGroup(clone $base, 'status'),
            'by_day'     => $this->zeroFillDays($this->usageByDay(clone $base), $from, $to),
        ]);
    }

    /**
     * Download matching tasks with their steps as JSON Lines or CSV. Exports contain the same full
     * conversations the log view shows, so they need the same permission, and every export is recorded
     * in the access log.
     */
    public function export(AdminRequest $request, AiLogExporter $exporter)
    {
        abort_unless($this->can($request, 'ai view audit logs'), 403, 'You are not authorized to export AI logs.');

        $format = in_array($request->input('format'), AiLogExporter::FORMATS, true) ? $request->input('format') : 'jsonl';
        $query  = $this->tasksQuery()->latest('id');
        $this->applyExportFilters($query, $request);

        $this->createAccessLog([
            'viewed_by_uuid' => optional($request->user())->uuid,
            'action'         => 'export_tasks',
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 1000),
            'metadata'       => ['format' => $format, 'filters' => collect(['ai_session_uuid', 'search', 'company_uuid', 'created_by_uuid', 'status', 'task_status', 'session_status', 'provider', 'model', 'from', 'to', 'feedback', 'degraded', 'truncated'])->mapWithKeys(fn ($key) => [$key => $request->input($key)])->filter()->all()],
        ]);

        return $this->download(function () use ($exporter, $query, $format) {
            $handle = fopen('php://output', 'w');
            $exporter->write($query, $handle, $format);
            fclose($handle);
        }, 'fleetbase-ai-logs-' . now()->format('Y-m-d-His') . '.' . $format, $format === 'csv' ? 'text/csv' : 'application/x-ndjson');
    }

    /**
     * @codeCoverageIgnore
     */
    protected function download(callable $callback, string $filename, string $contentType)
    {
        return response()->streamDownload($callback, $filename, ['Content-Type' => $contentType]);
    }

    /**
     * Documentation index status. Only system administrators manage the knowledge base.
     */
    public function knowledge(AdminRequest $request, KnowledgeBootstrapper $bootstrapper)
    {
        abort_unless($this->isSystemAdmin($request), 403, 'Only system administrators can manage the AI knowledge base.');

        return response()->json(['knowledge' => array_merge($this->knowledgeStats(), [
            'snapshot_available' => is_file($bootstrapper->snapshotPath()),
            'docs_enabled'       => (bool) config('ai.knowledge.docs.enabled', true),
        ])]);
    }

    /**
     * Queue a refresh of the documentation index from the docs site, or from the packaged snapshot.
     */
    public function syncKnowledge(AdminRequest $request)
    {
        abort_unless($this->isSystemAdmin($request), 403, 'Only system administrators can manage the AI knowledge base.');

        $source = $request->input('source') === 'snapshot' ? 'snapshot' : 'site';

        $this->createAccessLog([
            'viewed_by_uuid' => optional($request->user())->uuid,
            'action'         => 'knowledge_sync',
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 1000),
            'metadata'       => ['source' => $source],
        ]);

        $this->dispatchKnowledgeSync($source);

        return response()->json(['status' => 'queued', 'source' => $source]);
    }

    protected function isSystemAdmin(AdminRequest $request): bool
    {
        return data_get($request->user(), 'type') === 'admin' || optional($request->user())->isAdmin() === true;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function knowledgeStats(): array
    {
        return [
            'documents'    => AiKnowledgeDocument::count(),
            'chunks'       => AiKnowledgeChunk::count(),
            'by_audience'  => AiKnowledgeDocument::query()->selectRaw('audience, count(*) as total')->groupBy('audience')->pluck('total', 'audience')->all(),
            'by_module'    => AiKnowledgeDocument::query()->selectRaw('module, count(*) as total')->groupBy('module')->orderByDesc('total')->pluck('total', 'module')->all(),
            'last_synced'  => optional(AiKnowledgeDocument::max('fetched_at') ? Carbon::parse(AiKnowledgeDocument::max('fetched_at')) : null)->toIso8601String(),
        ];
    }

    /**
     * @codeCoverageIgnore
     */
    protected function dispatchKnowledgeSync(string $source): void
    {
        SyncAiKnowledge::dispatch($source);
    }

    protected function applySessionFilters(Builder $query, AdminRequest $request): void
    {
        if ($request->filled('company_uuid')) {
            $query->where('company_uuid', $request->input('company_uuid'));
        }

        if ($request->filled('created_by_uuid')) {
            $query->where('created_by_uuid', $request->input('created_by_uuid'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function (Builder $query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('uuid', $search)
                    ->orWhereHas('tasks', function (Builder $query) use ($search) {
                        $query->where('prompt', 'like', "%{$search}%")
                            ->orWhere('response_summary', 'like', "%{$search}%")
                            ->orWhere('provider', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('provider') || $request->filled('model')) {
            $query->whereHas('tasks', function (Builder $query) use ($request) {
                if ($request->filled('provider')) {
                    $query->where('provider', $request->input('provider'));
                }

                if ($request->filled('model')) {
                    $query->where('model', $request->input('model'));
                }
            });
        }

        if ($request->filled('task_status') || $request->filled('feedback') || $request->boolean('degraded') || $request->boolean('truncated')) {
            $query->whereHas('tasks', function (Builder $query) use ($request) {
                if ($request->filled('task_status')) {
                    $query->where('status', $request->input('task_status'));
                }

                $this->applyQualityFilters($query, $request);
            });
        }
    }

    /**
     * Filters that find answers worth reviewing: rated by the user, degraded by an unavailable
     * capability, or cut off at the output limit.
     */
    protected function applyQualityFilters(Builder $query, AdminRequest $request): void
    {
        if ($request->input('feedback') === 'negative') {
            $query->where('feedback_rating', '<', 0);
        } elseif ($request->input('feedback') === 'positive') {
            $query->where('feedback_rating', '>', 0);
        }

        if ($request->boolean('degraded')) {
            $query->where('metadata->degraded', true);
        }

        if ($request->boolean('truncated')) {
            $query->where('metadata->truncated', true);
        }
    }

    protected function applyTaskFilters(Builder $query, AdminRequest $request): void
    {
        foreach (['company_uuid', 'created_by_uuid', 'status', 'provider', 'model'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        $this->applyQualityFilters($query, $request);
    }

    /**
     * The log view's filters applied to tasks, so an export holds the conversations the admin is looking at.
     */
    protected function applyExportFilters(Builder $query, AdminRequest $request): void
    {
        foreach (['company_uuid', 'created_by_uuid', 'provider', 'model', 'ai_session_uuid'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        $status = $request->input('task_status', $request->input('status'));
        if (filled($status)) {
            $query->where('status', $status);
        }

        if ($request->filled('session_status')) {
            $query->whereHas('session', fn (Builder $query) => $query->where('status', $request->input('session_status')));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function (Builder $query) use ($search) {
                $query->where('prompt', 'like', "%{$search}%")
                    ->orWhere('response_summary', 'like', "%{$search}%")
                    ->orWhere('provider', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('ai_session_uuid', $search)
                    ->orWhereHas('session', fn (Builder $query) => $query->where('title', 'like', "%{$search}%"));
            });
        }

        $this->applyQualityFilters($query, $request);
    }

    protected function findSession(string $id): AiSession
    {
        return $this->sessionsQuery()->where(function (Builder $query) use ($id) {
            // A UUID beginning with digits is cast to an integer by MySQL, so it would
            // otherwise match an unrelated row by its numeric key.
            $query->where('uuid', $id)->when(ctype_digit($id), fn (Builder $query) => $query->orWhere('id', (int) $id));
        })->firstOrFail();
    }

    protected function findTask(string $id): AiTask
    {
        return $this->tasksQuery()->where(function (Builder $query) use ($id) {
            // A UUID beginning with digits is cast to an integer by MySQL, so it would
            // otherwise match an unrelated row by its numeric key.
            $query->where('uuid', $id)->when(ctype_digit($id), fn (Builder $query) => $query->orWhere('id', (int) $id));
        })->firstOrFail();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function companiesQuery(): Builder
    {
        return Company::query();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function usersQuery(): Builder
    {
        return User::query();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function companyUsersForCompany(string $companyUuid): Builder
    {
        return CompanyUser::where('company_uuid', $companyUuid);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function sessionsQuery(): Builder
    {
        return AiSession::query();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function tasksQuery(): Builder
    {
        return AiTask::query();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createAccessLog(array $attributes): AiAdminAccessLog
    {
        return AiAdminAccessLog::create($attributes);
    }

    protected function serializeSession(AiSession $session): array
    {
        return [
            'id'                      => $session->id,
            'uuid'                    => $session->uuid,
            'company_uuid'            => $session->company_uuid,
            'created_by_uuid'         => $session->created_by_uuid,
            'title'                   => $session->title,
            'status'                  => $session->status,
            'tasks_count'             => (int) ($session->tasks_count ?? $session->tasks()->count()),
            'total_tokens'            => (int) ($session->total_tokens_sum ?? 0),
            'negative_feedback_count' => (int) ($session->negative_feedback_count ?? 0),
            'failed_count'            => (int) ($session->failed_count ?? 0),
            'flagged_count'           => (int) ($session->flagged_count ?? 0),
            'company'                 => $this->serializeCompany($session->company),
            'created_by'              => $this->serializeUser($session->createdBy),
            'last_message_at'         => optional($session->last_message_at)->toISOString(),
            'ended_at'                => optional($session->ended_at)->toISOString(),
            'created_at'              => optional($session->created_at)->toISOString(),
            'updated_at'              => optional($session->updated_at)->toISOString(),
        ];
    }

    protected function serializeTask(AiTask $task): array
    {
        $responseSummary = $task->response_summary ?: $this->excerpt($task->response);

        return [
            'id'                => $task->id,
            'uuid'              => $task->uuid,
            'ai_session_uuid'   => $task->ai_session_uuid,
            'company_uuid'      => $task->company_uuid,
            'created_by_uuid'   => $task->created_by_uuid,
            'task_type'         => $task->task_type,
            'status'            => $task->status,
            'provider'          => $task->provider,
            'model'             => $task->model,
            'input_tokens'      => (int) ($task->input_tokens ?? 0),
            'output_tokens'     => (int) ($task->output_tokens ?? 0),
            'total_tokens'      => (int) ($task->total_tokens ?? 0),
            'feedback_rating'   => $task->feedback_rating,
            'feedback_comment'  => $task->feedback_comment,
            'prompt_excerpt'    => $this->excerpt($task->prompt),
            'response_summary'  => $responseSummary,
            'prompt'            => $task->prompt,
            'response'          => $task->response,
            'context'           => $task->context,
            'usage'             => $task->usage,
            'metadata'          => $task->metadata,
            'error'             => $task->error,
            'steps_count'       => (int) ($task->steps_count ?? ($task->relationLoaded('steps') ? $task->steps->count() : 0)),
            'steps'             => $task->relationLoaded('steps') ? $task->steps->map(fn ($step) => $this->serializeStep($step))->values() : [],
            'session'           => $task->relationLoaded('session') && $task->session ? $this->serializeSession($task->session) : null,
            'company'           => $this->serializeCompany($task->company),
            'created_by'        => $this->serializeUser($task->createdBy),
            'started_at'        => optional($task->started_at)->toISOString(),
            'completed_at'      => optional($task->completed_at)->toISOString(),
            'created_at'        => optional($task->created_at)->toISOString(),
            'updated_at'        => optional($task->updated_at)->toISOString(),
        ];
    }

    protected function serializeStep($step): array
    {
        return [
            'id'               => $step->id,
            'uuid'             => $step->uuid,
            'type'             => $step->type,
            'status'           => $step->status,
            'provider'         => $step->provider,
            'model'            => $step->model,
            'tool'             => $step->tool,
            'input'            => $step->input,
            'output'           => $step->output,
            'usage'            => $step->usage,
            'metadata'         => $step->metadata,
            'error'            => $step->error,
            'started_at'       => optional($step->started_at)->toISOString(),
            'completed_at'     => optional($step->completed_at)->toISOString(),
            'created_at'       => optional($step->created_at)->toISOString(),
        ];
    }

    protected function serializeCompany($company): ?array
    {
        if (!$company) {
            return null;
        }

        return [
            'uuid'      => $company->uuid,
            'public_id' => $company->public_id ?? null,
            'name'      => $company->name ?? null,
        ];
    }

    protected function serializeUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'uuid'      => $user->uuid,
            'public_id' => $user->public_id ?? null,
            'name'      => $user->name ?? null,
            'email'     => $user->email ?? null,
        ];
    }

    protected function usageGroup(Builder $query, string $field, ?string $labelType = null)
    {
        $rows = $query
            ->select($field)
            ->selectRaw('COUNT(*) as task_count')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->groupBy($field)
            ->orderByDesc('total_tokens')
            ->limit(50)
            ->get();

        $labels = $this->usageLabels($labelType, $rows->pluck($field)->filter()->values()->all());

        return $rows->map(fn ($row) => [
            'key'           => $row->{$field} ?: 'unknown',
            'label'         => $labels[$row->{$field}] ?? ($row->{$field} ?: 'unknown'),
            'task_count'    => (int) $row->task_count,
            'input_tokens'  => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'total_tokens'  => (int) $row->total_tokens,
        ]);
    }

    protected function usageLabels(?string $type, array $ids): array
    {
        if (!$type || empty($ids)) {
            return [];
        }

        if ($type === 'company') {
            return $this->companiesForLabels($ids)->get(['uuid', 'public_id', 'name'])->mapWithKeys(fn ($company) => [
                $company->uuid => $company->name ?: ($company->public_id ?: $company->uuid),
            ])->all();
        }

        if ($type === 'user') {
            return $this->usersForLabels($ids)->get(['uuid', 'public_id', 'name', 'email'])->mapWithKeys(fn ($user) => [
                $user->uuid => $user->name ?: ($user->email ?: ($user->public_id ?: $user->uuid)),
            ])->all();
        }

        return [];
    }

    /**
     * @codeCoverageIgnore
     */
    protected function companiesForLabels(array $ids): Builder
    {
        return Company::whereIn('uuid', $ids);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function usersForLabels(array $ids): Builder
    {
        return User::whereIn('uuid', $ids);
    }

    protected function usageByDay(Builder $query)
    {
        return $query
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as task_count')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->groupBy($this->dateRaw('created_at'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day'          => $row->day,
                'task_count'   => (int) $row->task_count,
                'total_tokens' => (int) $row->total_tokens,
            ]);
    }

    /**
     * Add the days with no activity, so a chart of the range has no gaps. Without a range the span runs
     * from the first to the last active day. An inverted or unreasonably long range is left as it is.
     *
     * @param Collection<int, array{day: string, task_count: int, total_tokens: int}> $rows
     *
     * @return Collection<int, array{day: string, task_count: int, total_tokens: int}>
     */
    protected function zeroFillDays(Collection $rows, ?string $from, ?string $to): Collection
    {
        /** @var array<string, array{day: string, task_count: int, total_tokens: int}> $byDay */
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr($row['day'], 0, 10)] = $row;
        }

        if (empty($byDay) && (!$from || !$to)) {
            return new Collection();
        }

        $days  = array_keys($byDay);
        $start = Carbon::parse($from ?: (string) reset($days))->startOfDay();
        $end   = Carbon::parse($to ?: (string) end($days))->startOfDay();

        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            return $rows->values();
        }

        $filled = [];
        foreach (CarbonPeriod::create($start, $end)->toArray() as $day) {
            $date     = $day->toDateString();
            $filled[] = array_merge(['day' => $date, 'task_count' => 0, 'total_tokens' => 0], $byDay[$date] ?? [], ['day' => $date]);
        }

        return new Collection($filled);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function dateRaw(string $column)
    {
        return DB::raw("DATE({$column})");
    }

    protected function excerpt(?string $value, int $limit = 180): ?string
    {
        if (!$value) {
            return null;
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $value)), $limit);
    }

    protected function can(AdminRequest $request, string $permission): bool
    {
        return optional($request->user())->isAdmin() === true || Auth::can($permission);
    }

    protected function canUseAdminFilters(AdminRequest $request): bool
    {
        return $this->can($request, 'ai view audit logs') || $this->can($request, 'ai view usage analytics');
    }

    protected function applyUserSearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('public_id', 'like', "%{$search}%")
                ->orWhere('uuid', $search);
        });
    }

    protected function serializeUserOption(User $user): array
    {
        return [
            'id'           => $user->uuid,
            'uuid'         => $user->uuid,
            'public_id'    => $user->public_id,
            'company_uuid' => $user->company_uuid,
            'name'         => $user->name,
            'email'        => $user->email,
            'status'       => $user->status,
        ];
    }
}
