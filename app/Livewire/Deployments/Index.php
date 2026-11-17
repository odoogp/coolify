<?php

namespace App\Livewire\Deployments;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    public const PAGE_SIZE = 25;

    public const FILTERS = ['all', 'active', 'failed', 'finished'];

    /** Embedded on another page: no page header, project filter fixed by the parent. */
    #[Locked]
    public bool $compact = false;

    #[Locked]
    public ?string $lockedProjectUuid = null;

    #[Url(as: 'status', except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'project', except: '')]
    public string $projectUuid = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public int $limit = self::PAGE_SIZE;

    public function mount(?string $project = null, bool $compact = false, ?int $limit = null): void
    {
        $this->compact = $compact;

        if ($project !== null) {
            $this->lockedProjectUuid = $this->teamProjects()->firstWhere('uuid', $project)?->uuid
                ?? abort(404);
        }

        if ($limit !== null) {
            $this->limit = max(1, $limit);
        }

        if (! in_array($this->filter, self::FILTERS, true)) {
            $this->filter = 'all';
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $this->limit = $this->compact ? $this->limit : self::PAGE_SIZE;
    }

    public function updatedProjectUuid(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function render(): View
    {
        $projects = $this->teamProjects();
        $project = $projects->firstWhere('uuid', $this->lockedProjectUuid ?? $this->projectUuid);

        $applications = Application::query()
            ->whereIn('environment_id', function ($query) use ($projects, $project) {
                $query->select('id')->from('environments')
                    ->whereIn('project_id', $project ? [$project->id] : $projects->pluck('id'));
            })
            ->with('environment:id,uuid,name,role,project_id')
            ->get(['id', 'uuid', 'name', 'environment_id'])
            ->keyBy(fn (Application $application) => (string) $application->id);

        $base = ApplicationDeploymentQueue::query()->whereIn('application_id', $applications->keys());

        $counts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $query = $this->applyFilter(clone $base);

        if (($term = trim($this->search)) !== '') {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('LOWER(application_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(commit_message) LIKE ?', [$like])
                    ->orWhereRaw('LOWER("commit") LIKE ?', [$like]);
            });
        }

        $deployments = $query->orderByDesc('created_at')->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get(['id', 'application_id', 'application_name', 'deployment_uuid', 'deployment_url', 'pull_request_id', 'commit', 'commit_message', 'status', 'is_webhook', 'is_api', 'rollback', 'restart_only', 'server_name', 'created_at', 'finished_at']);

        $hasMore = $deployments->count() > $this->limit;
        $timezone = $this->timezone();
        $rows = $deployments->take($this->limit)
            ->map(fn (ApplicationDeploymentQueue $deployment) => $this->row($deployment, $applications, $projects, $timezone));

        $active = (int) ($counts[ApplicationDeploymentStatus::IN_PROGRESS->value] ?? 0) + (int) ($counts[ApplicationDeploymentStatus::QUEUED->value] ?? 0);

        return view('livewire.deployments.index', [
            'groups' => $rows->groupBy('day'),
            'hasMore' => $hasMore,
            'projects' => $projects,
            'counts' => [
                'all' => (int) $counts->sum(),
                'active' => $active,
                'failed' => (int) ($counts[ApplicationDeploymentStatus::FAILED->value] ?? 0),
                'finished' => (int) ($counts[ApplicationDeploymentStatus::FINISHED->value] ?? 0),
            ],
            'polling' => $active > 0,
            'projectName' => $project?->name,
        ]);
    }

    /**
     * @return Collection<int, Project>
     */
    private function teamProjects(): Collection
    {
        return Project::query()
            ->where('team_id', currentTeam()->id)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name']);
    }

    private function timezone(): string
    {
        $timezone = instanceSettings()->instance_timezone ?: config('app.timezone');

        return validate_timezone($timezone) ? $timezone : 'UTC';
    }

    private function applyFilter(Builder $query): Builder
    {
        return match ($this->filter) {
            'active' => $query->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value]),
            'failed' => $query->where('status', ApplicationDeploymentStatus::FAILED->value),
            'finished' => $query->where('status', ApplicationDeploymentStatus::FINISHED->value),
            default => $query,
        };
    }

    /**
     * @param  Collection<string, Application>  $applications
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function row(ApplicationDeploymentQueue $deployment, Collection $applications, Collection $projects, string $timezone): array
    {
        $application = $applications->get((string) $deployment->application_id);
        $environment = $application?->environment;
        $project = $environment ? $projects->firstWhere('id', $environment->project_id) : null;
        $createdAt = ($deployment->created_at ?? now())->copy()->setTimezone($timezone);
        $endedAt = $deployment->finished_at ? Carbon::parse($deployment->finished_at) : null;
        $isActive = in_array($deployment->status, [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value], true);

        return [
            'key' => $deployment->deployment_uuid,
            'url' => $deployment->deployment_url,
            'name' => $application?->name ?? $deployment->application_name,
            'message' => str((string) $deployment->commit_message)->before("\n")->trim()->limit(90)->toString(),
            'commit' => $deployment->commit && $deployment->commit !== 'HEAD' ? substr($deployment->commit, 0, 7) : null,
            'pullRequest' => $deployment->pull_request_id ?: null,
            'project' => $project?->name,
            'environment' => $environment?->name,
            'production' => $environment?->isProduction() ?? false,
            'status' => $deployment->status,
            'statusLabel' => $this->statusLabel($deployment->status),
            'trigger' => match (true) {
                (bool) $deployment->rollback => __('Rollback'),
                (bool) $deployment->is_webhook => __('Push'),
                (bool) $deployment->is_api => __('API'),
                (bool) $deployment->restart_only => __('Restart'),
                default => __('Manual'),
            },
            'time' => $createdAt->format('H:i'),
            'ago' => $createdAt->diffForHumans(),
            'duration' => $this->duration($createdAt, $isActive ? null : $endedAt, $isActive),
            'isActive' => $isActive,
            'day' => $this->dayLabel($createdAt),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            ApplicationDeploymentStatus::FINISHED->value => __('Deployed'),
            ApplicationDeploymentStatus::IN_PROGRESS->value => __('Deploying'),
            ApplicationDeploymentStatus::QUEUED->value => __('Queued'),
            ApplicationDeploymentStatus::FAILED->value => __('Failed'),
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => __('Cancelled'),
            default => str($status)->headline()->toString(),
        };
    }

    private function duration(Carbon $start, ?Carbon $end, bool $isActive): ?string
    {
        if (! $end && ! $isActive) {
            return null;
        }

        $seconds = max(0, (int) $start->diffInSeconds($end ?? now()));

        return $seconds < 60
            ? $seconds.'s'
            : intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    }

    private function dayLabel(Carbon $date): string
    {
        return match (true) {
            $date->isToday() => __('Today'),
            $date->isYesterday() => __('Yesterday'),
            default => str($date->translatedFormat($date->isCurrentYear() ? 'l j F' : 'j F Y'))->ucfirst()->toString(),
        };
    }
}
