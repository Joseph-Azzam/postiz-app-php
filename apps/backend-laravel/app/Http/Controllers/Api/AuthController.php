<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Auth API for Postiz frontend compatibility.
 * Implements: OAuth link (GENERIC), register (LOCAL), login (LOCAL),
 * email verification (when REQUIRE_EMAIL_VERIFICATION=true).
 */
class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     * Creates a user (LOCAL provider only). When REQUIRE_EMAIL_VERIFICATION=true,
     * sends activation email and returns activate header; else user can log in immediately.
     */
    public function register(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:3|max:64',
            'company' => 'required|string|min:3|max:128',
            'provider' => 'required|string|in:LOCAL',
        ]);

        if (User::where('email', $validated['email'])->exists()) {
            return response('Email already exists', 400)->header('Content-Type', 'text/plain');
        }

        $requireVerification = config('postiz.require_email_verification');

        $user = User::create([
            'name' => $validated['company'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => $requireVerification ? null : now(),
        ]);

        if ($requireVerification) {
            $token = $this->createActivationToken($user->id);
            $this->sendActivationEmail($user->email, $user->name, $token);
            return response()->json(['activate' => true], 200)
                ->header('activate', 'true');
        }

        return response()->json(['register' => true], 200)
            ->header('onboarding', 'true');
    }

    /**
     * POST /api/auth/login
     * Authenticates user (LOCAL provider only). When REQUIRE_EMAIL_VERIFICATION=true,
     * requires email_verified_at; else allows login without activation.
     */
    public function login(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:3|max:64',
            'provider' => 'required|string|in:LOCAL',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response('Invalid user name or password', 400)->header('Content-Type', 'text/plain');
        }

        if (config('postiz.require_email_verification') && ! $user->email_verified_at) {
            return response('User is not activated', 400)->header('Content-Type', 'text/plain');
        }

        $token = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'email' => $user->email,
            'exp' => now()->addYear()->timestamp,
        ]));

        $secure = $request->secure();
        $cookie = cookie(
            'auth',
            $token,
            60 * 24 * 365, // 1 year in minutes
            '/',
            null,
            $secure,
            true, // httpOnly
            false,
            $secure ? 'none' : 'lax' // sameSite for cross-origin
        );

        $response = response()->json(['login' => true], 200)->header('reload', 'true')->cookie($cookie);

        if (filter_var(env('NOT_SECURED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            $response->header('auth', $token);
        }

        return $response;
    }

    /**
     * POST /api/auth/activate
     * Verifies activation token and marks user as activated. Sets auth cookie on success.
     * Token in request may be base64url-encoded (from email link).
     */
    public function activate(Request $request): JsonResponse|Response
    {
        $code = $request->input('code');
        if (empty($code)) {
            return response()->json(['can' => false], 200);
        }

        $token = $code;
        if (preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
            $decoded = base64_decode(strtr($code, '-_', '+/'), true);
            if ($decoded !== false) {
                $token = $decoded;
            }
        }
        $payload = $this->parseActivationToken($token);
        if (! $payload) {
            return response()->json(['can' => false], 200);
        }

        $user = User::find($payload['user_id']);
        if (! $user) {
            return response()->json(['can' => false], 200);
        }

        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        $authToken = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'email' => $user->email,
            'exp' => now()->addYear()->timestamp,
        ]));

        $secure = $request->secure();
        $cookie = cookie(
            'auth',
            $authToken,
            60 * 24 * 365,
            '/',
            null,
            $secure,
            true,
            false,
            $secure ? 'none' : 'lax'
        );

        $response = response()->json(['can' => true], 200)->header('onboarding', 'true')->cookie($cookie);
        if (filter_var(env('NOT_SECURED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            $response->header('auth', $authToken);
        }
        return $response;
    }

    /**
     * POST /api/auth/resend-activation
     * Resends activation email for the given email (when REQUIRE_EMAIL_VERIFICATION=true).
     */
    public function resendActivation(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 200);
        }
        if ($user->email_verified_at) {
            return response()->json(['success' => false, 'message' => 'Account is already activated'], 200);
        }

        $token = $this->createActivationToken($user->id);
        $this->sendActivationEmail($user->email, $user->name, $token);

        return response()->json(['success' => true], 200);
    }

    private function createActivationToken(int $userId): string
    {
        $hours = config('postiz.activation_token_hours', 24);
        $payload = [
            'user_id' => $userId,
            'exp' => now()->addHours($hours)->timestamp,
        ];
        return Crypt::encryptString(json_encode($payload));
    }

    /** @return array{user_id: int, exp: int}|null */
    private function parseActivationToken(string $code): ?array
    {
        try {
            $decrypted = Crypt::decryptString($code);
            $payload = json_decode($decrypted, true);
            if (! is_array($payload) || ! isset($payload['user_id'], $payload['exp'])) {
                return null;
            }
            if ($payload['exp'] < time()) {
                return null;
            }
            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sendActivationEmail(string $email, string $name, string $token): void
    {
        $frontendUrl = rtrim((string) (config('services.postiz_oauth.frontend_url') ?? env('FRONTEND_URL', config('app.url'))), '/');
        $tokenForUrl = rtrim(strtr(base64_encode($token), '+/', '-_'), '=');
        $link = $frontendUrl . '/auth/activate/' . $tokenForUrl;

        $html = sprintf(
            '<p>Hi %s,</p><p>Click <a href="%s">here</a> to activate your account.</p><p>This link expires in %d hours.</p>',
            htmlspecialchars($name),
            htmlspecialchars($link),
            config('postiz.activation_token_hours', 24)
        );

        Mail::html($html, function ($message) use ($email) {
            $message->to($email)
                ->subject('Activate your account');
        });
    }

    /**
     * POST /api/auth/forgot
     * Request password reset. Stub returns forgot: true (no email sent until mail config is used).
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        return response()->json(['forgot' => true]);
    }

    /**
     * POST /api/auth/forgot-return
     * Reset password with token. Stub returns reset: true until token storage is implemented.
     */
    public function forgotReturn(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:3', 'max:64'],
        ]);
        return response()->json(['reset' => true]);
    }

    /**
     * GET /api/auth/oauth/{provider}
     * Returns the OAuth authorization URL as plain text (frontend does response.text() then window.location.href = url).
     * Only GENERIC is implemented (Authentik / generic OIDC).
     */
    public function oauthLink(string $provider): Response
    {
        $provider = strtoupper($provider);

        if ($provider !== 'GENERIC') {
            return response('Provider not implemented', 404)->header('Content-Type', 'text/plain');
        }

        $authUrl = config('services.postiz_oauth.auth_url');
        $clientId = config('services.postiz_oauth.client_id');
        $frontendUrl = rtrim((string) config('services.postiz_oauth.frontend_url', config('app.url')), '/');

        if (empty($authUrl) || empty($clientId) || empty($frontendUrl)) {
            return response(
                'OAuth not configured: set POSTIZ_OAUTH_AUTH_URL, POSTIZ_OAUTH_CLIENT_ID, FRONTEND_URL',
                503
            )->header('Content-Type', 'text/plain');
        }

        $redirectUri = $frontendUrl . '/settings';
        $params = http_build_query([
            'client_id' => $clientId,
            'scope' => 'openid profile email',
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
        ]);

        $url = $authUrl . (str_contains($authUrl, '?') ? '&' : '?') . $params;

        return response($url, 200)->header('Content-Type', 'text/plain');
    }
}
