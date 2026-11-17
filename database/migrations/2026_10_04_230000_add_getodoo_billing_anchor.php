<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'getodoo_billing_anchor')) {
                $table->date('getodoo_billing_anchor')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'getodoo_billing_anchor')) {
                $table->dropColumn('getodoo_billing_anchor');
            }
        });
    }
};
