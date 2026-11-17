<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_environment_branches', function (Blueprint $table) {
            $table->string('owner_package_branch')->nullable()->after('git_branch');
        });
    }

    public function down(): void
    {
        Schema::table('odoo_environment_branches', function (Blueprint $table) {
            $table->dropColumn('owner_package_branch');
        });
    }
};
