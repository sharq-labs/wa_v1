<?php

namespace App\Http\Controllers\Api;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class CampaignTrackingController extends ApiController
{
    public function link(Request $request, Workspace $workspace, Campaign $campaign, CampaignRecipient $recipient): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id && $recipient->campaign_id === $campaign->id, 404);

        $data = $request->validate([
            'destination' => ['required', 'url:http,https', 'max:2000'],
            'expires_days' => ['sometimes', 'integer', 'between:1,90'],
        ]);

        $url = URL::temporarySignedRoute(
            'campaign.track.click',
            now()->addDays((int) ($data['expires_days'] ?? 30)),
            [
                'recipient' => $recipient->id,
                'destination' => $data['destination'],
            ],
        );

        return $this->success(['url' => $url]);
    }

    public function click(
        Request $request,
        CampaignRecipient $recipient,
        CampaignAttributionService $attribution,
    ): RedirectResponse {
        $destination = (string) $request->query('destination', '');
        $parts = parse_url($destination);
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            abort(422, 'Invalid campaign destination URL.');
        }

        $attribution->recordClick($recipient);

        return redirect()->away($destination);
    }

    public function conversion(
        Request $request,
        Workspace $workspace,
        Campaign $campaign,
        CampaignAttributionService $attribution,
    ): JsonResponse {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('workspace_id', $workspace->id)],
            'name' => ['sometimes', 'string', 'max:120'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);

        $contact = Contact::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($data['contact_id']);

        $recipient = $attribution->recordConversion(
            $campaign,
            $contact,
            (string) ($data['name'] ?? 'Conversion'),
            array_key_exists('value', $data) && $data['value'] !== null ? (float) $data['value'] : null,
        );

        if (! $recipient) {
            return $this->error(__('No sent campaign recipient exists for this contact.'), [], 422);
        }

        return $this->success($recipient, __('Campaign conversion recorded.'));
    }
}
