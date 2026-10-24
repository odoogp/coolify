<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog launches may create one production environment per service type
 * in the same project. Drop the partial unique index that allowed only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('environments')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS environments_one_production_per_project');

            return;
        }

        DB::statement('DROP INDEX IF EXISTS environments_one_production_per_project');
    }

    public function down(): void
    {
        if (! Schema::hasTable('environments')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX environments_one_production_per_project ON environments (project_id) WHERE role = \'production\'');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX environments_one_production_per_project ON environments (project_id) WHERE role = \'production\'');
    }
};
