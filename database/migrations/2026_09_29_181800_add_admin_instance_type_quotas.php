<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->unsignedInteger('max_production_branches')->nullable();
            $table->unsignedInteger('max_staging_branches')->nullable();
            $table->unsignedInteger('max_services')->nullable();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['environment_id', 'created_by']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['environment_id', 'created_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['environment_id', 'created_by']);
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['environment_id', 'created_by']);
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn(['max_production_branches', 'max_staging_branches', 'max_services']);
        });
    }
};
