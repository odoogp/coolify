<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('get_odoo_pricing_areas')) {
            Schema::create('get_odoo_pricing_areas', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('kind', 16);
                $table->foreignId('parent_id')->nullable()->constrained('get_odoo_pricing_areas')->nullOnDelete();
                $table->string('iso_code', 2)->nullable();
                $table->decimal('extra_fixed', 10, 2)->default(0);
                $table->decimal('extra_percent', 8, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('get_odoo_plan_signups') && ! Schema::hasColumn('get_odoo_plan_signups', 'pricing_area_id')) {
            Schema::table('get_odoo_plan_signups', function (Blueprint $table) {
                $table->foreignId('pricing_area_id')->nullable()->after('plan_id')->constrained('get_odoo_pricing_areas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('get_odoo_plan_signups') && Schema::hasColumn('get_odoo_plan_signups', 'pricing_area_id')) {
            Schema::table('get_odoo_plan_signups', function (Blueprint $table) {
                $table->dropConstrainedForeignId('pricing_area_id');
            });
        }

        Schema::dropIfExists('get_odoo_pricing_areas');
    }
};
