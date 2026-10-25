<?php

namespace App\View\Components\Services;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Links extends Component
{
    public Collection $links;

    public ?string $jupyterUrl = null;

    public function __construct(public Service $service, public bool $fullWidth = false, public bool $compact = false)
    {
        $this->jupyterUrl = OdooJupyter::sessionUrl($service);
        $this->links = $this->publicOpenLinks($service);
    }

    /**
     * One open URL for the service entrypoint — not every compose app, and never
     * Coolify instance host:port guesses (those look like getcloud.sh:9000).
     *
     * @return Collection<int, string>
     */
    private function publicOpenLinks(Service $service): Collection
    {
        $preferred = OdooGit::preferredPublicHttpApplication($service);
        if ($preferred instanceof ServiceApplication) {
            $type = $preferred->serviceType();
            if ($type) {
                $specific = generateServiceSpecificFqdns($preferred)
                    ->map(fn ($link): string => getFqdnWithoutPort((string) $link))
                    ->filter(fn (string $link): bool => $link !== '')
                    ->unique()
                    ->values();
                if ($specific->isNotEmpty()) {
                    return $specific;
                }
            }
        }

        $primary = OdooGit::publicHttpsUrl($service);
        if ($primary !== '') {
            return collect([getFqdnWithoutPort($primary)])->filter()->values();
        }

        if (! $preferred instanceof ServiceApplication || blank($preferred->fqdn)) {
            return collect([]);
        }

        return collect(str($preferred->fqdn)->explode(','))
            ->map(fn ($fqdn): string => getFqdnWithoutPort(trim((string) $fqdn)))
            ->filter(fn (string $fqdn): bool => $fqdn !== '')
            ->unique()
            ->values();
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.services.links');
    }
}
