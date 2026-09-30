<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_profiles', function (Blueprint $table) {
            $table->foreignId('github_app_id')->nullable()->constrained('github_apps')->nullOnDelete();
            $table->unsignedBigInteger('repository_id')->nullable();
            $table->string('git_repository')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('odoo_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_app_id');
            $table->dropColumn(['repository_id', 'git_repository']);
        });
    }
};
