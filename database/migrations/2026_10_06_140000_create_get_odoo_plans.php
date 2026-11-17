<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('get_odoo_plans')) {
            Schema::create('get_odoo_plans', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('name');
                $table->string('summary')->nullable();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 8)->default('USD');
                $table->string('payment_gateway', 32)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'getodoo_plan_id')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->foreignId('getodoo_plan_id')->nullable()->constrained('get_odoo_plans')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('get_odoo_plan_signups')) {
            Schema::create('get_odoo_plan_signups', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('plan_id')->constrained('get_odoo_plans')->restrictOnDelete();
                $table->string('name');
                $table->string('email');
                $table->text('password')->nullable();
                $table->decimal('amount', 10, 2);
                $table->string('payment_gateway', 32)->nullable();
                $table->string('status', 32);
                $table->string('wompi_link_id')->nullable();
                $table->text('wompi_link_url')->nullable();
                $table->string('wompi_transaction_id')->nullable()->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('get_odoo_plan_signups');

        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'getodoo_plan_id')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropConstrainedForeignId('getodoo_plan_id');
            });
        }

        Schema::dropIfExists('get_odoo_plans');
    }
};
