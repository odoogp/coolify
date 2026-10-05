<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('get_odoo_server_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('get_odoo_server_orders', 'purpose')) {
                $table->string('purpose', 16)->default('launch');
            }
        });

        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'getodoo_paid_until')) {
                $table->date('getodoo_paid_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('get_odoo_server_orders', function (Blueprint $table) {
            if (Schema::hasColumn('get_odoo_server_orders', 'purpose')) {
                $table->dropColumn('purpose');
            }
        });

        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'getodoo_paid_until')) {
                $table->dropColumn('getodoo_paid_until');
            }
        });
    }
};
