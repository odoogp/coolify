<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'getodoo_plan_paid_until')) {
                $table->date('getodoo_plan_paid_until')->nullable()->after('getodoo_pricing_area_id');
            }
            if (! Schema::hasColumn('teams', 'getodoo_plan_billing_anchor')) {
                $table->date('getodoo_plan_billing_anchor')->nullable()->after('getodoo_plan_paid_until');
            }
            if (! Schema::hasColumn('teams', 'getodoo_plan_billing_preference')) {
                $table->string('getodoo_plan_billing_preference')->default('once')->after('getodoo_plan_billing_anchor');
            }
            if (! Schema::hasColumn('teams', 'getodoo_plan_recurring_starts_at')) {
                $table->date('getodoo_plan_recurring_starts_at')->nullable()->after('getodoo_plan_billing_preference');
            }
            if (! Schema::hasColumn('teams', 'n1co_plan_id')) {
                $table->string('n1co_plan_id')->nullable()->after('getodoo_plan_recurring_starts_at');
            }
            if (! Schema::hasColumn('teams', 'n1co_subscription_id')) {
                $table->string('n1co_subscription_id')->nullable()->after('n1co_plan_id');
            }
            if (! Schema::hasColumn('teams', 'n1co_subscription_status')) {
                $table->string('n1co_subscription_status', 32)->nullable()->after('n1co_subscription_id');
            }
        });

        if (! Schema::hasTable('get_odoo_plan_orders')) {
            Schema::create('get_odoo_plan_orders', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('get_odoo_plans')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->string('status', 32);
                $table->string('purpose', 32)->default('renewal');
                $table->string('payment_gateway', 32)->nullable();
                $table->string('charge_mode', 32)->default('once');
                $table->string('n1co_plan_id')->nullable();
                $table->string('n1co_subscription_id')->nullable();
                $table->string('n1co_subscription_status', 32)->nullable();
                $table->string('wompi_link_id')->nullable();
                $table->text('wompi_link_url')->nullable();
                $table->string('wompi_transaction_id')->nullable()->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('get_odoo_plan_orders');

        Schema::table('teams', function (Blueprint $table) {
            foreach ([
                'n1co_subscription_status',
                'n1co_subscription_id',
                'n1co_plan_id',
                'getodoo_plan_recurring_starts_at',
                'getodoo_plan_billing_preference',
                'getodoo_plan_billing_anchor',
                'getodoo_plan_paid_until',
            ] as $column) {
                if (Schema::hasColumn('teams', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
