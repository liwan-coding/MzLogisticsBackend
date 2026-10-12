<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->decimal('trip_load_limit_tons', 8, 2)->default(25)->after('capacity_tons');
        });

        DB::table('trucks')
            ->where('capacity_tons', '<', 25)
            ->update(['trip_load_limit_tons' => DB::raw('capacity_tons')]);
    }

    public function down(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->dropColumn('trip_load_limit_tons');
        });
    }
};
