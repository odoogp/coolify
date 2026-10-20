<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'n1co_location_id')) {
                $table->unsignedInteger('n1co_location_id')->nullable();
            }

            if (! Schema::hasColumn('instance_settings', 'n1co_server_charge_mode')) {
                $table->string('n1co_server_charge_mode', 32)->default('once');
            }

            if (! Schema::hasColumn('instance_settings', 'n1co_client_id')) {
                $table->string('n1co_client_id')->nullable();
            }

            if (! Schema::hasColumn('instance_settings', 'n1co_client_secret')) {
                $table->text('n1co_client_secret')->nullable();
            }

            if (! Schema::hasColumn('instance_settings', 'bank_transfer_instructions')) {
                $table->text('bank_transfer_instructions')->nullable();
            }
        });

        if (Schema::hasTable('get_odoo_plans') && ! Schema::hasColumn('get_odoo_plans', 'charge_mode')) {
            Schema::table('get_odoo_plans', function (Blueprint $table) {
                $table->string('charge_mode', 32)->default('once');
            });
        }

        if (Schema::hasTable('get_odoo_plan_signups') && ! Schema::hasColumn('get_odoo_plan_signups', 'charge_mode')) {
            Schema::table('get_odoo_plan_signups', function (Blueprint $table) {
                $table->string('charge_mode', 32)->default('once');
                $table->string('n1co_plan_id')->nullable();
                $table->string('n1co_subscription_id')->nullable();
                $table->string('n1co_subscription_status', 32)->nullable();
            });
        }

        if (Schema::hasTable('get_odoo_server_orders') && ! Schema::hasColumn('get_odoo_server_orders', 'charge_mode')) {
            Schema::table('get_odoo_server_orders', function (Blueprint $table) {
                $table->string('charge_mode', 32)->default('once');
                $table->string('n1co_plan_id')->nullable();
                $table->string('n1co_subscription_id')->nullable();
                $table->string('n1co_subscription_status', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            foreach (['n1co_location_id', 'n1co_server_charge_mode', 'n1co_client_id', 'n1co_client_secret', 'bank_transfer_instructions'] as $column) {
                if (Schema::hasColumn('instance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('get_odoo_plans') && Schema::hasColumn('get_odoo_plans', 'charge_mode')) {
            Schema::table('get_odoo_plans', function (Blueprint $table) {
                $table->dropColumn('charge_mode');
            });
        }

        foreach (['get_odoo_plan_signups', 'get_odoo_server_orders'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['charge_mode', 'n1co_plan_id', 'n1co_subscription_id', 'n1co_subscription_status'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
