<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthCookieUser;
use App\Models\Set;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sets CRUD for Postiz (content sets for reuse).
 * GET /, POST /, PUT / (id in body), DELETE /:id.
 */
class SetsController extends Controller
{
    use AuthCookieUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->userFromAuthCookie($request);
            if (! $user) {
                return response()->json([]);
            }
            $list = Set::where('user_id', $user->id)->orderByDesc('updated_at')->get();
            return response()->json($list->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'content' => $s->content,
            ])->values()->all());
        } catch (\Throwable $e) {
            return response()->json([]);
        }
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
            'content' => ['required', 'string'],
        ]);
        $id = $data['id'] ?? null;
        $set = $id
            ? Set::updateOrCreate(
                ['id' => $id, 'user_id' => $user->id],
                ['name' => $data['name'], 'content' => $data['content']]
            )
            : Set::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'content' => $data['content'],
            ]);
        return response()->json(['id' => $set->id]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        Set::where('id', $id)->where('user_id', $user->id)->delete();
        return response()->json(['ok' => true]);
    }
}
