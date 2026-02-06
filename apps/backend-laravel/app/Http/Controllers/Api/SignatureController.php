<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthCookieUser;
use App\Models\Signature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signatures CRUD for Postiz (saved filters / signature blocks).
 * GET /, GET /default, POST /, PUT /:id, DELETE /:id.
 */
class SignatureController extends Controller
{
    use AuthCookieUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->userFromAuthCookie($request);
            if (! $user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            $list = Signature::where('user_id', $user->id)->get();
            return response()->json($list->map(fn ($s) => [
                'id' => $s->id,
                'content' => $s->content,
                'autoAdd' => $s->auto_add,
            ])->values()->all());
        } catch (\Throwable $e) {
            return response()->json([]);
        }
    }

    public function defaultSignature(Request $request): JsonResponse
    {
        try {
            $user = $this->userFromAuthCookie($request);
            if (! $user) {
                return response()->json([]);
            }
            $s = Signature::where('user_id', $user->id)->where('auto_add', true)->first();
            return response()->json($s ? ['id' => $s->id, 'content' => $s->content, 'autoAdd' => true] : []);
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
            'content' => ['required', 'string'],
            'autoAdd' => ['required', 'boolean'],
        ]);
        $id = (string) Str::uuid();
        if ($data['autoAdd']) {
            Signature::where('user_id', $user->id)->update(['auto_add' => false]);
        }
        Signature::create([
            'id' => $id,
            'user_id' => $user->id,
            'content' => $data['content'],
            'auto_add' => $data['autoAdd'],
        ]);
        return response()->json(['id' => $id]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $data = $request->validate([
            'content' => ['required', 'string'],
            'autoAdd' => ['required', 'boolean'],
        ]);
        $sig = Signature::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        if ($data['autoAdd']) {
            Signature::where('user_id', $user->id)->where('id', '!=', $id)->update(['auto_add' => false]);
        }
        $sig->update(['content' => $data['content'], 'auto_add' => $data['autoAdd']]);
        return response()->json(['id' => $sig->id]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $sig = Signature::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $sig->delete();
        return response()->json(['ok' => true]);
    }
}
