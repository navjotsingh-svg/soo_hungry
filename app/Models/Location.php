<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = ['merchant_id', 'name', 'address', 'lead_time_minutes', 'ordering_paused'];

    protected function casts(): array
    {
        return ['ordering_paused' => 'boolean'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }
}
