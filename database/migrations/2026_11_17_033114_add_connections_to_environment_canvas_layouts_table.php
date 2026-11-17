<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_canvas_layouts', function (Blueprint $table) {
            $table->json('connections')->nullable()->after('groups');
        });
    }

    public function down(): void
    {
        Schema::table('environment_canvas_layouts', function (Blueprint $table) {
            $table->dropColumn('connections');
        });
    }
};
