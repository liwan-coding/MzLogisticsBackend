<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('price_per_ton', 12, 3)->nullable()->after('price_per_trip');
            $table->string('pricing_basis', 16)->nullable()->after('price_per_ton');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['price_per_ton', 'pricing_basis']);
        });
    }
};
