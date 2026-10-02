<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpsh_notice_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('mounted')->default(true);
            $table->boolean('accessible')->default(true);
            $table->boolean('expiration')->default(true);
            $table->boolean('deletion')->default(true);
            $table->boolean('custom')->default(true);
            $table->timestamps();
        });

        DB::table('gpsh_notice_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('gpsh_notices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience');
            $table->string('kind');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gpsh_notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('gpsh_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['notice_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpsh_notice_reads');
        Schema::dropIfExists('gpsh_notices');
        Schema::dropIfExists('gpsh_notice_settings');
    }
};
