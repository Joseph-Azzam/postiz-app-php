<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthCookieUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics stubs for Postiz (integration stats, post stats, stars, trending).
 * Returns empty/minimal data until real analytics (e.g. from social APIs) are wired.
 */
class AnalyticsController extends Controller
{
    use AuthCookieUser;

    public function index(Request $request): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json([]);
    }

    public function trending(Request $request): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json([]);
    }

    public function stars(Request $request): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json([]);
    }

    public function integration(Request $request, string $integration): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json([]);
    }

    public function post(Request $request, string $postId): JsonResponse
    {
        $this->userFromAuthCookie($request);
        return response()->json([]);
    }
}
