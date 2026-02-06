<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/third-party and GET /api/third-party/list for Postiz third-party integrations.
 * Stub returns empty array until third-party providers are implemented.
 */
class ThirdPartyListController extends Controller
{
    /**
     * GET /api/third-party (third-party.component, third-party.media)
     * GET /api/third-party/list (third-party.list.component)
     * Frontend expects an array of { identifier, title, description }.
     */
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
