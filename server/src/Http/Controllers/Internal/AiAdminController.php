<?php

namespace Fleetbase\Ai\Http\Controllers\Internal;

use Carbon\Carbon;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiAdminController extends Controller
{
    public function companies(AdminRequest $request)
    {
        abort_unless($this->canUseAdminFilters($request), 403, 'You are not authorized to use AI admin filters.');

        $query  = Company::query()->select(['uuid', 'public_id', 'name', 'status', 'created_at'])->orderBy('name');
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
            $usersQuery = CompanyUser::where('company_uuid', $request->input('company_uuid'))
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

        $query = User::query()->select(['uuid', 'public_id', 'company_uuid', 'name', 'email', 'status'])->orderBy('name');

        if ($search) {
            $this->applyUserSearch($query, $search);
        }

        return response()->json($query->limit($limit)->get()->map(fn (User $user) => $this->serializeUserOption($user)));
    }

    public function sessions(AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view audit logs'), 403, 'You are not authorized to view AI audit logs.');

        $query = AiSession::query()
            ->with(['company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email'])
            ->withCount('tasks')
            ->withSum('tasks as total_tokens_sum', 'total_tokens')
            ->latest('last_message_at')
            ->latest();

        $this->applySessionFilters($query, $request);

        $limit = min(max((int) $request->input('limit', 30), 1), 100);

        return response()->json([
            'sessions' => $query->limit($limit)->get()->map(fn (AiSession $session) => $this->serializeSession($session)),
            'meta'     => [
                'can_reveal_content' => $this->can($request, 'ai view task content'),
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
                'tasks' => fn ($query) => $query->with(['steps', 'company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email'])->oldest(),
            ])
            ->loadCount('tasks')
            ->loadSum('tasks as total_tokens_sum', 'total_tokens');

        return response()->json([
            'session' => array_merge($this->serializeSession($session), [
                'tasks' => $session->tasks->map(fn (AiTask $task) => $this->serializeTask($task, false)),
            ]),
            'meta'    => [
                'can_reveal_content' => $this->can($request, 'ai view task content'),
            ],
        ]);
    }

    public function task(string $id, AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view audit logs'), 403, 'You are not authorized to view AI audit logs.');

        $task = $this->findTask($id)->load(['steps', 'session', 'company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email']);

        return response()->json([
            'task' => $this->serializeTask($task, false),
            'meta' => [
                'can_reveal_content' => $this->can($request, 'ai view task content'),
            ],
        ]);
    }

    public function revealTaskContent(string $id, AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view task content'), 403, 'You are not authorized to view AI task content.');

        $task = $this->findTask($id)->load(['steps', 'session', 'company:uuid,public_id,name', 'createdBy:uuid,public_id,name,email']);

        AiAdminAccessLog::create([
            'company_uuid'     => $task->company_uuid,
            'ai_session_uuid'  => $task->ai_session_uuid,
            'ai_task_uuid'     => $task->uuid,
            'viewed_by_uuid'   => optional($request->user())->uuid,
            'action'           => 'view_task_content',
            'ip_address'       => $request->ip(),
            'user_agent'       => substr((string) $request->userAgent(), 0, 1000),
            'metadata'         => [
                'task_status' => $task->status,
                'provider'    => $task->provider,
                'model'       => $task->model,
            ],
        ]);

        return response()->json(['task' => $this->serializeTask($task, true)]);
    }

    public function usage(AdminRequest $request)
    {
        abort_unless($this->can($request, 'ai view usage analytics'), 403, 'You are not authorized to view AI usage analytics.');

        $base = AiTask::query();
        $this->applyTaskFilters($base, $request);

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as task_count')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->first();

        return response()->json([
            'summary'  => [
                'task_count'      => (int) ($summary->task_count ?? 0),
                'input_tokens'    => (int) ($summary->input_tokens ?? 0),
                'output_tokens'   => (int) ($summary->output_tokens ?? 0),
                'total_tokens'    => (int) ($summary->total_tokens ?? 0),
                'failed_count'    => (int) ($summary->failed_count ?? 0),
                'completed_count' => (int) ($summary->completed_count ?? 0),
            ],
            'by_company' => $this->usageGroup(clone $base, 'company_uuid', 'company'),
            'by_user'    => $this->usageGroup(clone $base, 'created_by_uuid', 'user'),
            'by_provider'=> $this->usageGroup(clone $base, 'provider'),
            'by_model'   => $this->usageGroup(clone $base, 'model'),
            'by_status'  => $this->usageGroup(clone $base, 'status'),
            'by_day'     => $this->usageByDay(clone $base),
        ]);
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
    }

    protected function findSession(string $id): AiSession
    {
        return AiSession::where(function (Builder $query) use ($id) {
            $query->where('uuid', $id)->orWhere('id', $id);
        })->firstOrFail();
    }

    protected function findTask(string $id): AiTask
    {
        return AiTask::where(function (Builder $query) use ($id) {
            $query->where('uuid', $id)->orWhere('id', $id);
        })->firstOrFail();
    }

    protected function serializeSession(AiSession $session): array
    {
        return [
            'id'                => $session->id,
            'uuid'              => $session->uuid,
            'company_uuid'      => $session->company_uuid,
            'created_by_uuid'   => $session->created_by_uuid,
            'title'             => $session->title,
            'status'            => $session->status,
            'tasks_count'       => (int) ($session->tasks_count ?? $session->tasks()->count()),
            'total_tokens'      => (int) ($session->total_tokens_sum ?? 0),
            'company'           => $this->serializeCompany($session->company),
            'created_by'        => $this->serializeUser($session->createdBy),
            'last_message_at'   => optional($session->last_message_at)->toISOString(),
            'ended_at'          => optional($session->ended_at)->toISOString(),
            'created_at'        => optional($session->created_at)->toISOString(),
            'updated_at'        => optional($session->updated_at)->toISOString(),
        ];
    }

    protected function serializeTask(AiTask $task, bool $includeContent): array
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
            'prompt_excerpt'    => $this->excerpt($task->prompt),
            'response_summary'  => $responseSummary,
            'prompt'            => $includeContent ? $task->prompt : null,
            'response'          => $includeContent ? $task->response : null,
            'context'           => $includeContent ? $task->context : null,
            'usage'             => $task->usage,
            'metadata'          => $includeContent ? $task->metadata : $this->metadataSummary($task->metadata),
            'error'             => $task->error,
            'content_redacted'  => !$includeContent,
            'steps'             => $task->relationLoaded('steps') ? $task->steps->map(fn ($step) => $this->serializeStep($step, $includeContent))->values() : [],
            'session'           => $task->relationLoaded('session') && $task->session ? $this->serializeSession($task->session) : null,
            'company'           => $this->serializeCompany($task->company),
            'created_by'        => $this->serializeUser($task->createdBy),
            'started_at'        => optional($task->started_at)->toISOString(),
            'completed_at'      => optional($task->completed_at)->toISOString(),
            'created_at'        => optional($task->created_at)->toISOString(),
            'updated_at'        => optional($task->updated_at)->toISOString(),
        ];
    }

    protected function serializeStep($step, bool $includeContent): array
    {
        return [
            'id'               => $step->id,
            'uuid'             => $step->uuid,
            'type'             => $step->type,
            'status'           => $step->status,
            'provider'         => $step->provider,
            'model'            => $step->model,
            'tool'             => $step->tool,
            'input'            => $includeContent ? $step->input : null,
            'output'           => $includeContent ? $step->output : null,
            'usage'            => $step->usage,
            'metadata'         => $includeContent ? $step->metadata : $this->metadataSummary($step->metadata),
            'error'            => $step->error,
            'content_redacted' => !$includeContent,
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

    protected function metadataSummary($metadata): array
    {
        $metadata = (array) $metadata;

        return [
            'keys'                  => array_values(array_keys($metadata)),
            'action_previews_count' => count((array) data_get($metadata, 'action_previews', [])),
            'action_results_count'  => count((array) data_get($metadata, 'action_results', [])),
            'action_errors_count'   => count((array) data_get($metadata, 'action_errors', [])),
            'attachments_count'     => count((array) data_get($metadata, 'attachments', [])),
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
            return Company::whereIn('uuid', $ids)->get(['uuid', 'public_id', 'name'])->mapWithKeys(fn ($company) => [
                $company->uuid => $company->name ?: ($company->public_id ?: $company->uuid),
            ])->all();
        }

        if ($type === 'user') {
            return User::whereIn('uuid', $ids)->get(['uuid', 'public_id', 'name', 'email'])->mapWithKeys(fn ($user) => [
                $user->uuid => $user->name ?: ($user->email ?: ($user->public_id ?: $user->uuid)),
            ])->all();
        }

        return [];
    }

    protected function usageByDay(Builder $query)
    {
        return $query
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as task_count')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day'          => $row->day,
                'task_count'   => (int) $row->task_count,
                'total_tokens' => (int) $row->total_tokens,
            ]);
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
