<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gpsh_notice_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('keep_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('gpsh_notice_settings', function (Blueprint $table) {
            $table->dropColumn('keep_days');
        });
    }
};
