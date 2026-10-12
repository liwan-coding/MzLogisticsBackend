<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->string('truck_type', 32)->nullable()->after('cargo_type');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->dropColumn('truck_type');
        });
    }
};
