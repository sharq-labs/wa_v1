<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class AdminController extends ApiController
{
    public function overview(): JsonResponse
    {
        return $this->success([
            'users' => User::query()->count(),
            'workspaces' => Workspace::query()->count(),
            'whatsapp_accounts' => WhatsAppAccount::query()->count(),
            'automations' => Automation::query()->count(),
            'campaigns' => Campaign::query()->count(),
            'subscriptions' => Subscription::query()->where('status', 'active')->count(),
            'failed_webhooks_24h' => WebhookEvent::query()
                ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'failed_runs_24h' => AutomationRun::query()
                ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->withCount('workspaces')
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"),
            ))
            ->latest()
            ->paginate(50);

        return $this->success([
            'items' => $users->items(),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ]);
    }

    public function workspaces(Request $request): JsonResponse
    {
        $workspaces = Workspace::query()
            ->with(['owner:id,name,email', 'subscription.plan:id,name,slug'])
            ->withCount(['users', 'contacts', 'automations'])
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->latest()
            ->paginate(50);

        return $this->success([
            'items' => $workspaces->items(),
            'meta' => ['current_page' => $workspaces->currentPage(), 'last_page' => $workspaces->lastPage(), 'total' => $workspaces->total()],
        ]);
    }

    public function webhookEvents(Request $request): JsonResponse
    {
        $events = WebhookEvent::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(50);

        return $this->success([
            'items' => $events->items(),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total()],
        ]);
    }

    public function failedAutomationRuns(Request $request): JsonResponse
    {
        $runs = AutomationRun::query()
            ->where('status', 'failed')
            ->with(['automation:id,name,workspace_id', 'workspace:id,name'])
            ->latest()
            ->paginate(50);

        return $this->success([
            'items' => $runs->items(),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total()],
        ]);
    }

    public function failedJobs(): JsonResponse
    {
        $jobs = DB::table('failed_jobs')->latest('failed_at')->limit(100)->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'exception' => mb_substr($job->exception, 0, 500),
            ]);

        return $this->success($jobs);
    }

    public function health(): JsonResponse
    {
        $redisOk = true;
        $dbOk = true;
        $queueDepth = null;

        try {
            Redis::connection()->ping();
            $queueDepth = [
                'default' => Redis::connection()->llen('queues:default'),
                'whatsapp-webhooks' => Redis::connection()->llen('queues:whatsapp-webhooks'),
                'whatsapp-messages' => Redis::connection()->llen('queues:whatsapp-messages'),
                'automations' => Redis::connection()->llen('queues:automations'),
                'campaigns' => Redis::connection()->llen('queues:campaigns'),
                'notifications' => Redis::connection()->llen('queues:notifications'),
            ];
        } catch (\Throwable) {
            $redisOk = false;
        }

        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        return $this->success([
            'redis' => $redisOk,
            'database' => $dbOk,
            'queues' => $queueDepth,
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'webhook_failures_24h' => WebhookEvent::query()
                ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'message_failures_24h' => Message::query()
                ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'automation_errors_24h' => AutomationRun::query()
                ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
        ]);
    }

    public function plans(): JsonResponse
    {
        return $this->success(Plan::query()->with('features')->orderBy('sort_order')->get());
    }

    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_monthly' => ['sometimes', 'integer', 'min:0'],
            'price_yearly' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
        ]);

        $plan->update(collect($data)->except('features')->all());

        foreach (($data['features'] ?? []) as $key => $value) {
            $plan->features()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }

        return $this->success($plan->load('features'), __('Plan updated.'));
    }

    public function settings(): JsonResponse
    {
        return $this->success(SystemSetting::query()->get()->groupBy('group'));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable', 'string'],
            'settings.*.group' => ['sometimes', 'string', 'max:50'],
        ]);

        foreach ($data['settings'] as $setting) {
            SystemSetting::set($setting['key'], $setting['value'] ?? null, $setting['group'] ?? 'general');
        }

        return $this->success(null, __('Settings saved.'));
    }
}
