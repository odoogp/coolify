<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="addToken"
        x-data="{
            selectedProvider: $wire.entangle('provider'),
            get providerName() {
                const names = {
                    digitalocean: 'DigitalOcean',
                    linode: 'Linode',
                    upcloud: 'UpCloud',
                    scaleway: 'Scaleway',
                    contabo: 'Contabo',
                    exoscale: 'Exoscale',
                };

                return names[this.selectedProvider]
                    || this.selectedProvider.charAt(0).toUpperCase() + this.selectedProvider.slice(1);
            },
            get providerConsoleUrl() {
                const urls = {
                    hetzner: 'https://console.hetzner.com/projects',
                    vultr: 'https://console.vultr.com/user/apiaccess/',
                    digitalocean: 'https://cloud.digitalocean.com/account/api/tokens',
                    linode: 'https://cloud.linode.com/profile/tokens',
                    upcloud: 'https://hub.upcloud.com/people',
                    scaleway: 'https://console.scaleway.com/iam/api-keys',
                    contabo: 'https://my.contabo.com/api/details',
                    exoscale: 'https://portal.exoscale.com/iam/api-keys',
                };

                return urls[this.selectedProvider] || urls.digitalocean;
            },
            get needsAccount() {
                return ['upcloud', 'contabo', 'exoscale'].includes(this.selectedProvider);
            },
            get needsProject() {
                return ['scaleway', 'contabo'].includes(this.selectedProvider);
            }
        }">
        @if (!$provider_locked)
            <x-forms.listbox required id="provider" label="{{ __('Provider') }}" :wire="false" :value="$provider"
                x-model="selectedProvider" :options="[
                ['value' => 'hetzner', 'label' => __('Hetzner')],
                ['value' => 'digitalocean', 'label' => __('DigitalOcean')],
                ['value' => 'vultr', 'label' => __('Vultr')],
                ['value' => 'linode', 'label' => __('Linode')],
                ['value' => 'upcloud', 'label' => __('UpCloud')],
                ['value' => 'scaleway', 'label' => __('Scaleway')],
                ['value' => 'contabo', 'label' => __('Contabo')],
                ['value' => 'exoscale', 'label' => __('Exoscale')],
            ]" />
        @else
            <input type="hidden" wire:model="provider" />
        @endif

        <div
            class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[11px] leading-5 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
            {{ __('Create the token in the') }}
            <a :href="providerConsoleUrl"
                target="_blank" class="font-medium text-coollabs hover:underline dark:text-warning">
                <span x-text="providerName + ' console'"></span>
            </a>.
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input required id="name" label="{{ __('Token name') }}"
                x-bind:placeholder="`Production ${providerName} token`" />
            <div class="lg:col-span-2" x-cloak x-show="needsAccount || needsProject || selectedProvider === 'contabo'" style="display: none">
                <p class="text-[11px] leading-5 text-neutral-500 dark:text-fg-dim" x-show="selectedProvider === 'upcloud'">
                    {{ __('UpCloud uses the account username and its API password.') }}
                </p>
                <p class="text-[11px] leading-5 text-neutral-500 dark:text-fg-dim" x-show="selectedProvider === 'scaleway'">
                    {{ __('Scaleway uses a secret key and the project ID.') }}
                </p>
                <p class="text-[11px] leading-5 text-neutral-500 dark:text-fg-dim" x-show="selectedProvider === 'contabo'">
                    {{ __('Contabo uses the API client ID, client secret, username, and password.') }}
                </p>
                <p class="text-[11px] leading-5 text-neutral-500 dark:text-fg-dim" x-show="selectedProvider === 'exoscale'">
                    {{ __('Exoscale uses the API key and the API secret.') }}
                </p>
            </div>
            <div x-cloak x-show="needsAccount" style="display: none">
                <x-forms.input required id="account" label="{{ __('Username or API key') }}" />
            </div>
            <div x-cloak x-show="needsProject" style="display: none">
                <x-forms.input required id="project" label="{{ __('Project ID or client ID') }}" />
            </div>
            <div x-cloak x-show="selectedProvider === 'contabo'" style="display: none">
                <x-forms.input required type="password" id="secret" label="{{ __('Client secret') }}" />
            </div>
            <x-forms.input required type="password" id="token" label="{{ __('API token') }}"
                placeholder="{{ __('Paste the provider token') }}" />
            <div class="lg:col-span-2">
                <x-forms.textarea id="description" label="{{ __('Description') }}" rows="3"
                    placeholder="{{ __('Optional notes about where this token is used') }}" />
            </div>
        </div>

        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit"
                class="button-highlighted"
                wire:target="addToken">
                {{ __('Validate and add') }}
            </x-forms.button>
        </div>
    </form>
</div>
