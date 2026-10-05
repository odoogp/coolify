<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('get_odoo_server_offers')) {
            Schema::create('get_odoo_server_offers', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->unsignedBigInteger('hetzner_type_id')->unique();
                $table->string('name');
                $table->string('description')->nullable();
                $table->unsignedInteger('cores')->default(0);
                $table->decimal('memory', 8, 2)->default(0);
                $table->unsignedInteger('disk')->default(0);
                $table->string('architecture')->nullable();
                $table->string('currency', 8)->default('EUR');
                $table->decimal('monthly_price', 10, 4)->default(0);
                $table->decimal('markup', 10, 2)->default(0);
                $table->string('location')->nullable();
                $table->json('locations')->nullable();
                $table->boolean('available_for_admins')->default(false);
                $table->boolean('in_stock')->default(true);
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('servers', 'getodoo_offer_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->foreignId('getodoo_offer_id')->nullable()->constrained('get_odoo_server_offers')->nullOnDelete();
                $table->decimal('getodoo_monthly_price', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servers', 'getodoo_offer_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('getodoo_offer_id');
                $table->dropColumn('getodoo_monthly_price');
            });
        }

        Schema::dropIfExists('get_odoo_server_offers');
    }
};
