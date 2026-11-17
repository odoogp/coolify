<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odoo_backups')) {
            return;
        }

        Schema::table('odoo_backups', function (Blueprint $table) {
            if (! Schema::hasColumn('odoo_backups', 'kind')) {
                $table->string('kind')->default('manual')->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('odoo_backups') || ! Schema::hasColumn('odoo_backups', 'kind')) {
            return;
        }

        Schema::table('odoo_backups', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
