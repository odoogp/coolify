<?php

namespace App\Support;

use App\Enums\ProcessStatus;
use App\Models\GpshNotice;
use App\Models\GpshNoticeSetting;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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
        $name = trim((string) $service->environment?->project?->name.' / '.(string) $service->environment?->name, ' /');
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
