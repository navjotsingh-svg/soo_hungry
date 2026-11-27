<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationAlert extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'merchant_id', 'integration', 'severity', 'message', 'status', 'subject_type', 'subject_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
