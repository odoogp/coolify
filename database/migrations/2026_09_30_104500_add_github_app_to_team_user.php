<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->foreignId('github_app_id')->nullable()->constrained('github_apps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_app_id');
        });
    }
};
