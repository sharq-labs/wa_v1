<?php

namespace App\Http\Controllers\Api;

use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaEmbeddedSignupController extends ApiController
{
    public function config(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageWhatsAppAccounts', $workspace);

        return $this->success([
            'app_id' => config('meta.app_id'),
            'config_id' => config('meta.embedded_signup_config_id') ?: config('meta.config_id'),
            'graph_api_version' => config('meta.graph_api_version'),
            'enabled' => (bool) (config('meta.app_id') && config('meta.app_secret')),
        ]);
    }

    public function complete(Request $request, Workspace $workspace, EntitlementsService $entitlements, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageWhatsAppAccounts', $workspace);

        if (! $entitlements->canAddWhatsAppAccount($workspace)) {
            return $this->error(__('Your plan limit for WhatsApp numbers has been reached.'), [], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['nullable', 'string'],
        ]);

        if (! config('meta.app_id') || ! config('meta.app_secret')) {
            return $this->error(__('Meta integration is not configured on this server.'), [], 503);
        }

        $base = rtrim(config('meta.graph_base_url'), '/').'/'.config('meta.graph_api_version');
        $client = Http::acceptJson()->connectTimeout(5)->timeout(15)->retry(2, 250, throw: false);

        $tokenResponse = $client->get("{$base}/oauth/access_token", [
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'code' => $data['code'],
        ]);

        if (! $tokenResponse->successful() || ! $tokenResponse->json('access_token')) {
            Log::warning('Embedded signup token exchange failed', ['status' => $tokenResponse->status()]);

            return $this->error(__('Could not complete WhatsApp connection. Please try again.'), [], 502);
        }

        $accessToken = $tokenResponse->json('access_token');
        $expiresIn = $tokenResponse->json('expires_in');
        $wabaId = $data['waba_id'];
        $phoneNumberId = $data['phone_number_id'];
        $authorized = Http::withToken($accessToken)->acceptJson()
            ->connectTimeout(5)->timeout(15)->retry(2, 250, throw: false);

        $wabaResponse = $authorized->get("{$base}/{$wabaId}", ['fields' => 'id,name,owner_business_info']);
        if (! $wabaResponse->successful()) {
            return $this->error(__('Could not read the WhatsApp Business Account returned by Meta.'), [], 502);
        }
        $waba = $wabaResponse->json();

        $phonesResponse = $authorized->get("{$base}/{$wabaId}/phone_numbers", [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier',
        ]);
        if (! $phonesResponse->successful()) {
            return $this->error(__('Could not read phone numbers from the WhatsApp Business Account.'), [], 502);
        }

        $phones = $phonesResponse->json('data', []);
        $phone = collect($phones)->firstWhere('id', $phoneNumberId) ?? ($phones[0] ?? null);
        if (! $phone) {
            return $this->error(__('No phone number found on the WhatsApp Business Account.'), [], 422);
        }

        $subscribeResponse = $authorized->post("{$base}/{$wabaId}/subscribed_apps");
        if (! $subscribeResponse->successful() || $subscribeResponse->json('success') === false) {
            Log::warning('Embedded signup WABA subscription failed', [
                'waba_id' => $wabaId,
                'status' => $subscribeResponse->status(),
            ]);

            return $this->error(__('WhatsApp was authorized but webhook subscription failed. The account was not connected.'), [], 502);
        }

        $account = $workspace->whatsappAccounts()->updateOrCreate(
            ['phone_number_id' => $phone['id']],
            [
                'provider' => 'meta',
                'meta_business_id' => $waba['owner_business_info']['id'] ?? null,
                'waba_id' => $wabaId,
                'display_phone_number' => $phone['display_phone_number'] ?? null,
                'verified_name' => $phone['verified_name'] ?? null,
                'access_token' => $accessToken,
                'token_expiration' => $expiresIn ? now()->addSeconds((int) $expiresIn) : null,
                'quality_rating' => $phone['quality_rating'] ?? null,
                'messaging_limit' => $phone['messaging_limit_tier'] ?? null,
                'status' => 'connected',
                'last_sync_at' => now(),
            ],
        );

        $audit->log('whatsapp.connect', $workspace, $request->user(), $account, ['provider' => 'meta']);

        return $this->success($account, __('WhatsApp Business account connected.'), 201);
    }
}
