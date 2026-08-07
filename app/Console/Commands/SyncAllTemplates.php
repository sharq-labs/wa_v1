<?php

namespace App\Console\Commands;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingManager;
use Illuminate\Console\Command;

class SyncAllTemplates extends Command
{
    protected $signature = 'templates:sync-all';

    protected $description = 'Sync WhatsApp templates for all connected accounts';

    public function handle(MessagingManager $manager): int
    {
        $count = 0;

        WhatsAppAccount::query()
            ->where('status', 'connected')
            ->chunkById(50, function ($accounts) use ($manager, &$count) {
                foreach ($accounts as $account) {
                    try {
                        $count += $manager->forAccount($account)->syncTemplates($account);
                    } catch (\Throwable $e) {
                        $this->warn("Account {$account->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Synced {$count} template(s).");

        return self::SUCCESS;
    }
}
