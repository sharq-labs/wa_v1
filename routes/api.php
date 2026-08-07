<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\Api\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public webhooks (no auth, no CSRF; signature-verified)
|--------------------------------------------------------------------------
*/
Route::get('/webhooks/meta/whatsapp', [MetaWebhookController::class, 'verify']);
Route::post('/webhooks/meta/whatsapp', [MetaWebhookController::class, 'receive'])
    ->middleware('throttle:240,1');

/*
|--------------------------------------------------------------------------
| Auth (guest)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/auth/register', [Api\Auth\AuthController::class, 'register']);
    Route::post('/auth/login', [Api\Auth\AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [Api\Auth\PasswordResetController::class, 'forgot']);
    Route::post('/auth/reset-password', [Api\Auth\PasswordResetController::class, 'reset']);
});

Route::get('/auth/verify-email/{id}/{hash}', [Api\Auth\EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.verify');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [Api\Auth\AuthController::class, 'logout']);
    Route::get('/auth/me', [Api\Auth\AuthController::class, 'me']);
    Route::put('/auth/profile', [Api\Auth\AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [Api\Auth\AuthController::class, 'changePassword']);
    Route::post('/auth/email/resend', [Api\Auth\EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1');

    Route::post('/invitations/accept', [Api\WorkspaceMemberController::class, 'acceptInvitation']);

    // Workspace collection
    Route::get('/workspaces', [Api\WorkspaceController::class, 'index']);
    Route::post('/workspaces', [Api\WorkspaceController::class, 'store']);

    // Billing plan catalogue (public to logged-in users)
    Route::get('/plans', [Api\BillingController::class, 'plans']);

    // Workspace-scoped routes: membership enforced by the workspace middleware.
    Route::prefix('/workspaces/{workspace}')->middleware('workspace')->group(function () {
        Route::get('/', [Api\WorkspaceController::class, 'show']);
        Route::put('/', [Api\WorkspaceController::class, 'update']);
        Route::delete('/', [Api\WorkspaceController::class, 'destroy']);
        Route::post('/switch', [Api\WorkspaceController::class, 'switch']);
        Route::post('/logo', [Api\WorkspaceController::class, 'uploadLogo']);
        Route::post('/complete-onboarding', [Api\WorkspaceController::class, 'completeOnboarding']);

        // Members & invitations
        Route::get('/members', [Api\WorkspaceMemberController::class, 'index']);
        Route::put('/members/{user}/role', [Api\WorkspaceMemberController::class, 'updateRole']);
        Route::delete('/members/{user}', [Api\WorkspaceMemberController::class, 'destroy']);
        Route::get('/invitations', [Api\WorkspaceMemberController::class, 'invitations']);
        Route::post('/invitations', [Api\WorkspaceMemberController::class, 'invite']);
        Route::delete('/invitations/{invitation}', [Api\WorkspaceMemberController::class, 'revokeInvitation']);

        // Agents & teams
        Route::get('/agents', [Api\AgentController::class, 'index']);
        Route::put('/agents/self', [Api\AgentController::class, 'updateSelf']);
        Route::put('/agents/{agentProfile}', [Api\AgentController::class, 'update']);
        Route::get('/teams', [Api\AgentTeamController::class, 'index']);
        Route::post('/teams', [Api\AgentTeamController::class, 'store']);
        Route::put('/teams/{team}', [Api\AgentTeamController::class, 'update']);
        Route::delete('/teams/{team}', [Api\AgentTeamController::class, 'destroy']);

        // WhatsApp accounts
        Route::get('/whatsapp-accounts', [Api\WhatsAppAccountController::class, 'index']);
        Route::post('/whatsapp-accounts/connect-fake', [Api\WhatsAppAccountController::class, 'connectFake']);
        Route::get('/whatsapp-accounts/{account}', [Api\WhatsAppAccountController::class, 'show']);
        Route::delete('/whatsapp-accounts/{account}', [Api\WhatsAppAccountController::class, 'disconnect']);
        Route::post('/whatsapp-accounts/{account}/sync-templates', [Api\WhatsAppAccountController::class, 'syncTemplates']);
        Route::get('/meta/embedded-signup/config', [Api\MetaEmbeddedSignupController::class, 'config']);
        Route::post('/meta/embedded-signup/complete', [Api\MetaEmbeddedSignupController::class, 'complete']);

        // Contacts CRM
        Route::get('/contacts', [Api\ContactController::class, 'index']);
        Route::post('/contacts', [Api\ContactController::class, 'store']);
        Route::get('/contacts/export', [Api\ContactController::class, 'export']);
        Route::get('/contacts/{contact}', [Api\ContactController::class, 'show']);
        Route::put('/contacts/{contact}', [Api\ContactController::class, 'update']);
        Route::delete('/contacts/{contact}', [Api\ContactController::class, 'destroy']);
        Route::put('/contacts/{contact}/status', [Api\ContactController::class, 'setStatus']);
        Route::put('/contacts/{contact}/tags', [Api\ContactController::class, 'syncTags']);

        // Tags & custom fields
        Route::get('/tags', [Api\TagController::class, 'index']);
        Route::post('/tags', [Api\TagController::class, 'store']);
        Route::put('/tags/{tag}', [Api\TagController::class, 'update']);
        Route::delete('/tags/{tag}', [Api\TagController::class, 'destroy']);
        Route::get('/custom-fields', [Api\CustomFieldController::class, 'index']);
        Route::post('/custom-fields', [Api\CustomFieldController::class, 'store']);
        Route::put('/custom-fields/{customField}', [Api\CustomFieldController::class, 'update']);
        Route::delete('/custom-fields/{customField}', [Api\CustomFieldController::class, 'destroy']);

        // Inbox
        Route::get('/conversations', [Api\ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [Api\ConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/assign', [Api\ConversationController::class, 'assign']);
        Route::post('/conversations/{conversation}/unassign', [Api\ConversationController::class, 'unassign']);
        Route::put('/conversations/{conversation}/status', [Api\ConversationController::class, 'setStatus']);
        Route::post('/conversations/{conversation}/pause-bot', [Api\ConversationController::class, 'pauseBot']);
        Route::post('/conversations/{conversation}/resume-bot', [Api\ConversationController::class, 'resumeBot']);
        Route::post('/conversations/{conversation}/read', [Api\ConversationController::class, 'markRead']);
        Route::post('/conversations/{conversation}/typing', [Api\ConversationController::class, 'typing']);
        Route::get('/conversations/{conversation}/messages', [Api\ConversationMessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages/text', [Api\ConversationMessageController::class, 'sendText']);
        Route::post('/conversations/{conversation}/messages/media', [Api\ConversationMessageController::class, 'sendMedia']);
        Route::post('/conversations/{conversation}/messages/template', [Api\ConversationMessageController::class, 'sendTemplate']);
        Route::get('/conversations/{conversation}/notes', [Api\ConversationNoteController::class, 'index']);
        Route::post('/conversations/{conversation}/notes', [Api\ConversationNoteController::class, 'store']);

        // Automations
        Route::get('/automations', [Api\AutomationController::class, 'index']);
        Route::post('/automations', [Api\AutomationController::class, 'store']);
        Route::get('/automations/{automation}', [Api\AutomationController::class, 'show']);
        Route::put('/automations/{automation}', [Api\AutomationController::class, 'update']);
        Route::delete('/automations/{automation}', [Api\AutomationController::class, 'destroy']);
        Route::put('/automations/{automation}/draft', [Api\AutomationController::class, 'saveDraft']);
        Route::post('/automations/{automation}/validate', [Api\AutomationController::class, 'validateDraft']);
        Route::post('/automations/{automation}/publish', [Api\AutomationController::class, 'publish']);
        Route::put('/automations/{automation}/status', [Api\AutomationController::class, 'setStatus']);
        Route::get('/automations/{automation}/runs', [Api\AutomationController::class, 'runs']);
        Route::get('/automations/{automation}/runs/{runUuid}', [Api\AutomationController::class, 'runDetail']);
        Route::post('/automations/{automation}/simulate/start', [Api\SimulationController::class, 'start']);
        Route::post('/automations/{automation}/simulate/message', [Api\SimulationController::class, 'message']);

        // Templates
        Route::get('/templates', [Api\TemplateController::class, 'index']);
        Route::post('/templates', [Api\TemplateController::class, 'store']);
        Route::get('/templates/{template}', [Api\TemplateController::class, 'show']);
        Route::delete('/templates/{template}', [Api\TemplateController::class, 'destroy']);
        Route::post('/templates/sync', [Api\TemplateController::class, 'sync']);

        // Campaigns & segments
        Route::get('/campaigns', [Api\CampaignController::class, 'index']);
        Route::post('/campaigns', [Api\CampaignController::class, 'store']);
        Route::get('/campaigns/{campaign}', [Api\CampaignController::class, 'show']);
        Route::post('/campaigns/{campaign}/schedule', [Api\CampaignController::class, 'schedule']);
        Route::post('/campaigns/{campaign}/pause', [Api\CampaignController::class, 'pause']);
        Route::post('/campaigns/{campaign}/resume', [Api\CampaignController::class, 'resume']);
        Route::post('/campaigns/{campaign}/cancel', [Api\CampaignController::class, 'cancel']);
        Route::get('/campaigns/{campaign}/recipients', [Api\CampaignController::class, 'recipients']);
        Route::get('/segments', [Api\SegmentController::class, 'index']);
        Route::post('/segments', [Api\SegmentController::class, 'store']);
        Route::put('/segments/{segment}', [Api\SegmentController::class, 'update']);
        Route::delete('/segments/{segment}', [Api\SegmentController::class, 'destroy']);
        Route::post('/segments/preview', [Api\SegmentController::class, 'preview']);

        // Billing
        Route::get('/billing/summary', [Api\BillingController::class, 'summary']);
        Route::post('/billing/subscribe', [Api\BillingController::class, 'subscribe']);
        Route::post('/billing/cancel', [Api\BillingController::class, 'cancel']);

        // Dashboard, analytics, audit
        Route::get('/dashboard', [Api\DashboardController::class, 'index']);
        Route::get('/analytics', [Api\AnalyticsController::class, 'index']);
        Route::get('/audit-logs', [Api\AuditLogController::class, 'index']);
    });

    /*
    |----------------------------------------------------------------------
    | Platform admin (super admin only)
    |----------------------------------------------------------------------
    */
    Route::prefix('/admin')->middleware('super-admin')->group(function () {
        Route::get('/overview', [Api\Admin\AdminController::class, 'overview']);
        Route::get('/users', [Api\Admin\AdminController::class, 'users']);
        Route::get('/workspaces', [Api\Admin\AdminController::class, 'workspaces']);
        Route::get('/webhook-events', [Api\Admin\AdminController::class, 'webhookEvents']);
        Route::get('/failed-runs', [Api\Admin\AdminController::class, 'failedAutomationRuns']);
        Route::get('/failed-jobs', [Api\Admin\AdminController::class, 'failedJobs']);
        Route::get('/health', [Api\Admin\AdminController::class, 'health']);
        Route::get('/plans', [Api\Admin\AdminController::class, 'plans']);
        Route::put('/plans/{plan}', [Api\Admin\AdminController::class, 'updatePlan']);
        Route::get('/settings', [Api\Admin\AdminController::class, 'settings']);
        Route::put('/settings', [Api\Admin\AdminController::class, 'updateSettings']);
    });
});
