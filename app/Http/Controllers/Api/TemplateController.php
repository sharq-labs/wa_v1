<?php

namespace App\Http\Controllers\Api;

use App\Enums\TemplateStatus;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Messaging\MessagingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TemplateController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $query = WhatsAppTemplate::query()->forWorkspace($workspace)
            ->with('whatsappAccount:id,display_phone_number,verified_name');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($accountId = $request->query('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $accountId);
        }
        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->success($query->orderBy('name')->get());
    }

    public function store(Request $request, Workspace $workspace, MessagingManager $manager): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);

        $data = $request->validate([
            'whatsapp_account_id' => ['required', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('workspace_id', $workspace->id)],
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:10'],
            'category' => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'header_type' => ['nullable', 'in:none,text,image,video,document'],
            'header_content' => ['nullable', 'string', 'max:2048'],
            'body' => ['required', 'string', 'max:1024'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required_with:buttons', 'in:quick_reply,url,phone'],
            'buttons.*.text' => ['required_with:buttons', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone' => ['nullable', 'string', 'max:32'],
            'variables' => ['nullable', 'array'],
        ]);

        if (WhatsAppTemplate::query()
            ->where('whatsapp_account_id', $data['whatsapp_account_id'])
            ->where('name', $data['name'])
            ->where('language', $data['language'])->exists()) {
            return $this->error(__('A template with this name and language already exists.'), [
                'name' => [__('Already exists.')],
            ]);
        }

        preg_match_all('/\{\{(\d+)\}\}/', $data['body'], $matches);
        foreach (array_unique($matches[1] ?? []) as $index) {
            if (trim((string) ($data['variables'][$index] ?? '')) === '') {
                return $this->error(__('Provide a sample value for variable {{:index}}.', ['index' => $index]), [
                    'variables' => [__('Sample values are required for all variables.')],
                ]);
            }
        }

        $account = $workspace->whatsappAccounts()->findOrFail($data['whatsapp_account_id']);
        if (! $account->isConnected()) {
            return $this->error(__('The selected WhatsApp account is not connected.'), [], 422);
        }

        try {
            $remote = $manager->forAccount($account)->createTemplate($account, $data);
        } catch (\Throwable $e) {
            report($e);
            return $this->error(__('Meta rejected the template submission: :message', ['message' => $e->getMessage()]), [], 422);
        }

        $status = match (strtoupper((string) ($remote['status'] ?? 'PENDING'))) {
            'APPROVED' => TemplateStatus::Approved,
            'REJECTED' => TemplateStatus::Rejected,
            'PAUSED' => TemplateStatus::Paused,
            'DISABLED' => TemplateStatus::Disabled,
            default => TemplateStatus::Pending,
        };

        $template = WhatsAppTemplate::query()->create($data + [
            'workspace_id' => $workspace->id,
            'meta_template_id' => $remote['id'] ?? null,
            'status' => $status,
            'category' => $remote['category'] ?? $data['category'],
            'header_type' => ($data['header_type'] ?? 'none') === 'none' ? null : ($data['header_type'] ?? null),
            'last_synced_at' => now(),
        ]);

        return $this->success($template, __('Template submitted to Meta for review.'), 201);
    }

    public function show(Request $request, Workspace $workspace, WhatsAppTemplate $template): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($template->workspace_id === $workspace->id, 404);

        return $this->success($template->load('whatsappAccount:id,display_phone_number,verified_name'));
    }

    public function destroy(Request $request, Workspace $workspace, WhatsAppTemplate $template, AuditLogger $audit, MessagingManager $manager): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($template->workspace_id === $workspace->id, 404);

        $account = $template->whatsappAccount;
        if ($account && $account->isConnected() && $template->meta_template_id) {
            if (! $manager->forAccount($account)->deleteTemplate($account, $template->name)) {
                return $this->error(__('Could not delete the template from WhatsApp. Nothing was deleted locally.'), [], 502);
            }
        }

        $audit->log('template.delete', $workspace, $request->user(), $template, ['name' => $template->name]);
        $template->delete();

        return $this->success(null, __('Template deleted.'));
    }

    public function sync(Request $request, Workspace $workspace, MessagingManager $manager): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);

        $total = 0;
        foreach ($workspace->whatsappAccounts()->where('status', 'connected')->get() as $account) {
            $total += $manager->forAccount($account)->syncTemplates($account);
        }

        return $this->success(['synced' => $total], __(':count templates synced.', ['count' => $total]));
    }
}
