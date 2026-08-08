<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('meta.app_id', '123456789');
    config()->set('meta.app_secret', 'app-secret');
    config()->set('meta.embedded_signup_config_id', 'config-123');
    config()->set('meta.config_id', null);
    config()->set('meta.graph_api_version', 'v21.0');
    config()->set('meta.graph_base_url', 'https://graph.facebook.com');
});

it('reports whether Meta Embedded Signup is fully configured', function () {
    $ctx = createWorkspaceContext();

    $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$ctx['workspace']->id}/meta/embedded-signup/config")
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.app_id', '123456789')
        ->assertJsonPath('data.config_id', 'config-123')
        ->assertJsonPath('data.missing', []);

    config()->set('meta.embedded_signup_config_id', null);

    $this->actingAs($ctx['user'])
        ->getJson("/api/workspaces/{$ctx['workspace']->id}/meta/embedded-signup/config")
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.missing.0', 'META_EMBEDDED_SIGNUP_CONFIG_ID');
});

it('registers the phone and subscribes the WABA before saving the Meta account', function () {
    $ctx = createWorkspaceContext();
    $ctx['account']->delete();

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/oauth/access_token')) {
            return Http::response(['access_token' => 'business-token', 'expires_in' => 3600]);
        }

        if (str_ends_with($url, '/waba-123')) {
            return Http::response([
                'id' => 'waba-123',
                'name' => 'Customer WABA',
                'owner_business_info' => ['id' => 'business-123'],
            ]);
        }

        if (str_contains($url, '/waba-123/phone_numbers')) {
            return Http::response([
                'data' => [[
                    'id' => 'phone-123',
                    'display_phone_number' => '+201000000000',
                    'verified_name' => 'Demo Store',
                    'quality_rating' => 'GREEN',
                    'messaging_limit_tier' => 'TIER_250',
                ]],
            ]);
        }

        if (str_ends_with($url, '/phone-123/register')) {
            expect($request->method())->toBe('POST')
                ->and($request['messaging_product'])->toBe('whatsapp')
                ->and($request['pin'])->toBe('482615');

            return Http::response(['success' => true]);
        }

        if (str_ends_with($url, '/waba-123/subscribed_apps')) {
            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/meta/embedded-signup/complete", [
            'code' => 'embedded-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-123',
            'business_id' => 'business-123',
            'pin' => '482615',
        ])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'meta')
        ->assertJsonPath('data.waba_id', 'waba-123')
        ->assertJsonPath('data.phone_number_id', 'phone-123')
        ->assertJsonPath('data.status', 'connected');

    $this->assertDatabaseHas('whatsapp_accounts', [
        'workspace_id' => $ctx['workspace']->id,
        'provider' => 'meta',
        'waba_id' => 'waba-123',
        'phone_number_id' => 'phone-123',
        'meta_business_id' => 'business-123',
        'status' => 'connected',
    ]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/phone-123/register'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/waba-123/subscribed_apps'));
});

it('does not save the account when Meta phone registration fails', function () {
    $ctx = createWorkspaceContext();
    $ctx['account']->delete();

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/oauth/access_token')) {
            return Http::response(['access_token' => 'business-token']);
        }

        if (str_ends_with($url, '/waba-123')) {
            return Http::response(['id' => 'waba-123', 'owner_business_info' => ['id' => 'business-123']]);
        }

        if (str_contains($url, '/waba-123/phone_numbers')) {
            return Http::response(['data' => [[
                'id' => 'phone-123',
                'display_phone_number' => '+201000000000',
                'verified_name' => 'Demo Store',
            ]]]);
        }

        if (str_ends_with($url, '/phone-123/register')) {
            return Http::response(['error' => ['message' => 'Invalid PIN']], 400);
        }

        return Http::response([], 404);
    });

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/meta/embedded-signup/complete", [
            'code' => 'embedded-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-123',
            'business_id' => 'business-123',
            'pin' => '482615',
        ])
        ->assertStatus(502);

    $this->assertDatabaseMissing('whatsapp_accounts', [
        'workspace_id' => $ctx['workspace']->id,
        'phone_number_id' => 'phone-123',
    ]);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/waba-123/subscribed_apps'));
});
