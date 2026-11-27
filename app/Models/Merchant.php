<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Module;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Merchant extends Model
{
    protected $fillable = [
        'name', 'slug', 'timezone', 'currency', 'locale_primary', 'locale_secondary',
        'reminder_local_time', 'payment_instructions',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(MerchantModule::class);
    }

    public function cutoff(): HasOne
    {
        return $this->hasOne(CutoffPolicy::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    public function whatsappAccount(): HasOne
    {
        return $this->hasOne(WhatsAppAccount::class);
    }

    public function cloverConnection(): HasOne
    {
        return $this->hasOne(CloverConnection::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function enabledModules(): array
    {
        return $this->modules()->where('enabled', true)->pluck('module')->all();
    }

    public function hasModule(Module|string $module): bool
    {
        $value = $module instanceof Module ? $module->value : $module;

        return in_array($value, $this->enabledModules(), true);
    }

    public function nowLocal(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function cutoffTime(): string
    {
        $time = $this->cutoff?->cutoff_local_time ?? '20:00:00';

        return substr((string) $time, 0, 5);
    }

    public function graceMinutes(): int
    {
        return (int) ($this->cutoff?->grace_minutes ?? config('pilot.default_grace_minutes'));
    }
}
