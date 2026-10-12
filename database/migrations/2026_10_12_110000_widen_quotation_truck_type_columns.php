<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('truck_type', 120)->change();
        });

        Schema::table('platform_offers', function (Blueprint $table) {
            $table->string('truck_type', 120)->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('truck_type', 32)->change();
        });

        Schema::table('platform_offers', function (Blueprint $table) {
            $table->string('truck_type', 32)->change();
        });
    }
};
