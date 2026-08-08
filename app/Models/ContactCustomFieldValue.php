<?php

namespace App\Models;

use App\Enums\NodeType;
use App\Services\Automation\AutomationEventPublisher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactCustomFieldValue extends Model
{
    use HasFactory;

    protected $table = 'contact_custom_field_values';

    protected $fillable = [
        'contact_id',
        'custom_field_id',
        'value',
    ];

    protected static function booted(): void
    {
        static::created(function (ContactCustomFieldValue $value): void {
            $value->dispatchChange(null, $value->value);
        });

        static::updated(function (ContactCustomFieldValue $value): void {
            if (! $value->wasChanged('value')) {
                return;
            }

            $value->dispatchChange($value->getOriginal('value'), $value->value);
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    protected function dispatchChange(?string $oldValue, ?string $newValue): void
    {
        if ($oldValue === $newValue) {
            return;
        }

        $contact = Contact::query()->find($this->contact_id);
        $field = CustomField::query()->find($this->custom_field_id);
        if (! $contact || ! $field || $contact->workspace_id !== $field->workspace_id) {
            return;
        }

        app(AutomationEventPublisher::class)->publish(
            $contact->workspace_id,
            NodeType::TriggerFieldChanged->value,
            $contact->id,
            [
                'field_id' => $field->id,
                'field_key' => $field->key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'source' => 'custom_field_model',
            ],
        );
    }
}
