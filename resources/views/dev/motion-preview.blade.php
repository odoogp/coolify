@extends('layouts.base')

@section('title', 'Motion preview')

@section('body')
    @parent
    @livewireScripts
    <main class="min-h-screen bg-[var(--coollabs-canvas)] px-4 py-10 text-neutral-900 dark:text-fg sm:px-8">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-10">
            <header class="flex flex-col gap-3 border-b border-neutral-200 pb-6 dark:border-white/[0.08]">
                <p class="text-[11px] font-medium uppercase tracking-[0.08em] text-neutral-500 dark:text-fg-faint">
                    Local / testing only
                </p>
                <h1 class="text-2xl font-semibold tracking-tight">Motion preview</h1>
                <p class="max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                    Interactive check of Apple-style shell motion before any upload. Press buttons, open overlays,
                    fire toasts, and feel tile press feedback. Global search stays unanimated on purpose.
                </p>
                <div class="flex flex-wrap gap-2 pt-1 text-[13px]">
                    <a href="{{ route('dashboard') }}" class="button">Dashboard</a>
                    <a href="{{ route('onboarding') }}" class="button">Onboarding</a>
                    <a href="{{ route('project.index') }}" class="button">Projects</a>
                    <a href="{{ route('server.index') }}" class="button">Servers</a>
                </div>
            </header>

            <section class="flex flex-col gap-3">
                <h2 class="text-[15px] font-semibold tracking-tight">1. Button press</h2>
                <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                    Pointer-down should scale to 0.97 in about 140ms with a strong ease-out.
                </p>
                <div class="flex flex-wrap gap-2">
                    <x-forms.button>Default</x-forms.button>
                    <x-forms.button isHighlighted>Highlighted</x-forms.button>
                    <x-forms.button isError>Destructive</x-forms.button>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-[15px] font-semibold tracking-tight">2. Modal</h2>
                <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                    Overlay fades; panel scales from 0.96. Leave uses ease-out, not ease-in.
                </p>
                <div class="flex flex-wrap gap-2">
                    <x-modal-input title="Sample modal" buttonTitle="Open modal-input" :notify-closed="false">
                        <div class="flex flex-col gap-3 p-1 text-[13px] text-neutral-600 dark:text-fg-dim">
                            <p>This is the shared modal-input shell with the updated motion tokens.</p>
                            <label class="flex flex-col gap-1.5">
                                <span class="text-[13px] font-medium text-neutral-800 dark:text-fg">Name</span>
                                <input type="text" class="input" placeholder="demo-app" />
                            </label>
                        </div>
                    </x-modal-input>

                    <div x-data="{ open: false }" class="relative">
                        <x-forms.button @click="open = true">Open confirmation-style modal</x-forms.button>
                        <template x-teleport="body">
                            <div x-show="open" class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4"
                                x-cloak>
                                <div x-show="open" x-transition:enter="transition ease-motion-out duration-[280ms]"
                                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition ease-motion-out duration-[220ms]"
                                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                    class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" @click="open = false">
                                </div>
                                <div x-show="open" x-trap.inert.noscroll="open"
                                    x-transition:enter="transition ease-motion-out duration-[280ms]"
                                    x-transition:enter-start="opacity-0 scale-[0.96]"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-motion-out duration-[220ms]"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-[0.96]"
                                    class="application-settings-form application-settings-section relative flex w-full max-w-lg flex-col"
                                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                                    <header class="flex-nowrap!">
                                        <h3 class="min-w-0 flex-1 truncate">Confirmation shell</h3>
                                        <button type="button" @click="open = false"
                                            class="flex size-7 shrink-0 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                            <x-reicon name="x" class="size-4" />
                                        </button>
                                    </header>
                                    <div class="application-settings-section-body text-[13px] text-neutral-600 dark:text-fg-dim">
                                        Same enter/leave recipe as modal-confirmation.
                                    </div>
                                    <footer class="flex justify-end gap-2">
                                        <x-forms.button @click="open = false">Close</x-forms.button>
                                    </footer>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-[15px] font-semibold tracking-tight">3. Toasts</h2>
                <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                    Enter and leave from the same top edge. No ease-in on exit.
                </p>
                <div class="flex flex-wrap gap-2">
                    <x-forms.button
                        @click="window.toast('Saved', { type: 'success', description: 'Settings updated.' })">
                        Success
                    </x-forms.button>
                    <x-forms.button
                        @click="window.toast('Heads up', { type: 'info', description: 'Deploy queued.' })">
                        Info
                    </x-forms.button>
                    <x-forms.button
                        @click="window.toast('Check this', { type: 'warning', description: 'Disk is almost full.' })">
                        Warning
                    </x-forms.button>
                    <x-forms.button
                        @click="window.toast('Failed', { type: 'danger', description: 'Could not reach the server.' })">
                        Danger
                    </x-forms.button>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-[15px] font-semibold tracking-tight">4. Listbox and dropdown</h2>
                <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                    Panels open with scale 0.98 and strong ease-out (~180ms).
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="max-w-sm">
                        <x-forms.listbox id="motion-preview-listbox" label="Region" :wire="false" value="ams"
                            :options="[
                                ['value' => 'ams', 'label' => 'Amsterdam'],
                                ['value' => 'fra', 'label' => 'Frankfurt'],
                                ['value' => 'nyc', 'label' => 'New York'],
                            ]" />
                    </div>
                    <div class="max-w-sm">
                        <x-dropdown>
                            <x-slot:title>
                                <span class="text-[13px]">Actions</span>
                            </x-slot:title>
                            <button type="button" class="dropdown-item">Restart</button>
                            <button type="button" class="dropdown-item">Redeploy</button>
                            <button type="button" class="dropdown-item">View logs</button>
                        </x-dropdown>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-[13px] text-neutral-600 dark:text-fg-dim">
                    Helper tip:
                    <x-helper helper="Helpers also use ease-motion-out for enter and leave." />
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-[15px] font-semibold tracking-tight">5. Feature tiles</h2>
                <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                    Hover changes border/shadow only. Press uses the shared is-pressed scale from app.js.
                </p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (['Projects', 'Servers', 'Deployments'] as $tile)
                        <a href="#"
                            class="feature-tile group relative flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-4 shadow-sm motion-tile hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            onclick="event.preventDefault()">
                            <h3 class="feature-tile-title">{{ $tile }}</h3>
                            <p class="feature-tile-copy mt-1 text-[13px] text-neutral-600 dark:text-fg-dim">
                                Press and hold to feel the Apple-style press feedback.
                            </p>
                        </a>
                    @endforeach
                </div>
            </section>

            <section
                class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-white/[0.08] dark:bg-white/[0.025]">
                <h2 class="text-[15px] font-semibold tracking-tight">Checklist</h2>
                <ul class="mt-3 list-disc space-y-1.5 pl-5 text-[13px] text-neutral-600 dark:text-fg-dim">
                    <li>Buttons respond on press, not only on hover.</li>
                    <li>Modals never use ease-in on close.</li>
                    <li>Toasts enter and leave through the same edge.</li>
                    <li>Listbox/dropdown feel snappy (~180ms), not sluggish.</li>
                    <li>Tiles do not use transition-all or decorative hover lift.</li>
                    <li>Then spot-check Dashboard, Onboarding, Projects, and Servers with the links above.</li>
                </ul>
            </section>
        </div>
    </main>
@endsection
