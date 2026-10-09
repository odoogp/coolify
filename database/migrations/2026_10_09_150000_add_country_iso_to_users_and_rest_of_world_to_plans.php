<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'country_iso')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('country_iso', 2)->nullable()->after('locale');
                $table->index('country_iso');
            });
        }

        if (Schema::hasTable('get_odoo_plans') && ! Schema::hasColumn('get_odoo_plans', 'is_rest_of_world')) {
            Schema::table('get_odoo_plans', function (Blueprint $table) {
                $table->boolean('is_rest_of_world')->default(false)->after('is_active');
            });

            // Former "worldwide" plans had an empty pivot.
            if (Schema::hasTable('get_odoo_plan_pricing_area')) {
                $withAreas = DB::table('get_odoo_plan_pricing_area')
                    ->distinct()
                    ->pluck('plan_id')
                    ->all();

                DB::table('get_odoo_plans')
                    ->when($withAreas !== [], fn ($query) => $query->whereNotIn('id', $withAreas))
                    ->update(['is_rest_of_world' => true]);
            } else {
                DB::table('get_odoo_plans')->update(['is_rest_of_world' => true]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('get_odoo_plans') && Schema::hasColumn('get_odoo_plans', 'is_rest_of_world')) {
            Schema::table('get_odoo_plans', function (Blueprint $table) {
                $table->dropColumn('is_rest_of_world');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'country_iso')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['country_iso']);
                $table->dropColumn('country_iso');
            });
        }
    }
};
