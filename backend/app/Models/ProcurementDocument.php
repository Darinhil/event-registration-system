<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementDocument extends Model
{
    protected $fillable = ['user_id', 'type', 'document_number', 'document_date', 'status', 'payload'];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'payload' => 'array'];
    }
}
