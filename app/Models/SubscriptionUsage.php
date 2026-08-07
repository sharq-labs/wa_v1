<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionUsage extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $table = 'subscription_usage';

    protected $fillable = [
        'workspace_id',
        'key',
        'period',
        'used',
    ];
}
