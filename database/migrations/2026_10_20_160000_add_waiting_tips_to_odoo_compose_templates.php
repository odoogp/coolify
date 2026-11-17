<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_compose_templates', function (Blueprint $table) {
            $table->json('waiting_tips')->nullable()->after('compose');
        });
    }

    public function down(): void
    {
        Schema::table('odoo_compose_templates', function (Blueprint $table) {
            $table->dropColumn('waiting_tips');
        });
    }
};
