<?php

use App\Models\Contact;
use App\Services\Messaging\InboundMessageService;

it('opts contacts out and back in from WhatsApp system keywords', function () {
    $ctx = createWorkspaceContext();
    $service = app(InboundMessageService::class);

    $service->ingest($ctx['account'], [
        'provider_message_id' => 'wamid.consent.stop',
        'wa_id' => '201099999991',
        'phone_number' => '201099999991',
        'profile_name' => 'Consent User',
        'type' => 'text',
        'text' => 'STOP',
    ]);

    $contact = Contact::query()->where('wa_id', '201099999991')->firstOrFail();
    expect($contact->opt_in_status)->toBe('opted_out')
        ->and($contact->opt_out_at)->not->toBeNull()
        ->and($contact->consent_source)->toBe('whatsapp_keyword');

    $service->ingest($ctx['account'], [
        'provider_message_id' => 'wamid.consent.start',
        'wa_id' => '201099999991',
        'phone_number' => '201099999991',
        'profile_name' => 'Consent User',
        'type' => 'text',
        'text' => 'START',
    ]);

    $contact->refresh();
    expect($contact->opt_in_status)->toBe('opted_in')
        ->and($contact->opt_in_at)->not->toBeNull()
        ->and($contact->opt_out_at)->toBeNull()
        ->and($contact->consent_source)->toBe('whatsapp_keyword');
});

it('supports Arabic opt in and opt out keywords', function () {
    $ctx = createWorkspaceContext();
    $service = app(InboundMessageService::class);

    $service->ingest($ctx['account'], [
        'provider_message_id' => 'wamid.consent.ar.in',
        'wa_id' => '201099999992',
        'phone_number' => '201099999992',
        'type' => 'text',
        'text' => 'اشترك',
    ]);

    $contact = Contact::query()->where('wa_id', '201099999992')->firstOrFail();
    expect($contact->opt_in_status)->toBe('opted_in');

    $service->ingest($ctx['account'], [
        'provider_message_id' => 'wamid.consent.ar.out',
        'wa_id' => '201099999992',
        'phone_number' => '201099999992',
        'type' => 'text',
        'text' => 'إلغاء الاشتراك',
    ]);

    expect($contact->fresh()->opt_in_status)->toBe('opted_out');
});
