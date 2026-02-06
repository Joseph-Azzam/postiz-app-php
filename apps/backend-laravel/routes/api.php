<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ScheduledPostController;
use App\Http\Controllers\Api\PostRepeatRuleController;
use App\Http\Controllers\Api\NotificationDigestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AutopostRuleController;
use App\Http\Controllers\Api\IntegrationTokenController;
use App\Http\Controllers\Api\IntegrationsListController;
use App\Http\Controllers\Api\IntegrationsSocialController;
use App\Http\Controllers\Api\PostMonitorController;
use App\Http\Controllers\Api\UserStreakController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\SetsController;
use App\Http\Controllers\Api\CopilotController;
use App\Http\Controllers\Api\ThirdPartyListController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TagsController;
use App\Http\Controllers\Api\WebhooksController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\TestSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Postiz migration)
|--------------------------------------------------------------------------
|
| These routes are intentionally minimal and cron-friendly.
| They exist to support the Postiz UI without modifying core UI components.
| Keep endpoints stateless and safe for shared hosting.
*/

Route::get('user/self', [UserController::class, 'self']);
Route::get('user/organizations', [UserController::class, 'organizations']);
Route::post('user/change-org', [UserController::class, 'changeOrg']);
Route::post('user/logout', [UserController::class, 'logout']);
Route::get('user/personal', [UserController::class, 'personal']);
Route::post('user/personal', [UserController::class, 'personalUpdate']);
Route::get('user/email-notifications', [UserController::class, 'emailNotifications']);
Route::post('user/email-notifications', [UserController::class, 'emailNotificationsUpdate']);

Route::post('copilot/chat', [CopilotController::class, 'chat']);
Route::post('copilot/agent', [CopilotController::class, 'agent']);
Route::get('copilot/list', [CopilotController::class, 'list']);
Route::get('copilot/{id}/list', [CopilotController::class, 'threadList']);

Route::get('test/session-token', [TestSessionController::class, 'sessionToken']);

Route::get('media', [MediaController::class, 'index']);
Route::post('media/upload-server', [MediaController::class, 'uploadServer']);

Route::get('settings/shortlink', [SettingsController::class, 'shortlink']);
Route::post('settings/shortlink', [SettingsController::class, 'shortlinkUpdate']);
Route::get('settings/team', [SettingsController::class, 'team']);
Route::post('settings/team', [SettingsController::class, 'teamStore']);
Route::delete('settings/team/{id}', [SettingsController::class, 'teamDestroy']);

Route::get('notifications/list', [NotificationController::class, 'list']);
Route::get('notifications', [NotificationController::class, 'index']);

Route::get('integrations', [IntegrationsListController::class, 'channels']);
Route::get('integrations/list', [IntegrationsListController::class, 'index']);
Route::get('integrations/social/{identifier}', [IntegrationsSocialController::class, 'getIntegrationUrl']);
Route::post('integrations/social/{identifier}/connect', [IntegrationsSocialController::class, 'connect']);
Route::get('integrations/plug/list', [IntegrationsListController::class, 'plugList']);
Route::get('integrations/{identifier}/internal-plugs', [IntegrationsListController::class, 'internalPlugs']);
Route::put('integrations/{id}/group', [IntegrationsListController::class, 'updateGroup']);
Route::post('integrations/{id}/settings', [IntegrationsListController::class, 'updateSettings']);

Route::get('third-party/list', [ThirdPartyListController::class, 'index']);
Route::get('third-party', [ThirdPartyListController::class, 'index']);

Route::get('signatures', [SignatureController::class, 'index']);
Route::get('signatures/default', [SignatureController::class, 'defaultSignature']);
Route::post('signatures', [SignatureController::class, 'store']);
Route::put('signatures/{id}', [SignatureController::class, 'update']);
Route::delete('signatures/{id}', [SignatureController::class, 'destroy']);

Route::get('sets', [SetsController::class, 'index']);
Route::post('sets', [SetsController::class, 'store']);
Route::delete('sets/{id}', [SetsController::class, 'destroy']);

Route::get('webhooks', [WebhooksController::class, 'index']);
Route::post('webhooks', [WebhooksController::class, 'store']);
Route::put('webhooks', [WebhooksController::class, 'update']);
Route::delete('webhooks/{id}', [WebhooksController::class, 'destroy']);
Route::post('webhooks/send', [WebhooksController::class, 'send']);

Route::get('analytics', [AnalyticsController::class, 'index']);
Route::get('analytics/trending', [AnalyticsController::class, 'trending']);
Route::get('analytics/stars', [AnalyticsController::class, 'stars']);
Route::get('analytics/post/{postId}', [AnalyticsController::class, 'post']);
Route::get('analytics/{integration}', [AnalyticsController::class, 'integration']);

Route::prefix('auth')->group(function () {
    Route::get('oauth/{provider}', [AuthController::class, 'oauthLink']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('activate', [AuthController::class, 'activate']);
    Route::post('resend-activation', [AuthController::class, 'resendActivation']);
    Route::post('forgot', [AuthController::class, 'forgot']);
    Route::post('forgot-return', [AuthController::class, 'forgotReturn']);
});

Route::prefix('posts')->group(function () {
    Route::get('/', [ScheduledPostController::class, 'index']);
    Route::post('/', [ScheduledPostController::class, 'store']);
    Route::post('/should-shortlink', [ScheduledPostController::class, 'shouldShortlink']);
    Route::get('/find-slot', [ScheduledPostController::class, 'findSlot']);
    Route::post('/generator/draft', [ScheduledPostController::class, 'generatorDraft']);

    Route::get('/tags', [TagsController::class, 'index']);
    Route::post('/tags', [TagsController::class, 'store']);
    Route::put('/tags/{id}', [TagsController::class, 'update']);
    Route::delete('/tags/{id}', [TagsController::class, 'destroy']);

    Route::get('/group/{group}', [ScheduledPostController::class, 'showGroup']);
    Route::delete('/{group}', [ScheduledPostController::class, 'deleteGroup']);

    Route::put('/{id}/date', [ScheduledPostController::class, 'updateDate']);
    Route::post('/{id}/retry', [ScheduledPostController::class, 'retry']);
    Route::post('/{id}/comments', [ScheduledPostController::class, 'storeComment']);
});

Route::prefix('repeats')->group(function () {
    Route::get('/group/{group}', [PostRepeatRuleController::class, 'showByGroup']);
    Route::post('/', [PostRepeatRuleController::class, 'store']);
    Route::post('/{id}/pause', [PostRepeatRuleController::class, 'pause']);
    Route::post('/{id}/resume', [PostRepeatRuleController::class, 'resume']);
});

Route::prefix('digest')->group(function () {
    Route::post('/events', [NotificationDigestController::class, 'storeEvent']);
    Route::get('/events', [NotificationDigestController::class, 'index']);
});

Route::prefix('autopost')->group(function () {
    Route::get('/rules', [AutopostRuleController::class, 'index']);
    Route::post('/rules', [AutopostRuleController::class, 'store']);
    Route::post('/rules/{id}/disable', [AutopostRuleController::class, 'disable']);
    Route::post('/rules/{id}/enable', [AutopostRuleController::class, 'enable']);
});

Route::prefix('tokens')->group(function () {
    Route::get('/', [IntegrationTokenController::class, 'index']);
    Route::post('/', [IntegrationTokenController::class, 'store']);
});

Route::prefix('missing')->group(function () {
    Route::get('/entries', [PostMonitorController::class, 'index']);
});

Route::get('/streaks', [UserStreakController::class, 'index']);


