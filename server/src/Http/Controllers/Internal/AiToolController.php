<?php

namespace Fleetbase\Ai\Http\Controllers\Internal;

use Fleetbase\Ai\Support\AiCapabilityRegistry;
use Fleetbase\Http\Controllers\Controller;

class AiToolController extends Controller
{
    public function index(AiCapabilityRegistry $registry)
    {
        return response()->json([
            'tools' => $registry->list(),
        ]);
    }
}
