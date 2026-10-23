<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'n1co_plan_id')) {
                $table->string('n1co_plan_id')->nullable()->after('getodoo_recurring_starts_at');
            }
            if (! Schema::hasColumn('servers', 'n1co_subscription_id')) {
                $table->string('n1co_subscription_id')->nullable()->after('n1co_plan_id');
            }
            if (! Schema::hasColumn('servers', 'n1co_subscription_status')) {
                $table->string('n1co_subscription_status', 32)->nullable()->after('n1co_subscription_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            foreach (['n1co_subscription_status', 'n1co_subscription_id', 'n1co_plan_id'] as $column) {
                if (Schema::hasColumn('servers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
