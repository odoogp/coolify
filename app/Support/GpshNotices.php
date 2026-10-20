<?php

namespace App\Support;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Models\GpshNotice;
use App\Models\GpshNoticeSetting;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

class GpshNotices
{
    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return ['mounted', 'accessible', 'expiration', 'deletion', 'custom'];
    }

    public static function publish(
        ?Service $service,
        string $kind,
        string $title,
        string $body,
        string $audience = 'clients',
        ?int $teamId = null,
        ?int $userId = null,
    ): ?GpshNotice {
        if (! GpshNoticeSetting::current()->allows($kind)) {
            return null;
        }
        if (! in_array($audience, ['owner', 'clients'], true)) {
            $audience = 'clients';
        }

        return GpshNotice::query()->create([
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'kind' => $kind,
            'team_id' => $audience === 'clients' ? $teamId : null,
            'service_id' => $service?->id,
            'created_by' => $userId,
        ]);
    }

    public static function rememberUpgradeFailure(string $message): ?GpshNotice
    {
        if (! GpshNoticeSetting::current()->allows('custom')) {
            return null;
        }

        $message = trim($message);
        if ($message === '') {
            $message = __('The update stopped before it finished.');
        }

        if (! Cache::add('gpsh:upgrade-failure:'.sha1($message), true, now()->addHours(6))) {
            return null;
        }

        return self::publish(
            null,
            'custom',
            __('Update did not finish'),
            $message,
            'owner',
            null,
            Auth::id(),
        );
    }

    public static function announce(Service $service, string $kind): ?GpshNotice
    {
        $service->loadMissing('environment.project');
        $name = self::serviceLabel($service);
        $title = $kind === 'mounted' ? __('Odoo is up') : __('Odoo is accessible');
        $body = $kind === 'mounted'
            ? __('The containers for :name are running.', ['name' => $name])
            : __('You can open :name.', ['name' => $name]);

        return self::publish(
            $service,
            $kind,
            $title,
            $body,
            'clients',
            $service->environment?->project?->team_id,
        );
    }

    /**
     * Bell + toast signal when a client (or admin) clicks Restart on an Odoo service.
     */
    public static function announceRestarting(Service $service): ?GpshNotice
    {
        $service->loadMissing('environment.project');
        $name = self::serviceLabel($service);

        return self::publish(
            $service,
            'custom',
            __('Odoo is restarting'),
            __('The instance :name started restarting.', ['name' => $name]),
            'clients',
            $service->environment?->project?->team_id,
            Auth::id(),
        );
    }

    public static function watch(Service $service, string $output, string $status, bool &$mounted, bool &$accessible): void
    {
        if (! $mounted && str_contains($output, 'The service containers are running.')) {
            $mounted = true;
            self::announce($service, 'mounted');
        }
        if (! $accessible && $status === ProcessStatus::FINISHED->value) {
            $accessible = true;
            self::announce($service, 'accessible');
        }
    }

    /**
     * Poll a StartService / restart activity until it finishes (or fails), publishing notices.
     */
    public static function watchActivity(Service $service, Activity $activity, int $timeoutSeconds = 1200): void
    {
        $mounted = false;
        $accessible = false;
        $deadline = time() + max(60, $timeoutSeconds);
        while (time() < $deadline) {
            $status = RunRemoteProcess::readStatus($activity);
            $output = RunRemoteProcess::logContains($activity, 'The service containers are running.')
                ? 'The service containers are running.'
                : '';
            self::watch($service, $output, $status, $mounted, $accessible);
            if ($status === ProcessStatus::FINISHED->value) {
                return;
            }
            if (in_array($status, [
                ProcessStatus::ERROR->value,
                ProcessStatus::KILLED->value,
                ProcessStatus::CANCELLED->value,
            ], true)) {
                return;
            }
            sleep(3);
        }
    }

    private static function serviceLabel(Service $service): string
    {
        $service->loadMissing('environment.project');

        return trim((string) $service->environment?->project?->name.' / '.(string) $service->environment?->name, ' /');
    }

    public static function forgetExpired(): void
    {
        $days = (int) GpshNoticeSetting::current()->keep_days;
        if ($days < 1) {
            return;
        }

        GpshNotice::query()->where('created_at', '<', now()->subDays($days))->delete();
    }

    public static function forUser(User $user): Builder
    {
        self::forgetExpired();
        $enabled = array_values(array_filter(
            self::kinds(),
            fn (string $kind): bool => GpshNoticeSetting::current()->allows($kind),
        ));
        $query = GpshNotice::query()->whereIn('kind', $enabled === [] ? ['__none__'] : $enabled)->orderByDesc('id');
        if ($user->isInstanceOwner()) {
            return $query;
        }

        $teamIds = $user->teams()->pluck('teams.id');

        return $query->where('audience', 'clients')->where(function (Builder $query) use ($teamIds): void {
            $query->whereNull('team_id')->orWhereIn('team_id', $teamIds);
        });
    }
}
