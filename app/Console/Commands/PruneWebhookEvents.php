<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;

class PruneWebhookEvents extends Command
{
    protected $signature = 'webhooks:prune {--days=30}';

    protected $description = 'Delete processed webhook events older than the retention window';

    public function handle(): int
    {
        $deleted = WebhookEvent::query()
            ->where('status', 'processed')
            ->where('created_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $this->info("Pruned {$deleted} webhook event(s).");

        return self::SUCCESS;
    }
}
