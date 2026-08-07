<?php

namespace App\Console\Commands;

use App\Jobs\ResumeAutomationWait;
use App\Models\AutomationWait;
use Illuminate\Console\Command;

/**
 * Safety net for delayed queue jobs that were lost (e.g. Redis flush):
 * re-dispatches any due, still-pending delay/until waits.
 */
class ResumeDueAutomationWaits extends Command
{
    protected $signature = 'automation:resume-due-waits';

    protected $description = 'Dispatch resume jobs for due automation waits';

    public function handle(): int
    {
        $count = 0;

        AutomationWait::query()
            ->whereIn('wait_type', [AutomationWait::TYPE_DELAY, AutomationWait::TYPE_UNTIL])
            ->where('status', 'pending')
            ->where('resume_at', '<=', now())
            ->orderBy('resume_at')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                ResumeAutomationWait::dispatch($id);
                $count++;
            });

        $this->info("Dispatched {$count} due wait(s).");

        return self::SUCCESS;
    }
}
