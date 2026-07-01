<?php

namespace Fleetbase\Ai\Http\Controllers\Internal;

use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiTaskService;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AiTaskController extends Controller
{
    public function index(Request $request)
    {
        $query = AiTask::where('company_uuid', session('company'))->with(['steps', 'session'])->latest();

        if ($request->boolean('mine')) {
            $query->where('created_by_uuid', optional($request->user())->uuid);
        }

        return response()->json(['tasks' => $query->limit((int) $request->input('limit', 20))->get()]);
    }

    public function store(Request $request, AiTaskService $tasks)
    {
        $request->validate([
            'prompt'        => ['required', 'string', 'max:12000'],
            'session_uuid'  => ['nullable', 'string'],
            'attachments'   => ['nullable', 'array'],
            'attachments.*' => ['string'],
        ]);

        return response()->json(['task' => $tasks->createFromRequest($request)]);
    }

    public function show(string $id)
    {
        return response()->json(['task' => $this->findTask($id)->load(['steps', 'session'])]);
    }

    public function preview(string $id, Request $request, AiTaskService $tasks)
    {
        $task = $this->findTask($id);

        return response()->json(['task' => $tasks->refreshPreview($task, $request->input('action_key'), $request->input('input', []))->load('session')]);
    }

    public function apply(string $id, Request $request, AiTaskService $tasks)
    {
        $task = $this->findTask($id);

        return response()->json(['task' => $tasks->apply($task, $request->input('action_key'), $request->input('input', []))->load('session')]);
    }

    public function cancel(string $id, AiTaskService $tasks)
    {
        $task      = $this->findTask($id);
        $metadata  = (array) $task->metadata;
        $previews  = collect((array) data_get($metadata, 'action_previews', []));
        $preview   = $previews->first();
        $actionKey = is_array($preview) ? ($preview['key'] ?? $preview['action'] ?? null) : null;
        $error     = [
            'action'  => $actionKey,
            'type'    => 'cancelled',
            'message' => 'This AI action preview was cancelled.',
        ];

        $metadata['action_errors'] = array_values(array_merge((array) data_get($metadata, 'action_errors', []), [$error]));

        $task->update(['status' => 'cancelled', 'metadata' => $metadata, 'completed_at' => $task->completed_at ?? now()]);
        $tasks->recordStep($task, [
            'type'         => 'cancel',
            'status'       => 'completed',
            'tool'         => $actionKey,
            'output'       => ['message' => $error['message']],
            'completed_at' => now(),
        ]);

        return response()->json(['task' => $task->fresh(['steps', 'session'])]);
    }

    protected function findTask(string $id): AiTask
    {
        return AiTask::where('company_uuid', session('company'))
            ->where('created_by_uuid', optional(request()->user())->uuid)
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('id', $id);
            })
            ->firstOrFail();
    }
}
