<?php

namespace App\Http\Controllers\Api;

use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\EntitlementsService;
use App\Services\Messaging\MessagingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WhatsAppAccountController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->success(
            $workspace->whatsappAccounts()->latest()->get(),
        );
    }

    /**
     * Connect a fake WhatsApp number for development / demos / Meta review.
     */
    public function connectFake(Request $request, Workspace $workspace, EntitlementsService $entitlements, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageWhatsAppAccounts', $workspace);

        if (! app()->environment(['local', 'testing']) && ! config('whatsapp.allow_fake_accounts', false)) {
            return $this->error(__('Fake WhatsApp accounts are disabled in this environment.'), [], 404);
        }

        if (! $entitlements->canAddWhatsAppAccount($workspace)) {
            return $this->error(
                __('Your plan limit for WhatsApp numbers has been reached. Please upgrade your plan.'),
                ['plan' => ['limit_reached']],
                403,
            );
        }

        $data = $request->validate([
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'verified_name' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $workspace->whatsappAccounts()->create([
            'provider' => 'fake',
            'meta_business_id' => 'fake-business-'.Str::random(8),
            'waba_id' => 'fake-waba-'.Str::random(8),
            'phone_number_id' => 'fake-phone-'.Str::random(12),
            'display_phone_number' => $data['display_phone_number'] ?? '+2010'.random_int(10000000, 99999999),
            'verified_name' => $data['verified_name'] ?? $workspace->name,
            'access_token' => 'fake-token-'.Str::random(32),
            'quality_rating' => 'GREEN',
            'messaging_limit' => 'TIER_1K',
            'status' => 'connected',
            'last_sync_at' => now(),
        ]);

        $audit->log('whatsapp.connect', $workspace, $request->user(), $account, ['provider' => 'fake']);

        return $this->success($account, __('WhatsApp number connected.'), 201);
    }

    public function show(Request $request, Workspace $workspace, WhatsAppAccount $account): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($account->workspace_id === $workspace->id, 404);

        return $this->success($account);
    }

    public function disconnect(Request $request, Workspace $workspace, WhatsAppAccount $account, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageWhatsAppAccounts', $workspace);
        abort_unless($account->workspace_id === $workspace->id, 404);

        $account->update(['status' => 'disconnected', 'access_token' => null]);
        $account->delete();

        $audit->log('whatsapp.disconnect', $workspace, $request->user(), $account);

        return $this->success(null, __('WhatsApp number disconnected.'));
    }

    public function syncTemplates(Request $request, Workspace $workspace, WhatsAppAccount $account, MessagingManager $manager): JsonResponse
    {
        Gate::authorize('manageWhatsAppAccounts', $workspace);
        abort_unless($account->workspace_id === $workspace->id, 404);

        $count = $manager->forAccount($account)->syncTemplates($account);

        return $this->success(['synced' => $count], __(':count templates synced.', ['count' => $count]));
    }
}
