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
            if (! Schema::hasColumn('get_odoo_plans', 'max_projects')) {
                $table->unsignedInteger('max_projects')->nullable();
            }
            if (! Schema::hasColumn('get_odoo_plans', 'max_environments')) {
                $table->unsignedInteger('max_environments')->nullable();
            }
            if (! Schema::hasColumn('get_odoo_plans', 'max_members')) {
                $table->unsignedInteger('max_members')->nullable();
            }
            if (! Schema::hasColumn('get_odoo_plans', 'max_production_branches')) {
                $table->unsignedInteger('max_production_branches')->nullable();
            }
            if (! Schema::hasColumn('get_odoo_plans', 'max_staging_branches')) {
                $table->unsignedInteger('max_staging_branches')->nullable();
            }
            if (! Schema::hasColumn('get_odoo_plans', 'max_services')) {
                $table->unsignedInteger('max_services')->nullable();
            }
            if (! Schema::hasColumn('get_odoo_plans', 'can_add_servers')) {
                $table->boolean('can_add_servers')->default(false);
            }
            if (! Schema::hasColumn('get_odoo_plans', 'can_launch_on_instance_server')) {
                $table->boolean('can_launch_on_instance_server')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('get_odoo_plans')) {
            return;
        }

        Schema::table('get_odoo_plans', function (Blueprint $table) {
            $columns = [
                'max_projects',
                'max_environments',
                'max_members',
                'max_production_branches',
                'max_staging_branches',
                'max_services',
                'can_add_servers',
                'can_launch_on_instance_server',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('get_odoo_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
