<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionLifecycleService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'billing:expire-subscriptions';

    protected $description = 'Expire platform subscriptions whose paid period has ended.';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $expired = $lifecycle->expirePastDueSubscriptions();
        $this->info("Expired {$expired} subscription(s).");

        return self::SUCCESS;
    }
}
