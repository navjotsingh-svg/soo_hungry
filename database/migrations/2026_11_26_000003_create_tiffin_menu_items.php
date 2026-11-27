<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiffin_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('slot');
            $table->string('diet');
            $table->string('name');
            $table->timestamps();
            $table->index(['merchant_id', 'day_of_week', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiffin_menu_items');
    }
};
