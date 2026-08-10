<?php

use App\Enums\MessageStatus;
use App\Enums\WorkspaceRole;
use App\Events\MessageStatusChanged;
use App\Jobs\SendOutboundMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Mockery\MockInterface;

it('normalizes unsafe API pagination values instead of crashing list endpoints', function () {
    $ctx = createWorkspaceContext();
    Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);

    foreach (['0', '-5', 'nope'] as $perPage) {
        $response = $this->actingAs($ctx['user'])
            ->getJson("/api/workspaces/{$ctx['workspace']->id}/contacts?per_page={$perPage}")
            ->assertOk();

        expect($response->json('data.meta.current_page'))->toBe(1);
    }
});

it('blocks fake WhatsApp account creation outside local and testing unless explicitly enabled', function () {
    $ctx = createWorkspaceContext();
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn () => 'production');
        config(['whatsapp.allow_fake_accounts' => false]);

        $this->actingAs($ctx['user'])
            ->postJson("/api/workspaces/{$ctx['workspace']->id}/whatsapp-accounts/connect-fake")
            ->assertNotFound();
    } finally {
        app()->detectEnvironment(fn () => $originalEnvironment);
    }
});

it('blocks the fake messaging provider itself outside safe environments', function () {
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn () => 'production');
        config(['whatsapp.allow_fake_accounts' => false]);

        expect(fn () => app(MessagingManager::class)->driver('fake'))
            ->toThrow(InvalidArgumentException::class, 'Fake WhatsApp provider is disabled');
    } finally {
        app()->detectEnvironment(fn () => $originalEnvironment);
    }
});

it('lets only platform super admins access Horizon', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $regularUser = User::factory()->create(['is_super_admin' => false]);

    expect(Gate::forUser($superAdmin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($regularUser)->allows('viewHorizon'))->toBeFalse();
});

it('does not add viewer-only users to agent teams', function () {
    $ctx = createWorkspaceContext();
    $agent = addAgent($ctx['workspace'], 'Inbox Agent');
    $viewer = addAgent($ctx['workspace'], 'Viewer User', WorkspaceRole::Viewer);

    $response = $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/teams", [
            'name' => 'Support',
            'member_ids' => [$agent->id, $viewer->id],
        ])
        ->assertCreated();

    expect(collect($response->json('data.members'))->pluck('id')->all())
        ->toBe([$agent->id]);
});

it('rejects ambiguous string typing states instead of treating false as true', function () {
    $ctx = createWorkspaceContext();
    $contact = Contact::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'contact_id' => $contact->id,
    ]);

    $this->actingAs($ctx['user'])
        ->postJson("/api/workspaces/{$ctx['workspace']->id}/conversations/{$conversation->id}/typing", [
            'typing' => 'false',
        ])
        ->assertUnprocessable();
});

it('broadcasts a failed message status when the provider throws', function () {
    Event::fake([MessageStatusChanged::class]);

    $ctx = createWorkspaceContext();
    $contact = Contact::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'wa_id' => '201011112222',
    ]);
    $conversation = Conversation::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'contact_id' => $contact->id,
    ]);
    $message = Message::factory()->create([
        'workspace_id' => $ctx['workspace']->id,
        'conversation_id' => $conversation->id,
        'contact_id' => $contact->id,
        'whatsapp_account_id' => $ctx['account']->id,
        'provider_message_id' => null,
        'direction' => 'outbound',
        'sender_type' => 'agent',
        'message_type' => 'text',
        'status' => MessageStatus::Queued,
        'content' => 'hello',
    ]);

    $manager = $this->mock(MessagingManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forAccount')->once()->andThrow(new RuntimeException('provider exploded'));
    });

    (new SendOutboundMessage($message->id))->handle(app(MessageService::class), $manager);

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->fresh()->error_code)->toBe('delivery_exception');

    Event::assertDispatched(MessageStatusChanged::class, fn ($event) => $event->data['message_id'] === $message->id
        && $event->data['status'] === MessageStatus::Failed->value);
});
