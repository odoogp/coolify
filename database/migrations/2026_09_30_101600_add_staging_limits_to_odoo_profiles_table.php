<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_profiles', function (Blueprint $table) {
            $table->unsignedInteger('max_staging_environments')->default(1);
            $table->boolean('unlimited_staging_environments')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('odoo_profiles', function (Blueprint $table) {
            $table->dropColumn(['max_staging_environments', 'unlimited_staging_environments']);
        });
    }
};
