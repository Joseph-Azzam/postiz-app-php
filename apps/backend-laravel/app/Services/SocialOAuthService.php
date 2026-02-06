<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Social OAuth: generate auth URL and exchange code for token.
 * Mirrors NestJS IntegrationManager + provider.generateAuthUrl / authenticate.
 * Supports LinkedIn (OAuth2); others return err until configured.
 */
class SocialOAuthService
{
    private const CACHE_PREFIX = 'postiz_social_oauth:';
    private const STATE_TTL = 600; // 10 min

    protected ?string $lastError = null;

    public function __construct(
        protected string $frontendUrl,
        protected array $config,
    ) {}

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Options for outbound HTTP (project-wide SSL verify from config/postiz.php).
     */
    private function httpOptions(): array
    {
        if (Config::get('postiz.ssl_verify', true)) {
            return [];
        }

        return ['verify' => false];
    }

    /**
     * Generate OAuth URL for the given provider. Store state -> codeVerifier in cache.
     * Returns [ 'url' => string, 'codeVerifier' => string, 'state' => string ] or null if not supported.
     */
    public function generateAuthUrl(string $identifier, ?string $refresh = null, bool $onboarding = false, ?string $externalUrl = null): ?array
    {
        $this->lastError = null;
        $state = Str::random(24);
        $payload = [
            'codeVerifier' => Str::random(32),
            'refresh' => $refresh,
            'onboarding' => $onboarding,
            'externalUrl' => $externalUrl,
        ];

        if ($identifier === 'linkedin') {
            $url = $this->linkedInAuthUrl($state);
            if ($url === null) {
                $this->lastError = 'LinkedIn is not configured. Set LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET in your project .env or .env.local (root). If already set, run: php artisan config:clear';

                return null;
            }
            Cache::put(self::CACHE_PREFIX.$state, $payload, self::STATE_TTL);

            return [
                'url' => $url,
                'codeVerifier' => $payload['codeVerifier'],
                'state' => $state,
            ];
        }

        if ($identifier === 'x') {
            $useOAuth2 = $this->config['x']['use_oauth2'] ?? true;
            $result = $useOAuth2 ? $this->xAuthUrlOAuth2() : $this->xAuthUrl();
            if ($result === null) {
                if ($this->lastError === null) {
                    $this->lastError = 'X (Twitter) failed. See message above or check logs.';
                }
                return null;
            }
            $payload['codeVerifier'] = $result['codeVerifier'];
            Cache::put(self::CACHE_PREFIX.$result['state'], $payload, self::STATE_TTL);

            return [
                'url' => $result['url'],
                'codeVerifier' => $result['codeVerifier'],
                'state' => $result['state'],
            ];
        }

        // Bluesky: no OAuth; user enters service, identifier, password in UI. Return empty url so UI shows form (customFields).
        if ($identifier === 'bluesky') {
            return [
                'url' => '',
                'codeVerifier' => $payload['codeVerifier'],
                'state' => $state,
            ];
        }

        // Generic OAuth2: check for client_id in config
        $providerConfig = $this->config[$identifier] ?? null;
        if (is_array($providerConfig) && ! empty($providerConfig['client_id'] ?? '')) {
            $url = $this->genericOAuth2AuthUrl($identifier, $state, $providerConfig);
            if ($url !== null) {
                Cache::put(self::CACHE_PREFIX.$state, $payload, self::STATE_TTL);

                return [
                    'url' => $url,
                    'codeVerifier' => $payload['codeVerifier'],
                    'state' => $state,
                ];
            }
        }

        $this->lastError = sprintf(
            'Channel "%s" is not configured. Add OAuth credentials in your project .env or .env.local (see .env.local.example). If already set, run: php artisan config:clear',
            $identifier
        );

        return null;
    }

