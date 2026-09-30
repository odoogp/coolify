<x-layout-simple>
    <x-auth.shell title="{{ product_name() }}" description="{{ __('Verify your email address to activate your account.') }}">
        <div class="flex flex-col gap-4">
            <div class="auth-guidance">
                <x-reicon name="mail" class="mt-0.5 size-4 shrink-0" />
                <p>{{ __('We sent a verification link to your email address. Open it to continue to Coolify.') }}</p>
            </div>

            <livewire:verify-email />
        </div>

        <x-slot:footer>
            <span class="text-center">
                <span class="block sm:inline">{{ __('Didn’t receive the email?') }}</span>
                <span class="block sm:ml-1 sm:inline">{{ __('Check your spam folder or resend it.') }}</span>
            </span>
        </x-slot:footer>
    </x-auth.shell>
</x-layout-simple>
