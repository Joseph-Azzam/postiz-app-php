<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * One-time session token for /test dashboard so "Post on X" can run when
 * the auth cookie is not sent to the backend (e.g. frontend and backend on different origins).
 * GET /api/test/session-token: requires auth (cookie or header), returns { "url": "…" }.
 */
class TestSessionController extends Controller
{
    private const SESSION_TTL_MINUTES = 5;

    public function sessionToken(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if ($user === null) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $payload = [
            'user_id' => $user->id,
            'exp' => now()->addMinutes(self::SESSION_TTL_MINUTES)->timestamp,
        ];
        $token = Crypt::encryptString(json_encode($payload));

        $query = [
            'session' => $token,
            'suite' => $request->query('suite', 'full'),
            'no_ai' => $request->query('no_ai', '0'),
            'test_image' => $request->query('test_image', '0'),
            'post_on_x' => $request->query('post_on_x', '1'),
            'post_on_linkedin' => $request->query('post_on_linkedin', '0'),
            'post_on_bluesky' => $request->query('post_on_bluesky', '0'),
        ];

        $url = URL::to('/test').'?'.http_build_query($query);

        return response()->json(['url' => $url]);
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
