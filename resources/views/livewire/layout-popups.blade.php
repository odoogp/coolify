<div x-data="{
    popups: {
        notification: true,
        realtime: false,
    },
    reminders: {
        notification: { compact: false },
    },
    reminderCollapseAfter: 10000,
    isDevelopment: {{ isDev() ? 'true' : 'false' }},
    init() {
        this.popups.notification = this.shouldShowMonthlyPopup('popupNotification');
        this.popups.realtime = localStorage.getItem('popupRealtime');

        if (this.popups.notification) {
            this.scheduleReminderCollapse('notification');
        }

        let checkNumber = 1;
        let checkPusherInterval = null;
        let checkReconnectInterval = null;

        if (!this.popups.realtime) {
            checkPusherInterval = setInterval(() => {
                if (window.Echo) {
                    if (typeof window.Echo?.private === 'function' && window.Echo.connector?.pusher?.connection?.state === 'connected') {
                        this.popups.realtime = false;
                    } else {
                        checkNumber++;
                        if (checkNumber > 5) {
                            this.popups.realtime = true;
                            console.error(
                                'Coolify could not connect to its real-time service. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/knowledge-base/cloudflare/tunnels/overview) or get help on Discord (https://coollabs.io/discord).)'
                            );
                        }

                    }
                }
            }, 2000);
        }
    },
    scheduleReminderCollapse(reminder) {
        setTimeout(() => {
            if (reminder === 'notification') {
                this.reminders.notification.compact = true;
            }
        }, this.reminderCollapseAfter);
    },
    shouldShowMonthlyPopup(storageKey) {
        const disabledTimestamp = localStorage.getItem(storageKey);

        // If never disabled, show the popup
        if (!disabledTimestamp || disabledTimestamp === 'false') {
            return true;
        }

        // If disabled timestamp is not a valid number, show the popup
        const disabledTime = parseInt(disabledTimestamp);
        if (isNaN(disabledTime)) {
            return true;
        }

        const now = new Date();
        const disabledDate = new Date(disabledTime);

        {{-- if (this.isDevelopment) {
            // In development: check if 10 seconds have passed
            const timeDifference = now.getTime() - disabledDate.getTime();
            const tenSecondsInMs = 10 * 1000;
            return timeDifference >= tenSecondsInMs;
        } else { --}}
        // In production: check if we're in a different month or year
        const isDifferentMonth = now.getMonth() !== disabledDate.getMonth() ||
            now.getFullYear() !== disabledDate.getFullYear();
        return isDifferentMonth;
        {{-- } --}}
    }
}">
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-[999] flex flex-col items-center gap-3 px-3 pb-3 sm:px-5 sm:pb-5">
    @auth
        <span x-show="popups.realtime === true" class="pointer-events-none w-full max-w-2xl">
            @if (!isCloud())
                <x-popup stacked>
                    <x-slot:customActions>
                        <div
                            class="relative mx-auto flex w-full max-w-2xl flex-col gap-5 overflow-hidden rounded-2xl border border-red-200 bg-white p-5 shadow-modal sm:p-6 dark:border-red-500/20 dark:bg-surface">
                            <button type="button" aria-label="{{ __('Dismiss real-time connection warning') }}"
                                class="absolute top-3 right-3 flex size-7 items-center justify-center rounded-full text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                                @click="bannerVisible=false;disableRealtime()">
                                <x-reicon name="x" class="size-3.5" />
                            </button>

                            <div class="flex items-start gap-4 pr-8">
                                <div
                                    class="hidden size-12 shrink-0 items-center justify-center rounded-xl border border-red-200 bg-red-50 text-red-600 sm:flex dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400">
                                    <x-reicon name="alert-triangle" class="size-6" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-[15px]! leading-5! font-semibold! text-black dark:text-fg">
                                        {{ __('Cannot connect to real-time service') }}
                                    </h2>
                                    <p class="mt-1 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                                        {{ __('This will cause unusual problems on the UI. Open the') }}
                                        <a class="font-medium text-coollabs underline decoration-coollabs/30 underline-offset-2 transition-colors hover:text-coollabs-100 dark:text-warning dark:decoration-warning/30 dark:hover:text-warning/90"
                                            href="https://coolify.io/docs/knowledge-base/server/firewall"
                                            target="_blank" rel="noopener noreferrer">required ports</a>
                                        or get help on
                                        <a class="font-medium text-coollabs underline decoration-coollabs/30 underline-offset-2 transition-colors hover:text-coollabs-100 dark:text-warning dark:decoration-warning/30 dark:hover:text-warning/90"
                                            href="https://coollabs.io/discord" target="_blank"
                                            rel="noopener noreferrer">{{ __('Discord') }}</a>.
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                                <a target="_blank" rel="noopener noreferrer"
                                    href="https://coolify.io/docs/knowledge-base/server/firewall"
                                    class="button h-9 justify-center sm:min-w-28">
                                    {{ __('View docs') }}
                                </a>
                                <button type="button"
                                    class="button h-9 justify-center bg-red-600! text-white! ring-1 ring-red-600/25 hover:bg-red-700! sm:min-w-40 dark:bg-red-500! dark:ring-red-500/30 dark:hover:bg-red-400!"
                                    @click="bannerVisible=false;disableRealtime()">
                                    Acknowledge &amp; disable
                                </button>
                            </div>
                        </div>
                    </x-slot:customActions>
                </x-popup>
            @endif
        </span>
    @endauth
    @if (request()->query->get('cancelled'))
        <x-banner>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-red-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                        clip-rule="evenodd" />
                </svg>
                <span><span class="font-bold text-red-500">{{ __('Subscription Error.') }}</span> Something went wrong. Please try
                    again or <a class="underline dark:text-white"
                        href="{{ config('constants.urls.contact') }}" target="_blank">contact support</a>.</span>
            </div>
        </x-banner>
    @endif
    @if (request()->query->get('success'))
        <x-banner>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-green-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd" />
                </svg>
                <span><span class="font-bold text-green-500">{{ __('Welcome onboard!') }}</span> Your subscription has been
                    activated. It could take a few seconds before it's fully active.</span>
            </div>
        </x-banner>
    @endif
    @if (currentTeam()->subscriptionPastOverDue())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">{{ __('WARNING:') }}</span> Your subscription is in over-due. If your
                latest
                payment is not paid within a week, all automations <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}" {{ wireNavigate() }}
                    class="underline dark:text-white">/subscription</a> to check your subscription status or pay
                your
                invoice (or check your email for the invoice).
            </div>
        </x-banner>
    @endif
    @if (currentTeam()->serverOverflow())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">{{ __('WARNING:') }}</span> The number of active servers exceeds the limit
                covered by your payment. If not resolved, some of your servers <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}" {{ wireNavigate() }}
                    class="underline dark:text-white">/subscription</a> to update your subscription or remove some
                servers.
            </div>
        </x-banner>
    @endif
    @if (!currentTeam()->isAnyNotificationEnabled())
        <span x-show="popups.notification" class="pointer-events-none w-full">
            <x-popup stacked>
                <x-slot:customActions>
                    <div class="relative mx-auto flex w-full flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-modal transition-all duration-300 dark:border-white/[0.1] dark:bg-surface"
                        :class="reminders.notification.compact ? 'max-w-sm gap-3 p-4' : 'max-w-2xl gap-5 p-5 sm:p-6'">
                        <button type="button" aria-label="{{ __('Dismiss notifications reminder') }}"
                            class="absolute top-3 right-3 flex size-7 items-center justify-center rounded-full text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                            @click="bannerVisible=false;disableNotification()">
                            <x-reicon name="x" class="size-3.5" />
                        </button>

                        <div class="flex items-start gap-4 pr-8">
                            <div x-show="!reminders.notification.compact" x-transition.opacity
                                class="hidden size-12 shrink-0 items-center justify-center rounded-xl border border-amber-200 bg-amber-50 text-amber-700 sm:flex dark:border-warning/20 dark:bg-warning/10 dark:text-warning">
                                <x-reicon name="alert-triangle" class="size-6" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-[15px]! leading-5! font-semibold! text-black dark:text-fg">
                                    {{ __('No notifications enabled') }}
                                </h2>
                                <p x-show="!reminders.notification.compact" x-transition.opacity
                                    class="mt-1 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                                    {{ __('Enable at least one notification channel so you receive important alerts.') }}
                                    {{ __('Visit') }}
                                    <a href="{{ route('notifications.email') }}" {{ wireNavigate() }}
                                        class="font-medium text-coollabs underline decoration-coollabs/30 underline-offset-2 transition-colors hover:text-coollabs-100 dark:text-warning dark:decoration-warning/30 dark:hover:text-warning/90">notifications</a>
                                    to get started.
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                            <a href="{{ route('notifications.email') }}" {{ wireNavigate() }}
                                class="button h-9 justify-center sm:min-w-28">
                                {{ __('Open notifications') }}
                            </a>
                            <button x-show="!reminders.notification.compact" x-transition.opacity type="button"
                                class="button h-9 justify-center sm:min-w-32"
                                @click="bannerVisible=false;disableNotification()">
                                {{ __('Accept and close') }}
                            </button>
                        </div>
                    </div>
                </x-slot:customActions>
            </x-popup>
        </span>
    @endif
    </div>
    <script>
        function disableNotification() {
            // Store current timestamp instead of just 'false'
            localStorage.setItem('popupNotification', Date.now().toString());
        }

        function disableRealtime() {
            localStorage.setItem('popupRealtime', 'disabled');
        }
    </script>
</div>
