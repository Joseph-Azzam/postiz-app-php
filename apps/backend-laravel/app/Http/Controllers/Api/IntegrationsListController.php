<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * GET /api/integrations and GET /api/integrations/list for Postiz frontend compatibility.
 * - GET /integrations: list of available channel types for Add Channel modal (social + article).
 * - GET /integrations/list: list of connected integrations for current user.
 * Channel identifiers match frontend /icons/platforms/{identifier}.png.
 */
class IntegrationsListController extends Controller
{
    /**
     * GET /api/integrations
     * Response shape expected by Add Channel modal: { social: [], article: [] }.
     * Static list so the modal shows options; OAuth/connect flow still needs backend implementation.
     */
    public function channels(): JsonResponse
    {
        $social = [
            ['identifier' => 'x', 'name' => 'X (Twitter)', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'linkedin', 'name' => 'LinkedIn', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'linkedin-page', 'name' => 'LinkedIn Page', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'facebook', 'name' => 'Facebook', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'instagram', 'name' => 'Instagram', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'youtube', 'name' => 'YouTube', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'threads', 'name' => 'Threads', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'tiktok', 'name' => 'TikTok', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'pinterest', 'name' => 'Pinterest', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'reddit', 'name' => 'Reddit', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            ['identifier' => 'mastodon', 'name' => 'Mastodon', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
            [
                'identifier' => 'bluesky',
                'name' => 'Bluesky',
                'isExternal' => true,
                'isWeb3' => false,
                'toolTip' => "We don't currently support two-factor authentication. If it's enabled on Bluesky, you'll need to disable it.",
                'customFields' => [
                    ['key' => 'service', 'label' => 'Service', 'defaultValue' => 'https://bsky.social', 'validation' => '/^(https?:\\/\\/)?((([a-zA-Z0-9\\-_]{1,256}\\.[a-zA-Z]{2,6})|(([0-9]{1,3}\\.){3}[0-9]{1,3}))(:[0-9]{1,5})?)(\\/[^\\s]*)?$/', 'type' => 'text'],
                    ['key' => 'identifier', 'label' => 'Identifier', 'validation' => '/^.+$/', 'type' => 'text'],
                    ['key' => 'password', 'label' => 'Password', 'validation' => '/^.{3,}$/', 'type' => 'password'],
                ],
            ],
            ['identifier' => 'gmb', 'name' => 'Google My Business', 'isExternal' => true, 'isWeb3' => false, 'toolTip' => null, 'customFields' => null],
        ];

        $article = [
            ['identifier' => 'devto', 'name' => 'Dev.to'],
            ['identifier' => 'hashnode', 'name' => 'Hashnode'],
            ['identifier' => 'medium', 'name' => 'Medium'],
            ['identifier' => 'wordpress', 'name' => 'WordPress'],
        ];

        return response()->json([
            'social' => $social,
            'article' => $article,
        ]);
    }

    /**
     * GET /api/integrations/list
     * Returns connected integrations for current user (mirrors NestJS integrations/list).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['integrations' => []], 200);
        }

        $tokens = IntegrationToken::where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('updated_at', 'desc')
            ->get();

        $integrations = $tokens->map(function (IntegrationToken $t) {
            return [
                'name' => $t->name ?? 'Channel',
                'id' => $t->id,
                'internalId' => $t->account_identifier,
                'disabled' => (bool) $t->disabled,
                'editor' => 'normal',
                'picture' => $t->picture ?: '/no-picture.jpg',
                'identifier' => $t->provider,
                'inBetweenSteps' => (bool) $t->in_between_steps,
                'refreshNeeded' => false,
                'isCustomFields' => false,
                'display' => $t->profile ?? $t->name,
                'type' => 'social',
                'time' => $t->posting_times ?? [['time' => 120], ['time' => 400], ['time' => 700]],
                'changeProfilePicture' => false,
                'changeNickname' => false,
                'customer' => null,
                'additionalSettings' => $t->additional_settings ?? '[]',
            ];
        })->values()->all();

        return response()->json(['integrations' => $integrations], 200);
    }

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
     * GET /api/integrations/plug/list
     * Plugs/analytics list; stub returns empty array.
     */
    public function plugList(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * GET /api/integrations/{identifier}/internal-plugs
     * Internal sub-channels (e.g. LinkedIn pages, FB pages). Stub returns empty array for UI compatibility.
     */
    public function internalPlugs(string $identifier): JsonResponse
    {
        return response()->json(['internalPlugs' => []]);
    }

    /**
     * PUT /api/integrations/{id}/group
     * Update integration group (calendar grouping).
     */
    public function updateGroup(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $data = $request->validate(['group' => ['required', 'string', 'max:64']]);
        $token = IntegrationToken::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $token->update(['group' => $data['group']]);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/integrations/{id}/settings
     * Update integration additional settings (JSON string).
     */
    public function updateSettings(Request $request, string $id): JsonResponse
    {
        $user = $this->userFromAuthCookie($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $token = IntegrationToken::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $body = $request->getContent();
        $token->update(['additional_settings' => is_string($body) ? $body : json_encode($request->all())]);
        return response()->json(['ok' => true]);
    }
}
