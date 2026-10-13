<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('transport_price', 12, 3)->nullable()->after('total_price');
        });

        DB::table('quotations')->update([
            'transport_price' => DB::raw('total_price'),
        ]);
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('transport_price');
        });
    }
};
