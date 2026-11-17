<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->string('role', 32)->default('staging')->after('name');
            $table->index(['project_id', 'role']);
        });

        $this->backfillRoles();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX environments_one_production_per_project ON environments (project_id) WHERE role = \'production\'');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX environments_one_production_per_project ON environments (project_id) WHERE role = \'production\'');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS environments_one_production_per_project');
        }

        Schema::table('environments', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'role']);
            $table->dropColumn('role');
        });
    }

    private function backfillRoles(): void
    {
        DB::table('environments')
            ->whereRaw('LOWER(name) = ?', ['production'])
            ->update(['role' => 'production']);

        DB::table('environments')
            ->where('role', '!=', 'production')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) = ?', ['staging'])
                    ->orWhereRaw("LOWER(name) LIKE 'staging-%'");
            })
            ->update(['role' => 'staging']);

        $projectIds = DB::table('environments')
            ->distinct()
            ->pluck('project_id');

        foreach ($projectIds as $projectId) {
            $hasProduction = DB::table('environments')
                ->where('project_id', $projectId)
                ->where('role', 'production')
                ->exists();

            if ($hasProduction) {
                DB::table('environments')
                    ->where('project_id', $projectId)
                    ->where('role', '!=', 'production')
                    ->update(['role' => 'staging']);

                continue;
            }

            $oldestId = DB::table('environments')
                ->where('project_id', $projectId)
                ->orderBy('id')
                ->value('id');

            if ($oldestId === null) {
                continue;
            }

            DB::table('environments')->where('id', $oldestId)->update(['role' => 'production']);
            DB::table('environments')
                ->where('project_id', $projectId)
                ->where('id', '!=', $oldestId)
                ->update(['role' => 'staging']);
        }
    }
};
