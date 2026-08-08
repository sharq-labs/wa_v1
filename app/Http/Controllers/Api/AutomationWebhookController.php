<?php

namespace App\Http\Controllers\Api;

use App\Enums\NodeType;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Automation;
use App\Models\AutomationWebhookEndpoint;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AutomationWebhookController extends ApiController
{
    public function show(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $endpoint = AutomationWebhookEndpoint::query()
            ->where('workspace_id', $workspace->id)
            ->where('automation_id', $automation->id)
            ->first();

        return $this->success($endpoint ? [
            'public_key' => $endpoint->public_key,
            'url' => url('/api/automation-hooks/'.$endpoint->public_key),
            'is_active' => $endpoint->is_active,
            'last_used_at' => $endpoint->last_used_at?->toIso8601String(),
            'signature_header' => 'X-WhatsFlow-Signature',
            'signature_format' => 'sha256=<hex hmac of raw request body>',
        ] : null);
    }

    public function rotate(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $secret = Str::random(64);
        $endpoint = AutomationWebhookEndpoint::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'automation_id' => $automation->id,
            ],
            [
                'public_key' => Str::lower(Str::random(40)),
                'secret' => $secret,
                'is_active' => true,
            ],
        );

        return $this->success([
            'public_key' => $endpoint->public_key,
            'url' => url('/api/automation-hooks/'.$endpoint->public_key),
            // Returned once. The model never serializes the encrypted secret.
            'secret' => $secret,
            'signature_header' => 'X-WhatsFlow-Signature',
            'signature_format' => 'sha256=<hex hmac of raw request body>',
        ], __('Webhook secret rotated. Update the sender before using the old endpoint again.'));
    }

    public function receive(Request $request, string $publicKey): JsonResponse
    {
        $endpoint = AutomationWebhookEndpoint::query()
            ->where('public_key', $publicKey)
            ->where('is_active', true)
            ->with(['automation.publishedVersion'])
            ->first();

        if (! $endpoint || ! $endpoint->automation?->publishedVersion) {
            return response()->json(['success' => false, 'message' => 'Webhook endpoint not found.'], 404);
        }

        $signature = trim((string) $request->header('X-WhatsFlow-Signature', ''));
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $request->getContent(), (string) $endpoint->secret);

        if ($provided === '' || ! hash_equals($expected, strtolower($provided))) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $body = $request->json()->all();
        $workspace = Workspace::query()->findOrFail($endpoint->workspace_id);
        $contact = $this->resolveContact($workspace, $body);

        ProcessAutomationEvent::dispatch(
            $workspace->id,
            NodeType::TriggerWebhook->value,
            $contact?->id,
            [
                'automation_id' => $endpoint->automation_id,
                'webhook_key' => $endpoint->public_key,
                'webhook_body' => $body,
                'source' => 'signed_webhook',
            ],
        )->afterCommit();

        $endpoint->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'success' => true,
            'message' => 'Webhook accepted.',
            'contact_id' => $contact?->id,
        ], 202);
    }

    protected function resolveContact(Workspace $workspace, array $body): ?Contact
    {
        if (isset($body['contact_id']) && is_numeric($body['contact_id'])) {
            $contact = Contact::query()
                ->where('workspace_id', $workspace->id)
                ->find((int) $body['contact_id']);
            if ($contact) {
                return $contact;
            }
        }

        $phone = trim((string) ($body['phone_number'] ?? $body['wa_id'] ?? ''));
        if ($phone === '') {
            return null;
        }

        return Contact::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($phone) {
                $query->where('phone_number', $phone)->orWhere('wa_id', $phone);
            })
            ->first();
    }
}
