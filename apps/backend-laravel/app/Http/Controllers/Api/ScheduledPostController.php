<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Models\ScheduledPost;
use App\Services\GeneratorDraftService;
use App\Support\PostizLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;

/**
 * Minimal Postiz-compatible-ish endpoints for scheduled posts.
 *
 * Goal: keep Postiz UI forms unchanged while we migrate backend incrementally.
 * This controller intentionally focuses on "store scheduled posts" and "list for calendar".
 */
class ScheduledPostController extends Controller
{
    /**
     * POST /api/posts
     *
     * The Postiz UI sends a complex payload; we store it as-is in `payload`.
     * Required fields for scheduling are extracted and normalized.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'string'], // 'draft' | 'now' | 'schedule' | 'update' (UI)
            'date' => ['required', 'string'], // ISO-ish string in UTC from UI
            'posts' => ['required', 'array', 'min:1'],
            'posts.*.group' => ['nullable', 'string'],
            'posts.*.value' => ['nullable', 'array'],
            'posts.*.value.*.content' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'], // UI sends tag ids/objects for calendar display
            'tags.*' => ['nullable'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        // Group id (Postiz uses makeId(10)); if missing, generate a safe short id.
        $group = $data['posts'][0]['group'] ?? (string) Str::ulid();

        // Try to create a stable preview for calendar list.
        $contentPreview = null;
        $firstValue = $data['posts'][0]['value'][0]['content'] ?? null;
        if (is_string($firstValue) && trim($firstValue) !== '') {
            $contentPreview = mb_substr(strip_tags($firstValue), 0, 280);
        }

        // "now" should be executed by cron as well; we schedule it immediately.
        $scheduledAt = now()->utc();
        if (!in_array($data['type'], ['now'], true)) {
            // UI sends UTC formatted `YYYY-MM-DDTHH:mm:ss`
            $scheduledAt = \Illuminate\Support\Carbon::parse($data['date'])->utc();
        }

        $status = $data['type'] === 'draft'
            ? ScheduledPost::STATUS_DRAFT
            : ScheduledPost::STATUS_SCHEDULED;

        // One row per channel (same group) so calendar shows each channel and cron publishes each once.
        $created = [];
        foreach ($data['posts'] as $postItem) {
            $integrationId = isset($postItem['integration']['id'])
                ? (string) $postItem['integration']['id']
                : null;
            $post = ScheduledPost::create([
                'group' => $group,
                'integration_id' => $integrationId,
                'status' => $status,
                'scheduled_at' => $scheduledAt,
                'attempts' => 0,
                'max_attempts' => $data['max_attempts'] ?? 5,
                'payload' => $data,
                'content_preview' => $contentPreview,
            ]);
            $created[] = $post;
            PostizLogger::event('post.created', [
                'entity' => 'post',
                'post_id' => $post->id,
                'group' => $post->group,
                'integration_id' => $integrationId,
                'status' => $post->status,
                'scheduled_at' => $post->scheduled_at?->toISOString(),
            ]);
        }

        $first = $created[0];
        return response()->json([
            'id' => $first->id,
            'group' => $first->group,
            'status' => $first->status,
        ]);
    }

    /**
     * GET /api/posts
     *
     * Minimal list endpoint for calendars/polling:
     * accepts optional start/end to limit the range.
     */
    public function index(Request $request)
    {
        $request->validate([
            'start' => ['nullable', 'string'],
            'end' => ['nullable', 'string'],
        ]);

        $query = ScheduledPost::query();

        if ($request->filled('start')) {
            $query->where('scheduled_at', '>=', \Illuminate\Support\Carbon::parse($request->string('start'))->utc());
        }
        if ($request->filled('end')) {
            $query->where('scheduled_at', '<=', \Illuminate\Support\Carbon::parse($request->string('end'))->utc());
        }

        $posts = $query
            ->orderBy('scheduled_at', 'asc')
            ->limit(500) // safety cap for shared hosting
            ->get();

        return response()->json([
            'posts' => $posts->map(fn (ScheduledPost $p) => $this->toUiShape($p))->values(),
        ]);
    }