    /**
     * Exchange code for token and return auth details (id, name, picture, username, accessToken, ...).
     * Returns array or error string.
     */
    public function authenticate(string $identifier, string $code, string $state, ?string $refresh = null): array|string
    {
        // Bluesky: no OAuth redirect; frontend sends code = base64(JSON{ service, identifier, password }) and state=nostate
        if ($identifier === 'bluesky') {
            return $this->blueskyAuthenticate($code);
        }

        $cached = Cache::get(self::CACHE_PREFIX.$state);
        if (! is_array($cached)) {
            return 'Invalid or expired state. Please try connecting again.';
        }

        $codeVerifier = $cached['codeVerifier'] ?? '';
        Cache::forget(self::CACHE_PREFIX.$state);

        if ($identifier === 'linkedin') {
            return $this->linkedInAuthenticate($code, $codeVerifier, $refresh);
        }

        if ($identifier === 'x') {
            $useOAuth2 = $this->config['x']['use_oauth2'] ?? true;
            return $useOAuth2 ? $this->xAuthenticateOAuth2($code, $codeVerifier, $cached) : $this->xAuthenticate($code, $codeVerifier);
        }

        $providerConfig = $this->config[$identifier] ?? null;
        if (is_array($providerConfig) && ! empty($providerConfig['client_id'] ?? '')) {
            return $this->genericOAuth2Authenticate($identifier, $code, $codeVerifier, $providerConfig);
        }

        return 'This channel is not configured on the server.';
    }

    public function getCachedStatePayload(string $state): ?array
    {
        return Cache::get(self::CACHE_PREFIX.$state);
    }

