<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('breakfast_portions')->default(0)->after('service_days');
            $table->string('diet')->nullable()->after('meal_type');
        });

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->string('diet')->nullable()->after('meal_type');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['breakfast_portions', 'diet']);
        });

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropColumn('diet');
        });
    }
};
