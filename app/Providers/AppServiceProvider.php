<?php

namespace App\Providers;

use App\Services\Billing\BillingProviderInterface;
use App\Services\Billing\ManualBillingProvider;
use App\Services\Messaging\FakeWhatsAppProvider;
use App\Services\Messaging\MessagingManager;
use App\Services\Messaging\MessagingProviderInterface;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessagingManager::class);
        $this->app->singleton(FakeWhatsAppProvider::class);

        // Default provider binding follows WHATSAPP_PROVIDER.
        $this->app->bind(
            MessagingProviderInterface::class,
            fn ($app) => $app->make(MessagingManager::class)->driver(),
        );

        $this->app->bind(BillingProviderInterface::class, ManualBillingProvider::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
