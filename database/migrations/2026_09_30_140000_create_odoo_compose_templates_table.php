<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_compose_templates', function (Blueprint $table) {
            $table->id();
            $table->string('version', 8)->unique();
            $table->string('postgres_version', 64)->default('16-alpine');
            $table->longText('compose');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_compose_templates');
    }
};
