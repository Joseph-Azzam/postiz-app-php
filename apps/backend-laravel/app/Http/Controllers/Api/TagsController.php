<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Post tags (CRUD). Mirrors NestJS /posts/tags.
 * GET /api/posts/tags, POST /api/posts/tags, PUT /api/posts/tags/{id}, DELETE /api/posts/tags/{id}.
 */
class TagsController extends Controller
{
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

    /**
     * GET /api/posts/tags → { tags: [...] }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tags = Tag::where('user_id', $user->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color ?? '#942828',
            ]);

        return response()->json(['tags' => $tags]);
    }

    /**
     * POST /api/posts/tags → create tag.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $name = trim((string) ($request->input('name') ?? ''));
        $color = trim((string) ($request->input('color') ?? '#942828'));
        if ($name === '') {
            return response()->json(['message' => 'Name is required'], 400);
        }

        $tag = Tag::create([
            'user_id' => $user->id,
            'name' => $name,
            'color' => $color ?: null,
        ]);

        return response()->json([
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color ?? '#942828',
        ], 201);
    }

    /**
     * PUT /api/posts/tags/{id} → update tag.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tag = Tag::where('user_id', $user->id)->where('id', $id)->first();
        if (! $tag) {
            return response()->json(['message' => 'Tag not found'], 404);
        }

        $name = trim((string) ($request->input('name') ?? $tag->name));
        $color = trim((string) ($request->input('color') ?? $tag->color ?? ''));
        if ($name === '') {
            return response()->json(['message' => 'Name is required'], 400);
        }

        $tag->update([
            'name' => $name,
            'color' => $color ?: null,
        ]);

        return response()->json([
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color ?? '#942828',
        ]);
    }

    /**
     * DELETE /api/posts/tags/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $tag = Tag::where('user_id', $user->id)->where('id', $id)->first();
        if (! $tag) {
            return response()->json(['message' => 'Tag not found'], 404);
        }

        $tag->delete();

        return response()->json([], 204);
    }
}
