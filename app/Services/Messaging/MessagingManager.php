<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppAccount;
use InvalidArgumentException;

/**
 * Resolves the messaging provider for a WhatsApp account.
 *
 * The account's own provider column wins so fake demo accounts keep working
 * even when the platform default is "meta".
 */
class MessagingManager
{
    public function forAccount(WhatsAppAccount $account): MessagingProviderInterface
    {
        return $this->driver($account->provider ?: config('whatsapp.provider'));
    }

    public function driver(?string $name = null): MessagingProviderInterface
    {
        $name = $name ?: config('whatsapp.provider');

        if ($name === 'fake'
            && ! app()->environment(['local', 'testing'])
            && ! config('whatsapp.allow_fake_accounts', false)) {
            throw new InvalidArgumentException('Fake WhatsApp provider is disabled in this environment.');
        }

        return match ($name) {
            'fake' => app(FakeWhatsAppProvider::class),
            'meta' => app(MetaWhatsAppProvider::class),
            default => throw new InvalidArgumentException("Unknown WhatsApp provider [{$name}]."),
        };
    }
}