    private function linkedInAuthUrl(string $state): ?string
    {
        $clientId = $this->config['linkedin']['client_id'] ?? '';
        $scopes = $this->config['linkedin']['scopes'] ?? 'openid profile email w_member_social';
        if ($clientId === '') {
            return null;
        }
        $redirectUri = $this->frontendUrl.'/integrations/social/linkedin';

        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scopes,
            'prompt' => 'none',
        ]);
    }

    private function linkedInAuthenticate(string $code, string $codeVerifier, ?string $refresh): array|string
    {
        $clientId = $this->config['linkedin']['client_id'] ?? '';
        $clientSecret = $this->config['linkedin']['client_secret'] ?? '';
        if ($clientId === '' || $clientSecret === '') {
            return 'LinkedIn is not configured.';
        }

        $redirectUri = $this->frontendUrl.'/integrations/social/linkedin';
        $response = Http::withOptions($this->httpOptions())->asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (! $response->successful()) {
            return 'Failed to get access token from LinkedIn.';
        }

        $body = $response->json();
        $accessToken = $body['access_token'] ?? '';
        $expiresIn = (int) ($body['expires_in'] ?? 0);
        $refreshToken = $body['refresh_token'] ?? '';

        if ($accessToken === '') {
            return 'Invalid response from LinkedIn.';
        }

        $userInfo = Http::withOptions($this->httpOptions())->withToken($accessToken)->get('https://api.linkedin.com/v2/userinfo');
        if (! $userInfo->successful()) {
            return 'Failed to get user info from LinkedIn.';
        }

        $user = $userInfo->json();
        $id = (string) ($user['sub'] ?? '');
        $name = $user['name'] ?? '';
        $picture = $user['picture'] ?? '';

        $me = Http::withOptions($this->httpOptions())->withToken($accessToken)->get('https://api.linkedin.com/v2/me');
        $username = '';
        if ($me->successful()) {
            $vanity = $me->json('vanityName');
            $username = is_string($vanity) ? $vanity : '';
        }

        return [
            'id' => $id,
            'name' => $name,
            'picture' => $picture,
            'username' => $username,
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresIn' => $expiresIn,
            'additionalSettings' => [],
        ];
    }

    /**
     * X (Twitter) OAuth 1.0a: generate auth link. Requires X_API_KEY and X_API_SECRET.
     * Uses 3-legged flow: request token -> redirect user -> exchange for access token.
     * Returns state = oauth_token (request token) so frontend callback can send it back.
     */
    private function xAuthUrl(): ?array
    {
        $apiKey = trim((string) ($this->config['x']['api_key'] ?? ''));
        $apiSecret = trim((string) ($this->config['x']['api_secret'] ?? ''));
        $keyLen = strlen($apiKey);
        $secretLen = strlen($apiSecret);

        if ($apiKey === '' || $apiSecret === '') {
            $this->lastError = sprintf(
                'X (Twitter) keys missing or empty. X_API_KEY: %s (length %d), X_API_SECRET: %s (length %d). Set both in project root .env or .env.local, then run: php artisan config:clear',
                $apiKey === '' ? 'missing' : 'present',
                $keyLen,
                $apiSecret === '' ? 'missing' : 'present',
                $secretLen
            );
            \Illuminate\Support\Facades\Log::channel('copilot')->info('X (Twitter) config check', [
                'api_key_present' => $apiKey !== '',
                'api_key_length' => $keyLen,
                'api_secret_present' => $apiSecret !== '',
                'api_secret_length' => $secretLen,
            ]);

            return null;
        }

        $callback = $this->frontendUrl.'/integrations/social/x';
        $requestUrl = 'https://api.twitter.com/oauth/request_token';
        $params = [
            'oauth_callback' => $callback,
            'oauth_consumer_key' => $apiKey,
            'oauth_nonce' => Str::random(32),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_version' => '1.0',
        ];
        $params['oauth_signature'] = $this->oauth1Signature('POST', $requestUrl, $params, $apiSecret, null);
        $authHeader = 'OAuth '.implode(', ', array_map(fn ($k, $v) => rawurlencode($k).'="'.rawurlencode($v).'"', array_keys($params), $params));

        try {
            $response = Http::withOptions($this->httpOptions())->withHeaders(['Authorization' => $authHeader])->post($requestUrl);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->lastError = sprintf(
                'X (Twitter) connection error: %s. Keys read: X_API_KEY length %d, X_API_SECRET length %d. If using WAMP/XAMPP, set SSL_VERIFY=false in .env.local and run config:clear.',
                \Illuminate\Support\Str::limit($msg, 150),
                $keyLen,
                $secretLen
            );
            \Illuminate\Support\Facades\Log::channel('copilot')->warning('X (Twitter) request_token connection failed', ['message' => $msg]);

            return null;
        }

        if (! $response->successful()) {
            $body = \Illuminate\Support\Str::limit($response->body(), 300);
            $this->lastError = sprintf(
                'X (Twitter) API request_token failed. HTTP %d. Response: %s. Keys read: X_API_KEY length %d, X_API_SECRET length %d. Check callback URL (%s), app permissions (OAuth 1.0a), and that keys are Consumer Key + Consumer Secret.',
                $response->status(),
                $body,
                $keyLen,
                $secretLen,
                $callback
            );
            \Illuminate\Support\Facades\Log::channel('copilot')->warning('X (Twitter) request_token failed', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            return null;
        }

        parse_str($response->body(), $params);
        $oauthToken = $params['oauth_token'] ?? '';
        $oauthTokenSecret = $params['oauth_token_secret'] ?? '';
        if ($oauthToken === '' || $oauthTokenSecret === '') {
            $this->lastError = sprintf(
                'X (Twitter) API returned unexpected response (missing oauth_token or oauth_token_secret). Keys read: X_API_KEY length %d, X_API_SECRET length %d. Raw body (first 200 chars): %s',
                $keyLen,
                $secretLen,
                \Illuminate\Support\Str::limit($response->body(), 200)
            );

            return null;
        }

        $authUrl = 'https://api.twitter.com/oauth/authenticate?'.http_build_query(['oauth_token' => $oauthToken]);
        $codeVerifier = $oauthToken.':'.$oauthTokenSecret;

        return [
            'url' => $authUrl,
            'codeVerifier' => $codeVerifier,
            'state' => $oauthToken,
        ];
    }

    /**
     * X (Twitter) OAuth 2.0: generate authorize URL with PKCE.
     * Uses Client ID (X_API_KEY) and Client Secret (X_API_SECRET) from developer portal OAuth 2.0 settings.
     * Returns [ 'url' => string, 'codeVerifier' => string, 'state' => string ] or null.
     */
    private function xAuthUrlOAuth2(): ?array
    {
        $clientId = trim((string) ($this->config['x']['client_id'] ?? $this->config['x']['api_key'] ?? ''));
        if ($clientId === '') {
            $this->lastError = sprintf(
                'X (Twitter) OAuth 2.0: Client ID missing. Set X_API_KEY (or X_CLIENT_ID) in project root .env or .env.local, then run: php artisan config:clear'
            );
            return null;
        }

        $state = Str::random(24);
        $codeVerifier = Str::random(32);
        $codeChallenge = strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_');
        $redirectUri = $this->frontendUrl.'/integrations/social/x';
        $scopes = 'tweet.read users.read tweet.write offline.access';
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        $url = 'https://x.com/i/oauth2/authorize?'.http_build_query($params);

        return [
            'url' => $url,
            'codeVerifier' => $codeVerifier,
            'state' => $state,
        ];
    }

    /**
     * X (Twitter) OAuth 2.0: exchange authorization code for access token and fetch user (GET /2/users/me).
     */
    private function xAuthenticateOAuth2(string $code, string $codeVerifier, array $cached): array|string
    {
        $clientId = trim((string) ($this->config['x']['client_id'] ?? $this->config['x']['api_key'] ?? ''));
        $clientSecret = trim((string) ($this->config['x']['client_secret'] ?? $this->config['x']['api_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return 'X (Twitter) OAuth 2.0: Client ID or Client Secret missing. Set X_API_KEY and X_API_SECRET (or X_CLIENT_ID and X_CLIENT_SECRET) in project root .env or .env.local.';
        }

        $redirectUri = $this->frontendUrl.'/integrations/social/x';
        $tokenUrl = 'https://api.x.com/2/oauth2/token';
        $basicAuth = base64_encode($clientId.':'.$clientSecret);

        try {
            $response = Http::withOptions($this->httpOptions())
                ->withHeaders(['Authorization' => 'Basic '.$basicAuth])
                ->asForm()
                ->post($tokenUrl, [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (\Throwable $e) {
            return sprintf(
                'X (Twitter) OAuth 2.0 connection error: %s. If using WAMP/XAMPP, set SSL_VERIFY=false in .env.local.',
                \Illuminate\Support\Str::limit($e->getMessage(), 120)
            );
        }

        if (! $response->successful()) {
            return sprintf(
                'X (Twitter) OAuth 2.0 token failed. HTTP %d. Response: %s. Check callback URL (%s) and Client ID/Secret.',
                $response->status(),
                \Illuminate\Support\Str::limit($response->body(), 300),
                $redirectUri
            );
        }

        $body = $response->json();
        $accessToken = $body['access_token'] ?? '';
        $refreshToken = $body['refresh_token'] ?? '';
        $expiresIn = (int) ($body['expires_in'] ?? 0);
        if ($accessToken === '') {
            return 'X (Twitter) OAuth 2.0: invalid token response (no access_token).';
        }

        $userResponse = Http::withOptions($this->httpOptions())
            ->withToken($accessToken)
            ->get('https://api.x.com/2/users/me', ['user.fields' => 'id,name,username,profile_image_url']);
        if (! $userResponse->successful()) {
            return sprintf(
                'X (Twitter) OAuth 2.0 users/me failed. HTTP %d. Response: %s',
                $userResponse->status(),
                \Illuminate\Support\Str::limit($userResponse->body(), 200)
            );
        }

        $user = $userResponse->json('data');
        if (! is_array($user)) {
            return 'X (Twitter) OAuth 2.0: users/me returned no user data.';
        }
        $id = (string) ($user['id'] ?? '');
        $name = (string) ($user['name'] ?? '');
        $username = (string) ($user['username'] ?? '');
        $picture = (string) ($user['profile_image_url'] ?? '');
        if ($id === '') {
            return 'X (Twitter) OAuth 2.0: user id missing.';
        }

        return [
            'id' => $id,
            'name' => $name,
            'picture' => $picture,
            'username' => $username,
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresIn' => $expiresIn > 0 ? $expiresIn : 7200,
            'additionalSettings' => [],
        ];
    }

    private function oauth1Signature(string $method, string $url, array $params, string $consumerSecret, ?string $tokenSecret = null): string
    {
        ksort($params);
        $base = $method.'&'.rawurlencode($url).'&'.rawurlencode(http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $key = rawurlencode($consumerSecret).'&'.rawurlencode((string) $tokenSecret);

        return base64_encode(hash_hmac('sha1', $base, $key, true));
    }

    /**
     * X (Twitter): exchange oauth_verifier for access token. Frontend sends state=oauth_token, code=oauth_verifier.
     */
    private function xAuthenticate(string $code, string $codeVerifier): array|string
    {
        $apiKey = trim((string) ($this->config['x']['api_key'] ?? ''));
        $apiSecret = trim((string) ($this->config['x']['api_secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            return sprintf(
                'X (Twitter) keys missing on connect. X_API_KEY: %s (length %d), X_API_SECRET: %s (length %d). Set both in project root .env or .env.local.',
                $apiKey === '' ? 'missing' : 'present',
                strlen($apiKey),
                $apiSecret === '' ? 'missing' : 'present',
                strlen($apiSecret)
            );
        }

        $parts = explode(':', $codeVerifier, 2);
        $oauthToken = $parts[0] ?? '';
        $oauthTokenSecret = $parts[1] ?? '';
        if ($oauthToken === '' || $oauthTokenSecret === '') {
            return 'Invalid state for X.';
        }

        $requestUrl = 'https://api.twitter.com/oauth/access_token';
        $params = [
            'oauth_consumer_key' => $apiKey,
            'oauth_nonce' => Str::random(32),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $oauthToken,
            'oauth_verifier' => $code,
            'oauth_version' => '1.0',
        ];
        $params['oauth_signature'] = $this->oauth1Signature('POST', $requestUrl, $params, $apiSecret, $oauthTokenSecret);
        $authHeader = 'OAuth '.implode(', ', array_map(fn ($k, $v) => rawurlencode($k).'="'.rawurlencode((string) $v).'"', array_keys($params), $params));

        try {
            $response = Http::withOptions($this->httpOptions())->withHeaders(['Authorization' => $authHeader])->asForm()->post($requestUrl, [
                'oauth_verifier' => $code,
            ]);
        } catch (\Throwable $e) {
            return sprintf('X (Twitter) connection error on access_token: %s. If using WAMP/XAMPP, set SSL_VERIFY=false in .env.local.', \Illuminate\Support\Str::limit($e->getMessage(), 120));
        }

        if (! $response->successful()) {
            return sprintf('X (Twitter) access_token failed. HTTP %d. Response: %s', $response->status(), \Illuminate\Support\Str::limit($response->body(), 200));
        }

        parse_str($response->body(), $params);
        $accessToken = $params['oauth_token'] ?? '';
        $accessSecret = $params['oauth_token_secret'] ?? '';
        $userId = $params['user_id'] ?? '';
        $username = $params['screen_name'] ?? '';

        if ($accessToken === '' || $accessSecret === '' || $userId === '') {
            return 'Invalid response from X.';
        }

        $tokenForStorage = $accessToken.':'.$accessSecret;

        return [
            'id' => (string) $userId,
            'name' => $username,
            'picture' => '',
            'username' => $username,
            'accessToken' => $tokenForStorage,
            'refreshToken' => '',
            'expiresIn' => 999999999,
            'additionalSettings' => [],
        ];
    }

    private function genericOAuth2AuthUrl(string $identifier, string $state, array $providerConfig): ?string
    {
        $clientId = $providerConfig['client_id'] ?? '';
        $scopes = $providerConfig['scopes'] ?? 'openid profile';
        $authUrl = $providerConfig['authorize_url'] ?? null;
        if ($clientId === '' || $authUrl === '') {
            return null;
        }
        $redirectUri = $this->frontendUrl.'/integrations/social/'.$identifier;

        return $authUrl.(str_contains($authUrl, '?') ? '&' : '?').http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scopes,
        ]);
    }

    private function genericOAuth2Authenticate(string $identifier, string $code, string $codeVerifier, array $providerConfig): array|string
    {
        $clientId = $providerConfig['client_id'] ?? '';
        $clientSecret = $providerConfig['client_secret'] ?? '';
        $tokenUrl = $providerConfig['token_url'] ?? null;
        if ($clientId === '' || $clientSecret === '' || $tokenUrl === '') {
            return 'Provider not fully configured.';
        }

        $redirectUri = $this->frontendUrl.'/integrations/social/'.$identifier;
        $response = Http::withOptions($this->httpOptions())->asForm()->post($tokenUrl, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (! $response->successful()) {
            return 'Failed to get access token.';
        }

        $body = $response->json();
        $accessToken = $body['access_token'] ?? '';
        if ($accessToken === '') {
            return 'Invalid token response.';
        }

        $userInfoUrl = $providerConfig['userinfo_url'] ?? null;
        $id = '';
        $name = '';
        $picture = '';
        $username = '';
        if ($userInfoUrl !== null && $userInfoUrl !== '') {
            $userInfo = Http::withOptions($this->httpOptions())->withToken($accessToken)->get($userInfoUrl);
            if ($userInfo->successful()) {
                $user = $userInfo->json();
                $id = (string) ($user['id'] ?? $user['sub'] ?? '');
                $name = $user['name'] ?? '';
                $picture = $user['picture'] ?? $user['avatar_url'] ?? '';
                $username = $user['login'] ?? $user['username'] ?? '';
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'picture' => $picture,
            'username' => $username,
            'accessToken' => $accessToken,
            'refreshToken' => $body['refresh_token'] ?? '',
            'expiresIn' => (int) ($body['expires_in'] ?? 0),
            'additionalSettings' => [],
        ];
    }

    /**
     * Bluesky: code is base64(JSON{ service, identifier, password }). No OAuth; createSession via AT Protocol.
     */
    private function blueskyAuthenticate(string $code): array|string
    {
        $decoded = base64_decode($code, true);
        if ($decoded === false) {
            return 'Invalid Bluesky credentials format.';
        }
        $body = json_decode($decoded, true);
        if (! is_array($body) || empty($body['identifier'] ?? '') || empty($body['password'] ?? '')) {
            return 'Invalid credentials.';
        }
        $service = rtrim((string) ($body['service'] ?? 'https://bsky.social'), '/');
        $identifier = $body['identifier'];
        $password = $body['password'];

        $sessionUrl = $service.'/xrpc/com.atproto.server.createSession';
        $response = Http::withOptions($this->httpOptions())
            ->timeout(30)
            ->acceptJson()
            ->post($sessionUrl, [
                'identifier' => $identifier,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            $msg = $response->json('message') ?? $response->body();
            return 'Invalid credentials. '.Str::limit((string) $msg, 120);
        }

        $data = $response->json();
        $accessJwt = $data['accessJwt'] ?? '';
        $refreshJwt = $data['refreshJwt'] ?? '';
        $handle = $data['handle'] ?? '';
        $did = $data['did'] ?? '';
        if ($accessJwt === '' || $did === '') {
            return 'Invalid Bluesky response.';
        }

        // Get profile for display name and avatar
        $profileUrl = $service.'/xrpc/com.atproto.actor.getProfile?actor='.urlencode($did);
        $profileResponse = Http::withOptions($this->httpOptions())
            ->timeout(15)
            ->withToken($accessJwt)
            ->get($profileUrl);
        $displayName = $handle;
        $avatar = '';
        if ($profileResponse->successful()) {
            $profile = $profileResponse->json();
            $displayName = $profile['displayName'] ?? $handle;
            $avatar = $profile['avatar'] ?? '';
        }

        $credentials = [
            'service' => $service,
            'identifier' => $identifier,
            'password' => $password,
        ];
        $encryptedCredentials = \Illuminate\Support\Facades\Crypt::encryptString(json_encode($credentials));

        return [
            'id' => $did,
            'name' => $displayName,
            'picture' => $avatar,
            'username' => $handle,
            'accessToken' => $accessJwt,
            'refreshToken' => $refreshJwt,
            'expiresIn' => 100 * 365 * 24 * 3600, // long-lived
            'additionalSettings' => [],
            'customCredentials' => $encryptedCredentials,
        ];
    }
}
