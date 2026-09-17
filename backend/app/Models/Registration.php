<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Registration extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'event_name', 'event_date', 'qr_token', 'status'];

    protected function casts(): array
    {
        return ['event_date' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function checkIn(): HasOne { return $this->hasOne(CheckIn::class); }
}