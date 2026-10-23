<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'getodoo_plan_monthly_extra')) {
                $table->decimal('getodoo_plan_monthly_extra', 10, 2)->default(0)->after('getodoo_plan_billing_preference');
            }
        });

        if (Schema::hasTable('get_odoo_server_orders') && ! Schema::hasColumn('get_odoo_server_orders', 'description')) {
            Schema::table('get_odoo_server_orders', function (Blueprint $table) {
                $table->string('description')->nullable()->after('server_name');
            });
        }

        if (Schema::hasTable('get_odoo_plan_orders') && ! Schema::hasColumn('get_odoo_plan_orders', 'description')) {
            Schema::table('get_odoo_plan_orders', function (Blueprint $table) {
                $table->string('description')->nullable()->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('get_odoo_plan_orders') && Schema::hasColumn('get_odoo_plan_orders', 'description')) {
            Schema::table('get_odoo_plan_orders', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        if (Schema::hasTable('get_odoo_server_orders') && Schema::hasColumn('get_odoo_server_orders', 'description')) {
            Schema::table('get_odoo_server_orders', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'getodoo_plan_monthly_extra')) {
                $table->dropColumn('getodoo_plan_monthly_extra');
            }
        });
    }
};
