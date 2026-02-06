<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Central test runner for Postiz backend: DB, app key, Copilot config, AI (OpenAI/Gemini), social OAuth.
 * Used by the Artisan command (postiz:test) and the web test dashboard (/test).
 * All test logic lives here; CLI and web only format and display results.
 */
class PostizTestRunner
{
    /**
     * Run a test suite. Returns structured results for display.
     *
     * @param  string  $suite  'full' | 'gemini' | 'db' | 'social'
     * @param  bool  $noAi  When true, skip live OpenAI/Gemini API calls (full suite only).
     * @param  bool  $testImage  When true and suite is gemini, call Imagen API to generate one image (off by default).
     * @param  array{x?: bool, linkedin?: bool, bluesky?: bool}  $postOnPlatform  When true for a key, post a test post to that platform (requires logged-in user). Off by default.
     * @param  int|null  $userId  Logged-in user id; required for "post on platform" tests.
     * @return array{results: array<int, array{name: string, pass: bool|null, message: string, model?: string|null, prompt?: string|null, error_parsed?: array|null}>, summary: array{passed: int, failed: int, skipped: int}}
     */
    public function run(string $suite, bool $noAi = false, bool $testImage = false, array $postOnPlatform = [], ?int $userId = null): array
    {
        $results = [];
        $suite = strtolower($suite);

        if ($suite === 'full' || $suite === 'db') {
            $results[] = $this->runDatabase();
            $results[] = $this->runAppKey();
            $results[] = $this->runCopilotConfig();
        }

        if ($suite === 'full' && ! $noAi) {
            $results[] = $this->runOpenAi();
            $results[] = $this->runGeminiSimple();
        } elseif ($suite === 'full' && $noAi) {
            $results[] = ['name' => 'AI (OpenAI)', 'pass' => null, 'message' => 'Skipped (no AI).'];
            $results[] = ['name' => 'AI (Gemini)', 'pass' => null, 'message' => 'Skipped (no AI).'];
        }

        if ($suite === 'gemini') {
            $results = array_merge($results, $this->runGeminiDetailed($testImage));
        }

        if ($suite === 'full' || $suite === 'social') {
            $results[] = $this->runSocialProvider('linkedin');
            $results[] = $this->runSocialProvider('x');
            $results[] = $this->runSocialProvider('bluesky');

            if (! empty($postOnPlatform)) {
                $results = array_merge($results, $this->runSocialPostOnPlatform($postOnPlatform, $userId));
            }
        }

        $passed = (int) collect($results)->whereStrict('pass', true)->count();
        $failed = (int) collect($results)->whereStrict('pass', false)->count();
        $skipped = (int) collect($results)->filter(fn ($r) => ($r['pass'] ?? null) === null)->count();

        return [
            'results' => $results,
            'summary' => ['passed' => $passed, 'failed' => $failed, 'skipped' => $skipped],
        ];
    }

    public function runDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            $name = DB::connection()->getDatabaseName();

