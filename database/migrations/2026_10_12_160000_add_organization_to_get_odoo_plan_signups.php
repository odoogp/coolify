<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('get_odoo_plan_signups')) {
            return;
        }

        if (! Schema::hasColumn('get_odoo_plan_signups', 'organization')) {
            Schema::table('get_odoo_plan_signups', function (Blueprint $table) {
                $table->string('organization')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('get_odoo_plan_signups')) {
            return;
        }

        if (Schema::hasColumn('get_odoo_plan_signups', 'organization')) {
            Schema::table('get_odoo_plan_signups', function (Blueprint $table) {
                $table->dropColumn('organization');
            });
        }
    }
};
