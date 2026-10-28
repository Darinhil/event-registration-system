<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceVoucher extends Model
{
    protected $fillable = ['user_id', 'voucher_number', 'voucher_date', 'payee_name', 'payload'];

    protected function casts(): array
    {
        return ['voucher_date' => 'date:Y-m-d', 'payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
