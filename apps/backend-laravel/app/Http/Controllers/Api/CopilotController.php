<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CopilotThread;
use App\Models\User;
use App\Services\CopilotKitAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/**
 * CopilotKit endpoints for Postiz agent/chat.
 * - POST /api/copilot/chat: layout CopilotKit runtime (stub).
 * - POST /api/copilot/agent: agent page; parses GraphQL (loadAgentState, generateCopilotResponse) and returns AI reply via OpenAI; persists chats when user authenticated.
 * - GET /api/copilot/list: list of threads for current user (mirrors NestJS memory.getThreadsByResourceIdPaginated).
 * - GET /api/copilot/{id}/list: thread messages for LoadMessages (mirrors NestJS memory.query).
 * Per project rules: AI features are synchronous only; no background jobs.
 */
class CopilotController extends Controller
{
    /**
     * POST /api/copilot/chat
     * CopilotKit sends requests here; stub to avoid 404.
     */
    public function chat(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [],
            'errors' => [],
        ]);
    }

    /**
     * POST /api/copilot/agent
     * Agent page uses runtimeUrl = backendUrl + '/copilot/agent'. CopilotKit sends GraphQL POST
     * (operationName + variables). We handle loadAgentState and generateCopilotResponse;
     * generateCopilotResponse calls OpenAI when OPENAI_API_KEY is set and persists chats to DB when user is authenticated.
     * Always returns 200 with valid GraphQL envelope and CORS headers so the client never sees CombinedError or CORS block.
     */
    public function agent(Request $request): JsonResponse
    {
        $operationName = null;
        $variables = [];
        $corsHeaders = $this->corsHeaders($request);

        try {
            $user = $this->userFromAuthCookie($request);
            $userId = $user?->id;

            $body = $request->all();
            $raw = $request->getContent();
            if (empty($body) && is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $body = is_array($decoded) ? $decoded : [];
            }

            if (isset($body[0]) && is_array($body[0])) {
                $body = $body[0];
            }

            $operationName = $body['operationName'] ?? $body['operation'] ?? null;
            $variables = $body['variables'] ?? [];
            $variables = is_array($variables) ? $variables : [];

            $config = Config::get('postiz.copilot', []);
            $config = is_array($config) ? $config : [];
            $openaiKey = trim((string) ($config['openai_api_key'] ?? ''));
            $geminiKey = trim((string) ($config['gemini_api_key'] ?? ''));
            $provider = $config['provider'] ?? 'openai';
            if ($provider === 'openai' && $openaiKey === '' && $geminiKey !== '') {
                $provider = 'gemini';
            }
            $geminiModel = $config['ai_model'] ?? $config['gemini_model'] ?? 'gemini-2.5-flash';
            $service = new CopilotKitAgentService(
                $provider,
                $openaiKey,
                $config['openai_model'] ?? 'gpt-4o-mini',
                $geminiKey,
                $geminiModel
            );

            $data = [];
            try {
                if ($operationName === 'loadAgentState') {
                    $data = $service->loadAgentState($variables, $userId);
                } elseif ($operationName === 'generateCopilotResponse') {
                    $data = $service->generateCopilotResponse($variables, $userId);
                } else {
                    $data = $this->copilotStubForOperation($operationName, $variables, $userId, $service);
                }
            } catch (\Throwable $e) {
                $this->logCopilotError($operationName, $e);
                if ($operationName === 'generateCopilotResponse') {
                    $dataInner = is_array($variables['data'] ?? null) ? $variables['data'] : [];
                    $threadId = $dataInner['threadId'] ?? \Illuminate\Support\Str::uuid()->toString();
                    $runId = $dataInner['runId'] ?? \Illuminate\Support\Str::uuid()->toString();
                    $hint = $service->getLastError();
                    $message = $hint !== null && $hint !== ''
                        ? 'Something went wrong. '.$hint
                        : 'Something went wrong. Please try again.';
                    $data = $service->buildErrorResponse($threadId, $runId, $message);
                } else {
                    $data = $this->copilotStubForOperation($operationName, $variables, $userId, $service);
                }
            }

            $config = Config::get('postiz.copilot', []);
            $config = is_array($config) ? $config : [];

            return response()->json([
                'data' => $data,
                'errors' => [],
            ], 200, [], JSON_UNESCAPED_SLASHES)->withHeaders(array_merge($corsHeaders, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache',
                'X-CopilotKit-Runtime-Version' => $config['runtime_version'] ?? '1.10.6',
            ]));
        } catch (\Throwable $e) {
            $this->logCopilotError($operationName, $e);
            $data = $this->copilotStubSafeWithoutService($operationName, $variables);
            return response()->json([
                'data' => $data,
                'errors' => [],
            ], 200, [], JSON_UNESCAPED_SLASHES)->withHeaders(array_merge($corsHeaders, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache',
            ]));
        }
    }

    /**
     * CORS headers so agent response is never blocked when called from frontend origin (e.g. localhost:4200).
     * Laravel HandleCors may not run on 500 responses; we attach these on every agent response.
     */
    private function corsHeaders(Request $request): array
    {
        $allowed = [
            'http://localhost:3000',
            'http://localhost:4200',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:4200',
        ];
        $extra = env('CORS_ALLOWED_ORIGINS') ? array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS'))) : [];
        $allowed = array_values(array_unique(array_filter(array_merge($allowed, $extra))));
        $origin = $request->header('Origin');
        $allowOrigin = ($origin !== null && $origin !== '' && in_array($origin, $allowed, true))
            ? $origin
            : ($allowed[0] ?? 'http://localhost:4200');

        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, auth',
            'Access-Control-Allow-Credentials' => 'true',
        ];
    }

    /**
     * Stub for any operation when service is not available (e.g. exception during bootstrap).
     * Returns data keyed by operation name so urql does not get CombinedError.
     */
    private function copilotStubSafeWithoutService(?string $operationName, array $variables): array
    {
        $key = $operationName !== null && $operationName !== '' ? lcfirst($operationName) : 'unknown';
        $op = $operationName ?? '';

        if (in_array($op, ['availableAgents', 'AvailableAgents'], true)) {
            return ['availableAgents' => [['name' => 'postiz']]];
        }
        if (in_array($op, ['fetchMessages', 'FetchMessages', 'getMessages', 'GetMessages', 'LoadMessages', 'loadMessages'], true)) {
            return [$key => ['messages' => []]];
        }
        if (in_array($op, ['loadAgentState', 'LoadAgentState'], true)) {
            $data = $variables['data'] ?? [];
            $threadId = $data['threadId'] ?? '';
            return ['loadAgentState' => [
                'threadId' => $threadId,
                'threadExists' => false,
                'state' => '{}',
                'messages' => '[]',
            ]];
        }
        if (in_array($op, ['generateCopilotResponse', 'GenerateCopilotResponse'], true)) {
            $data = $variables['data'] ?? [];
            $threadId = $data['threadId'] ?? \Illuminate\Support\Str::uuid()->toString();
            $runId = $data['runId'] ?? \Illuminate\Support\Str::uuid()->toString();
            $now = now()->toIso8601String();
            $content = 'Something went wrong. Please try again.';
            return ['generateCopilotResponse' => [
                'threadId' => $threadId,
                'runId' => $runId,
                'extensions' => ['openaiAssistantAPI' => ['runId' => $runId, 'threadId' => $threadId]],
                'status' => ['code' => 'success'],
                'messages' => [[
                    '__typename' => 'TextMessageOutput',
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'createdAt' => $now,
                    'status' => ['code' => 'success'],
                    'content' => $content,
                    'text' => $content,
                    'role' => 'assistant',
                    'parentMessageId' => null,
                ]],
            ]];
        }

        return [$key => []];
    }

    private function logCopilotError(?string $operationName, \Throwable $e): void
    {
        try {
            \Illuminate\Support\Facades\Log::channel('copilot')->error('Copilot agent error', [
                'operation' => $operationName,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::error('Copilot agent error', [
                'operation' => $operationName,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/copilot/list
     * List threads for current user (mirrors NestJS GET /copilot/list → threads with id, title).
     */
    public function list(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['threads' => []], 200);
        }

        $threads = CopilotThread::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'title'])
            ->map(fn ($t) => ['id' => $t->id, 'title' => $t->title]);

        return response()->json(['threads' => $threads->values()->all()]);
    }

    /**
     * GET /api/copilot/{id}/list
     * LoadMessages fetches thread messages. Frontend expects { uiMessages: [{ content, role }] }.
     * Mirrors NestJS GET /copilot/:thread/list (memory.query).
     */
    public function threadList(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->userFromAuthCookie($request);
            if (! $user) {
                return response()->json(['uiMessages' => []], 200);
            }

            $thread = CopilotThread::where('id', $id)->where('user_id', $user->id)->first();
            if (! $thread) {
                return response()->json(['uiMessages' => []], 200);
            }

            $uiMessages = $thread->messages()
                ->orderBy('created_at')
                ->get(['content', 'role'])
                ->map(fn ($m) => ['content' => $m->content, 'role' => $m->role])
                ->values()
                ->all();

            return response()->json(['uiMessages' => $uiMessages]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('copilot')->warning('Copilot threadList failed', ['id' => $id, 'message' => $e->getMessage()]);

            return response()->json(['uiMessages' => []], 200);
        }
    }

    /**
     * Return a GraphQL-style data payload for CopilotKit operations we don't implement.
     * The client (urql) expects response.data[operationName]; wrong or missing key causes CombinedError.
     * Stub so availableAgents, fetchMessages, etc. get the key they expect.
     */
    private function copilotStubForOperation(?string $operationName, array $variables, ?int $userId, CopilotKitAgentService $service): array
    {
        $key = $operationName !== null && $operationName !== '' ? lcfirst($operationName) : 'unknown';
        $op = $operationName ?? '';

        if (in_array($op, ['availableAgents', 'AvailableAgents'], true)) {
            return ['availableAgents' => [['name' => 'postiz']]];
        }
        // Urql expects response.data[operationName]; use $key so fetchMessages/LoadMessages/etc. all resolve.
        if (in_array($op, ['fetchMessages', 'FetchMessages', 'getMessages', 'GetMessages', 'LoadMessages', 'loadMessages'], true)) {
            return [$key => ['messages' => []]];
        }

        // Fallback: return loadAgentState so chat still works; but key must match so client finds data[key].
        $load = $service->loadAgentState($variables, $userId);
        $inner = $load['loadAgentState'] ?? [];

        return [$key => $inner];
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
