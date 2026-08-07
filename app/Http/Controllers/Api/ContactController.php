<?php

namespace App\Http\Controllers\Api;

use App\Events\ContactUpdated;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $query = Contact::query()
            ->forWorkspace($workspace)
            ->with(['tags', 'customFieldValues.customField']);

        if ($search = $request->query('search')) {
            $query->search($search);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($tagId = $request->query('tag_id')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }
        if ($accountId = $request->query('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $accountId);
        }

        $sort = in_array($request->query('sort'), ['created_at', 'last_message_at', 'first_name'], true)
            ? $request->query('sort')
            : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $contacts = $query->orderBy($sort, $direction)
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->success([
            'items' => ContactResource::collection($contacts->items()),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'total' => $contacts->total(),
                'per_page' => $contacts->perPage(),
            ],
        ]);
    }

    public function store(Request $request, Workspace $workspace, EntitlementsService $entitlements): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);

        if (! $entitlements->canAddContact($workspace)) {
            return $this->error(__('Your plan contact limit has been reached. Please upgrade.'), [], 403);
        }

        $data = $this->validateContact($request, $workspace);
        $contact = Contact::query()->create($data + ['workspace_id' => $workspace->id]);
        $this->syncCustomFields($request, $workspace, $contact);

        return $this->success(new ContactResource($contact->load(['tags', 'customFieldValues.customField'])), __('Contact created.'), 201);
    }

    public function show(Request $request, Workspace $workspace, Contact $contact): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($contact->workspace_id === $workspace->id, 404);

        return $this->success(new ContactResource(
            $contact->load(['tags', 'customFieldValues.customField', 'conversations']),
        ));
    }

    public function update(Request $request, Workspace $workspace, Contact $contact): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($contact->workspace_id === $workspace->id, 404);

        $data = $this->validateContact($request, $workspace, $contact);
        $contact->update($data);
        $this->syncCustomFields($request, $workspace, $contact);

        broadcast(new ContactUpdated($workspace->id, ['contact_id' => $contact->id]));

        return $this->success(new ContactResource($contact->load(['tags', 'customFieldValues.customField'])), __('Contact updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, Contact $contact, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($contact->workspace_id === $workspace->id, 404);

        $audit->log('contact.delete', $workspace, $request->user(), $contact, ['phone' => $contact->phone_number]);
        $contact->delete();

        return $this->success(null, __('Contact deleted.'));
    }

    public function setStatus(Request $request, Workspace $workspace, Contact $contact): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($contact->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:active,archived,blocked'],
        ]);
        $contact->update(['status' => $data['status']]);

        return $this->success(new ContactResource($contact), __('Contact status updated.'));
    }

    public function syncTags(Request $request, Workspace $workspace, Contact $contact): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($contact->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $validTagIds = $workspace->tags()->whereIn('id', $data['tag_ids'])->pluck('id');
        $contact->tags()->sync($validTagIds);

        return $this->success(new ContactResource($contact->load('tags')), __('Tags updated.'));
    }

    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        Gate::authorize('manageContacts', $workspace);
        $fields = CustomField::query()->forWorkspace($workspace)->orderBy('id')->get();

        return response()->streamDownload(function () use ($workspace, $fields) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(
                ['id', 'phone_number', 'first_name', 'last_name', 'display_name', 'email', 'country', 'status', 'tags', 'created_at'],
                $fields->pluck('key')->all(),
            ));

            Contact::query()->forWorkspace($workspace)
                ->with(['tags', 'customFieldValues.customField'])
                ->chunk(500, function ($contacts) use ($out, $fields) {
                    foreach ($contacts as $contact) {
                        $row = array_merge([
                            $contact->id,
                            $contact->phone_number,
                            $contact->first_name,
                            $contact->last_name,
                            $contact->display_name,
                            $contact->email,
                            $contact->country,
                            $contact->status,
                            $contact->tags->pluck('name')->implode('|'),
                            $contact->created_at?->toDateTimeString(),
                        ], $fields->map(fn ($f) => $contact->customFieldValue($f->key))->all());

                        fputcsv($out, array_map(fn ($value) => $this->csvSafe($value), $row));
                    }
                });

            fclose($out);
        }, 'contacts.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function csvSafe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Spreadsheet applications may execute cells starting with these
        // characters as formulas. Prefix with an apostrophe to force text.
        return preg_match('/^[=+\-@]/u', ltrim($value)) === 1 ? "'".$value : $value;
    }

    protected function validateContact(Request $request, Workspace $workspace, ?Contact $contact = null): array
    {
        return $request->validate([
            'phone_number' => [
                $contact ? 'sometimes' : 'required', 'string', 'max:32',
                Rule::unique('contacts', 'phone_number')
                    ->where('workspace_id', $workspace->id)
                    ->ignore($contact?->id),
            ],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'language' => ['nullable', 'string', 'max:10'],
            'opt_in_status' => ['sometimes', 'in:opted_in,opted_out,unknown'],
            'whatsapp_account_id' => ['nullable', 'integer',
                Rule::exists('whatsapp_accounts', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);
    }

    protected function syncCustomFields(Request $request, Workspace $workspace, Contact $contact): void
    {
        $values = $request->input('custom_fields');
        if (! is_array($values)) {
            return;
        }

        $fields = CustomField::query()->forWorkspace($workspace)->get()->keyBy('key');
        foreach ($values as $key => $value) {
            if ($fields->has($key)) {
                $contact->setCustomFieldValue($fields[$key], $value === null ? null : (string) $value);
            }
        }
    }
}
