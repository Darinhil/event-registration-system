<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompliancePolicy extends Model
{
    protected $fillable = [
        'key',
        'title',
        'category',
        'description',
        'icon',
    ];
}
