<?php

namespace Fleetbase\Ai\Http\Controllers\Internal;

use Fleetbase\Ai\Models\AiSession;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AiSessionController extends Controller
{
    public function index(Request $request)
    {
        $query = AiSession::where('company_uuid', session('company'))
            ->withCount('tasks')
            ->latest('last_message_at')
            ->latest();

        if ($request->boolean('mine')) {
            $query->where('created_by_uuid', optional($request->user())->uuid);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json(['sessions' => $query->limit((int) $request->input('limit', 30))->get()]);
    }

    public function store(Request $request)
    {
        $title = trim((string) $request->input('title', ''));

        $session = AiSession::create([
            'company_uuid'     => session('company'),
            'created_by_uuid'  => optional($request->user())->uuid,
            'title'            => $title ?: 'New AI chat',
            'status'           => 'active',
            'last_message_at'  => now(),
        ]);

        return response()->json(['session' => $session->load(['tasks' => fn ($query) => $query->with('steps')->oldest()])]);
    }

    public function show(string $id)
    {
        return response()->json(['session' => $this->findSession($id)->load(['tasks' => fn ($query) => $query->with('steps')->oldest()])]);
    }

    public function end(string $id)
    {
        $session = $this->findSession($id);
        $session->update([
            'status'   => 'ended',
            'ended_at' => $session->ended_at ?? now(),
        ]);

        return response()->json(['session' => $session->fresh(['tasks' => fn ($query) => $query->with('steps')->oldest()])]);
    }

    public function destroy(string $id)
    {
        $session = $this->findSession($id);
        $session->delete();

        return response()->json(['deleted' => true]);
    }

    protected function findSession(string $id): AiSession
    {
        return AiSession::where('company_uuid', session('company'))
            ->where('created_by_uuid', optional(request()->user())->uuid)
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('id', $id);
            })
            ->firstOrFail();
    }
}
