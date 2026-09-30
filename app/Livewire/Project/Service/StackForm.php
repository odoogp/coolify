<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Support\OdooVersion;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class StackForm extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public Collection $fields;

    public bool $isPasswordHiddenForMember = false;

    protected $listeners = ['saveCompose'];

    // Explicit properties
    public string $name;

    public ?string $description = null;

    public string $dockerComposeRaw;

    public ?string $dockerCompose = null;

    public ?bool $connectToDockerNetwork = null;

    public bool $jupyterEnabled = false;

    public ?string $odooVersion = null;

    private bool $applyingOdooVersion = false;

    protected function rules(): array
    {
        $baseRules = [
            'dockerComposeRaw' => 'required',
            'dockerCompose' => 'nullable',
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'connectToDockerNetwork' => 'nullable',
            'jupyterEnabled' => 'boolean',
            'odooVersion' => 'nullable|string|max:32',
        ];

        // Add dynamic field rules
        foreach ($this->fields ?? collect() as $key => $field) {
            $rules = data_get($field, 'rules', 'nullable');
            $baseRules["fields.$key.value"] = $rules;
        }

        return $baseRules;
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'dockerComposeRaw.required' => 'The Docker Compose Raw field is required.',
                'dockerCompose.required' => 'The Docker Compose field is required.',
            ]
        );
    }

    public $validationAttributes = [];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->service->name = $this->name;
            $this->service->description = $this->description;
            $this->service->docker_compose_raw = $this->dockerComposeRaw;
            $this->service->docker_compose = $this->dockerCompose;
            $this->service->connect_to_docker_network = $this->connectToDockerNetwork;
            $this->service->jupyter_enabled = $this->jupyterEnabled;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->service->name;
            $this->description = $this->service->description;
            $this->dockerComposeRaw = $this->service->docker_compose_raw;
            $this->dockerCompose = $this->service->docker_compose;
            $this->connectToDockerNetwork = $this->service->connect_to_docker_network;
            $this->jupyterEnabled = (bool) $this->service->jupyter_enabled;
            $this->odooVersion = OdooVersion::current((string) $this->service->docker_compose_raw);
        }
    }

    public function updatedOdooVersion(?string $version): void
    {
        if ($this->applyingOdooVersion || $version === null || $version === '' || $version === OdooVersion::current((string) $this->dockerComposeRaw)) {
            return;
        }

        $this->applyingOdooVersion = true;

        try {
            $this->authorize('update', $this->service);
            if (! in_array($version, OdooVersion::SUPPORTED, true)) {
                $this->odooVersion = OdooVersion::current((string) $this->dockerComposeRaw);
                $this->dispatch('error', __('Choose Odoo 17, 18, 19, or 20.'));

                return;
            }

            $updated = OdooVersion::apply((string) $this->dockerComposeRaw, $version);
            if ($updated === $this->dockerComposeRaw) {
                $this->odooVersion = OdooVersion::current((string) $this->dockerComposeRaw);
                $this->dispatch('error', __('This Odoo image cannot be changed from here. Edit the Compose file.'));

                return;
            }

            $this->dockerComposeRaw = $updated;
            $this->submit();
        } catch (\Throwable $e) {
            $this->odooVersion = OdooVersion::current((string) $this->service->docker_compose_raw);
            handleError($e, $this);
        } finally {
            $this->applyingOdooVersion = false;
        }
    }

    public function mount()
    {
        $this->syncData(false);
        $this->fields = collect([]);
        $extraFields = $this->service->extraFields();
        foreach ($extraFields as $serviceName => $fields) {
            foreach ($fields as $fieldKey => $field) {
                $key = data_get($field, 'key');
                $value = data_get($field, 'value');
                $rules = data_get($field, 'rules', 'nullable');
                $isPassword = data_get($field, 'isPassword', false);
                $customHelper = data_get($field, 'customHelper', false);
                $sortOrder = data_get($field, 'sortOrder');
                $this->fields->put($key, [
                    'serviceName' => $serviceName,
                    'key' => $key,
                    'name' => $fieldKey,
                    'value' => $value,
                    'isPassword' => $isPassword,
                    'rules' => $rules,
                    'customHelper' => $customHelper,
                    'sortOrder' => $sortOrder,
                ]);

                $this->validationAttributes["fields.$key.value"] = $fieldKey;
            }
        }
        $this->fields = $this->fields->groupBy('serviceName')->map(function ($group) {
            return $group->sortBy(function ($field) {
                return data_get($field, 'sortOrder') ?? (data_get($field, 'isPassword') ? 1 : 0);
            })->mapWithKeys(function ($field) {
                return [$field['key'] => $field];
            });
        })->flatMap(function ($group) {
            return $group;
        });

        $this->isPasswordHiddenForMember = auth()->user()?->isMember() ?? false;
        if ($this->isPasswordHiddenForMember) {
            $this->fields = $this->fields->map(function ($field) {
                if (data_get($field, 'isPassword')) {
                    $field['value'] = null;
                }

                return $field;
            });
        }
    }

    public function saveCompose($raw)
    {
        $this->dockerComposeRaw = $raw;
        $this->submit(notify: true);
        $this->dispatch('compose-save-finished');
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->service);
            $this->syncData(true);
            $this->service->save();
            $this->dispatch('success', __('Service settings saved.'));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit($notify = true)
    {
        try {
            $this->authorize('update', $this->service);
            $this->validate();
            $this->syncData(true);

            // Validate for command injection BEFORE any database operations
            validateDockerComposeForInjection($this->service->docker_compose_raw);

            // Use transaction to ensure atomicity - if parse fails, save is rolled back
            DB::transaction(function () {
                $this->service->save();
                $this->service->saveExtraFields($this->fields);
                $this->service->parse();
            });
            // Refresh and write files after a successful commit
            $this->service->refresh();
            $this->service->saveComposeConfigs();

            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
            $notify && $this->dispatch('success', __('Service saved.'));
        } catch (\Throwable $e) {
            // On error, refresh from database to restore clean state
            $this->service->refresh();
            $this->syncData(false);

            return handleError($e, $this);
        } finally {
            if (is_null($this->service->config_hash)) {
                $this->service->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }

    public function render()
    {
        return view('livewire.project.service.stack-form');
    }
}
