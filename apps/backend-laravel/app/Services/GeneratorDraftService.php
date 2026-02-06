<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Generates draft post content via OpenAI or Gemini.
 * Uses writer model (POSTIZ_WRITER_MODEL / gemini-2.5-flash) for cost-effective bulk captions.
 * Used by POST /api/posts/generator/draft for feature parity with NestJS.
 */
class GeneratorDraftService
{
    public function __construct(
        protected string $provider,
        protected string $openaiApiKey,
        protected string $openaiModel,
        protected string $geminiApiKey,
        /** Gemini model for caption/draft generation (writer_model in config). */
        protected string $geminiWriterModel,
    ) {}

    /**
     * Generate one or more draft texts from optional prompt/post input.
     * Returns array of { content: string }.
     */
    public function generate(array $input): array
    {
        $posts = $input['posts'] ?? [];
        $prompt = 'Generate a short, engaging social media post draft.';
        if (! empty($posts) && is_array($posts[0]['list'] ?? null)) {
            $first = $posts[0]['list'][0]['post'] ?? '';
            if (is_string($first) && $first !== '') {
                $prompt = 'Based on this idea, write a short social media post: ' . $first;
            }
        }
        if (isset($input['prompt']) && is_string($input['prompt']) && trim($input['prompt']) !== '') {
            $prompt = $input['prompt'];
        }

        $content = $this->callAi($prompt);
        if ($content === null || $content === '') {
            return [['content' => 'Draft could not be generated. Try again or add a prompt.']];
        }

        return [['content' => $content]];
    }

    protected function callAi(string $prompt): ?string
    {
        if ($this->provider === 'gemini' && $this->geminiApiKey !== '') {
            return $this->callGemini($prompt);
        }
        if ($this->openaiApiKey !== '') {
            return $this->callOpenAi($prompt);
        }
        return null;
    }

    /**
     * Call Gemini for draft/caption. On 429 (rate limit) waits and retries per config.
     */
    protected function callGemini(string $prompt): ?string
    {
        $config = Config::get('postiz.copilot', []);
        $maxRetries = (int) ($config['gemini_retry_max'] ?? 3);
        $delaySeconds = (int) ($config['gemini_retry_delay_seconds'] ?? 65);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.urlencode($this->geminiWriterModel).':generateContent?key='.urlencode($this->geminiApiKey);
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 512],
        ];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::timeout(30)->post($url, $body);

                if ($response->successful()) {
                    $data = $response->json();
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                    return is_string($text) ? trim($text) : null;
                }

                if ($response->status() === 429 && $attempt < $maxRetries) {
                    sleep($delaySeconds);
                    continue;
                }

                return null;
            } catch (\Throwable $e) {
                if ($attempt >= $maxRetries) {
                    return null;
                }
                sleep($delaySeconds);
            }
        }

        return null;
    }

    protected function callOpenAi(string $prompt): ?string
    {
        try {
            $request = Http::withToken($this->openaiApiKey)->timeout(30);
            $response = $request->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->openaiModel,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a social media copywriter. Reply with only the post text, no quotes or explanation.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 512,
            ]);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            return is_string($content) ? trim($content) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
