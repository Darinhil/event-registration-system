<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveEntitlement extends Model
{
    protected $fillable = ['user_id', 'year', 'leave_type', 'entitled_days', 'carried_over_days'];

    protected function casts(): array
    {
        return ['entitled_days' => 'float', 'carried_over_days' => 'float', 'year' => 'integer'];
    }
}
