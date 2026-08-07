<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    /**
     * GET verification handshake.
     */
    public function verify(Request $request): Response
    {
        if (
            $request->query('hub_mode') === 'subscribe'
            && hash_equals((string) config('meta.webhook_verify_token'), (string) $request->query('hub_verify_token'))
        ) {
            return response($request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * POST event receiver. Verifies the signature, stores the raw event and
     * acknowledges immediately; all heavy work happens on the queue.
     */
    public function receive(Request $request): Response
    {
        if (! $this->verifySignature($request)) {
            Log::warning('Meta webhook signature verification failed');

            return response('Invalid signature', 403);
        }

        $payload = $request->json()->all();
        $eventId = $this->extractEventId($payload);

        $event = null;

        try {
            $event = WebhookEvent::query()->create([
                'provider' => 'meta',
                'event_id' => $eventId,
                'event_type' => $this->extractEventType($payload),
                'payload' => $payload,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Duplicate delivery of the same event: acknowledge, do nothing.
            return response('OK', 200);
        }

        ProcessWebhookEvent::dispatch($event->id);

        return response('OK', 200);
    }

    protected function verifySignature(Request $request): bool
    {
        $secret = config('meta.app_secret');

        // Without an app secret configured (local fake mode) accept the call.
        if (! $secret) {
            return app()->environment(['local', 'testing']);
        }

        $signature = $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    protected function extractEventId(array $payload): string
    {
        // Meta does not send a global event id; derive a deterministic one so
        // duplicate deliveries collide on the unique(provider, event_id) index.
        $ids = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $m) {
                    $ids[] = 'm:'.($m['id'] ?? '');
                }
                foreach ($value['statuses'] ?? [] as $s) {
                    $ids[] = 's:'.($s['id'] ?? '').':'.($s['status'] ?? '');
                }
                if (($change['field'] ?? '') === 'message_template_status_update') {
                    $ids[] = 't:'.($value['message_template_id'] ?? '').':'.($value['event'] ?? '');
                }
            }
        }

        return $ids !== []
            ? sha1(implode('|', $ids))
            : sha1(json_encode($payload));
    }

    protected function extractEventType(array $payload): string
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                return $change['field'] ?? 'unknown';
            }
        }

        return 'unknown';
    }
}
