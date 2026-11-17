<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_agent_id')->constrained('project_agents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment_uuid', 64)->nullable();
            $table->string('role', 32);
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['project_agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_agent_messages');
    }
};
