<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            foreach (['n1co_client_id', 'n1co_client_secret', 'bank_transfer_instructions'] as $column) {
                if (Schema::hasColumn('instance_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
