<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('get_odoo_server_offers')) {
            return;
        }

        Schema::table('get_odoo_server_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('get_odoo_server_offers', 'customer_summary')) {
                $table->string('customer_summary', 280)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('get_odoo_server_offers')) {
            return;
        }

        Schema::table('get_odoo_server_offers', function (Blueprint $table) {
            if (Schema::hasColumn('get_odoo_server_offers', 'customer_summary')) {
                $table->dropColumn('customer_summary');
            }
        });
    }
};
