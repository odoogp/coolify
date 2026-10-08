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
            if (! Schema::hasColumn('odoo_backups', 'filename')) {
                $table->string('filename')->nullable()->after('kind');
            }
            if (! Schema::hasColumn('odoo_backups', 'filesize')) {
                $table->unsignedBigInteger('filesize')->nullable()->after('filename');
            }
            if (! Schema::hasColumn('odoo_backups', 'error')) {
                $table->text('error')->nullable()->after('filesize');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('odoo_backups')) {
            return;
        }

        Schema::table('odoo_backups', function (Blueprint $table) {
            foreach (['filename', 'filesize', 'error'] as $column) {
                if (Schema::hasColumn('odoo_backups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
