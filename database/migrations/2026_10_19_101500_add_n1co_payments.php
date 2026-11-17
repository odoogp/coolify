<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'n1co_secret')) {
                $table->text('n1co_secret')->nullable();
            }

            if (! Schema::hasColumn('instance_settings', 'n1co_webhook_secret')) {
                $table->text('n1co_webhook_secret')->nullable();
            }

            if (! Schema::hasColumn('instance_settings', 'n1co_sandbox')) {
                $table->boolean('n1co_sandbox')->default(true);
            }

            if (! Schema::hasColumn('instance_settings', 'n1co_charges')) {
                $table->boolean('n1co_charges')->default(false);
            }
        });

        if (Schema::hasTable('get_odoo_server_orders') && ! Schema::hasColumn('get_odoo_server_orders', 'payment_gateway')) {
            Schema::table('get_odoo_server_orders', function (Blueprint $table) {
                $table->string('payment_gateway', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('get_odoo_server_orders') && Schema::hasColumn('get_odoo_server_orders', 'payment_gateway')) {
            Schema::table('get_odoo_server_orders', function (Blueprint $table) {
                $table->dropColumn('payment_gateway');
            });
        }

        Schema::table('instance_settings', function (Blueprint $table) {
            foreach (['n1co_secret', 'n1co_webhook_secret', 'n1co_sandbox', 'n1co_charges'] as $column) {
                if (Schema::hasColumn('instance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
