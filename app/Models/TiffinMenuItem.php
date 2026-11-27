<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiffinMenuItem extends Model
{
    public const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public const SLOTS = [
        'breakfast' => 'Breakfast',
        'lunch' => 'Lunch',
        'dinner' => 'Dinner',
    ];

    public const DIETS = [
        'veg' => 'Veg',
        'non_veg' => 'Non-veg',
    ];

    protected $fillable = [
        'merchant_id', 'day_of_week', 'slot', 'diet', 'name', 'photo_path',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function dietLabel(): string
    {
        return self::DIETS[$this->diet] ?? $this->diet;
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }
}
