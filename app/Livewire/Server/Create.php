<?php

namespace App\Livewire\Server;

use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Services\Cloud\AdditionalCloudCatalog;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public ?string $selectedType = null;

    public ?string $selectedTokenUuid = null;

    public bool $limit_reached = false;

    public bool $has_hetzner_tokens = false;

    public bool $getodoo_owner = false;

    public bool $getodoo_available = false;

    public function mount(?string $selectedType = null, ?string $selectedTokenUuid = null): void
    {
        $allowedTypes = array_merge(['hetzner', 'vultr', 'digital-ocean', 'manual', 'getodoo'], AdditionalCloudCatalog::slugs());
        $this->selectedType = in_array($selectedType, $allowedTypes, true)
            ? $selectedType
            : null;
        $this->selectedTokenUuid = $this->selectedType && $this->selectedType !== 'manual' ? $selectedTokenUuid : null;

        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
        $this->getodoo_owner = isInstanceOwner();
        $this->getodoo_available = GetOdooServerOffer::query()->forAdmins()->exists();
        if (! isCloud()) {
            $this->limit_reached = false;

            return;
        }
        $this->limit_reached = Team::serverLimitReached();

        // Check if user has Hetzner tokens
        $this->has_hetzner_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->exists();
    }

    public function render()
    {
        return view('livewire.server.create');
    }
}
