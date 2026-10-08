<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('get_odoo_plan_pricing_area') && ! Schema::hasColumn('get_odoo_plan_pricing_area', 'promo_price')) {
            Schema::table('get_odoo_plan_pricing_area', function (Blueprint $table) {
                $table->decimal('promo_price', 10, 2)->nullable()->after('pricing_area_id');
            });
        }

        if (Schema::hasTable('odoo_migrations') && ! Schema::hasColumn('odoo_migrations', 'environment_id')) {
            Schema::table('odoo_migrations', function (Blueprint $table) {
                $table->foreignId('environment_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('odoo_migrations') && Schema::hasColumn('odoo_migrations', 'environment_id')) {
            Schema::table('odoo_migrations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('environment_id');
            });
        }

        if (Schema::hasTable('get_odoo_plan_pricing_area') && Schema::hasColumn('get_odoo_plan_pricing_area', 'promo_price')) {
            Schema::table('get_odoo_plan_pricing_area', function (Blueprint $table) {
                $table->dropColumn('promo_price');
            });
        }
    }
};
