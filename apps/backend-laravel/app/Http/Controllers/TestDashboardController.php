<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PostizTestRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/**
 * Serves the test dashboard at /test. Runs suites via PostizTestRunner and renders results.
 * Optional: set POSTIZ_TEST_KEY in .env and pass ?key=… to allow access.
 * "Post on platform" tests require the user to be logged in (same-origin request with auth cookie).
 */
class TestDashboardController extends Controller
{
    public function __invoke(Request $request, PostizTestRunner $runner)
    {
        $testKey = trim((string) Config::get('postiz.test_key', ''));
        if ($testKey !== '') {
            $given = $request->query('key', '');
            if (! is_string($given) || ! hash_equals($testKey, $given)) {
                abort(403, 'Forbidden. Set POSTIZ_TEST_KEY in .env and pass ?key=… to run.');
            }
        }

        $suite = $request->query('suite', 'full');
        $suite = in_array($suite, ['full', 'gemini', 'db', 'social'], true) ? $suite : 'full';
        $noAi = filter_var($request->query('no_ai', false), FILTER_VALIDATE_BOOLEAN);
        $testImage = filter_var($request->query('test_image', false), FILTER_VALIDATE_BOOLEAN);

        $postOnPlatform = [
            'x' => filter_var($request->query('post_on_x', false), FILTER_VALIDATE_BOOLEAN),
            'linkedin' => filter_var($request->query('post_on_linkedin', false), FILTER_VALIDATE_BOOLEAN),
            'bluesky' => filter_var($request->query('post_on_bluesky', false), FILTER_VALIDATE_BOOLEAN),
        ];

        $user = $this->userFromAuthCookie($request);
        if ($user === null) {
            $user = $this->userFromSessionToken($request);
        }
        $userId = $user?->id;

        $data = $runner->run($suite, $noAi, $testImage, $postOnPlatform, $userId);

        $showSessionHint = ($postOnPlatform['x'] || $postOnPlatform['linkedin'] || $postOnPlatform['bluesky']) && $userId === null;
        $frontendUrl = rtrim((string) Config::get('postiz.integrations.frontend_url', config('app.url')), '/');

        return view('test.dashboard', [
            'results' => $data['results'],
            'summary' => $data['summary'],
            'suite' => $suite,
            'noAi' => $noAi,
            'testImage' => $testImage,
            'postOnX' => $postOnPlatform['x'],
            'postOnLinkedin' => $postOnPlatform['linkedin'],
            'postOnBluesky' => $postOnPlatform['bluesky'],
            'key' => $request->query('key', ''),
            'showSessionHint' => $showSessionHint,
            'frontendUrl' => $frontendUrl,
        ]);
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

    /**
     * Resolve user from one-time ?session= token (from /api/test/session-token).
     * Used when the auth cookie is not sent to the backend (e.g. different origin).
     */
    private function userFromSessionToken(Request $request): ?User
    {
        $token = $request->query('session');
        if (empty($token) || ! is_string($token)) {
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
