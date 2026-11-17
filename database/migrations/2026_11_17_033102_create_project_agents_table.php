<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Project agent');
            $table->string('provider', 64)->default('openai');
            $table->string('model', 128)->default('gpt-4o-mini');
            $table->text('api_key')->nullable();
            $table->string('base_url')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('follow_in_cloud')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_agents');
    }
};
