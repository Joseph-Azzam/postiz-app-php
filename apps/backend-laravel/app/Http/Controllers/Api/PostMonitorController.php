<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostMonitorEntry;
use Illuminate\Http\Request;

/**
 * API for inspecting post monitoring entries (optional admin view).
 */
class PostMonitorController extends Controller
{
    /**
     * GET /api/missing/entries
     */
    public function index(Request $request)
    {
        $entries = PostMonitorEntry::query()
            ->orderBy('last_checked_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'entries' => $entries,
        ]);
    }
}

