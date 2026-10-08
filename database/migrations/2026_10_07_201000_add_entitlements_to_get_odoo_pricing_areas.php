<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('get_odoo_pricing_areas')) {
            Schema::table('get_odoo_pricing_areas', function (Blueprint $table) {
                if (! Schema::hasColumn('get_odoo_pricing_areas', 'allow_multiple_projects')) {
                    $table->boolean('allow_multiple_projects')->default(true)->after('is_active');
                }
                if (! Schema::hasColumn('get_odoo_pricing_areas', 'allow_all_services')) {
                    $table->boolean('allow_all_services')->default(true)->after('allow_multiple_projects');
                }
                if (! Schema::hasColumn('get_odoo_pricing_areas', 'allowed_services')) {
                    $table->json('allowed_services')->nullable()->after('allow_all_services');
                }
            });
        }

        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'getodoo_pricing_area_id')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->foreignId('getodoo_pricing_area_id')->nullable()->after('getodoo_plan_id')->constrained('get_odoo_pricing_areas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'getodoo_pricing_area_id')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropConstrainedForeignId('getodoo_pricing_area_id');
            });
        }

        if (Schema::hasTable('get_odoo_pricing_areas')) {
            Schema::table('get_odoo_pricing_areas', function (Blueprint $table) {
                foreach (['allowed_services', 'allow_all_services', 'allow_multiple_projects'] as $column) {
                    if (Schema::hasColumn('get_odoo_pricing_areas', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
