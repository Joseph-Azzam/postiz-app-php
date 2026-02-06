<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostRepeatRule;
use App\Support\PostizLogger;
use Illuminate\Http\Request;

/**
 * Simple CRUD-ish controller for repeat rules.
 *
 * Note: current Postiz UI sends repeat data as part of the post payload.
 * This API lets us manage rules explicitly from adapters without touching UI.
 */
class PostRepeatRuleController extends Controller
{
    /**
     * GET /api/repeats/group/{group}
     *
     * Fetch rule for a given post group (if any).
     */
    public function showByGroup(string $group)
    {
        $rule = PostRepeatRule::where('group', $group)->first();

        return response()->json([
            'rule' => $rule,
        ]);
    }

    /**
     * POST /api/repeats
     *
     * Create or update a repeat rule for a group.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'max:64'],
            'interval_days' => ['required', 'integer', 'min:1', 'max:365'],
            'max_repeats' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'next_run_at' => ['required', 'string'],
            'status' => ['nullable', 'string'], // active|paused|completed
            'payload' => ['nullable', 'array'],
        ]);

        $nextRunAt = \Illuminate\Support\Carbon::parse($data['next_run_at'])->utc();

        $rule = PostRepeatRule::updateOrCreate(
            ['group' => $data['group']],
            [
                'interval_days' => $data['interval_days'],
                'max_repeats' => $data['max_repeats'] ?? null,
                'next_run_at' => $nextRunAt,
                'status' => $data['status'] ?? PostRepeatRule::STATUS_ACTIVE,
                'payload' => $data['payload'] ?? null,
            ]
        );

        PostizLogger::event('repeat.rule.upserted', [
            'entity' => 'repeat',
            'rule_id' => $rule->id,
            'group' => $rule->group,
            'interval_days' => $rule->interval_days,
            'max_repeats' => $rule->max_repeats,
            'next_run_at' => $rule->next_run_at?->toISOString(),
            'status' => $rule->status,
        ]);

        return response()->json([
            'rule' => $rule,
        ]);
    }

    /**
     * POST /api/repeats/{id}/pause
     */
    public function pause(string $id)
    {
        $rule = PostRepeatRule::findOrFail($id);
        $from = $rule->status;
        $rule->status = PostRepeatRule::STATUS_PAUSED;
        $rule->save();

        PostizLogger::event('repeat.rule.paused', [
            'entity' => 'repeat',
            'rule_id' => $rule->id,
            'group' => $rule->group,
            'from' => $from,
            'to' => $rule->status,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/repeats/{id}/resume
     */
    public function resume(string $id)
    {
        $rule = PostRepeatRule::findOrFail($id);
        $from = $rule->status;
        $rule->status = PostRepeatRule::STATUS_ACTIVE;
        // If next_run_at is in the past or null, schedule it shortly in the future.
        if (!$rule->next_run_at || $rule->next_run_at->lt(now()->utc())) {
            $rule->next_run_at = now()->utc()->addMinutes(5);
        }
        $rule->save();

        PostizLogger::event('repeat.rule.resumed', [
            'entity' => 'repeat',
            'rule_id' => $rule->id,
            'group' => $rule->group,
            'from' => $from,
            'to' => $rule->status,
            'next_run_at' => $rule->next_run_at?->toISOString(),
        ]);

        return response()->json(['ok' => true]);
    }
}

