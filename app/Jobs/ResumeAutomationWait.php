<?php

namespace App\Jobs;

use App\Models\AutomationWait;
use App\Services\Automation\AutomationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ResumeAutomationWait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $waitId)
    {
        $this->onQueue('automations');
    }

    public function handle(AutomationEngine $engine): void
    {
        Cache::lock('automation-wait:'.$this->waitId, 15)->block(5, function () use ($engine) {
            $wait = AutomationWait::query()->find($this->waitId);

            if (! $wait || ! $wait->isPending()) {
                return;
            }

            if ($wait->resume_at && $wait->resume_at->isFuture()) {
                return;
            }

            $engine->resumeWait($wait);
        });
    }
}
