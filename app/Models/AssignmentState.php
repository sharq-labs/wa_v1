<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class AssignmentState extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'pool_type',
        'pool_id',
        'last_assigned_user_id',
    ];
}
