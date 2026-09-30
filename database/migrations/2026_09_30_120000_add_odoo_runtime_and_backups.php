<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_environment_branches', function (Blueprint $table) {
            $table->string('domain')->nullable();
            $table->string('odoo_version', 8)->nullable();
            $table->unsignedSmallInteger('workers')->default(0);
            $table->string('addons_path')->default('/mnt/extra-addons');
            $table->boolean('jupyter_enabled')->default(false);
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('addons_application_id')->nullable()->constrained('applications')->nullOnDelete();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('is_odoo_addons')->default(false);
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->json('odoo_abilities')->nullable();
        });

        Schema::create('odoo_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_backup_execution_id')->nullable()->constrained('scheduled_database_backup_executions')->nullOnDelete();
            $table->foreignId('volume_backup_execution_id')->nullable()->constrained('scheduled_volume_backup_executions')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('odoo_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('result');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_audit_logs');
        Schema::dropIfExists('odoo_backups');

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('odoo_abilities');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('is_odoo_addons');
        });

        Schema::table('odoo_environment_branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('addons_application_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['domain', 'odoo_version', 'workers', 'addons_path', 'jupyter_enabled']);
        });
    }
};
