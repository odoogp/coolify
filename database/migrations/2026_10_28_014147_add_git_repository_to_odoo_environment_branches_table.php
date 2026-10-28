<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_environment_branches', function (Blueprint $table) {
            $table->string('git_repository')->nullable()->after('git_branch');
            $table->unsignedBigInteger('repository_id')->nullable()->after('git_repository');
        });
    }

    public function down(): void
    {
        Schema::table('odoo_environment_branches', function (Blueprint $table) {
            $table->dropColumn(['git_repository', 'repository_id']);
        });
    }
};
