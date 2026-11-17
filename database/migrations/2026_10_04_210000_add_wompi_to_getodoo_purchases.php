<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('instance_settings', 'wompi_client_id')) {
                $table->string('wompi_client_id')->nullable();
            }

            if (! Schema::hasColumn('instance_settings', 'wompi_client_secret')) {
                $table->text('wompi_client_secret')->nullable();
            }
        });

        if (! Schema::hasTable('get_odoo_server_orders')) {
            Schema::create('get_odoo_server_orders', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('offer_id')->constrained('get_odoo_server_offers')->restrictOnDelete();
                $table->foreignId('private_key_id')->constrained()->restrictOnDelete();
                $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
                $table->string('server_name');
                $table->string('location');
                $table->decimal('amount', 10, 2);
                $table->string('status', 32);
                $table->string('wompi_link_id')->nullable();
                $table->text('wompi_link_url')->nullable();
                $table->string('wompi_transaction_id')->nullable()->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('get_odoo_server_orders');

        Schema::table('instance_settings', function (Blueprint $table) {
            foreach (['wompi_client_id', 'wompi_client_secret'] as $column) {
                if (Schema::hasColumn('instance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
