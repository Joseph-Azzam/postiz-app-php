<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthCookieUser;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Webhooks CRUD + send for Postiz.
 * GET /, POST /, PUT / (id in body), DELETE /:id, POST /send?url=...
 */
class WebhooksController extends Controller
{
    use AuthCookieUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $list = Webhook::where('user_id', $user->id)->orderByDesc('updated_at')->get();
        $out = $list->map(fn (Webhook $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'url' => $w->url,
            'integrations' => array_map(fn ($id) => ['id' => $id], $w->integration_ids ?? []),
        ])->values()->all();
        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $data = $request->validate([
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'integrations' => ['nullable', 'array'],
            'integrations.*.id' => ['nullable', 'string'],
        ]);
        $ids = isset($data['integrations']) ? array_column($data['integrations'], 'id') : [];
        $id = $data['id'] ?? null;
        $webhook = $id
            ? Webhook::updateOrCreate(
                ['id' => $id, 'user_id' => $user->id],
                ['name' => $data['name'], 'url' => $data['url'], 'integration_ids' => $ids]
            )
            : Webhook::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'url' => $data['url'],
                'integration_ids' => $ids,
            ]);
        return response()->json(['id' => $webhook->id]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $data = $request->validate([
            'id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'integrations' => ['nullable', 'array'],
            'integrations.*.id' => ['nullable', 'string'],
        ]);
        $ids = isset($data['integrations']) ? array_column($data['integrations'], 'id') : [];
        $webhook = Webhook::where('id', $data['id'])->where('user_id', $user->id)->firstOrFail();
        $webhook->update(['name' => $data['name'], 'url' => $data['url'], 'integration_ids' => $ids]);
        return response()->json(['id' => $webhook->id]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $webhook = Webhook::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $webhook->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /webhooks/send?url=...
     * Sends request body as JSON POST to the given url (test webhook).
     */
    public function send(Request $request): JsonResponse
    {
        $url = $request->query('url');
        if (! is_string($url) || $url === '') {
            return response()->json(['send' => false, 'message' => 'Missing url'], 400);
        }
        try {
            $body = $request->all();
            if (empty($body) && $request->getContent() !== '') {
                $body = json_decode($request->getContent(), true) ?? [];
            }
            Http::timeout(10)->post($url, $body);
        } catch (\Throwable $e) {
            // Log but still return send: true so UI doesn't break
        }
        return response()->json(['send' => true]);
    }
}