    /**
     * GET /api/posts/group/{group}
     *
     * UI uses this to load a "thread"/group for editing.
     * For now we return the stored payload + a small normalized shape.
     */
    public function showGroup(string $group)
    {
        $posts = ScheduledPost::query()
            ->where('group', $group)
            ->orderBy('created_at', 'asc')
            ->get();

        $uiPosts = $posts->map(fn (ScheduledPost $p) => $this->toUiShape($p))->values();
        $first = $posts->first();
        $firstShape = $first ? $this->toUiShape($first) : null;
        $integration = $firstShape['integration'] ?? null;
        $integrationId = is_array($integration) ? ($integration['id'] ?? null) : null;
        $integrationPicture = is_array($integration) ? ($integration['picture'] ?? null) : null;

        // One payload per group (same for all rows); frontend uses it for editing.
        return response()->json([
            'group' => $group,
            'posts' => $uiPosts,
            'raw' => $first ? [$first->payload] : [],
            'integration' => $integrationId,
            'integrationPicture' => $integrationPicture,
        ]);
    }

    /**
     * PUT /api/posts/{id}/date
     *
     * Calendar drag/drop: reschedule this post. When multiple rows share the same group,
     * we update all of them so X and Bluesky (etc.) stay in sync.
     */
    public function updateDate(Request $request, string $id)
    {
        $data = $request->validate([
            'date' => ['required', 'string'],
            'action' => ['nullable', 'string'], // 'schedule' | 'update' (UI)
        ]);

        $post = ScheduledPost::findOrFail($id);
        $newAt = \Illuminate\Support\Carbon::parse($data['date'])->utc();

        // Update all rows in the same group so multi-channel posts move together.
        $updated = ScheduledPost::query()
            ->where('group', $post->group)
            ->get();
        foreach ($updated as $p) {
            $from = $p->status;
            $p->scheduled_at = $newAt;
            if ($p->status !== ScheduledPost::STATUS_DRAFT) {
                $p->status = ScheduledPost::STATUS_SCHEDULED;
            }
            $p->next_retry_at = null;
            $p->last_error = null;
            $p->save();
            PostizLogger::postTransition($p->id, $from, $p->status, [
                'group' => $p->group,
                'scheduled_at' => $p->scheduled_at?->toISOString(),
                'ui_action' => $data['action'] ?? null,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/posts/{group}
     *
     * The UI deletes by group in several places.
     */
    public function deleteGroup(string $group)
    {
        $posts = ScheduledPost::query()->where('group', $group)->get();
        foreach ($posts as $post) {
            PostizLogger::event('post.deleted', [
                'entity' => 'post',
                'post_id' => $post->id,
                'group' => $group,
                'status' => $post->status,
            ]);
        }
        ScheduledPost::query()->where('group', $group)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/posts/should-shortlink
     *
     * Feature parity with NestJS: accepts { messages: string[] }, returns { ask: bool }.
     * If any message contains a URL (http/https), ask is true so the UI can prompt to shortlink.
     */
    public function shouldShortlink(Request $request)
    {
        $data = $request->validate([
            'messages' => ['required', 'array'],
            'messages.*' => ['nullable', 'string'],
        ]);

        $messages = $data['messages'] ?? [];
        $hasUrl = false;
        foreach ($messages as $text) {
            if (is_string($text) && preg_match('#https?://\S+#i', $text)) {
                $hasUrl = true;
                break;
            }
        }

        return response()->json(['ask' => $hasUrl]);
    }

    /**
     * GET /api/posts/find-slot
     *
     * The Postiz UI asks for the next available slot. For now we return "now + 10 minutes".
     * This is safe and deterministic (no background workers required).
     */
    public function findSlot()
    {
        $date = now()->utc()->addMinutes(10)->startOfMinute();
        return response()->json([
            'date' => $date->format('Y-m-d\TH:i:s'),
        ]);
    }

    /**
     * POST /api/posts/{id}/retry
     * Manual retry: set to retrying and clear errors.
     */
    public function retry(string $id)
    {
        $post = ScheduledPost::findOrFail($id);
        $from = $post->status;
        $post->status = ScheduledPost::STATUS_RETRYING;
        $post->next_retry_at = now()->utc();
        $post->last_error = null;
        $post->save();

        PostizLogger::postTransition($post->id, $from, $post->status, [
            'reason' => 'manual_retry',
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Convert internal model to minimal shape the UI expects in calendar lists.
     * When integration_id is set, use that post's integration from payload; else first post (legacy).
     */
    private function toUiShape(ScheduledPost $p): array
    {
        $payload = $p->payload ?? [];
        $tagsPayload = $payload['tags'] ?? [];
        $tags = collect($tagsPayload)->map(function ($t) {
            $tag = is_array($t) ? ($t['tag'] ?? $t) : ['name' => (string) $t, 'color' => null];
            return ['tag' => ['name' => $tag['name'] ?? '', 'color' => $tag['color'] ?? null]];
        })->values()->all();

        $posts = $payload['posts'] ?? [];
        $postItem = null;
        if ($p->integration_id !== null && $p->integration_id !== '') {
            foreach ($posts as $item) {
                $id = isset($item['integration']['id']) ? (string) $item['integration']['id'] : null;
                if ($id === $p->integration_id) {
                    $postItem = $item;
                    break;
                }
            }
        }
        if ($postItem === null) {
            $postItem = $posts[0] ?? [];
        }
        $integrationPayload = $postItem['integration'] ?? [];
        $integrationId = is_array($integrationPayload) ? ($integrationPayload['id'] ?? null) : null;
        $integrationId = $integrationId ? (string) $integrationId : 'unknown';
        $name = is_array($integrationPayload) ? ($integrationPayload['name'] ?? 'Unknown') : 'Unknown';
        $picture = is_array($integrationPayload) ? ($integrationPayload['picture'] ?? '/no-picture.jpg') : '/no-picture.jpg';
        $identifier = is_array($integrationPayload) ? ($integrationPayload['identifier'] ?? $integrationPayload['providerIdentifier'] ?? null) : null;
        if ($identifier === null || $identifier === '') {
            $identifier = 'x';
        }
        // When we have integration_id, use the token's provider so the correct icon always shows (e.g. Bluesky vs X).
        if ($p->integration_id !== null && $p->integration_id !== '') {
            $token = IntegrationToken::find($p->integration_id);
            if ($token && $token->provider !== null && $token->provider !== '') {
                $identifier = $token->provider;
                if ($name === 'Unknown' && $token->name) {
                    $name = $token->name;
                }
                if ($picture === '/no-picture.jpg' && $token->picture) {
                    $picture = $token->picture ?: '/no-picture.jpg';
                }
            }
        }

        return [
            'id' => $p->id,
            'group' => $p->group,
            'content' => $p->content_preview ?? '',
            'publishDate' => $p->scheduled_at?->toISOString(),
            'actualDate' => null,
            'state' => $p->uiState(),
            'lastError' => $p->status === ScheduledPost::STATUS_FAILED ? ($p->last_error ?? null) : null,
            'integration' => [
                'id' => $integrationId,
                'name' => $name,
                'picture' => $picture,
                'providerIdentifier' => $identifier,
            ],
            'tags' => $tags,
        ];
    }

    /**
     * POST /api/posts/generator/draft
     * Generate draft post content via AI (OpenAI or Gemini). Returns { drafts: [{ content }] }.
     */
    public function generatorDraft(Request $request): JsonResponse
    {
        $input = $request->all();
        $config = Config::get('postiz.copilot', []);
        $writerModel = $config['writer_model'] ?? $config['gemini_model'] ?? 'gemini-2.5-flash';
        $service = new GeneratorDraftService(
            $config['provider'] ?? 'openai',
            $config['openai_api_key'] ?? '',
            $config['openai_model'] ?? 'gpt-4o-mini',
            $config['gemini_api_key'] ?? '',
            $writerModel
        );
        $drafts = $service->generate($input);
        return response()->json(['drafts' => $drafts]);
    }

    /**
     * POST /api/posts/{id}/comments
     * Add comment to post (stub). No comment storage yet; returns ok.
     */
    public function storeComment(Request $request, string $id): JsonResponse
    {
        $request->validate(['comment' => ['nullable', 'string']]);
        return response()->json(['ok' => true]);
    }
}

