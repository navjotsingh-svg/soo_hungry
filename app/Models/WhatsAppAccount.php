<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppAccount extends Model
{
    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'merchant_id', 'location_id', 'phone_number_id', 'waba_id', 'display_number',
        'meta_app_id', 'meta_config_id', 'meta_app_secret',
        'access_token', 'status', 'existing_number_status', 'production_access_confirmed_at',
        'google_chat_status',
    ];

    protected $hidden = ['access_token', 'meta_app_secret'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'meta_app_secret' => 'encrypted',
            'production_access_confirmed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
