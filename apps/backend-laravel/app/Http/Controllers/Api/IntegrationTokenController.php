<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Support\PostizLogger;
use Illuminate\Http\Request;

/**
 * Thin API for listing and updating integration tokens (status / payload).
 */
class IntegrationTokenController extends Controller
{
    /**
     * GET /api/tokens
     */
    public function index(Request $request)
    {
        $request->validate([
            'provider' => ['nullable', 'string'],
            'account_identifier' => ['nullable', 'string'],
        ]);

        $query = IntegrationToken::query()->orderBy('created_at', 'desc')->limit(100);

        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider'));
        }
        if ($request->filled('account_identifier')) {
            $query->where('account_identifier', $request->string('account_identifier'));
        }

        return response()->json([
            'tokens' => $query->get(),
        ]);
    }

    /**
     * POST /api/tokens
     *
     * Upsert a token row (metadata only; do not store secrets in plain text in production).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'account_identifier' => ['required', 'string', 'max:128'],
            'payload' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        $attributes = [
            'payload' => $data['payload'] ?? null,
            'status' => $data['status'] ?? 'active',
        ];

        if (!empty($data['expires_at'])) {
            $attributes['expires_at'] = \Illuminate\Support\Carbon::parse($data['expires_at'])->utc();
        }

        $token = IntegrationToken::updateOrCreate(
            [
                'provider' => $data['provider'],
                'account_identifier' => $data['account_identifier'],
            ],
            $attributes
        );

        PostizLogger::event('token.upserted', [
            'entity' => 'token',
            'token_id' => $token->id,
            'provider' => $token->provider,
            'account_identifier' => $token->account_identifier,
            'status' => $token->status,
            'expires_at' => $token->expires_at?->toISOString(),
        ]);

        return response()->json([
            'token' => $token,
        ]);
    }
}

