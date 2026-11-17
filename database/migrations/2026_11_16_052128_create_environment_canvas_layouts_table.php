<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_canvas_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->json('positions')->nullable();
            $table->json('groups')->nullable();
            $table->timestamps();

            $table->unique('environment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_canvas_layouts');
    }
};
