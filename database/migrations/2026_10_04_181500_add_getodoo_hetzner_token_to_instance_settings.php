<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instance_settings', 'getodoo_hetzner_token_id')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->foreignId('getodoo_hetzner_token_id')
                    ->nullable()
                    ->constrained('cloud_provider_tokens')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('instance_settings', 'getodoo_hetzner_token_id')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('getodoo_hetzner_token_id');
            });
        }
    }
};
