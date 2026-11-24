<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instance_settings') && ! Schema::hasColumn('instance_settings', 'clients_can_connect_servers')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->boolean('clients_can_connect_servers')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instance_settings') && Schema::hasColumn('instance_settings', 'clients_can_connect_servers')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('clients_can_connect_servers');
            });
        }
    }
};
