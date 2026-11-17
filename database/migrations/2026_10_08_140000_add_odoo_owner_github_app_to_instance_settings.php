<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'odoo_owner_github_app_id')) {
                $table->foreignId('odoo_owner_github_app_id')
                    ->nullable()
                    ->after('odoo_owner_branch')
                    ->constrained('github_apps')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (Schema::hasColumn('instance_settings', 'odoo_owner_github_app_id')) {
                $table->dropConstrainedForeignId('odoo_owner_github_app_id');
            }
        });
    }
};
