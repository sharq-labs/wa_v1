<?php

namespace App\Http\Controllers\Api;

use App\Models\CustomField;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomFieldController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->success(
            CustomField::query()->forWorkspace($workspace)->orderBy('name')->get(),
        );
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'key' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'type' => ['required', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'required' => ['sometimes', 'boolean'],
        ]);

        $data['key'] = $data['key'] ?? Str::snake(Str::ascii($data['name']));

        $exists = CustomField::query()->forWorkspace($workspace)->where('key', $data['key'])->exists();
        if ($exists) {
            return $this->error(__('A field with this key already exists.'), [
                'key' => [__('A field with this key already exists.')],
            ]);
        }

        $field = CustomField::query()->create($data + ['workspace_id' => $workspace->id]);

        return $this->success($field, __('Custom field created.'), 201);
    }

    public function update(Request $request, Workspace $workspace, CustomField $customField): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($customField->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'default_value' => ['nullable', 'string', 'max:255'],
            'required' => ['sometimes', 'boolean'],
        ]);

        $customField->update($data);

        return $this->success($customField, __('Custom field updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, CustomField $customField): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($customField->workspace_id === $workspace->id, 404);

        $customField->delete();

        return $this->success(null, __('Custom field deleted.'));
    }
}
