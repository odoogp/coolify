<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('odoo_migrations')) {
            return;
        }

        Schema::table('odoo_migrations', function (Blueprint $table) {
            if (! Schema::hasColumn('odoo_migrations', 'addons_disk_path')) {
                $table->string('addons_disk_path')->nullable()->after('filestore_original_name');
            }
            if (! Schema::hasColumn('odoo_migrations', 'addons_original_name')) {
                $table->string('addons_original_name')->nullable()->after('addons_disk_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('odoo_migrations')) {
            return;
        }

        Schema::table('odoo_migrations', function (Blueprint $table) {
            foreach (['addons_original_name', 'addons_disk_path'] as $column) {
                if (Schema::hasColumn('odoo_migrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
