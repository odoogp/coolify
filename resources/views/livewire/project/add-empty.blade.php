<form class="space-y-4" wire:submit="submit">
    <div class="grid gap-4 md:grid-cols-2">
        <x-forms.input placeholder="{{ __('Your project name') }}" id="name" label="{{ __('Name') }}" required />
        <x-forms.input placeholder="{{ __('A short project description') }}" id="description" label="{{ __('Description') }}" />
    </div>

    <x-creation-quota :quota="$creationQuota" />

    <x-forms.listbox id="service" label="{{ __('Service') }}" live :options="$serviceOptions" />

    @if ($service === 'odoo')
        <x-forms.listbox id="odooVersion" label="{{ __('Odoo version') }}"
            :options="collect(\App\Support\OdooVersion::SUPPORTED)->map(fn (string $version) => ['value' => $version, 'label' => 'Odoo '.$version])->all()" />
        <x-forms.checkbox id="connectGithub" label="{{ __('Connect GitHub') }}"
            helper="{{ __('You can leave this off. JupyterLab then shows the addon files, and a repository can be connected later.') }}" />
    @endif

    <p
        class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
        {{ __('A production environment will be created automatically.') }}
    </p>

    <footer class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit"
            defaultClass="button button-highlighted">
            {{ __('Create project') }}
        </x-forms.button>
    </footer>
</form>
