<?php

namespace App\Livewire\Settings;

use App\Models\GpshOwnerModule;
use App\Models\InstanceSettings;
use App\Models\OdooComposeTemplate;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Component;

class Odoo extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    public string $version = '18';

    public string $newVersion = '';

    public string $postgresVersion = '16-alpine';

    public string $compose = '';

    public string $moduleName = '';

    public string $odooBaseDomain = '';

    public int $volumePage = 1;

    /** @var list<string> */
    public array $selectedVolumes = [];

    public function mount(): void
    {
        if (! isInstanceAdmin()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->settings = instanceSettings();
        $this->odooBaseDomain = (string) ($this->settings->odoo_base_domain ?? '');
        $this->loadVersion();
    }

    public function updatedVersion(): void
    {
        $this->loadVersion();
    }

    public function createVersion(): void
    {
        $version = trim($this->newVersion);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $version)) {
            $this->dispatch('error', __('The Odoo version is the image tag, for example 21.'));

            return;
        }

        $this->version = $version;
        $this->newVersion = '';
        $this->loadVersion();
    }

    public function save(): void
    {
        try {
            $this->authorize('update', $this->settings);
            $row = OdooComposeTemplate::saveFor($this->version, $this->compose, $this->postgresVersion);
            $this->compose = $row->compose;
            $this->postgresVersion = $row->postgres_version;
            $this->dispatch('success', __('Odoo template saved.'));
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveBaseDomain(): void
    {
        try {
            $this->authorize('update', $this->settings);
            $domain = OdooGit::normalizedBaseDomain($this->odooBaseDomain);
            if (trim($this->odooBaseDomain) !== '' && $domain === '') {
                $this->dispatch('error', __('The domain needs a name and a dot, for example dev.odoo.com.'));

                return;
            }
            $this->settings->odoo_base_domain = $domain === '' ? null : $domain;
            $this->settings->save();
            $this->odooBaseDomain = $domain;
            $this->dispatch('success', __('Odoo domain saved.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function addModule(): void
    {
        $this->authorize('update', $this->settings);
        $name = trim($this->moduleName);
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            $this->dispatch('error', __('The module name can only use letters, numbers, and underscores.'));

            return;
        }

        GpshOwnerModule::query()->firstOrCreate(['name' => $name]);
        $this->moduleName = '';
        $this->dispatch('success', __('Owner module saved. Redeploy Odoo to link it.'));
    }

    public function removeModule(string $name): void
    {
        $this->authorize('update', $this->settings);
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) === 1) {
            OdooJupyter::forgetOwnerModule($name);
            GpshOwnerModule::query()->where('name', $name)->delete();
        }
        $this->dispatch('success', __('Owner module removed. Redeploy Odoo to drop the link.'));
    }

    public function selectAllVolumes(): void
    {
        $this->authorize('update', $this->settings);
        $names = array_column(OdooJupyter::leftoverVolumeRowsOnInstance(), 'name');
        $selected = $this->selectedVolumes;
        sort($selected);
        sort($names);
        $this->selectedVolumes = $selected === $names ? [] : $names;
    }

    public function deleteSelectedVolumes(): void
    {
        $this->authorize('update', $this->settings);
        $allowed = array_column(OdooJupyter::leftoverVolumeRowsOnInstance(), 'name');
        foreach ($this->selectedVolumes as $name) {
            if (in_array($name, $allowed, true)) {
                OdooJupyter::deleteLeftoverVolume($name);
            }
        }
        $this->selectedVolumes = [];
        $this->volumePage = 1;
        $this->dispatch('success', __('Volume deleted.'));
    }

    public function previousVolumePage(): void
    {
        $this->volumePage = max(1, $this->volumePage - 1);
    }

    public function nextVolumePage(): void
    {
        $pages = OdooJupyter::pageVolumeRows(OdooJupyter::leftoverVolumeRowsOnInstance(), $this->volumePage)['pages'];
        $this->volumePage = min($pages, $this->volumePage + 1);
    }

    public function render()
    {
        $saved = OdooComposeTemplate::query()->orderBy('version')->pluck('version')->all();

        return view('livewire.settings.odoo', [
            'versions' => array_values(array_unique([...OdooVersion::SUPPORTED, ...$saved, $this->version])),
            'ownerModules' => GpshOwnerModule::names(),
            'volumes' => OdooJupyter::pageVolumeRows(OdooJupyter::leftoverVolumeRowsOnInstance(), $this->volumePage),
        ]);
    }

    private function loadVersion(): void
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $this->version)) {
            $this->version = '18';
        }

        $state = OdooComposeTemplate::editorState($this->version);
        $this->compose = $state['compose'];
        $this->postgresVersion = $state['postgresVersion'];
    }
}
