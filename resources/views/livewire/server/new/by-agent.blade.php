<div class="w-full" @if (in_array($status['state'], ['waiting', 'preparing'], true)) wire:poll.3s @endif>
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif (! $available)
        <x-application.settings-section title="{{ __('Connect your server with a command') }}"
            description="{{ __('This option is not available yet. Use IP address or domain, or ask your advisor.') }}" />
    @elseif ($command === '')
        <form wire:submit="start">
            <x-application.settings-section title="{{ __('Connect your server with a command') }}"
                description="{{ __('Name your server and copy one command. Run it on your server and it connects by itself, without opening the SSH port.') }}">
                <x-slot:actions>
                    <button type="submit" class="button button-highlighted" wire:loading.attr="disabled" wire:target="start">
                        {{ __('Get the command') }}
                        <x-reicon name="arrow-right" class="size-3.5" />
                    </button>
                </x-slot:actions>

                <div class="mb-5">
                    <x-forms.input id="name" label="{{ __('Server name') }}" required
                        helper="{{ __('Only you see it. For example Office server.') }}" />
                </div>
                <ul class="flex flex-col gap-1.5 text-[13px] text-neutral-600 dark:text-fg-dim">
                    <li>{{ __('Works on Ubuntu, Debian and most Linux servers with root access.') }}</li>
                    <li>{{ __('Your apps receive visits on ports 80 and 443 of your server.') }}</li>
                    <li>{{ __('Your data stays on your server.') }}</li>
                </ul>
            </x-application.settings-section>
        </form>
    @else
        <x-application.settings-section title="{{ __('Run this command on your server') }}"
            description="{{ __('Open a terminal on your server, paste it and press Enter. The command is only for this server and expires in 24 hours.') }}">
            <div class="flex items-start gap-2 rounded-lg bg-neutral-100 p-3 dark:bg-white/[0.04]" data-agent-command>
                <code class="min-w-0 flex-1 break-all font-mono text-[12px] leading-5 text-black dark:text-fg">{{ $command }}</code>
                <x-copy-button :value="$command" label="{{ __('Copy command') }}" />
            </div>

            <div class="mt-5 flex items-start gap-3" aria-live="polite">
                @if ($status['state'] === 'ready')
                    <span class="mt-1 size-2.5 shrink-0 rounded-full bg-success"></span>
                @elseif ($status['state'] === 'failed')
                    <span class="mt-1 size-2.5 shrink-0 rounded-full bg-error"></span>
                @else
                    <span class="mt-1 size-2.5 shrink-0 animate-pulse rounded-full bg-coollabs dark:bg-warning"></span>
                @endif
                <div class="min-w-0">
                    <p class="text-[13px] font-semibold text-black dark:text-fg">{{ $status['title'] }}</p>
                    <p class="mt-0.5 break-words text-[12px] text-neutral-500 dark:text-fg-dim">{{ $status['detail'] }}</p>
                    @if ($status['state'] === 'ready' && $status['server'])
                        <a href="{{ route('server.show', ['server_uuid' => $status['server']->uuid]) }}" {{ wireNavigate() }}
                            class="button button-highlighted mt-3">
                            {{ __('Open server') }}
                        </a>
                    @elseif ($status['state'] === 'failed')
                        <button type="button" class="button mt-3" wire:click="startOver">
                            {{ __('Start again') }}
                        </button>
                    @endif
                </div>
            </div>
        </x-application.settings-section>
    @endif
</div>
