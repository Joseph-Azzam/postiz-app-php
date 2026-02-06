<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;

/**
 * User API for Postiz frontend compatibility.
 * GET /api/user/self returns current user from auth cookie (minimal shape for layout).
 */
class UserController extends Controller
{
    /**
     * GET /api/user/self
     * Returns the current user from the auth cookie. Frontend expects user + orgId, tier, role, etc.
     * Laravel backend has no organizations; we return user with a single default "org" (user id).
     */
    public function self(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $orgId = (string) $user->id;

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'orgId' => $orgId,
            'tier' => 'FREE',
            'role' => 'ADMIN',
            'totalChannels' => 10,
            'publicApi' => '',
            'impersonate' => false,
            'isLifetime' => false,
            'allowTrial' => false,
            'isTrailing' => false,
            'streakSince' => null,
            'admin' => false,
        ]);
    }

    /**
     * GET /api/user/organizations
     * Laravel backend has no org model; return single "org" per user for Postiz UI compatibility.
     */
    public function organizations(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $orgId = (string) $user->id;

        return response()->json([
            [
                'id' => $orgId,
                'name' => $user->name ?: 'My workspace',
            ],
        ]);
    }

    /**
     * POST /api/user/change-org
     * No multi-org in Laravel; accept and no-op for Postiz UI compatibility.
     */
    public function changeOrg(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate(['id' => ['nullable', 'string']]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/user/logout
     * Frontend (settings logout) calls this when isSecured; we clear the auth cookie and return 200.
     */
    public function logout(Request $request): JsonResponse
    {
        $response = response()->json(['ok' => true]);
        // Clear auth cookie so subsequent requests are unauthenticated.
        $response->withCookie(Cookie::forget('auth'));

        return $response;
    }

    /**
     * GET /api/user/personal
     * Profile (name, bio, picture) for settings. Stub returns from auth user.
     */
    public function personal(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'name' => $user->name ?? '',
            'bio' => '',
            'picture' => null,
        ]);
    }

    /**
     * POST /api/user/personal
     * Update profile; accept and persist when user profile fields exist.
     */
    public function personalUpdate(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'fullname' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'picture' => ['nullable', 'string'],
        ]);

        if ($request->filled('fullname')) {
            $user->name = $request->input('fullname');
            $user->save();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/user/email-notifications
     * Email notification preferences. Stub returns defaults.
     */
    public function emailNotifications(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'sendSuccessEmails' => true,
            'sendFailureEmails' => true,
            'sendStreakEmails' => true,
        ]);
    }

    /**
     * POST /api/user/email-notifications
     * Update email notification preferences; no-op until persisted.
     */
    public function emailNotificationsUpdate(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'sendSuccessEmails' => ['nullable', 'boolean'],
            'sendFailureEmails' => ['nullable', 'boolean'],
            'sendStreakEmails' => ['nullable', 'boolean'],
        ]);

        return response()->json(['ok' => true]);
    }

    private function userFromAuthCookie(Request $request): ?User
    {
        $token = $request->cookie('auth') ?? $request->header('auth');
        if (empty($token)) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($token);
            $payload = json_decode($decrypted, true);
            if (! is_array($payload) || ! isset($payload['user_id'], $payload['exp'])) {
                return null;
            }
            if ($payload['exp'] < time()) {
                return null;
            }
            return User::find($payload['user_id']);
        } catch (\Throwable) {
            return null;
        }
    }
}
