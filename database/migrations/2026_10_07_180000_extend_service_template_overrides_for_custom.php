<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_template_overrides', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('name');
            $table->string('display_name')->nullable()->after('is_custom');
            $table->string('description', 2000)->nullable()->after('display_name');
            $table->string('logo')->nullable()->after('description');
            $table->string('category')->nullable()->after('logo');
            $table->boolean('is_visible')->default(true)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('service_template_overrides', function (Blueprint $table) {
            $table->dropColumn([
                'is_custom',
                'display_name',
                'description',
                'logo',
                'category',
                'is_visible',
            ]);
        });
    }
};
