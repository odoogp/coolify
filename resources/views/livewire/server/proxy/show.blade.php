<div>
    <x-slot:title>
        {{ __('Proxy Configuration | Coolify') }}
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="proxy" activeSubMenu="configuration" />
        @if ($server->isFunctional())
            <div class="w-full">
                <livewire:server.proxy :server="$server" />
            </div>
        @else
            <div class="application-settings-form w-full">
                <x-application.settings-section title="{{ __('Proxy') }}"
                    helper="{{ __('Configure the reverse proxy for this server.') }}">
                    <x-empty size="sm" title="{{ __('Server validation required') }}"
                        description="{{ __('Validate this server before configuring its proxy.') }}"
                        icon-name="servers" />
                </x-application.settings-section>
            </div>
        @endif
    </div>
</div>
