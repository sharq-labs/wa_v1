<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'message_id',
        'status',
        'error_message',
        'sent_at',
        'replied_at',
        'click_count',
        'first_clicked_at',
        'last_clicked_at',
        'converted_at',
        'conversion_name',
        'conversion_value',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'replied_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'converted_at' => 'datetime',
            'conversion_value' => 'decimal:4',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
