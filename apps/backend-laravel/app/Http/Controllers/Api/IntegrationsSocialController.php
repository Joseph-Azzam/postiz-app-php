<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\SocialOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Config;

/**
 * Social OAuth for Add Channel (mirrors NestJS GET /integrations/social/:id and POST /integrations/social/:id/connect).
 * GET /api/integrations/social/{identifier} → { url } or { err: true }.
 * POST /api/integrations/social/{identifier}/connect → { id, inBetweenSteps?, onboarding? } or 4xx.
 */
class IntegrationsSocialController extends Controller
{
    private const ALLOWED_IDENTIFIERS = [
        'x', 'linkedin', 'linkedin-page', 'facebook', 'instagram', 'youtube', 'threads',
        'tiktok', 'pinterest', 'reddit', 'mastodon', 'bluesky', 'gmb',
        'devto', 'hashnode', 'medium', 'wordpress',
    ];

    /**
     * GET /api/integrations/social/{identifier}
     * Query: refresh?, onboarding?, externalUrl?
     */
    public function getIntegrationUrl(Request $request, string $identifier): JsonResponse
    {
        $identifier = strtolower($identifier);
        if (! in_array($identifier, self::ALLOWED_IDENTIFIERS, true)) {
            return response()->json(['err' => true], 200);
        }

        $config = $this->integrationsConfigWithEnvFallback();
        $frontendUrl = $config['frontend_url'] ?? rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $service = new SocialOAuthService($frontendUrl, $config);

        $refresh = $request->query('refresh');
        $onboarding = $request->query('onboarding') === 'true';
        $externalUrl = $request->query('externalUrl');

        $result = $service->generateAuthUrl($identifier, $refresh, $onboarding, $externalUrl);
        if ($result === null) {
            $message = $service->getLastError();

            return response()->json([
                'err' => true,
                'message' => $message ?? 'Channel not configured. Add OAuth credentials in your project .env or .env.local.',
            ], 200);
        }

        return response()->json(['url' => $result['url']], 200);
    }

    /**
     * POST /api/integrations/social/{identifier}/connect
     * Body: code, state, timezone, refresh?, and provider-specific (e.g. oauth_token/oauth_verifier for X).
     */
    public function connect(Request $request, string $identifier): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $identifier = strtolower($identifier);
        if (! in_array($identifier, self::ALLOWED_IDENTIFIERS, true)) {
            return response()->json(['message' => 'Integration not allowed'], 400);
        }

        $body = $request->all();
        $code = $body['code'] ?? '';
        $state = $body['state'] ?? '';
        $timezone = (int) ($body['timezone'] ?? 0);
        $refresh = $body['refresh'] ?? null;

        if ($code === '' || $state === '') {
            return response()->json(['message' => 'Missing code or state'], 400);
        }

        $config = $this->integrationsConfigWithEnvFallback();
        $frontendUrl = $config['frontend_url'] ?? rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $service = new SocialOAuthService($frontendUrl, $config);

        $statePayload = $service->getCachedStatePayload($state);

        $auth = $service->authenticate($identifier, $code, $state, $refresh);
        if (is_string($auth)) {
            return response()->json(['msg' => $auth], 406);
        }

        $id = $auth['id'] ?? '';
        if ($id === '') {
            return response()->json(['msg' => 'Invalid API key'], 406);
        }

        $name = $auth['name'] ?? $auth['username'] ?? '';
        if ($name === '') {
            $name = $auth['username'] ?? 'Channel_'.substr($id, 0, 8);
        }
        $picture = $auth['picture'] ?? '';
        $username = $auth['username'] ?? '';
        $accessToken = $auth['accessToken'] ?? '';
        $refreshToken = $auth['refreshToken'] ?? '';
        $expiresIn = $auth['expiresIn'] ?? 0;
        $additionalSettings = $auth['additionalSettings'] ?? [];
        $customCredentials = $auth['customCredentials'] ?? null;

        $expiresAt = null;
        if ($expiresIn > 0) {
            $expiresAt = now()->addSeconds($expiresIn);
        }

        $payload = ['access_token' => $accessToken];
        if ($customCredentials !== null) {
            $payload['custom_credentials'] = $customCredentials;
        }

        $integration = IntegrationToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $identifier,
                'account_identifier' => $id,
            ],
            [
                'name' => $name,
                'picture' => $picture,
                'profile' => $username,
                'payload' => $payload,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'disabled' => false,
                'in_between_steps' => false,
                'additional_settings' => json_encode($additionalSettings),
                'posting_times' => [['time' => 120], ['time' => 400], ['time' => 700]],
            ]
        );

        $onboarding = is_array($statePayload) && ($statePayload['onboarding'] ?? false);

        return response()->json([
            'id' => $integration->id,
            'inBetweenSteps' => false,
            'onboarding' => $onboarding,
        ], 201);
    }

    /**
     * Integration config. For X (Twitter), we always try direct .env file read first
     * so web requests (Apache/WAMP) get keys even when config/$_ENV are empty.
     */
    private function integrationsConfigWithEnvFallback(): array
    {
        $config = Config::get('postiz.integrations', []);
        $x = $config['x'] ?? [];

        // X: prefer direct .env read so it works under Apache when config/env are empty
        $fromFile = $this->readXApiKeysFromProjectEnv();
        $apiKey = trim((string) ($fromFile['api_key'] !== '' ? $fromFile['api_key'] : ($x['api_key'] ?? $_ENV['X_API_KEY'] ?? env('X_API_KEY', ''))));
        $apiSecret = trim((string) ($fromFile['api_secret'] !== '' ? $fromFile['api_secret'] : ($x['api_secret'] ?? $_ENV['X_API_SECRET'] ?? env('X_API_SECRET', ''))));
        $clientId = trim((string) ($x['client_id'] ?? $_ENV['X_CLIENT_ID'] ?? env('X_CLIENT_ID', $apiKey)));
        $clientSecret = trim((string) ($x['client_secret'] ?? $_ENV['X_CLIENT_SECRET'] ?? env('X_CLIENT_SECRET', $apiSecret)));

        $useOAuth2 = $x['use_oauth2'] ?? true;
        if (isset($_ENV['X_USE_OAUTH2'])) {
            $useOAuth2 = filter_var($_ENV['X_USE_OAUTH2'], FILTER_VALIDATE_BOOLEAN);
        }
        $config['x'] = [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'use_oauth2' => $useOAuth2,
        ];

        return $config;
    }

    /**
     * Read X_API_KEY and X_API_SECRET directly from project root .env (and .env.local).
     * Used as primary source for X so it works under Apache when config/env are empty.
     */
    private function readXApiKeysFromProjectEnv(): array
    {
        $rootPath = realpath(dirname(base_path(), 2)) ?: dirname(base_path(), 2);
        $result = ['api_key' => '', 'api_secret' => ''];
        foreach (['.env', '.env.local'] as $file) {
            $envPath = $rootPath.DIRECTORY_SEPARATOR.$file;
            if (! is_file($envPath) || ! is_readable($envPath)) {
                continue;
            }
            $content = @file_get_contents($envPath);
            if ($content === false) {
                continue;
            }
            foreach (preg_split('/\r?\n/', $content) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^X_API_KEY\s*=\s*(.+)$/i', $line, $m)) {
                    $result['api_key'] = trim(trim($m[1]), "\"'");
                }
                if (preg_match('/^X_API_SECRET\s*=\s*(.+)$/i', $line, $m)) {
                    $result['api_secret'] = trim(trim($m[1]), "\"'");
                }
            }
        }
        return $result;
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
