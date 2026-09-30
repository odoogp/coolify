<?php

namespace App\View\Components\Services;

use App\Models\Service;
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
        $this->links = collect([]);
        $applications = $service->applications()->get();
        if ($service->jupyter_enabled && auth()->user()?->can('view', $service)) {
            $jupyter = $applications->firstWhere('name', OdooJupyter::SERVICE_NAME);
            if (filled($jupyter?->fqdn)) {
                $this->jupyterUrl = getFqdnWithoutPort(firstDomainFromList($jupyter->fqdn));
                $token = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_JUPYTER')->first()?->value;
                if (filled($token)) {
                    $this->jupyterUrl .= '?token='.urlencode($token);
                }
            }
        }
        $applications->each(function ($application) {
            if ($this->jupyterUrl && $application->name === OdooJupyter::SERVICE_NAME) {
                return;
            }
            $type = $application->serviceType();
            if ($type) {
                $links = generateServiceSpecificFqdns($application);
                $links = $links->map(function ($link) {
                    return getFqdnWithoutPort($link);
                });
                $this->links = $this->links->merge($links);
            } else {
                if ($application->fqdn) {
                    $fqdns = collect(str($application->fqdn)->explode(','));
                    $fqdns->map(function ($fqdn) {
                        $this->links->push(getFqdnWithoutPort($fqdn));
                    });
                }
                if ($application->ports) {
                    $portsCollection = collect(str($application->ports)->explode(','));
                    $portsCollection->map(function ($port) {
                        if (str($port)->contains(':')) {
                            $hostPort = str($port)->before(':');
                        } else {
                            $hostPort = $port;
                        }
                        $this->links->push(base_url(withPort: false).":{$hostPort}");
                    });
                }
            }
        });
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.services.links');
    }
}
