<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessContactImport;
use App\Models\ContactImport;
use App\Models\Workspace;
use App\Services\Contacts\SpreadsheetContactReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ContactImportController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $imports = ContactImport::query()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return $this->success([
            'items' => $imports->items(),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'total' => $imports->total(),
            ],
        ]);
    }

    public function upload(Request $request, Workspace $workspace, SpreadsheetContactReader $reader): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            return $this->error(__('Only CSV and XLSX contact imports are supported.'), [], 422);
        }

        $disk = 'local';
        $path = $file->store("contact-imports/{$workspace->id}", $disk);
        $absolute = Storage::disk($disk)->path($path);

        try {
            $preview = $reader->preview($absolute, $extension, 10);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);

            return $this->error(__('Unable to read the contact import file.'), [
                'file' => [$e->getMessage()],
            ], 422);
        }

        if (! in_array('phone_number', array_keys($reader->suggestedMapping($preview['headers'])), true)) {
            // The user can still manually map a non-standard phone column on the next step.
        }

        $import = ContactImport::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'status' => 'uploaded',
            'headers' => $preview['headers'],
            'total_rows' => $preview['total_rows'],
        ]);

        return $this->success([
            'import' => $import,
            'headers' => $preview['headers'],
            'sample_rows' => $preview['rows'],
            'suggested_mapping' => $reader->suggestedMapping($preview['headers']),
            'supported_targets' => [
                'phone_number', 'first_name', 'last_name', 'display_name',
                'email', 'country', 'language', 'opt_in_status', 'custom.<field_key>',
            ],
        ], __('Contact import uploaded. Review the field mapping before starting.'), 201);
    }

    public function start(Request $request, Workspace $workspace, ContactImport $contactImport): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($contactImport->workspace_id === $workspace->id, 404);

        if (! in_array($contactImport->status, ['uploaded', 'failed'], true)) {
            return $this->error(__('This import has already been started.'), [], 422);
        }

        $headers = $contactImport->headers ?? [];
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.phone_number' => ['required', 'string', Rule::in($headers)],
            'mapping.*' => ['nullable', 'string'],
            'update_existing' => ['sometimes', 'boolean'],
            'overwrite_empty' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array', 'max:50'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('workspace_id', $workspace->id)],
            'whatsapp_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('workspace_id', $workspace->id)],
        ]);

        foreach ($data['mapping'] as $target => $source) {
            if ($source !== null && $source !== '' && ! in_array($source, $headers, true)) {
                return $this->error(__('A mapped source column does not exist in this file.'), [
                    'mapping' => ["{$target}: {$source}"],
                ], 422);
            }
        }

        $contactImport->update([
            'mapping' => $data['mapping'],
            'options' => [
                'update_existing' => $data['update_existing'] ?? true,
                'overwrite_empty' => $data['overwrite_empty'] ?? false,
                'tag_ids' => $data['tag_ids'] ?? [],
                'whatsapp_account_id' => $data['whatsapp_account_id'] ?? null,
            ],
            'status' => 'queued',
            'errors' => null,
            'completed_at' => null,
        ]);

        ProcessContactImport::dispatch($contactImport->id);

        return $this->success($contactImport->fresh(), __('Contact import queued.'));
    }

    public function show(Request $request, Workspace $workspace, ContactImport $contactImport): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($contactImport->workspace_id === $workspace->id, 404);

        return $this->success($contactImport);
    }
}
