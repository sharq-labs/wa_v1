<?php

namespace App\Console\Commands;

use App\Enums\AutomationState;
use App\Enums\NodeType;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Automation;
use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DispatchScheduledAutomations extends Command
{
    protected $signature = 'automation:dispatch-scheduled';

    protected $description = 'Dispatch due trigger_scheduled automations to their configured contact audience.';

    public function handle(): int
    {
        $utcMinute = now()->utc()->startOfMinute();
        $dispatched = 0;

        Automation::query()
            ->where('status', AutomationState::Published)
            ->whereNotNull('published_version_id')
            ->with(['workspace', 'publishedVersion'])
            ->chunkById(100, function ($automations) use ($utcMinute, &$dispatched): void {
                foreach ($automations as $automation) {
                    $trigger = $this->scheduledTrigger($automation);
                    if (! $trigger || ! $this->isDue($automation->workspace->timezone, $trigger['config'] ?? [], $utcMinute)) {
                        continue;
                    }

                    $lockKey = 'scheduled-automation:'.$automation->id.':'.$utcMinute->format('YmdHi');
                    Cache::lock($lockKey, 90)->get(function () use ($automation, $trigger, $utcMinute, &$dispatched): void {
                        $existing = DB::table('automation_schedule_ticks')
                            ->where('automation_id', $automation->id)
                            ->where('scheduled_for', $utcMinute)
                            ->first();

                        if ($existing?->dispatched_at) {
                            return;
                        }

                        $tickId = $existing?->id;
                        if (! $tickId) {
                            $tickId = DB::table('automation_schedule_ticks')->insertGetId([
                                'workspace_id' => $automation->workspace_id,
                                'automation_id' => $automation->id,
                                'scheduled_for' => $utcMinute,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        $count = $this->dispatchAudience($automation, $trigger['config'] ?? []);

                        DB::table('automation_schedule_ticks')->where('id', $tickId)->update([
                            'contacts_dispatched' => $count,
                            'dispatched_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $dispatched += $count;
                    });
                }
            });

        $this->info("Dispatched {$dispatched} scheduled automation run(s).");

        return self::SUCCESS;
    }

    protected function scheduledTrigger(Automation $automation): ?array
    {
        foreach ($automation->publishedVersion?->definitionNodes() ?? [] as $node) {
            if (($node['type'] ?? null) === NodeType::TriggerScheduled->value) {
                return $node;
            }
        }

        return null;
    }

    protected function isDue(string $workspaceTimezone, array $config, Carbon $utcMinute): bool
    {
        $timezone = trim((string) ($config['timezone'] ?? '')) ?: $workspaceTimezone ?: 'UTC';
        $local = $utcMinute->copy()->setTimezone($timezone);
        $frequency = (string) ($config['frequency'] ?? 'daily');
        $time = (string) ($config['time'] ?? '09:00');
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return match ($frequency) {
            'hourly' => $local->minute === $minute,
            'weekly' => $local->dayOfWeek === (int) ($config['weekday'] ?? 1)
                && $local->hour === $hour
                && $local->minute === $minute,
            default => $local->hour === $hour && $local->minute === $minute,
        };
    }

    protected function dispatchAudience(Automation $automation, array $config): int
    {
        $audienceType = (string) ($config['audience_type'] ?? 'all');

        if ($audienceType === 'none') {
            ProcessAutomationEvent::dispatch(
                $automation->workspace_id,
                NodeType::TriggerScheduled->value,
                null,
                ['automation_id' => $automation->id, 'source' => 'schedule'],
            );

            return 1;
        }

        $query = Contact::query()->where('workspace_id', $automation->workspace_id);
        $this->applyAudience($query, $audienceType, $config);
        $count = 0;

        $query->select('id')->chunkById(250, function ($contacts) use ($automation, &$count): void {
            foreach ($contacts as $contact) {
                ProcessAutomationEvent::dispatch(
                    $automation->workspace_id,
                    NodeType::TriggerScheduled->value,
                    $contact->id,
                    ['automation_id' => $automation->id, 'source' => 'schedule'],
                );
                $count++;
            }
        });

        return $count;
    }

    protected function applyAudience(Builder $query, string $audienceType, array $config): void
    {
        if ($audienceType === 'tag' && ! empty($config['tag_id'])) {
            $tagId = (int) $config['tag_id'];
            $query->whereHas('tags', fn (Builder $q) => $q->where('tags.id', $tagId));
        } elseif ($audienceType === 'contact' && ! empty($config['contact_id'])) {
            $query->whereKey((int) $config['contact_id']);
        }
    }
}
