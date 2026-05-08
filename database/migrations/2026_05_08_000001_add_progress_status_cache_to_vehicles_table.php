<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('progress_status_cache', 20)->nullable()->after('is_disposed');
            $table->index('progress_status_cache');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['progress_status_cache']);
            $table->dropColumn('progress_status_cache');
        });
    }
};
