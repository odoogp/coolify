<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->boolean('can_add_servers')->default(false);
            $table->boolean('can_launch_on_instance_server')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn(['can_add_servers', 'can_launch_on_instance_server']);
        });
    }
};
