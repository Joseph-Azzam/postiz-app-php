<?php

namespace App\Services;

use App\Models\CopilotMessage;
use App\Models\CopilotThread;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Handles CopilotKit GraphQL operations for the agent: loadAgentState and generateCopilotResponse.
 * Synchronous only; supports OpenAI or Gemini when API key is set.
 * Persists threads and messages to DB when userId is provided (mirrors NestJS Mastra memory).
 */
class CopilotKitAgentService
{
    /** Last API error (HTTP status + body or exception message) for debugging. */
    protected ?string $lastError = null;

    /** When set (e.g. 0 in tests), overrides config for Gemini 429 retries so test runs don't amplify requests. */
    protected ?int $geminiRetryMaxOverride = null;

    public function __construct(
        protected string $provider,
        protected string $openaiApiKey,
        protected string $openaiModel,
        protected string $geminiApiKey,
        protected string $geminiModel,
        ?int $geminiRetryMaxOverride = null,
    ) {
        $this->geminiRetryMaxOverride = $geminiRetryMaxOverride;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Handle loadAgentState query. Returns thread state; threadExists from DB when userId set.
     *
     * @param  int|null  $userId  Current user id (resourceId); null = no persistence
     */
    public function loadAgentState(array $variables, ?int $userId = null): array
    {
        $data = $variables['data'] ?? [];
        $threadId = $data['threadId'] ?? null;
        $threadId = $threadId ?? '';

        $threadExists = false;
        if ($userId !== null && $threadId !== '') {
            $threadExists = CopilotThread::where('id', $threadId)->where('user_id', $userId)->exists();
        }

        return [
            'loadAgentState' => [
                'threadId' => $threadId,
                'threadExists' => $threadExists,
                'state' => '{}',
                'messages' => '[]',
            ],
        ];
    }

    /**
     * Handle generateCopilotResponse mutation. Builds conversation from input messages,
     * calls OpenAI, persists to DB when userId set, returns response in CopilotKit shape.
     *
     * @param  int|null  $userId  Current user id (resourceId); null = no persistence
     */
    public function generateCopilotResponse(array $variables, ?int $userId = null): array
    {
        $data = $variables['data'] ?? [];
        $messages = $data['messages'] ?? [];
        $threadId = $data['threadId'] ?? Str::uuid()->toString();
        $runId = $data['runId'] ?? Str::uuid()->toString();

        $openAiMessages = $this->buildOpenAiMessages($messages);
        if ($openAiMessages === []) {
            return $this->copilotResponse($threadId, $runId, 'I didn\'t receive any messages. Say something to get started!');
        }

        if (($this->provider === 'gemini' && $this->geminiApiKey === '') || ($this->provider === 'openai' && $this->openaiApiKey === '')) {
            return $this->copilotResponse(
                $threadId,
                $runId,
                'AI is not configured. Set OPENAI_API_KEY (for OpenAI) or COPILOT_PROVIDER=gemini and GEMINI_API_KEY in your .env to enable the agent.'
            );
        }

        $reply = $this->callAi($openAiMessages);
        if ($reply === null) {
            $hint = $this->getLastError();
            $msg = $hint !== null && $hint !== ''
                ? 'Sorry, I couldn\'t generate a response. '.$hint
                : 'Sorry, I couldn\'t generate a response. Please try again.';
            // Persist user messages + error reply so history is complete and appears after refresh.
            if ($userId !== null) {
                try {
                    $this->persistMessages($userId, $threadId, $messages, $msg);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::channel('copilot')->warning('Copilot persist messages failed', ['message' => $e->getMessage()]);
                }
            }
            return $this->copilotResponse($threadId, $runId, $msg);
        }

        if ($userId !== null) {
            try {
                $this->persistMessages($userId, $threadId, $messages, $reply);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::channel('copilot')->warning('Copilot persist messages failed', ['message' => $e->getMessage()]);
            }
        }

        return $this->copilotResponse($threadId, $runId, $reply);
    }

    /**
     * Persist thread and messages (mirrors NestJS Mastra memory).
     * Ensures thread exists; appends new user messages from request then assistant reply.
     */
    protected function persistMessages(int $userId, string $threadId, array $requestMessages, string $assistantContent): void
    {
        $threadId = strlen($threadId) > 36 ? substr($threadId, 0, 36) : $threadId;
        $thread = CopilotThread::where('id', $threadId)->where('user_id', $userId)->first();

        if (! $thread) {
            $title = $this->threadTitleFromMessages($requestMessages);
            $thread = CopilotThread::create([
                'id' => $threadId,
                'user_id' => $userId,
                'title' => Str::limit($title, 255),
            ]);
        }

        $existingCount = $thread->messages()->count();
        $toAppend = array_slice($requestMessages, $existingCount);

        foreach ($toAppend as $msg) {
            $text = $msg['textMessage'] ?? null;
            if (! is_array($text) || ! isset($text['content'], $text['role'])) {
                continue;
            }
            $role = in_array($text['role'], ['user', 'assistant', 'system'], true) ? $text['role'] : 'user';
            CopilotMessage::create([
                'copilot_thread_id' => $thread->id,
                'content' => $text['content'],
                'role' => $role,
                'type' => 'text',
            ]);
        }

        CopilotMessage::create([
            'copilot_thread_id' => $thread->id,
            'content' => $assistantContent,
            'role' => 'assistant',
            'type' => 'text',
        ]);
    }

    protected function threadTitleFromMessages(array $messages): string
    {
        foreach ($messages as $msg) {
            $text = $msg['textMessage'] ?? null;
            if (is_array($text) && isset($text['content'], $text['role']) && $text['role'] === 'user') {
                $content = trim((string) $text['content']);
                if ($content !== '') {
                    return Str::limit($content, 50);
                }
            }
        }

        return 'New chat';
    }

    /**
     * Build OpenAI chat messages from CopilotKit message input (textMessage content/role).
     */
    protected function buildOpenAiMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            $text = $msg['textMessage'] ?? null;
            if (! is_array($text) || ! isset($text['content'], $text['role'])) {
                continue;
            }
            $role = $text['role'] === 'user' ? 'user' : ($text['role'] === 'assistant' ? 'assistant' : 'user');
            $out[] = ['role' => $role, 'content' => $text['content']];
        }
        return $out;
    }

    /**
     * Call configured AI provider (OpenAI or Gemini). Returns content string or null on failure.
     */
    protected function callAi(array $messages): ?string
    {
        if ($this->provider === 'gemini') {
            return $this->callGemini($messages);
        }

        return $this->callOpenAi($messages);
    }

    /**
     * Call Gemini generateContent API. Returns content string or null on failure.
     * On 429 (rate limit) waits and retries per config (gemini_retry_max, gemini_retry_delay_seconds).
     */
    protected function callGemini(array $messages): ?string
    {
        $this->lastError = null;
        $config = \Illuminate\Support\Facades\Config::get('postiz.copilot', []);
        $maxRetries = $this->geminiRetryMaxOverride !== null
            ? $this->geminiRetryMaxOverride
            : (int) ($config['gemini_retry_max'] ?? 3);
        $maxAttempts = max(1, $maxRetries);
        $delaySeconds = (int) ($config['gemini_retry_delay_seconds'] ?? 65);

        $systemContent = 'You are the Postiz assistant. You help users schedule posts, manage social media, and use the Postiz app. Be concise and helpful.';
        $contents = [];
        foreach ($messages as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ];
        }
        $body = [
            'contents' => $contents,
            'systemInstruction' => ['parts' => [['text' => $systemContent]]],
            'generationConfig' => [
                'maxOutputTokens' => 1024,
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.urlencode($this->geminiModel).':generateContent?key='.urlencode($this->geminiApiKey);
        $sslVerify = isset($_ENV['SSL_VERIFY']) ? filter_var($_ENV['SSL_VERIFY'], FILTER_VALIDATE_BOOLEAN) : \Illuminate\Support\Facades\Config::get('postiz.ssl_verify', true);
        // Use 30s timeout so we stay under typical PHP max_execution_time (e.g. 120s) even with retries.
        $request = Http::connectTimeout(10)->timeout(30);
        if ($sslVerify === false) {
            $request = $request->withOptions(['verify' => false]);
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $request->post($url, $body);

                if ($response->successful()) {
                    $data = $response->json();
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if (is_string($text) && trim($text) !== '') {
                        return trim($text);
                    }
                    $this->lastError = 'Unexpected response: no candidates or content. '.substr(json_encode($data), 0, 400);

                    return null;
                }

                $status = $response->status();
                $errBody = $response->json();
                $errMsg = is_array($errBody) ? ($errBody['error']['message'] ?? $errBody['error']['status'] ?? $response->body()) : $response->body();
                $lastError = 'HTTP '.$status.': '.(is_string($errMsg) ? $errMsg : json_encode($errMsg));

                // On 429 (rate limit) do not wait 65s and retry 3x — that can exceed PHP max_execution_time. Fail fast with a clear message.
                if ($status === 429) {
                    $this->lastError = 'Gemini rate limit (429). Please try again in a minute or use a different API key.';
                    \Illuminate\Support\Facades\Log::channel('copilot')->info('Gemini rate limited (429), returning without retry to avoid timeout');
                    return null;
                }

                if ($attempt < $maxAttempts) {
                    $shortDelay = min(5, $delaySeconds);
                    \Illuminate\Support\Facades\Log::channel('copilot')->info('Gemini request failed, retrying', ['attempt' => $attempt, 'error' => $lastError]);
                    sleep($shortDelay);
                    continue;
                }

                $this->lastError = $lastError;

                return null;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                \Illuminate\Support\Facades\Log::channel('copilot')->warning('Gemini request failed', ['message' => $e->getMessage(), 'attempt' => $attempt]);
                if ($attempt >= $maxAttempts) {
                    $this->lastError = $lastError;

                    return null;
                }
                sleep(min(5, $delaySeconds));
            }
        }

        $this->lastError = $lastError;

        return null;
    }

    /**
     * Call OpenAI Chat Completions API. Returns content string or null on failure.
     */
    protected function callOpenAi(array $messages): ?string
    {
        $this->lastError = null;
        try {
            $request = Http::withToken($this->openaiApiKey)->connectTimeout(10)->timeout(30);
            // Project-wide SSL verify: prefer $_ENV['SSL_VERIFY'] (set by bootstrap .env.local) so it works even when config is cached.
            $sslVerify = true;
            if (isset($_ENV['SSL_VERIFY'])) {
                $sslVerify = filter_var($_ENV['SSL_VERIFY'], FILTER_VALIDATE_BOOLEAN);
            } else {
                $sslVerify = \Illuminate\Support\Facades\Config::get('postiz.ssl_verify', true);
            }
            if ($sslVerify === false) {
                $request = $request->withOptions(['verify' => false]);
            }
            $response = $request->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->openaiModel,
                    'messages' => array_merge(
                        [
                            [
                                'role' => 'system',
                                'content' => 'You are the Postiz assistant. You help users schedule posts, manage social media, and use the Postiz app. Be concise and helpful.',
                            ],
                        ],
                        $messages
                    ),
                    'max_tokens' => 1024,
                ]);

            if (! $response->successful()) {
                $body = $response->json();
                $errMsg = $body['error']['message'] ?? $body['error']['code'] ?? $response->body();
                $this->lastError = 'HTTP '.$response->status().': '.(is_string($errMsg) ? $errMsg : json_encode($errMsg));

                return null;
            }

            $body = $response->json();
            if (! is_array($body)) {
                $this->lastError = 'Invalid JSON response';

                return null;
            }

            $content = $body['choices'][0]['message']['content'] ?? null;

            return is_string($content) ? trim($content) : null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            \Illuminate\Support\Facades\Log::channel('copilot')->warning('OpenAI request failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build CopilotKit generateCopilotResponse payload (non-streaming: single TextMessageOutput).
     */
    protected function copilotResponse(string $threadId, string $runId, string $content): array
    {
        return $this->buildErrorResponse($threadId, $runId, $content);
    }

    /**
     * Same shape as copilotResponse; use for success or error message so client always gets a valid reply.
     */
    /**
     * Build the exact shape CopilotKit runtime expects so the client can show the message live.
     * Includes both "content" and "text" for compatibility; __typename and status are required.
     */
    public function buildErrorResponse(string $threadId, string $runId, string $content): array
    {
        $now = now()->toIso8601String();
        $messageId = (string) Str::uuid();
        $message = [
            '__typename' => 'TextMessageOutput',
            'id' => $messageId,
            'createdAt' => $now,
            'status' => [
                'code' => 'success',
            ],
            'content' => $content,
            'text' => $content,
            'role' => 'assistant',
            'parentMessageId' => null,
        ];

        return [
            'generateCopilotResponse' => [
                'threadId' => $threadId,
                'runId' => $runId,
                'extensions' => [
                    'openaiAssistantAPI' => [
                        'runId' => $runId,
                        'threadId' => $threadId,
                    ],
                ],
                'status' => [
                    'code' => 'success',
                ],
                'messages' => [$message],
            ],
        ];
    }
}
