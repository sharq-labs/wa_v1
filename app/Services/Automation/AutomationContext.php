<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Workspace;

/**
 * Everything a node handler may need while executing a run.
 */
class AutomationContext
{
    /** @var array<string, string|null> */
    public array $variables = [];

    public ?array $currentNode = null;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly Automation $automation,
        public readonly AutomationVersion $version,
        public readonly AutomationRun $run,
        public readonly ?Contact $contact = null,
        public readonly ?Conversation $conversation = null,
        public readonly ?Message $triggerMessage = null,
        public readonly ?WhatsAppAccount $account = null,
        public ?Message $latestInboundMessage = null,
    ) {
        $this->variables = $run->variablesMap();
    }

    public static function forRun(AutomationRun $run): self
    {
        $run->loadMissing(['workspace', 'automation', 'version', 'contact', 'conversation.whatsappAccount', 'triggerMessage']);

        return new self(
            workspace: $run->workspace,
            automation: $run->automation,
            version: $run->version,
            run: $run,
            contact: $run->contact,
            conversation: $run->conversation,
            triggerMessage: $run->triggerMessage,
            account: $run->conversation?->whatsappAccount,
        );
    }

    public function assignedAgent(): ?User
    {
        return $this->conversation?->assignedUser;
    }

    public function setVariable(string $key, ?string $value): void
    {
        $this->variables[$key] = $value;
        $this->run->setVariable($key, $value);
    }

    /**
     * Resolve a dotted variable path to a string value.
     */
    public function resolve(string $path): ?string
    {
        [$namespace, $key] = array_pad(explode('.', $path, 2), 2, null);

        return match ($namespace) {
            'contact' => $this->resolveContact($key),
            'workspace' => $this->resolveWorkspace($key),
            'agent' => $this->resolveAgent($key),
            'custom' => $key ? $this->contact?->customFieldValue($key) : null,
            'variables', 'variable' => $key ? ($this->variables[$key] ?? null) : null,
            'message' => $this->resolveMessage($key),
            default => null,
        };
    }

    protected function resolveContact(?string $key): ?string
    {
        if (! $this->contact) {
            return null;
        }

        return match ($key) {
            'first_name' => $this->contact->first_name ?? $this->contact->display_name,
            'last_name' => $this->contact->last_name,
            'name', 'full_name' => $this->contact->full_name,
            'phone', 'phone_number' => $this->contact->phone_number,
            'email' => $this->contact->email,
            'country' => $this->contact->country,
            'language' => $this->contact->language,
            default => null,
        };
    }

    protected function resolveWorkspace(?string $key): ?string
    {
        return match ($key) {
            'name' => $this->workspace->name,
            'timezone' => $this->workspace->timezone,
            'currency' => $this->workspace->currency,
            default => null,
        };
    }

    protected function resolveAgent(?string $key): ?string
    {
        $agent = $this->assignedAgent();

        if (! $agent) {
            return null;
        }

        return match ($key) {
            'name' => $agent->name,
            'email' => $agent->email,
            default => null,
        };
    }

    protected function resolveMessage(?string $key): ?string
    {
        $message = $this->latestInboundMessage ?? $this->triggerMessage;

        return match ($key) {
            'text', 'body', 'content' => $message?->content,
            'type' => $message?->message_type?->value,
            default => null,
        };
    }

    public function resolver(): callable
    {
        return fn (string $path): ?string => $this->resolve($path);
    }
}
