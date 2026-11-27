<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE inbound_messages MODIFY wa_timestamp DATETIME NOT NULL');
        DB::statement('ALTER TABLE pickup_orders MODIFY pickup_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE inbound_messages MODIFY wa_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE pickup_orders MODIFY pickup_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
};
