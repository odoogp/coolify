<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'getodoo_billing_preference')) {
                $table->string('getodoo_billing_preference')->default('once')->after('getodoo_paid_until');
            }
            if (! Schema::hasColumn('servers', 'getodoo_recurring_starts_at')) {
                $table->date('getodoo_recurring_starts_at')->nullable()->after('getodoo_billing_preference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'getodoo_recurring_starts_at')) {
                $table->dropColumn('getodoo_recurring_starts_at');
            }
            if (Schema::hasColumn('servers', 'getodoo_billing_preference')) {
                $table->dropColumn('getodoo_billing_preference');
            }
        });
    }
};
