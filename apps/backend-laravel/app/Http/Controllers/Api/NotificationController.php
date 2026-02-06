<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stub notification API for Postiz frontend compatibility.
 * GET /api/notifications returns total count; GET /api/notifications/list returns list.
 * No persistence until a notifications feature is implemented.
 */
class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Returns total unread count for header badge.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'total' => 0,
        ]);
    }

    /**
     * GET /api/notifications/list
     * Returns notifications array and lastRead timestamp for popup.
     */
    public function list(Request $request): JsonResponse
    {
        return response()->json([
            'notifications' => [],
            'lastReadNotifications' => now()->toIso8601String(),
        ]);
    }
}
