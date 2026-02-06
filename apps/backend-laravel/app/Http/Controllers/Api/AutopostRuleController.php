<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutopostRule;
use App\Support\PostizLogger;
use Illuminate\Http\Request;

/**
 * Thin API for managing autopost rules.
 */
class AutopostRuleController extends Controller
{
    /**
     * GET /api/autopost/rules
     */
    public function index(Request $request)
    {
        $request->validate([
            'account_identifier' => ['nullable', 'string'],
        ]);

        $query = AutopostRule::query()->orderBy('created_at', 'desc')->limit(100);
        if ($request->filled('account_identifier')) {
            $query->where('account_identifier', $request->string('account_identifier'));
        }

        return response()->json([
            'rules' => $query->get(),
        ]);
    }

    /**
     * POST /api/autopost/rules
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'account_identifier' => ['required', 'string', 'max:128'],
            'next_run_at' => ['required', 'string'],
            'status' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
        ]);

        $nextRunAt = \Illuminate\Support\Carbon::parse($data['next_run_at'])->utc();

        $rule = AutopostRule::updateOrCreate(
            ['account_identifier' => $data['account_identifier']],
            [
                'next_run_at' => $nextRunAt,
                'status' => $data['status'] ?? AutopostRule::STATUS_ENABLED,
                'payload' => $data['payload'] ?? null,
            ]
        );

        PostizLogger::event('autopost.rule.upserted', [
            'entity' => 'autopost',
            'rule_id' => $rule->id,
            'account_identifier' => $rule->account_identifier,
            'status' => $rule->status,
            'next_run_at' => $rule->next_run_at?->toISOString(),
        ]);

        return response()->json([
            'rule' => $rule,
        ]);
    }

    /**
     * POST /api/autopost/rules/{id}/disable
     */
    public function disable(string $id)
    {
        $rule = AutopostRule::findOrFail($id);
        $from = $rule->status;
        $rule->status = AutopostRule::STATUS_DISABLED;
        $rule->save();

        PostizLogger::event('autopost.rule.disabled', [
            'entity' => 'autopost',
            'rule_id' => $rule->id,
            'account_identifier' => $rule->account_identifier,
            'from' => $from,
            'to' => $rule->status,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/autopost/rules/{id}/enable
     */
    public function enable(string $id)
    {
        $rule = AutopostRule::findOrFail($id);
        $from = $rule->status;
        $rule->status = AutopostRule::STATUS_ENABLED;
        if (!$rule->next_run_at || $rule->next_run_at->lt(now()->utc())) {
            $rule->next_run_at = now()->utc()->addHour();
        }
        $rule->save();

        PostizLogger::event('autopost.rule.enabled', [
            'entity' => 'autopost',
            'rule_id' => $rule->id,
            'account_identifier' => $rule->account_identifier,
            'from' => $from,
            'to' => $rule->status,
            'next_run_at' => $rule->next_run_at?->toISOString(),
        ]);

        return response()->json(['ok' => true]);
    }
}

