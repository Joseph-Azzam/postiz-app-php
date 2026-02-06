<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationEvent;
use App\Support\PostizLogger;
use Illuminate\Http\Request;

/**
 * Thin API for recording notification events and inspecting digests.
 *
 * UI can call this via `frontend/adapters/digest.ts`.
 */
class NotificationDigestController extends Controller
{
    /**
     * POST /api/digest/events
     *
     * Store a notification event to be batched later.
     */
    public function storeEvent(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:128'],
            'payload' => ['nullable', 'array'],
        ]);

        $event = NotificationEvent::create([
            'user_id' => $data['user_id'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            'channel' => $data['channel'] ?? 'default',
            'type' => $data['type'],
            'payload' => $data['payload'] ?? null,
        ]);

        PostizLogger::event('digest.event.created', [
            'entity' => 'digest',
            'event_id' => $event->id,
            'user_id' => $event->user_id,
            'organization_id' => $event->organization_id,
            'channel' => $event->channel,
            'type' => $event->type,
        ]);

        return response()->json([
            'event' => $event,
        ]);
    }

    /**
     * GET /api/digest/events
     *
     * Minimal listing endpoint for admin / debugging UIs.
     */
    public function index(Request $request)
    {
        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string'],
            'digested' => ['nullable', 'boolean'],
        ]);

        $query = NotificationEvent::query()->orderBy('id', 'desc')->limit(100);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->string('channel'));
        }
        if ($request->has('digested')) {
            if ($request->boolean('digested')) {
                $query->whereNotNull('digested_at');
            } else {
                $query->whereNull('digested_at');
            }
        }

        return response()->json([
            'events' => $query->get(),
        ]);
    }
}

