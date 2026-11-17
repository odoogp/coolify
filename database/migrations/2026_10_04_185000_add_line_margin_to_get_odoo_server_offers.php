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
            if (! Schema::hasColumn('get_odoo_server_offers', 'margin_percent')) {
                $table->decimal('margin_percent', 5, 2)->nullable();
            }

            if (! Schema::hasColumn('get_odoo_server_offers', 'available_since')) {
                $table->timestamp('available_since')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('get_odoo_server_offers')) {
            return;
        }

        Schema::table('get_odoo_server_offers', function (Blueprint $table) {
            foreach (['margin_percent', 'available_since'] as $column) {
                if (Schema::hasColumn('get_odoo_server_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
