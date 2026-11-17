<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_environment_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('git_branch');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_environment_branches');
    }
};
