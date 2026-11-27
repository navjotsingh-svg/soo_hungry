<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantUser extends Model
{
    protected $table = 'merchant_user';

    protected $fillable = ['merchant_id', 'user_id', 'role', 'active'];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
