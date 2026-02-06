<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Models\ScheduledPost;
use Illuminate\Http\JsonResponse;

/**
 * Public (unauthenticated) endpoints for shared post preview.
 * Matches NestJS PublicController GET /public/posts/:id for frontend preview page.
 */
class PublicPostController extends Controller
{
    /**
     * GET /public/posts/{id}
     *
     * Returns an array of post items for the preview page. Frontend expects:
     * [{ id, content, image, publishDate, integration: { id, name, picture, providerIdentifier, profile } }, ...]
     * id can be a ScheduledPost id or group (we try both).
     */
    public function preview(string $id): JsonResponse
    {
        $post = ScheduledPost::find($id) ?? ScheduledPost::where('group', $id)->first();
        if (! $post) {
            return response()->json([]);
        }

        $payload = $post->payload ?? [];
        $postsPayload = $payload['posts'] ?? [];
        $first = $postsPayload[0] ?? null;
        if (! $first) {
            return response()->json([]);
        }

        $integrationId = $first['integration']['id'] ?? null;
        $integration = $integrationId ? IntegrationToken::find($integrationId) : null;
        $integrationShape = [
            'id' => $integration?->id ?? $integrationId ?? 'unknown',
            'name' => $integration?->name ?? 'Unknown',
            'picture' => $integration?->picture ?? '/no-picture.jpg',
            'providerIdentifier' => $integration ? ($integration->provider ?? 'unknown') : 'unknown',
            'profile' => $integration?->profile ?? '',
        ];

        $values = $first['value'] ?? [];
        if (empty($values)) {
            $values = [['content' => $post->content_preview ?? '', 'image' => []]];
        }

        $publishDate = $post->scheduled_at?->toISOString();
        $out = [];
        foreach ($values as $i => $v) {
            $content = $v['content'] ?? '';
            $images = $v['image'] ?? [];
            $out[] = [
                'id' => $post->id.($i > 0 ? '-'.$i : ''),
                'content' => is_string($content) ? $content : '',
                'image' => is_array($images) ? json_encode($images) : '[]',
                'publishDate' => $publishDate,
                'integration' => $integrationShape,
            ];
        }

        return response()->json($out);
    }

    /**
     * GET /public/posts/{id}/comments
     * Returns comments for the post. No comment storage in Laravel yet; returns empty array.
     */
    public function comments(string $id): JsonResponse
    {
        return response()->json(['comments' => []]);
    }
}
