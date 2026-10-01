<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('github_app_name')->default('gpsh1');
            $table->string('github_app_icon')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['github_app_name', 'github_app_icon']);
        });
    }
};
