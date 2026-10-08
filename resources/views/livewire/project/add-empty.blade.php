<div>
@if ($needsServer)
    <div class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-3 dark:border-white/[0.08] dark:bg-white/[0.025]">
        <p class="text-[13px] leading-5 text-neutral-700 dark:text-fg">
            {{ __('Do you want to create a server?') }}
        </p>
        @if ($canAddServer)
            <a href="{{ route('server.create') }}" class="button button-highlighted" {{ wireNavigate() }}>
                {{ __('Create a new server') }}
            </a>
        @else
            <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                {{ __('The owner has to add a server, or allow you to add servers, before you can create a project.') }}
            </p>
        @endif
    </div>
@else
<form class="space-y-4" wire:submit="submit">
    <div class="grid gap-4 md:grid-cols-2">
        <x-forms.input placeholder="{{ __('Your project name') }}" id="name" label="{{ __('Name') }}" required />
        <x-forms.input placeholder="{{ __('A short project description') }}" id="description" label="{{ __('Description') }}" />
    </div>

    <x-creation-quota :quota="$creationQuota" />

    <x-forms.searchable-listbox id="service" label="{{ __('What do you want to launch?') }}" live portal
        searchPlaceholder="{{ __('Search the catalog (Odoo, Redis, n8n, custom…)') }}"
        emptyText="{{ __('No matching service') }}"
        helper="{{ __('Odoo is the default hook. Any visible template from Settings → Service templates can launch in one click.') }}"
        :options="$serviceOptions" />

    @if ($serverChoices !== [])
        <x-forms.listbox id="serverId" portal
            label="{{ $hasOtherServers ? __('Which server should run this project?') : __('Do you want to create a new server?') }}"
            :options="$serverChoices" />
    @endif

    @if ($service === 'odoo')
        <x-forms.listbox id="odooVersion" label="{{ __('Odoo version') }}" portal
            :options="collect(\App\Domain\Odoo\OdooVersion::SUPPORTED)->map(fn (string $version) => ['value' => $version, 'label' => 'Odoo '.$version])->all()" />
        <x-forms.checkbox id="connectGithub" label="{{ __('Connect GitHub') }}"
            helper="{{ __('You can leave this off. JupyterLab then shows the addon files, and a repository can be connected later.') }}" />
    @endif

    <p
        class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
        {{ __('A production environment will be created automatically.') }}
    </p>

    <footer class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit" wire:target="submit"
            defaultClass="button button-highlighted">
            {{ __('Create project') }}
        </x-forms.button>
    </footer>
</form>
@endif
</div>
