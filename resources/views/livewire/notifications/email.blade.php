<div>
    <x-slot:title>
        {{ __('Notifications | Coolify') }}
    </x-slot>

    <x-notification.settings-layout>
    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="{{ __('Email delivery') }}">
                <x-slot:actions>
                    @if (auth()->user()->isAdminFromSession())
                        @can('sendTest', $settings)
                            @if ($team->isNotificationEnabled('email'))
                                <x-modal-input title="{{ __('Send Test Email') }}">
                                    <x-slot:content>
                                        <button type="button" class="button">
                                            <x-reicon name="notifications" class="size-3.5" />
                                            {{ __('Send test') }}
                                        </button>
                                    </x-slot:content>
                                    <form wire:submit.prevent="sendTestEmail" class="flex w-full flex-col gap-4">
                                        <x-forms.input wire:model="testEmailAddress" placeholder="{{ __('test@example.com') }}"
                                            id="testEmailAddress" label="{{ __('Recipient') }}" required />
                                        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                            <button type="submit" @click="modalOpen=false"
                                                class="button button-highlighted">
                                                {{ __('Send email') }}
                                            </button>
                                        </div>
                                    </form>
                                </x-modal-input>
                            @else
                                <button type="button" class="button" disabled>{{ __('Send test') }}</button>
                            @endif
                        @endcan
                    @endif
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @if (isCloud())
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="useInstanceEmailSettings" label="{{ __('Email service') }}"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => __('Use hosted email service')],
                                        ['value' => false, 'label' => __('Use team email settings')],
                                    ]" />
                            </div>
                        @else
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="useInstanceEmailSettings" label="{{ __('Email service') }}"
                                    onChange="instantSave"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => __('Use system-wide settings')],
                                        ['value' => false, 'label' => __('Use team email settings')],
                                    ]" />
                            </div>
                        @endif
                    </div>

                    @if (!$useInstanceEmailSettings)
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromName"
                            helper="{{ __('Name used in emails.') }}" label="{{ __('From name') }}" />
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromAddress"
                            helper="{{ __('Email address used in emails.') }}" label="{{ __('From address') }}" />

                        @if (isInstanceAdmin())
                            <div class="lg:col-span-2">
                                <x-forms.button type="button" wire:click="copyFromInstanceSettings">
                                    {{ __('Copy from instance settings') }}
                                </x-forms.button>
                            </div>
                        @endif
                    @endif
                </div>
            </x-application.settings-section>
        </form>

        @if (!$useInstanceEmailSettings)
            <div class="application-settings-form">
                <x-application.settings-section title="{{ __('SMTP server') }}"
                    description="{{ __('Deliver messages through your own SMTP server.') }}">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-3">
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$settings" id="smtpEnabled" label="{{ __('SMTP delivery') }}"
                                    onChange="submitSmtp"
                                    :disabled="!auth()->user()->can('update', $settings)" :options="[
                                        ['value' => true, 'label' => __('Enabled')],
                                        ['value' => false, 'label' => __('Disabled')],
                                    ]" />
                            </div>
                        </div>
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpHost"
                            placeholder="{{ __('smtp.mailgun.org') }}" label="{{ __('Host') }}" />
                        <x-forms.input canGate="update" :canResource="$settings" required id="smtpPort"
                            type="number" placeholder="587" label="{{ __('Port') }}" />
                        <x-forms.listbox canGate="update" :canResource="$settings" id="smtpEncryption" label="{{ __('Encryption') }}" required
                            :disabled="!auth()->user()->can('update', $settings)" :options="[
                            ['value' => 'starttls', 'label' => __('StartTLS')],
                            ['value' => 'tls', 'label' => __('TLS / SSL')],
                            ['value' => 'none', 'label' => __('None')],
                        ]" />
                        <x-forms.input canGate="update" :canResource="$settings" id="smtpUsername"
                            label="{{ __('SMTP username') }}" />
                        @can('update', $settings)
                            <x-forms.input canGate="update" :canResource="$settings" id="smtpPassword" type="password"
                                label="{{ __('SMTP password') }}" />
                        @else
                            <x-forms.input disabled label="{{ __('SMTP password') }}" value="Hidden (only admins can view)" />
                        @endcan
                        <x-forms.input canGate="update" :canResource="$settings" id="smtpTimeout" type="number"
                            helper="{{ __('Timeout value for sending emails.') }}" label="{{ __('Timeout') }}" />
                        <x-forms.input canGate="update" :canResource="$settings" id="smtpEhloDomain"
                            placeholder="{{ __('coolify.example.com') }}"
                            helper="{{ __('Fully qualified domain sent in the SMTP EHLO command. Uses the system default when empty.') }}"
                            label="{{ __('EHLO domain') }}" />
                    </div>
                </x-application.settings-section>
            </div>

            <div class="application-settings-form">
                <x-application.settings-section title="{{ __('Resend') }}">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox canGate="update" :canResource="$settings" id="resendEnabled" label="{{ __('Resend delivery') }}"
                            onChange="submitResend"
                            :disabled="!auth()->user()->can('update', $settings)" :options="[
                                ['value' => true, 'label' => __('Enabled')],
                                ['value' => false, 'label' => __('Disabled')],
                            ]" />
                        @can('update', $settings)
                            <x-forms.input canGate="update" :canResource="$settings" :required="$resendEnabled"
                                type="password" id="resendApiKey" placeholder="{{ __('API key') }}" label="{{ __('API key') }}"
                                autocomplete="new-password" />
                        @else
                            <x-forms.input disabled label="{{ __('API key') }}" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </x-application.settings-section>
            </div>
        @endif

        <div class="application-settings-form">
            <x-application.settings-section title="{{ __('Notification events') }}">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-notification.event-multiselect :settings="$settings" id="deployment-email-events" label="{{ __('Deployments') }}"
                        :events="[
                            ['property' => 'deploymentSuccessEmailNotifications', 'label' => __('Deployment success'), 'enabled' => $deploymentSuccessEmailNotifications],
                            ['property' => 'deploymentFailureEmailNotifications', 'label' => __('Deployment failure'), 'enabled' => $deploymentFailureEmailNotifications],
                            ['property' => 'statusChangeEmailNotifications', 'label' => __('Container status changes'), 'enabled' => $statusChangeEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="backup-email-events" label="{{ __('Backups') }}"
                        :events="[
                            ['property' => 'backupSuccessEmailNotifications', 'label' => __('Backup success'), 'enabled' => $backupSuccessEmailNotifications],
                            ['property' => 'backupFailureEmailNotifications', 'label' => __('Backup failure'), 'enabled' => $backupFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="scheduled-task-email-events"
                        label="{{ __('Scheduled tasks') }}" :events="[
                            ['property' => 'scheduledTaskSuccessEmailNotifications', 'label' => __('Scheduled task success'), 'enabled' => $scheduledTaskSuccessEmailNotifications],
                            ['property' => 'scheduledTaskFailureEmailNotifications', 'label' => __('Scheduled task failure'), 'enabled' => $scheduledTaskFailureEmailNotifications],
                        ]" />
                    <x-notification.event-multiselect :settings="$settings" id="server-email-events" label="{{ __('Server') }}"
                        :events="[
                            ['property' => 'dockerCleanupSuccessEmailNotifications', 'label' => __('Docker cleanup success'), 'enabled' => $dockerCleanupSuccessEmailNotifications],
                            ['property' => 'dockerCleanupFailureEmailNotifications', 'label' => __('Docker cleanup failure'), 'enabled' => $dockerCleanupFailureEmailNotifications],
                            ['property' => 'serverDiskUsageEmailNotifications', 'label' => __('Server disk usage'), 'enabled' => $serverDiskUsageEmailNotifications],
                            ['property' => 'serverReachableEmailNotifications', 'label' => __('Server reachable'), 'enabled' => $serverReachableEmailNotifications],
                            ['property' => 'serverUnreachableEmailNotifications', 'label' => __('Server unreachable'), 'enabled' => $serverUnreachableEmailNotifications],
                            ['property' => 'serverPatchEmailNotifications', 'label' => __('Server patching'), 'enabled' => $serverPatchEmailNotifications],
                            ['property' => 'traefikOutdatedEmailNotifications', 'label' => __('Traefik proxy outdated'), 'enabled' => $traefikOutdatedEmailNotifications],
                        ]" />
                </div>
            </x-application.settings-section>
        </div>
    </div>
    </x-notification.settings-layout>
</div>
