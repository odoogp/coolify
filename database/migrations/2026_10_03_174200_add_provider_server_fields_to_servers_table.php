<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'provider_server_id')) {
                $table->string('provider_server_id')->nullable()->after('digitalocean_droplet_status');
            }

            if (! Schema::hasColumn('servers', 'provider_server_status')) {
                $table->string('provider_server_status')->nullable()->after('provider_server_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'provider_server_status')) {
                $table->dropColumn('provider_server_status');
            }

            if (Schema::hasColumn('servers', 'provider_server_id')) {
                $table->dropColumn('provider_server_id');
            }
        });
    }
};
