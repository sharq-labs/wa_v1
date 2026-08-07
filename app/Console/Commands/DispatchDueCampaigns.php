<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Jobs\ProcessCampaign;
use App\Models\Campaign;
use Illuminate\Console\Command;

class DispatchDueCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-due';

    protected $description = 'Dispatch scheduled campaigns whose scheduled time has passed';

    public function handle(): int
    {
        $count = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                ProcessCampaign::dispatch($id);
                $count++;
            });

        $this->info("Dispatched {$count} campaign(s).");

        return self::SUCCESS;
    }
}
