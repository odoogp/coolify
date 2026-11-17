<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'getodoo_eur_usd_rate')) {
                $table->decimal('getodoo_eur_usd_rate', 8, 4)->default(1.1);
            }

            if (! Schema::hasColumn('instance_settings', 'getodoo_tax_percent')) {
                $table->decimal('getodoo_tax_percent', 5, 2)->default(19);
            }

            if (! Schema::hasColumn('instance_settings', 'getodoo_margin_percent')) {
                $table->decimal('getodoo_margin_percent', 5, 2)->default(20);
            }
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            foreach (['getodoo_eur_usd_rate', 'getodoo_tax_percent', 'getodoo_margin_percent'] as $column) {
                if (Schema::hasColumn('instance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
