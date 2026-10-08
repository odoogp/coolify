<?php

namespace App\Livewire\Settings;

use App\Domain\Odoo\OdooVersion;
use App\Models\GithubApp;
use App\Models\GpshOwnerModule;
use App\Models\InstanceSettings;
use App\Models\OdooComposeTemplate;
use App\Rules\ValidGitBranch;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
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

    public string $ownerRepository = '';

    public string $ownerBranch = '';

    public ?int $ownerGithubAppId = null;

    /** @var list<string> */
    public array $ownerBranches = [];

    public string $odooBaseDomain = '';

    public int $mailDailyLimit = 20;

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
        $this->mailDailyLimit = max(0, min(10000, (int) ($this->settings->odoo_mail_daily_limit ?? 20)));
        $this->ownerRepository = (string) ($this->settings->odoo_owner_repository ?? '');
        $this->ownerBranch = (string) ($this->settings->odoo_owner_branch ?? '');
        $storedAppId = (int) ($this->settings->odoo_owner_github_app_id ?? 0);
        $this->ownerGithubAppId = $storedAppId > 0
            ? $storedAppId
            : OdooGit::ownerGithubApp()?->id;
        $this->loadVersion();
    }

    public function updatedOwnerGithubAppId(): void
    {
        $this->authorize('update', $this->settings);
        $this->ownerBranches = [];
        $this->persistOwnerGithubAppId();
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

    public function saveMailLimit(): void
    {
        abort_unless(isInstanceOwner(), 403);

        try {
            $this->authorize('update', $this->settings);
            $limit = max(0, min(10000, (int) $this->mailDailyLimit));
            $this->settings->odoo_mail_daily_limit = $limit;
            $this->settings->save();
            $this->mailDailyLimit = $limit;
            $this->dispatch('success', __('Mail limit saved.'));
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
        $this->dispatch('success', __('Owner module saved. The next Odoo start copies it into the image addons.'));
    }

    public function loadOwnerBranches(): void
    {
        $this->authorize('update', $this->settings);
        $repository = OdooGit::normalizeRepository($this->ownerRepository);
        if (preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $repository) !== 1) {
            $this->dispatch('error', __('Use the GitHub repository as owner/name.'));

            return;
        }

        $this->ownerRepository = $repository;
        $this->persistOwnerGithubAppId();
        $githubApp = $this->selectedOwnerGithubApp();
        if (! $githubApp instanceof GithubApp) {
            $this->dispatch('error', __('Connect a GitHub App before loading branches.'));

            return;
        }

        try {
            $this->ownerBranches = OdooGit::repositoryBranches($githubApp, $repository);
        } catch (\Throwable) {
            $this->ownerBranches = [];
            $this->dispatch('error', __('GitHub did not return branches for that repository.'));

            return;
        }

        if ($this->ownerBranches === []) {
            $this->dispatch('error', __('GitHub did not return branches for that repository.'));

            return;
        }

        if (! in_array($this->ownerBranch, $this->ownerBranches, true)) {
            $this->ownerBranch = $this->ownerBranches[0];
        }
    }

    public function saveOwnerRepository(): void
    {
        $this->authorize('update', $this->settings);
        $repository = OdooGit::normalizeRepository($this->ownerRepository);
        $branch = trim($this->ownerBranch);
        if ($repository === '' && $branch === '') {
            $this->settings->update([
                'odoo_owner_repository' => null,
                'odoo_owner_branch' => null,
                'odoo_owner_github_app_id' => $this->resolvedOwnerGithubAppId(),
            ]);
            $this->ownerRepository = '';
            $this->ownerBranch = '';
            OdooGit::syncOwnerRepository();
            $this->dispatch('success', __('Owner repository cleared. The next start removes those modules from Odoo.'));

            return;
        }

        if (preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $repository) !== 1) {
            $this->dispatch('error', __('Use the GitHub repository as owner/name.'));

            return;
        }

        $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
        if ($check->fails()) {
            $this->dispatch('error', __('The GitHub branch name is invalid.'));

            return;
        }

        if (! $this->selectedOwnerGithubApp() instanceof GithubApp) {
            $this->dispatch('error', __('Connect a GitHub App before saving the owner branch.'));

            return;
        }

        $this->settings->update([
            'odoo_owner_repository' => $repository,
            'odoo_owner_branch' => $branch,
            'odoo_owner_github_app_id' => $this->resolvedOwnerGithubAppId(),
        ]);
        $this->ownerRepository = $repository;
        $this->ownerBranch = $branch;

        try {
            OdooGit::syncOwnerRepository();
        } catch (\Throwable) {
            $this->dispatch('error', __('The branch was saved, but the clone did not finish. The next start still uses the previous copy.'));

            return;
        }

        $this->dispatch('success', __('Owner branch saved. The next Odoo start copies these modules into the image. The client addon folder does not include them.'));
    }

    public function removeModule(string $name): void
    {
        $this->authorize('update', $this->settings);
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) === 1) {
            OdooJupyter::forgetOwnerModule($name);
            GpshOwnerModule::query()->where('name', $name)->delete();
        }
        $this->dispatch('success', __('Owner module removed. The next Odoo start drops it from the image addons.'));
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
        $ownerGithubApps = OdooGit::connectedAppsForOwnerModules();
        $selected = $this->selectedOwnerGithubApp();
        $ownerGithubLogin = '';
        if ($selected instanceof GithubApp) {
            $ownerGithubLogin = OdooGit::githubAppLabel($selected);
        }

        return view('livewire.settings.odoo', [
            'versions' => array_values(array_unique([...OdooVersion::SUPPORTED, ...$saved, $this->version])),
            'ownerModules' => GpshOwnerModule::names(),
            'volumes' => OdooJupyter::pageVolumeRows(OdooJupyter::leftoverVolumeRowsOnInstance(), $this->volumePage),
            'ownerGithubApps' => $ownerGithubApps
                ->map(fn (GithubApp $app): array => [
                    'value' => $app->id,
                    'label' => OdooGit::githubAppLabel($app),
                ])
                ->values()
                ->all(),
            'ownerGithubLogin' => $ownerGithubLogin,
            'ownerGithubConnected' => $selected instanceof GithubApp,
        ]);
    }

    private function selectedOwnerGithubApp(): ?GithubApp
    {
        $id = (int) ($this->ownerGithubAppId ?? 0);
        if ($id <= 0) {
            return null;
        }

        return OdooGit::connectedAppsForOwnerModules()->firstWhere('id', $id);
    }

    private function resolvedOwnerGithubAppId(): ?int
    {
        $app = $this->selectedOwnerGithubApp();

        return $app instanceof GithubApp ? (int) $app->id : null;
    }

    private function persistOwnerGithubAppId(): void
    {
        $this->settings->odoo_owner_github_app_id = $this->resolvedOwnerGithubAppId();
        $this->settings->save();
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
