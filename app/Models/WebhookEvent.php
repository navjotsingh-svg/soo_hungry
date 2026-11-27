<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payload_hash', 'phone_number_id', 'raw_payload', 'signature_valid', 'resolution', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
