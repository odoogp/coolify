<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('get_odoo_plans')) {
            return;
        }

        Schema::table('get_odoo_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('get_odoo_plans', 'includes_migration')) {
                $table->boolean('includes_migration')->default(false)->after('can_launch_on_instance_server');
            }
            if (! Schema::hasColumn('get_odoo_plans', 'backup_frequency')) {
                $table->string('backup_frequency', 32)->default('daily')->after('includes_migration');
            }
            if (! Schema::hasColumn('get_odoo_plans', 'backup_retention_days')) {
                $table->unsignedSmallInteger('backup_retention_days')->default(7)->after('backup_frequency');
            }
        });

        if (! Schema::hasTable('odoo_migrations')) {
            Schema::create('odoo_migrations', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 32)->default('draft');
                $table->string('odoo_version', 8)->nullable();
                $table->string('git_repository')->nullable();
                $table->string('database_disk_path')->nullable();
                $table->string('filestore_disk_path')->nullable();
                $table->string('database_original_name')->nullable();
                $table->string('filestore_original_name')->nullable();
                $table->text('error')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_migrations');

        if (! Schema::hasTable('get_odoo_plans')) {
            return;
        }

        Schema::table('get_odoo_plans', function (Blueprint $table) {
            foreach (['backup_retention_days', 'backup_frequency', 'includes_migration'] as $column) {
                if (Schema::hasColumn('get_odoo_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
