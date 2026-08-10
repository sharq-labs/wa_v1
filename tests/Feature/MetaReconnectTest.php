<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('restores the same WhatsApp account row when a disconnected Meta phone reconnects', function () {
    config()->set('meta.app_id', '123456789');
    config()->set('meta.app_secret', 'app-secret');
    config()->set('meta.graph_api_version', 'v21.0');
    config()->set('meta.graph_base_url', 'https://graph.facebook.com');

    $ctx = createWorkspaceContext();
    $account = $ctx['account'];
    $account->update([
        'provider' => 'meta',
        'waba_id' => 'waba-123',
        'phone_number_id' => 'phone-123',
    ]);
    $originalAccountId = $account->id;

    $contact = Contact::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $account->id,
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $account->id,
        'contact_id' => $contact->id,
    ]);

    $account->update(['status' => 'disconnected', 'access_token' => null]);
    $account->delete();

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/oauth/access_token') => Http::response(['access_token' => 'new-business-token', 'expires_in' => 3600]),
            str_ends_with($path, '/waba-123/phone_numbers') => Http::response(['data' => [[
                'id' => 'phone-123',
                'display_phone_number' => '+201000000000',
                'verified_name' => 'Reconnected Store',
                'quality_rating' => 'GREEN',
                'messaging_limit_tier' => 'TIER_250',
            ]]]),
            str_ends_with($path, '/waba-123') => Http::response([
                'id' => 'waba-123',
                'owner_business_info' => ['id' => 'business-123'],
            ]),
            str_ends_with($path, '/phone-123/register') => Http::response(['success' => true]),
            str_ends_with($path, '/waba-123/subscribed_apps') => Http::response(['success' => true]),
            default => Http::response([], 404),
        };
    });

    $response = $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/meta/embedded-signup/complete", [
            'code' => 'new-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-123',
            'business_id' => 'business-123',
            'pin' => '482615',
        ])
        ->assertCreated();

    expect($response->json('data.id'))->toBe($originalAccountId)
        ->and($response->json('data.status'))->toBe('connected')
        ->and($conversation->fresh()->whatsapp_account_id)->toBe($originalAccountId)
        ->and($ctx['workspace']->whatsappAccounts()->where('phone_number_id', 'phone-123')->count())->toBe(1);

    $this->assertDatabaseHas('whatsapp_accounts', [
        'id' => $originalAccountId,
        'deleted_at' => null,
        'status' => 'connected',
    ]);

    $restored = WhatsAppAccount::withTrashed()->findOrFail($originalAccountId);
    expect($restored->access_token)->toBe('new-business-token');
});
