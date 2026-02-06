<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserStreak;

/**
 * Minimal API for listing streaks (optional admin/status view).
 */
class UserStreakController extends Controller
{
    /**
     * GET /api/streaks
     */
    public function index()
    {
        $streaks = UserStreak::query()
            ->orderBy('updated_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'streaks' => $streaks,
        ]);
    }
}

