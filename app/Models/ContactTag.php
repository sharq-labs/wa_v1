<?php

namespace App\Models;

use App\Enums\NodeType;
use App\Services\Automation\AutomationEventPublisher;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ContactTag extends Pivot
{
    protected $table = 'contact_tag';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::created(function (ContactTag $pivot): void {
            $pivot->dispatchMutation(NodeType::TriggerTagAdded->value);
        });

        static::deleted(function (ContactTag $pivot): void {
            $pivot->dispatchMutation(NodeType::TriggerTagRemoved->value);
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    protected function dispatchMutation(string $eventType): void
    {
        $contact = Contact::query()->find($this->contact_id);
        $tag = Tag::query()->find($this->tag_id);

        if (! $contact || ! $tag || $contact->workspace_id !== $tag->workspace_id) {
            return;
        }

        app(AutomationEventPublisher::class)->publish(
            $contact->workspace_id,
            $eventType,
            $contact->id,
            [
                'tag_id' => $tag->id,
                'tag_name' => $tag->name,
                'source' => 'contact_tag_pivot',
            ],
        );
    }
}
