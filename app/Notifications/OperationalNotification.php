<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationalNotification extends Notification
{
    public function __construct(public readonly array $payload) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (($this->payload['email'] ?? false) === true) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->payload['workspace_id'] ?? null,
            'type' => $this->payload['type'] ?? 'info',
            'title' => $this->payload['title'] ?? 'Notification',
            'message' => $this->payload['message'] ?? '',
            'url' => $this->payload['url'] ?? null,
            'severity' => $this->payload['severity'] ?? 'info',
            'meta' => $this->payload['meta'] ?? [],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject((string) ($this->payload['title'] ?? config('app.name').' notification'))
            ->line((string) ($this->payload['message'] ?? ''));

        if (! empty($this->payload['url'])) {
            $mail->action((string) ($this->payload['action_label'] ?? 'Open'), (string) $this->payload['url']);
        }

        return $mail;
    }
}
