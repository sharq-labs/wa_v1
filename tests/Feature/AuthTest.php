<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a user and creates their first workspace', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'super-secret-1',
        'password_confirmation' => 'super-secret-1',
        'workspace_name' => 'My Business',
    ]);

    $response->assertCreated()->assertJsonPath('success', true);

    $user = User::query()->where('email', 'new@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->workspaces)->toHaveCount(1)
        ->and($user->workspaces->first()->name)->toBe('My Business')
        ->and($user->workspaces->first()->pivot->role)->toBe('owner')
        ->and($user->current_workspace_id)->toBe($user->workspaces->first()->id);
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create(['password' => 'password-123']);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password-123',
    ])->assertOk()->assertJsonPath('success', true);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => 'password-123']);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong',
    ])->assertStatus(422)->assertJsonPath('success', false);
});

it('returns the authenticated profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', $user->email);
});

it('changes the password when the current password matches', function () {
    $user = User::factory()->create(['password' => 'old-password-1']);

    $this->actingAs($user)->putJson('/api/auth/password', [
        'current_password' => 'old-password-1',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

it('blocks unauthenticated access to protected endpoints', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
    $this->getJson('/api/workspaces')->assertUnauthorized();
});
