@php
    $showServiceColumn = $showServiceColumn ?? false;
    $showHeader = $showHeader ?? true;
    $showDirection = $showDirection ?? true;
    $gridClass = 'service-domains-overview-grid';
@endphp

<div class="data-table w-full">
    @if ($showHeader)
        <div class="data-table-header {{ $gridClass }}">
            <span>{{ __('Domain') }}</span>
            @if ($showServiceColumn)
                <span>{{ __('Service') }}</span>
            @endif
            <span>{{ __('DNS Check') }}</span>
            <span class="whitespace-nowrap">{{ __('Search engine indexing') }}</span>
            <span>{{ __('Direction') }}</span>
            <span></span>
        </div>
    @endif
    @foreach ($rows as $row)
        @php
            $index = collect($domainRows)->search(
                fn ($item) => $item['url'] === $row['url']
                    && (int) $item['service_application_id'] === (int) $row['service_application_id']
                    && (bool) ($item['is_suggested'] ?? false) === (bool) ($row['is_suggested'] ?? false),
            );
            $isSuggested = (bool) ($row['is_suggested'] ?? false);
            $dnsType = match ($row['dns_status']) {
                'ok' => 'success',
                'failed' => 'error',
                'skipped' => 'warning',
                default => 'neutral',
            };
            $dnsLabel = match ($row['dns_status']) {
                'ok' => 'DNS matches',
                'failed' => 'DNS mismatch',
                'skipped' => 'DNS skipped',
                'checking' => 'Checking DNS...',
                'pending' => 'Not checked',
                default => 'DNS unknown',
            };
            $serviceLabel = filled($row['service_name'] ?? null)
                ? \Illuminate\Support\Str::headline($row['service_name'])
                : '-';
            $publicUrl = getFqdnWithoutPort($row['url']);
            $domainParts = $isSuggested ? null : parse_url($publicUrl);
            $isNoindexed = $service->applications->firstWhere('id', $row['service_application_id'])?->isDomainNoindexed($row['url']);
            $rowDirection = $serviceRedirects[$row['service_application_id']] ?? 'both';
            $directionLabel = match ($rowDirection) {
                'www' => 'Redirect to www',
                'non-www' => 'Redirect to non-www',
                default => 'Both www and non-www',
            };
            $faviconUrl = is_array($domainParts) && isset($domainParts['scheme'], $domainParts['host'])
                ? $domainParts['scheme'].'://'.$domainParts['host'].(isset($domainParts['port']) ? ':'.$domainParts['port'] : '').'/favicon.ico'
                : null;
            $domainKey = hash('sha256', $row['url'].'|'.($row['service_application_id'] ?? ''));
            $editingParts = \App\Support\DomainUrlParts::split($row['url']);
            if ($row['has_port_override'] ?? false) {
                $editingParts['port'] = (string) $row['internal_port'];
            }
        @endphp

        <div wire:key="svc-domain-{{ $row['service_application_id'] ?? 'x' }}-{{ md5(($isSuggested ? 's:' : '') . $row['url']) }}"
            class="env-table-item">
            <div @class([
                'data-table-row',
                $gridClass,
                'domains-row-suggested' => $isSuggested,
            ])>
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="flex min-w-0 items-center gap-2">
                        @if ($isSuggested)
                            <span
                                class="min-w-0 text-[13px] text-black sm:truncate dark:text-white"
                                title="{{ $row['url'] }} (not configured yet)">
                                {{ $row['url'] }}
                            </span>
                        @else
                            @if ($faviconUrl)
                                <span class="relative size-4 shrink-0" aria-hidden="true">
                                    <x-reicon name="globe"
                                        class="domain-favicon-fallback size-4 text-neutral-400 dark:text-fg-faint" />
                                    <img src="{{ $faviconUrl }}" alt="" loading="lazy" decoding="async"
                                        referrerpolicy="no-referrer"
                                        x-init="if ($el.complete && $el.naturalWidth > 0) { $el.previousElementSibling.classList.add('hidden'); $el.classList.remove('invisible') }"
                                        x-on:load="$el.previousElementSibling.classList.add('hidden'); $el.classList.remove('invisible')"
                                        x-on:error="$el.remove()"
                                        class="invisible absolute inset-0 size-4 rounded-sm" />
                                </span>
                            @endif
                            <a href="{{ data_get($app ?? null, 'name') === 'odoo' ? route('project.service.odoo.enter', ['project_uuid' => $service->environment->project->uuid, 'environment_uuid' => $service->environment->uuid, 'service_uuid' => $service->uuid]) : getFqdnWithoutPort($row['url']) }}" target="_blank"
                                class="min-w-0 flex-1 text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs sm:truncate dark:text-fg dark:decoration-white/20 dark:hover:decoration-warning"
                                title="{{ $row['url'] }}">
                                {{ $row['url'] }}
                            </a>
                        @endif
                        @if ($isSuggested && ! empty($row['suggestion_label']))
                            <span class="table-badge table-badge-warning shrink-0">{{ $row['suggestion_label'] }}</span>
                        @endif
                    </div>
                    @if ($isSuggested && filled($row['dns_message']))
                        <p class="text-[12px] leading-4 text-amber-700 sm:truncate dark:text-amber-400/90"
                            title="{{ $row['dns_message'] }}">
                            {{ $row['dns_message'] }}
                        </p>
                    @endif
                </div>

                @if ($showServiceColumn)
                    <div class="domains-service-desktop min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim"
                        title="{{ $serviceLabel }}">
                        {{ $serviceLabel }}
                    </div>
                @endif

                <div class="service-domain-detail" title="Protocol redirect">
                    <span class="service-domain-detail-label">Protocol redirect</span>
                    <span>{{ str_starts_with($row['url'], 'https://') && ($forceHttpsRedirects[$row['service_application_id']] ?? true) ? 'HTTP → HTTPS' : 'Disabled' }}</span>
                </div>
                <div class="service-domain-detail" title="{{ $directionLabel }}">
                    <span class="service-domain-detail-label">Domain redirect</span>
                    <span>{{ match ($rowDirection) { 'www' => 'non-www → www', 'non-www' => 'www → non-www', default => 'Disabled' } }}</span>
                </div>
                <div class="service-domain-detail"
                    title="{{ ($row['has_port_override'] ?? false) ? 'Custom internal port for this domain' : 'Inherited from the Coolify service port' }}">
                    <span class="service-domain-detail-label">Internal port</span>
                    <span @if (filled($row['internal_port'] ?? null)) aria-label="Internal port {{ $row['internal_port'] }}" @endif>{{ $row['internal_port'] ?? '—' }}</span>
                </div>
                <div class="service-domain-detail">
                    <span class="service-domain-detail-label">Search indexing</span>
                    <span role="img" aria-label="{{ $isNoindexed ? 'Search indexing blocked' : 'Search indexing allowed' }}"
                        title="{{ $isNoindexed ? 'Search indexing blocked' : 'Search indexing allowed' }}">
                        <x-reicon :name="$isNoindexed ? 'x' : 'check'" class="size-4" />
                    </span>
                </div>

                <div class="service-domain-mobile-summary" aria-label="Domain routing summary">
                    @if (str_starts_with($row['url'], 'https://') && ($forceHttpsRedirects[$row['service_application_id']] ?? true))
                        <span>HTTP → HTTPS</span>
                    @endif
                    @if (in_array($rowDirection, ['www', 'non-www'], true))
                        <span>{{ $rowDirection === 'www' ? 'non-www → www' : 'www → non-www' }}</span>
                    @elseif (! str_starts_with($row['url'], 'https://') || ! ($forceHttpsRedirects[$row['service_application_id']] ?? true))
                        <span>No redirects</span>
                    @endif
                    <span>Port {{ $row['internal_port'] ?? 'missing' }}</span>
                    <span>{{ $isNoindexed ? 'Noindex' : 'Indexable' }}</span>
                </div>

                <div class="service-domain-dns flex min-w-0 items-center">
                    @if ($row['dns_status'] === 'failed')
                        <x-status-badge as="button" @click="$dispatch('open-dns-records-modal')" :status="$dnsLabel" :type="$dnsType"
                            title="{{ __('View DNS records to fix') }}" class="cursor-pointer hover:bg-neutral-200 dark:hover:bg-white/[0.1]" />
                    @else
                        <x-status-badge :status="$dnsLabel" :type="$dnsType"
                            :title="$row['dns_status'] === 'ok' ? null : $row['dns_message']" />
                    @endif
                </div>

                <div class="min-w-0">
                    @unless ($isSuggested)
                        <span class="domains-mobile-label">{{ __('Search engine indexing') }}</span>
                    @endunless
                    @if ($isSuggested)
                        <span class="text-[13px] text-neutral-500 dark:text-fg-dim">-</span>
                    @elseif (auth()->user()?->can('update', $service))
                        <x-forms.listbox id="service-domain-indexing-{{ $row['service_application_id'] }}-{{ $index }}"
                            :wire="false"
                            preserveValue
                            :value="$service->applications->firstWhere('id', $row['service_application_id'])?->isDomainNoindexed($row['url']) ? 'noindex' : 'index'"
                            onChange="toggleNoindexDomain"
                            :onChangeArgs="[(int) $row['service_application_id'], $row['url']]" portal :options="[
                                ['value' => 'index', 'label' => __('Indexable')],
                                ['value' => 'noindex', 'label' => __('Noindex')],
                            ]" />
                    @else
                        <span class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ $service->applications->firstWhere('id', $row['service_application_id'])?->isDomainNoindexed($row['url']) ? __('Noindex') : __('Indexable') }}
                        </span>
                    @endif
                </div>

                <div class="min-w-0">
                    @php
                        $rowDirection = $serviceRedirects[$row['service_application_id']] ?? 'both';
                        $directionLabel = match ($rowDirection) {
                            'www' => 'Redirect to www',
                            'non-www' => 'Redirect to non-www',
                            default => 'Allow both',
                        };
                    @endphp
                    @if ($showDirection)
                        <span class="domains-mobile-label">{{ __('Direction') }}</span>
                    @endif
                    @if ($showDirection && auth()->user()?->can('update', $service))
                        <x-forms.listbox id="service-domain-direction-{{ $row['service_application_id'] }}-{{ $index }}"
                            :wire="false" :value="$rowDirection" preserveValue onChange="updateServiceRedirect"
                            :onChangeArgs="[(int) $row['service_application_id']]" portal :options="[
                                ['value' => 'both', 'label' => __('Allow www & non-www')],
                                ['value' => 'www', 'label' => __('Redirect to www')],
                                ['value' => 'non-www', 'label' => __('Redirect to non-www')],
                            ]" />
                    @elseif ($showDirection)
                        <span class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ $directionLabel }}</span>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-1">
                    @can('update', $service)
                        <button type="button" wire:click="checkDomainDns({{ $index }})"
                            wire:loading.attr="disabled"
                            wire:target="checkDomainDns({{ $index }}),checkAllDns"
                            class="icon-button shrink-0" title="{{ __('Check DNS') }}" aria-label="{{ __('Check DNS') }}">
                            <x-reicon name="refresh" class="size-3.5"
                                wire:loading.remove.delay
                                wire:target="checkDomainDns({{ $index }}),checkAllDns" />
                            <x-loading-on-button wire:loading.delay
                                wire:target="checkDomainDns({{ $index }}),checkAllDns" />
                        </button>
                        @if ($isSuggested)
                            @if ($row['needs_force_add'] ?? false)
                                <x-forms.button canGate="update" :canResource="$service"
                                    wire:click="addSuggestedDomain({{ $index }})" isError
                                    class="h-7! px-2! text-[12px]!">
                                    {{ __('Continue') }}
                                </x-forms.button>
                            @else
                                <x-forms.button canGate="update" :canResource="$service"
                                    wire:click="addSuggestedDomain({{ $index }})" isHighlighted
                                    class="h-7! shrink-0 px-2.5! text-[12px]!">
                                    {{ __('Add domain') }}
                                </x-forms.button>
                            @endif
                        @else
                            <button type="button" wire:click="startEdit({{ $index }})"
                                class="icon-button shrink-0" title="{{ __('Edit domain') }}" aria-label="{{ __('Edit domain') }}">
                                <x-reicon name="settings" class="size-3.5" />
                            </button>
                            <x-modal-confirmation class="!w-auto shrink-0" title="{{ __('Remove domain?') }}"
                                buttonTitle="Remove" isErrorButton
                                canGate="update" :canResource="$service"
                                submitAction="removeDomainByKey({{ $domainKey }})" :actions="[
                                    'This domain will be removed from the service application.',
                                    'Redeploy or restart may be required for proxy changes.',
                                ]" :checkboxes="[['id' => 'deleteManagedDns', 'label' => 'Also delete the DNS record created by Coolify, if present.']]"
                                :confirmWithPassword="false" :confirmWithText="false"
                                step2ButtonText="Remove domain">
                                <x-slot:trigger>
                                    <button type="button"
                                        class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                        title="{{ __('Remove domain') }}" aria-label="{{ __('Remove domain') }}">
                                        <x-reicon name="trash" class="size-3.5" />
                                    </button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    @endforeach
</div>
