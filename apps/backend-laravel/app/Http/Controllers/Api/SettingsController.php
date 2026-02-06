<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthCookieUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings: shortlink and team (stub). GET/POST /api/settings/shortlink, GET/POST/DELETE /api/settings/team.
 */
class SettingsController extends Controller
{
    use AuthCookieUser;

    /**
     * GET /api/settings/shortlink
     * Frontend expects { shortlink: 'ASK' | 'YES' | 'NO' }.
     */
    public function shortlink(Request $request): JsonResponse
    {
        return response()->json([
            'shortlink' => 'ASK',
        ]);
    }

    /**
     * POST /api/settings/shortlink
     * Accept and no-op until settings are persisted.
     */
    public function shortlinkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'shortlink' => ['nullable', 'string', 'in:ASK,YES,NO'],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/settings/team
     * Team members. Laravel has no org/team; return current user as single member.
     */
    public function team(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['users' => []], 200);
        }
        return response()->json([
            'users' => [
                ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'picture' => null], 'role' => 'ADMIN'],
            ],
        ]);
    }

    /**
     * POST /api/settings/team
     * Invite (stub). No multi-user in Laravel; accept and no-op.
     */
    public function teamStore(Request $request): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/settings/team/{id}
     * Remove team member (stub). No multi-user in Laravel.
     */
    public function teamDestroy(Request $request, string $id): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json(['ok' => true]);
    }
}