            return ['name' => 'Database connection', 'pass' => true, 'message' => $name];
        } catch (\Throwable $e) {
            return ['name' => 'Database connection', 'pass' => false, 'message' => $e->getMessage()];
        }
    }

    public function runAppKey(): array
    {
        $key = Config::get('app.key');
        if (empty($key) || $key === 'base64:') {
            return ['name' => 'Application key', 'pass' => false, 'message' => 'APP_KEY is empty. Set it in .env (e.g. php artisan key:generate).'];
        }

        return ['name' => 'Application key', 'pass' => true, 'message' => 'Set'];
    }

    public function runCopilotConfig(): array
    {
        $copilot = Config::get('postiz.copilot', []);
        if (! is_array($copilot)) {
            return ['name' => 'Copilot config loaded', 'pass' => false, 'message' => 'postiz.copilot config missing or not array'];
        }
        $provider = $copilot['provider'] ?? 'openai';
        $openaiKey = $copilot['openai_api_key'] ?? '';
        $geminiKey = $copilot['gemini_api_key'] ?? '';
        $msg = "provider={$provider}, OpenAI key=".(strlen($openaiKey) > 0 ? 'set' : 'empty').', Gemini key='.(strlen($geminiKey) > 0 ? 'set' : 'empty');
        if ($provider === 'gemini' && strlen($geminiKey) > 0) {
            $msg .= '; ai='.($copilot['ai_model'] ?? $copilot['gemini_model'] ?? '?').', writer='.($copilot['writer_model'] ?? $copilot['gemini_model'] ?? '?').', image='.($copilot['image_model'] ?? '?');
        }

        return ['name' => 'Copilot config loaded', 'pass' => true, 'message' => $msg];
    }

    public function runOpenAi(): array
    {
        $copilot = Config::get('postiz.copilot', []);
        $key = trim((string) ($copilot['openai_api_key'] ?? ''));
        $model = $copilot['openai_model'] ?? 'gpt-4o-mini';
        if ($key === '') {
            return ['name' => 'AI (OpenAI) – connect and get response', 'pass' => true, 'message' => 'OpenAI key not set (skipped). Set OPENAI_API_KEY to test live.'];
        }

        $service = new CopilotKitAgentService('openai', $key, $model, '', '');
        $variables = [
            'data' => [
                'messages' => [
                    ['textMessage' => ['content' => 'Reply with exactly: OK', 'role' => 'user']],
                ],
                'threadId' => 'test-'.uniqid(),
                'runId' => 'test-'.uniqid(),
            ],
        ];
        $out = $service->generateCopilotResponse($variables, null);
        $content = $out['generateCopilotResponse']['messages'][0]['content'] ?? '';
        if ($content === '') {
            return ['name' => 'AI (OpenAI) – connect and get response', 'pass' => false, 'message' => 'Empty response from OpenAI'];
        }
        if (str_contains($content, 'not configured')) {
            return ['name' => 'AI (OpenAI) – connect and get response', 'pass' => false, 'message' => $content];
        }
        if (str_contains($content, "couldn't generate a response")) {
            return ['name' => 'AI (OpenAI) – connect and get response', 'pass' => false, 'message' => $this->formatAiFailureMessage($service->getLastError())];
        }

        return ['name' => 'AI (OpenAI) – connect and get response', 'pass' => true, 'message' => 'Got reply: '.mb_substr($content, 0, 60).(strlen($content) > 60 ? '…' : '')];
    }

    /**
     * Single Gemini result for CLI / full suite (one line).
     */
    public function runGeminiSimple(): array
    {
        $copilot = Config::get('postiz.copilot', []);
        $key = trim((string) ($copilot['gemini_api_key'] ?? ''));
        $model = $copilot['ai_model'] ?? $copilot['gemini_model'] ?? 'gemini-2.5-flash';
        if ($key === '') {
            return ['name' => 'AI (Gemini) – connect and get response', 'pass' => true, 'message' => 'Gemini key not set (skipped). Set GEMINI_API_KEY to test live.'];
        }

        $service = new CopilotKitAgentService('gemini', '', '', $key, $model, 0);
        $variables = [
            'data' => [
                'messages' => [
                    ['textMessage' => ['content' => 'Reply with exactly: OK', 'role' => 'user']],
                ],
                'threadId' => 'test-'.uniqid(),
                'runId' => 'test-'.uniqid(),
            ],
        ];
        $out = $service->generateCopilotResponse($variables, null);
        $content = $out['generateCopilotResponse']['messages'][0]['content'] ?? '';
        if ($content === '') {
            return ['name' => 'AI (Gemini) – connect and get response', 'pass' => false, 'message' => 'Empty response from Gemini'];
        }
        if (str_contains($content, 'not configured')) {
            return ['name' => 'AI (Gemini) – connect and get response', 'pass' => false, 'message' => $content];
        }
        if (str_contains($content, "couldn't generate a response")) {
            return ['name' => 'AI (Gemini) – connect and get response', 'pass' => false, 'message' => $this->formatAiFailureMessage($service->getLastError())];
        }

        return ['name' => 'AI (Gemini) – connect and get response', 'pass' => true, 'message' => 'Got reply: '.mb_substr($content, 0, 60).(strlen($content) > 60 ? '…' : '')];
    }

    /**
     * Detailed Gemini results for web UI: config + text (ai_model) + writer (writer_model) + image (config only, or live generate when $includeImageGeneration).
     *
     * @param  bool  $includeImageGeneration  When true, call Imagen API to generate one test image (off by default).
     * @return list<array{name: string, pass: bool|null, message: string, model?: string|null, prompt?: string|null, error_parsed?: array|null}>
     */
    public function runGeminiDetailed(bool $includeImageGeneration = false): array
    {
        $results = [];
        $copilot = Config::get('postiz.copilot', []);
        $geminiKey = trim((string) ($copilot['gemini_api_key'] ?? ''));
        $aiModel = $copilot['ai_model'] ?? $copilot['gemini_model'] ?? '—';
        $writerModel = $copilot['writer_model'] ?? $copilot['gemini_model'] ?? '—';
        $imageModel = $copilot['image_model'] ?? '—';

        $results[] = [
            'name' => 'Gemini config',
            'pass' => $geminiKey !== '',
            'message' => $geminiKey !== ''
                ? 'Key set; ai='.$aiModel.', writer='.$writerModel.', image='.$imageModel
                : 'GEMINI_API_KEY not set. Set it in .env.',
            'model' => null,
            'prompt' => null,
            'error_parsed' => null,
        ];

        if ($geminiKey === '') {
            $results[] = ['name' => 'Text (chat)', 'pass' => null, 'message' => 'Skipped (no key).', 'model' => $aiModel, 'prompt' => null, 'error_parsed' => null];
            $results[] = ['name' => 'Writer (drafts)', 'pass' => null, 'message' => 'Skipped (no key).', 'model' => $writerModel, 'prompt' => null, 'error_parsed' => null];
            $results[] = [
                'name' => 'Image',
                'pass' => null,
                'message' => $includeImageGeneration
                    ? 'Skipped (no key). Enable image test when GEMINI_API_KEY is set.'
                    : 'Image model configured: '.$imageModel.'. Enable "Test image generation" to call Imagen API.',
                'model' => $imageModel,
                'prompt' => null,
                'error_parsed' => null,
            ];

            return $results;
        }

        $textPrompt = 'Reply with exactly: OK';
        $textService = new CopilotKitAgentService('gemini', '', '', $geminiKey, $aiModel, 0);
        $textVariables = [
            'data' => [
                'messages' => [
                    ['textMessage' => ['content' => $textPrompt, 'role' => 'user']],
                ],
                'threadId' => 'test-'.uniqid(),
                'runId' => 'test-'.uniqid(),
            ],
        ];
        $textOut = $textService->generateCopilotResponse($textVariables, null);
        $textContent = $textOut['generateCopilotResponse']['messages'][0]['content'] ?? '';
        $textPass = false;
        $textMsg = '';
        $textParsed = null;
        if ($textContent !== '' && ! str_contains($textContent, 'not configured') && ! str_contains($textContent, "couldn't generate a response")) {
            $textPass = true;
            $textMsg = 'Got reply: '.mb_substr($textContent, 0, 100).(mb_strlen($textContent) > 100 ? '…' : '');
        } else {
            $rawErr = $textService->getLastError() ?? 'Empty or generic failure.';
            $textParsed = $this->parseGemini429($rawErr);
            $textMsg = $rawErr;
            if (($textParsed['suggestion'] ?? '') !== '') {
                $textMsg .= ' — '.$textParsed['suggestion'];
            }
        }
        $results[] = [
            'name' => 'Text (chat)',
            'pass' => $textPass,
            'message' => $textMsg,
            'model' => $aiModel,
            'prompt' => $textPrompt,
            'error_parsed' => $textParsed,
        ];

        $writerPrompt = 'Say only: OK';
        $writerUrl = 'https://generativelanguage.googleapis.com/v1beta/models/'.urlencode($writerModel).':generateContent?key='.urlencode($geminiKey);
        $writerBody = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $writerPrompt]]]],
            'generationConfig' => ['maxOutputTokens' => 64],
        ];
        $sslVerify = Config::get('postiz.ssl_verify', true);
        $writerReq = Http::timeout(30);
        if (! $sslVerify) {
            $writerReq = $writerReq->withOptions(['verify' => false]);
        }
        $writerResponse = $writerReq->post($writerUrl, $writerBody);
        $writerPass = false;
        $writerMsg = '';
        $writerParsed = null;
        if ($writerResponse->successful()) {
            $data = $writerResponse->json();
            $writerText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (is_string($writerText) && trim($writerText) !== '') {
                $writerPass = true;
                $writerMsg = 'Got reply: '.mb_substr(trim($writerText), 0, 80).(mb_strlen(trim($writerText)) > 80 ? '…' : '');
            } else {
                $writerMsg = 'Unexpected response: no text in candidates.';
            }
        } else {
            $rawErr = 'HTTP '.$writerResponse->status().': '.($writerResponse->json()['error']['message'] ?? $writerResponse->body());
            $writerParsed = $this->parseGemini429($rawErr);
            $writerMsg = $rawErr;
            if (($writerParsed['suggestion'] ?? '') !== '') {
                $writerMsg .= ' — '.$writerParsed['suggestion'];
            }
        }
        $results[] = [
            'name' => 'Writer (drafts)',
            'pass' => $writerPass,
            'message' => $writerMsg,
            'model' => $writerModel,
            'prompt' => $writerPrompt,
            'error_parsed' => $writerParsed,
        ];

        if ($includeImageGeneration) {
            $results[] = $this->runImagenGenerate($geminiKey, $imageModel);
        } else {
            $results[] = [
                'name' => 'Image',
                'pass' => null,
                'message' => 'Image model configured: '.$imageModel.'. Enable "Test image generation" to call Imagen API.',
                'model' => $imageModel,
                'prompt' => null,
                'error_parsed' => null,
            ];
        }

        return $results;
    }

    /**
     * Call Imagen :predict API to generate one image. Used when test dashboard has "Test image generation" enabled.
     *
     * @return array{name: string, pass: bool|null, message: string, model?: string, prompt?: string, error_parsed?: array|null}
     */
    protected function runImagenGenerate(string $geminiKey, string $imageModel): array
    {
        $prompt = 'A simple red circle on a white background';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.urlencode($imageModel).':predict';
        $body = [
            'instances' => [['prompt' => $prompt]],
            'parameters' => ['sampleCount' => 1],
        ];
        $sslVerify = Config::get('postiz.ssl_verify', true);
        $request = Http::withHeaders(['x-goog-api-key' => $geminiKey])
            ->timeout(60);
        if (! $sslVerify) {
            $request = $request->withOptions(['verify' => false]);
        }
        $response = $request->post($url, $body);

        if ($response->successful()) {
            $data = $response->json();
            $predictions = $data['predictions'] ?? $data['generatedImages'] ?? null;
            $hasImage = false;
            if (is_array($predictions) && count($predictions) > 0) {
                $first = $predictions[0];
                if (is_array($first)) {
                    $hasImage = isset($first['bytesBase64Encoded']) || isset($first['imageBytes'])
                        || (isset($first['image']) && is_array($first['image']) && isset($first['image']['imageBytes']));
                }
            }
            if ($hasImage) {
                return [
                    'name' => 'Image (generate)',
                    'pass' => true,
                    'message' => 'Generated 1 image successfully.',
                    'model' => $imageModel,
                    'prompt' => $prompt,
                    'error_parsed' => null,
                ];
            }
            return [
                'name' => 'Image (generate)',
                'pass' => false,
                'message' => 'Unexpected response: no image data in predictions. '.json_encode(array_keys($data)),
                'model' => $imageModel,
                'prompt' => $prompt,
                'error_parsed' => null,
            ];
        }

        $rawErr = 'HTTP '.$response->status().': '.($response->json()['error']['message'] ?? $response->body());
        $parsed = $this->parseGemini429($rawErr);

        return [
            'name' => 'Image (generate)',
            'pass' => false,
            'message' => $rawErr,
            'model' => $imageModel,
            'prompt' => $prompt,
            'error_parsed' => $parsed,
        ];
    }

    public function runSocialProvider(string $identifier): array
    {
        $config = Config::get('postiz.integrations', []);
        $frontendUrl = rtrim((string) ($config['frontend_url'] ?? env('FRONTEND_URL', 'http://localhost:4200')), '/');
        $service = new SocialOAuthService($frontendUrl, $config);
        $result = $service->generateAuthUrl($identifier);
        if ($result !== null && is_array($result)) {
            $hasUrl = ! empty($result['url']) || array_key_exists('state', $result);

            return ['name' => 'Social: '.$this->socialLabel($identifier), 'pass' => true, 'message' => $hasUrl ? 'Auth URL/state generated' : 'Form flow (e.g. Bluesky) ready'];
        }
        $err = $service->getLastError();
        if ($err !== null && $err !== '') {
            return ['name' => 'Social: '.$this->socialLabel($identifier), 'pass' => true, 'message' => 'Not configured: '.$err];
        }

        return ['name' => 'Social: '.$this->socialLabel($identifier), 'pass' => false, 'message' => 'generateAuthUrl returned null and no lastError'];
    }

    /**
     * Run optional "post on platform" tests (test tweet/post). Requires logged-in user.
     *
     * @param  array{x?: bool, linkedin?: bool, bluesky?: bool}  $postOnPlatform
     * @return list<array{name: string, pass: bool|null, message: string, model?: null, prompt?: null, error_parsed?: null}>
     */
    protected function runSocialPostOnPlatform(array $postOnPlatform, ?int $userId): array
    {
        $results = [];

        if (! empty($postOnPlatform['x'])) {
            $results[] = $this->runSocialPostOnX($userId);
        }
        if (! empty($postOnPlatform['linkedin'])) {
            $results[] = [
                'name' => 'Post on LinkedIn',
                'pass' => null,
                'message' => 'Not implemented yet. Enable "Post on X" to test Twitter.',
                'model' => null,
                'prompt' => null,
                'error_parsed' => null,
            ];
        }
        if (! empty($postOnPlatform['bluesky'])) {
            $results[] = [
                'name' => 'Post on Bluesky',
                'pass' => null,
                'message' => 'Not implemented yet. Enable "Post on X" to test Twitter.',
                'model' => null,
                'prompt' => null,
                'error_parsed' => null,
            ];
        }

        return $results;
    }

    /**
     * Post a test tweet ("test") to the user's first connected X account.
     */
    protected function runSocialPostOnX(?int $userId): array
    {
        if ($userId === null) {
            return [
                'name' => 'Post on X (test tweet)',
                'pass' => null,
                'message' => 'Skipped: log in to Postiz and open /test with the same browser so your X account is used.',
                'model' => null,
                'prompt' => null,
                'error_parsed' => null,
            ];
        }

        $publish = new PublishToSocialService;
        $err = $publish->postTestTweetForUser($userId);
        if ($err === null) {
            return [
                'name' => 'Post on X (test tweet)',
                'pass' => true,
                'message' => 'Posted "test" to your connected X account.',
                'model' => null,
                'prompt' => 'test',
                'error_parsed' => null,
            ];
        }

        return [
            'name' => 'Post on X (test tweet)',
            'pass' => false,
            'message' => $err,
            'model' => null,
            'prompt' => 'test',
            'error_parsed' => null,
        ];
    }

    private function socialLabel(string $id): string
    {
        return match ($id) {
            'linkedin' => 'LinkedIn auth URL',
            'x' => 'X (Twitter) auth URL',
            'bluesky' => 'Bluesky (form flow)',
            default => $id,
        };
    }

    /**
     * Parse Gemini 429/quota error text into summary and suggestion.
     *
     * @return array{summary: string, model: string, metrics: array, suggestion: string}
     */
    public function parseGemini429(string $err): array
    {
        $out = ['summary' => '', 'model' => '', 'metrics' => [], 'suggestion' => ''];
        if (strpos($err, '429') === false && strpos($err, 'quota') === false) {
            $out['summary'] = $err;

            return $out;
        }
        if (preg_match('/model:\s*([^\s,*]+)/i', $err, $m)) {
            $out['model'] = trim($m[1]);
        }
        if (preg_match_all('/Quota exceeded for metric:\s*([^,]+),\s*limit:\s*(\d+)/i', $err, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out['metrics'][] = ['metric' => trim($m[1]), 'limit' => (int) $m[2]];
            }
        }
        $out['summary'] = 'Rate limit / quota exceeded.';
        if ($out['model'] !== '') {
            $out['summary'] .= ' Model: '.$out['model'].'.';
        }
        foreach ($out['metrics'] as $m) {
            if ($m['limit'] === 0 && str_contains($m['metric'], 'free_tier')) {
                $out['suggestion'] = 'Free tier for this model has limit 0 (not available or not enabled). Try POSTIZ_AI_MODEL=gemini-2.5-flash, or enable billing for '.$out['model'].'.';
                break;
            }
        }
        if ($out['suggestion'] === '') {
            $out['suggestion'] = 'Check https://ai.google.dev/gemini-api/docs/rate-limits and billing.';
        }

        return $out;
    }

    public function formatAiFailureMessage(?string $detail): string
    {
        $detail = $detail ?? '';
        $lower = strtolower($detail);
        $cause = null;
        if (str_contains($lower, '401') || str_contains($lower, 'incorrect api key') || str_contains($lower, 'invalid api key')
            || str_contains($lower, 'invalid_api_key') || str_contains($lower, 'authentication') || str_contains($lower, 'unauthorized')
            || str_contains($lower, 'api key not valid') || str_contains($lower, 'api_key_invalid')) {
            $cause = 'Invalid or revoked API key. Check the key in .env / .env.local and that it has not been revoked.';
        } elseif (str_contains($lower, '429') || str_contains($lower, 'quota') || str_contains($lower, 'rate limit')
            || str_contains($lower, 'exceeded') || str_contains($lower, 'resource_exhausted')) {
            $cause = 'Quota exceeded or rate limited. Wait or check usage/billing in the provider dashboard.';
        } elseif (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'curl')
            || str_contains($lower, 'connection') || str_contains($lower, 'timed out') || str_contains($lower, 'resolve')
            || str_contains($lower, 'could not resolve') || str_contains($lower, 'network')) {
            $cause = 'Network or SSL issue. On local WAMP you can set SSL_VERIFY=false in .env.local; fix certificates in production.';
        } elseif (str_contains($lower, '403') || str_contains($lower, 'forbidden')) {
            $cause = 'Forbidden (wrong key or no access to this model). Check key and model name.';
        } elseif (str_contains($lower, '400') || str_contains($lower, 'bad request')) {
            $cause = 'Bad request (e.g. wrong model name or parameters). Check OPENAI_MODEL / GEMINI_MODEL.';
        }
        if ($cause !== null) {
            $msg = 'Likely cause: '.$cause;
            if ($detail !== '') {
                $msg .= ' Detail: '.$detail;
            }

            return $msg;
        }
        if ($detail !== '') {
            return 'API call failed. Detail: '.$detail;
        }

        return 'API call failed. No detail captured; check storage/logs/copilot-*.log.';
    }
}
