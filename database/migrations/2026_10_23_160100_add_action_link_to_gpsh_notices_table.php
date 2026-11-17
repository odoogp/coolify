<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gpsh_notices', function (Blueprint $table) {
            if (! Schema::hasColumn('gpsh_notices', 'action_url')) {
                $table->string('action_url')->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('gpsh_notices', 'action_label')) {
                $table->string('action_label')->nullable()->after('action_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gpsh_notices', function (Blueprint $table) {
            if (Schema::hasColumn('gpsh_notices', 'action_label')) {
                $table->dropColumn('action_label');
            }
            if (Schema::hasColumn('gpsh_notices', 'action_url')) {
                $table->dropColumn('action_url');
            }
        });
    }
};
