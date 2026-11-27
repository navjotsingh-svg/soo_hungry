<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('meta_app_id')->nullable()->after('waba_id');
            $table->string('meta_config_id')->nullable()->after('meta_app_id');
            $table->text('meta_app_secret')->nullable()->after('meta_config_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['meta_app_id', 'meta_config_id', 'meta_app_secret']);
        });
    }
};
