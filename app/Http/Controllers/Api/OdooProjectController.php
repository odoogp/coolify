<?php

namespace App\Http\Controllers\Api;

use App\Actions\Odoo\ProvisionOdooEnvironment;
use App\Domain\Odoo\OdooAbilities;
use App\Domain\Odoo\OdooStaging;
use App\Http\Controllers\Controller;
use App\Jobs\CloneProductionDataJob;
use App\Jobs\CreateOdooBackupJob;
use App\Jobs\RestoreOdooBackupJob;
use App\Jobs\SyncStagingBranchJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\OdooBackup;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Support\EnsureOdooBackupSchedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OdooProjectController extends Controller
{
    public function show(string $uuid): JsonResponse
    {
        $project = $this->project($uuid);
        if (! OdooAbilities::allows(request()->user(), (int) $project->team_id, 'odoo.project.view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->load('odooProfile', 'environments.odooBranch');

        return response()->json(serializeApiResponse([
            'uuid' => $project->uuid,
            'environments' => $project->environments->map(fn (Environment $environment): array => [
                'name' => $environment->name,
                'domain' => $environment->odooBranch?->domain,
                'git_branch' => $environment->odooBranch?->git_branch,
                'odoo_version' => $environment->odooBranch?->odoo_version,
                'workers' => $environment->odooBranch?->workers,
                'addons_path' => $environment->odooBranch?->addons_path,
                'jupyter_enabled' => (bool) $environment->odooBranch?->jupyter_enabled,
            ])->values(),
        ]));
    }

    public function provision(Request $request, string $uuid, string $environment): JsonResponse
    {
        $project = $this->project($uuid);
        $env = $this->environment($project, $environment);
        $ability = OdooStaging::isStagingName($env->name) ? 'odoo.staging.deploy' : 'odoo.production.deploy';
        if (! OdooAbilities::allows($request->user(), (int) $project->team_id, $ability)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $destination = StandaloneDocker::query()->find($request->input('destination_id'));
        if ($destination === null) {
            return response()->json(['message' => 'Destination is required.'], 422);
        }

        try {
            $service = ProvisionOdooEnvironment::run($env, $destination, (string) $request->input('domain'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['uuid' => $service->uuid]);
    }

    public function deploy(Request $request, string $uuid, string $environment): JsonResponse
    {
        $project = $this->project($uuid);
        $env = $this->environment($project, $environment);
        $ability = OdooStaging::isStagingName($env->name) ? 'odoo.staging.deploy' : 'odoo.production.deploy';
        if (! OdooAbilities::allows($request->user(), (int) $project->team_id, $ability)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $application = Application::query()->find($env->odooBranch?->addons_application_id);
        if ($application === null) {
            return response()->json(['message' => 'This environment has no Odoo service yet.'], 422);
        }

        $result = queue_application_deployment($application, new_public_id(), commit: 'HEAD', is_api: true);

        return response()->json($result);
    }

    public function sync(Request $request, string $uuid, string $environment): JsonResponse
    {
        $project = $this->project($uuid);
        $env = $this->environment($project, $environment);
        if (! OdooAbilities::allows($request->user(), (int) $project->team_id, 'odoo.staging.sync')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        SyncStagingBranchJob::dispatch($env->id);

        return response()->json(['status' => 'queued']);
    }

    public function backup(Request $request, string $uuid, string $environment): JsonResponse
    {
        $project = $this->project($uuid);
        $env = $this->environment($project, $environment);
        if (! OdooAbilities::allows($request->user(), (int) $project->team_id, 'odoo.backup.create')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->loadMissing('team.getodooPlan');
        $plan = $project->team?->getodooPlan;
        if (! EnsureOdooBackupSchedules::userMayCreateManualBackup($plan, $request->user())) {
            return response()->json(['message' => __('Contact an advisor to add backups to your plan.')], 422);
        }

        $bypass = $request->user()->isInstanceOwner()
            && ! EnsureOdooBackupSchedules::planAllowsBackups($plan);
        $backup = (new CreateOdooBackupJob($env->id, $bypass))->handle();

        return response()->json(['id' => $backup->id, 'status' => $backup->status]);
    }

    public function restore(Request $request, string $uuid, int $backupId): JsonResponse
    {
        $project = $this->project($uuid);
        $backup = OdooBackup::query()->with('environment')->find($backupId);
        if ($backup === null || $backup->environment?->project_id !== $project->id) {
            return response()->json(['message' => 'Backup not found.'], 404);
        }
        if (! OdooAbilities::allows($request->user(), (int) $project->team_id, 'odoo.backup.restore')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        try {
            $plan = (new RestoreOdooBackupJob($backup->id))->plan();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        RestoreOdooBackupJob::dispatch($backup->id);

        return response()->json($plan);
    }

    public function cloneData(Request $request, string $uuid, string $environment): JsonResponse
    {
        $project = $this->project($uuid);
        $env = $this->environment($project, $environment);
        if (! OdooAbilities::allows($request->user(), (int) $project->team_id, 'odoo.backup.restore')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $backup = OdooBackup::query()->where('environment_id', $env->id)->where('status', 'complete')->latest('id')->first();
        if ($backup === null) {
            return response()->json(['message' => 'Data clone requires a complete backup of staging first.'], 422);
        }

        try {
            $plan = (new CloneProductionDataJob($env->id, $backup->id))->plan();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($plan);
    }

    private function project(string $uuid): Project
    {
        $teamId = getTeamIdFromToken();
        if ($teamId === null) {
            abort(401);
        }

        return Project::query()->where('team_id', $teamId)->where('uuid', $uuid)->firstOrFail();
    }

    private function environment(Project $project, string $nameOrUuid): Environment
    {
        return $project->environments()
            ->where(fn ($query) => $query->where('uuid', $nameOrUuid)->orWhere('name', $nameOrUuid))
            ->firstOrFail();
    }
}
