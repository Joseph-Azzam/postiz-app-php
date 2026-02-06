<?php

namespace App\Services;

use App\Models\IntegrationToken;
use App\Models\ScheduledPost;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Publishes a scheduled post to the connected social provider (X/Twitter, etc.).
 * Called synchronously from ProcessScheduledPosts cron.
 * Supports X (Twitter) OAuth 2.0 bearer token; OAuth 1.0a requires request signing (not implemented).
 */
class PublishToSocialService
{
    /**
     * Publish the post to its integration. Returns null on success or error message string.
     * When the row has integration_id, we publish only to that channel (one row per channel).
     */
    public function publish(ScheduledPost $post): ?string
    {
        $payload = $post->payload ?? [];
        $posts = $payload['posts'] ?? [];
        $postItem = null;
        if ($post->integration_id !== null && $post->integration_id !== '') {
            foreach ($posts as $item) {
                $id = isset($item['integration']['id']) ? (string) $item['integration']['id'] : null;
                if ($id === $post->integration_id) {
                    $postItem = $item;
                    break;
                }
            }
        }
        if ($postItem === null) {
            $postItem = $posts[0] ?? null;
        }
        if (! $postItem || ! isset($postItem['integration']['id'])) {
            return 'Post payload missing integration id';
        }

        $integrationId = (string) $postItem['integration']['id'];
        $token = IntegrationToken::find($integrationId);
        if (! $token || $token->provider === null) {
            return 'Integration not found or disabled';
        }

        $text = $this->extractText($postItem);
        if ($text === '') {
            return 'Post has no text content';
        }

        if ($token->provider === 'x') {
            return $this->postTextToX($token, $text);
        }

        if ($token->provider === 'bluesky') {
            return $this->postToBluesky($token, $text);
        }

        return 'Publishing to '.$token->provider.' is not implemented yet';
    }

    /**
     * Post a test tweet for the given user (first connected X account). Used by /test dashboard "Post on X".
     * Returns null on success, or an error message string.
     */
    public function postTestTweetForUser(int $userId): ?string
    {
        $token = IntegrationToken::where('user_id', $userId)
            ->where('provider', 'x')
            ->where(function ($q) {
                $q->whereNull('disabled')->orWhere('disabled', false);
            })
            ->first();

        if (! $token) {
            return 'No connected X account. Connect an X account in Postiz first (and use OAuth 2.0).';
        }

        return $this->postTextToX($token, 'test');
    }

    private function extractText(array $firstPost): string
    {
        $values = $firstPost['value'] ?? [];
        $firstValue = $values[0] ?? [];
        $content = $firstValue['content'] ?? '';
        if (! is_string($content)) {
            return '';
        }

        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Post text to X (Twitter) API v2. Used by publish() and postTestTweetForUser().
     * Supports OAuth 2.0 bearer token. OAuth 1.0a (token:secret) would need signed requests.
     */
    public function postTextToX(IntegrationToken $token, string $text): ?string
    {
        $payload = $token->payload ?? [];
        $accessToken = $payload['access_token'] ?? '';
        if ($accessToken === '') {
            return 'X integration has no access token';
        }

        // OAuth 1.0a stores "accessToken:accessSecret" – we would need to sign requests (e.g. guzzle/oauth-subscriber).
        if (str_contains($accessToken, ':')) {
            return 'X OAuth 1.0a posting is not implemented. Use X OAuth 2.0 (set X_USE_OAUTH2=true) when connecting the account.';
        }

        $maxLength = 280;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 3).'...';
        }

        $url = 'https://api.twitter.com/2/tweets';
        $body = ['text' => $text];

        $request = Http::withToken($accessToken)
            ->timeout(30)
            ->acceptJson();

        if (Config::get('postiz.ssl_verify', true) === false || (isset($_ENV['SSL_VERIFY']) && ! filter_var($_ENV['SSL_VERIFY'], FILTER_VALIDATE_BOOLEAN))) {
            $request = $request->withOptions(['verify' => false]);
        }

        $response = $request->post($url, $body);

        if (! $response->successful()) {
            $respBody = $response->json();
            $detail = $respBody['detail'] ?? $response->body();

            return 'X API error: '.(\is_string($detail) ? $detail : json_encode($detail));
        }

        return null;
    }

    /**
     * Post text to Bluesky via AT Protocol createRecord (app.bsky.feed.post).
     * Uses access_token (JWT) and service URL from custom_credentials; repo = account_identifier (DID).
     */
    private function postToBluesky(IntegrationToken $token, string $text): ?string
    {
        $payload = $token->payload ?? [];
        $accessToken = $payload['access_token'] ?? '';
        if ($accessToken === '') {
            return 'Bluesky integration has no access token';
        }

        $did = $token->account_identifier ?? '';
        if ($did === '') {
            return 'Bluesky integration has no DID';
        }

        $service = 'https://bsky.social';
        if (! empty($payload['custom_credentials'])) {
            try {
                $decrypted = Crypt::decryptString($payload['custom_credentials']);
                $creds = json_decode($decrypted, true);
                if (is_array($creds) && ! empty($creds['service'])) {
                    $service = rtrim($creds['service'], '/');
                }
            } catch (\Throwable) {
                // keep default bsky.social
            }
        }

        $maxLength = 300;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 3).'...';
        }

        $url = $service.'/xrpc/com.atproto.repo.createRecord';
        $body = [
            'repo' => $did,
            'collection' => 'app.bsky.feed.post',
            'record' => [
                'text' => $text,
                'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ];

        $request = Http::withToken($accessToken)
            ->timeout(30)
            ->acceptJson();

        if (Config::get('postiz.ssl_verify', true) === false || (isset($_ENV['SSL_VERIFY']) && ! filter_var($_ENV['SSL_VERIFY'], FILTER_VALIDATE_BOOLEAN))) {
            $request = $request->withOptions(['verify' => false]);
        }

        $response = $request->post($url, $body);

        if (! $response->successful()) {
            $msg = $response->json('message') ?? $response->body();
            return 'Bluesky API error: '.\Illuminate\Support\Str::limit((string) $msg, 200);
        }

        return null;
    }
}
