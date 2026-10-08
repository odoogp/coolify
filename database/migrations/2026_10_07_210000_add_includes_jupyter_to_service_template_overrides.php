<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_template_overrides')) {
            return;
        }

        Schema::table('service_template_overrides', function (Blueprint $table) {
            if (! Schema::hasColumn('service_template_overrides', 'includes_jupyter')) {
                $table->boolean('includes_jupyter')->default(false)->after('is_visible');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_template_overrides')) {
            return;
        }

        Schema::table('service_template_overrides', function (Blueprint $table) {
            if (Schema::hasColumn('service_template_overrides', 'includes_jupyter')) {
                $table->dropColumn('includes_jupyter');
            }
        });
    }
};
