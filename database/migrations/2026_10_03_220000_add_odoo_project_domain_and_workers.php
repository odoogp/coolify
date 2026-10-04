<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('odoo_base_domain')->nullable();
        });

        Schema::table('odoo_profiles', function (Blueprint $table) {
            $table->string('subdomain')->nullable();
            $table->unsignedSmallInteger('workers')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('odoo_profiles', function (Blueprint $table) {
            $table->dropColumn(['subdomain', 'workers']);
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('odoo_base_domain');
        });
    }
};
