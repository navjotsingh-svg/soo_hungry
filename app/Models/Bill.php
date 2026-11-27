<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    protected $fillable = [
        'merchant_id', 'customer_id', 'subscription_id', 'cycle_key', 'kind', 'issue_date',
        'period_start', 'period_end', 'due_date', 'amount_cents', 'status',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balanceCents(?string $asOf = null): int
    {
        $paid = (int) $this->payments()
            ->when($asOf, fn ($query) => $query->whereDate('received_date', '<=', $asOf))
            ->sum('amount_cents');

        return (int) $this->amount_cents - $paid;
    }
}
