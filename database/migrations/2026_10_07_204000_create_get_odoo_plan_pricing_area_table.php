<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('get_odoo_plan_pricing_area')) {
            return;
        }

        if (! Schema::hasTable('get_odoo_plans') || ! Schema::hasTable('get_odoo_pricing_areas')) {
            return;
        }

        Schema::create('get_odoo_plan_pricing_area', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('get_odoo_plans')->cascadeOnDelete();
            $table->foreignId('pricing_area_id')->constrained('get_odoo_pricing_areas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'pricing_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('get_odoo_plan_pricing_area');
    }
};
