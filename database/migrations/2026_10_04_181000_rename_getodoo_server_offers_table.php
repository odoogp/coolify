<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('getodoo_server_offers') && ! Schema::hasTable('get_odoo_server_offers')) {
            Schema::rename('getodoo_server_offers', 'get_odoo_server_offers');
        }
    }

    public function down(): void
    {
        // The model reads get_odoo_server_offers. Leave that name in place.
    }
};
