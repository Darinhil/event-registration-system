<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    protected $fillable = ['event_id', 'label', 'description', 'placeholder', 'type', 'required', 'options', 'settings', 'sort_order'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'options' => 'array', 'settings' => 'array'];
    }

    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
}
