<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instance_settings') && ! Schema::hasColumn('instance_settings', 'platform_logo')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->string('platform_logo')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instance_settings') && Schema::hasColumn('instance_settings', 'platform_logo')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('platform_logo');
            });
        }
    }
};
