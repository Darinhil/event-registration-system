<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'enabled_fields', 'form_config', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'branding' => 'array', 'enabled_fields' => 'array', 'form_config' => 'array'];
    }

    public function registrations(): HasMany { return $this->hasMany(Registration::class); }
    public function formFields(): HasMany { return $this->hasMany(FormField::class)->orderBy('sort_order'); }
}
