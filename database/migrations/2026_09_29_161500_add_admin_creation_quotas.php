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
            $table->unsignedInteger('max_projects')->nullable();
            $table->unsignedInteger('max_environments')->nullable();
            $table->unsignedInteger('max_members')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'added_by']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'created_by']);
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['project_id', 'created_by']);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'invited_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'invited_by']);
            $table->dropConstrainedForeignId('invited_by');
        });

        Schema::table('environments', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'created_by']);
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'created_by']);
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'added_by']);
            $table->dropConstrainedForeignId('added_by');
            $table->dropColumn(['max_projects', 'max_environments', 'max_members']);
        });
    }
};
