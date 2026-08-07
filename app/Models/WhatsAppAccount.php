<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppAccount extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'workspace_id',
        'provider',
        'meta_business_id',
        'waba_id',
        'phone_number_id',
        'display_phone_number',
        'verified_name',
        'access_token',
        'token_expiration',
        'quality_rating',
        'messaging_limit',
        'status',
        'last_sync_at',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expiration' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    /*
     * The foreign key must be named explicitly. Eloquent would derive it from
     * the class name — WhatsAppAccount becomes `whats_app_account_id` — but the
     * columns are `whatsapp_account_id`, matching the `whatsapp_accounts` table.
     */

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'whatsapp_account_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'whatsapp_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'whatsapp_account_id');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}
