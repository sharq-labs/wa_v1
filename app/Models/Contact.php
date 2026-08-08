<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'whatsapp_account_id',
        'wa_id',
        'phone_number',
        'first_name',
        'last_name',
        'display_name',
        'email',
        'country',
        'language',
        'profile_picture',
        'status',
        'opt_in_status',
        'opt_in_at',
        'opt_out_at',
        'consent_source',
        'last_seen_at',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'opt_in_at' => 'datetime',
            'opt_out_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')
            ->using(ContactTag::class)
            ->withTimestamps();
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(ContactCustomFieldValue::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->display_name ?? $this->phone_number);
    }

    public function customFieldValue(string $key): ?string
    {
        $this->loadMissing('customFieldValues.customField');
        $value = $this->customFieldValues
            ->first(fn (ContactCustomFieldValue $v) => $v->customField && $v->customField->key === $key);

        return $value?->value;
    }

    public function setCustomFieldValue(CustomField $field, ?string $value): void
    {
        $this->customFieldValues()->updateOrCreate(
            ['custom_field_id' => $field->id],
            ['value' => $value],
        );
        $this->unsetRelation('customFieldValues');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('display_name', 'like', "%{$term}%")
                ->orWhere('phone_number', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }
}
