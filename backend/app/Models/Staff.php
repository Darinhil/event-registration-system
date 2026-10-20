<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = ['staff_id', 'user_id', 'name', 'phone', 'position', 'employment_status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
