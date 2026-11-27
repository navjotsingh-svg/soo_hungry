<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'merchant_id', 'phone_e164', 'name', 'address', 'address_approved_at',
        'address_approved_by', 'language', 'clover_customer_id',
    ];

    protected function casts(): array
    {
        return ['address_approved_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function permission(): HasOne
    {
        return $this->hasOne(NotificationPermission::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function displayName(): string
    {
        return $this->name ?: 'WhatsApp customer';
    }

    public function phoneDisplay(): string
    {
        return PhoneNumber::display($this->phone_e164);
    }
}
