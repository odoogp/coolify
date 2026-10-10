<x-auth.shell wide scroll :title="__('Terms and conditions')"
    :description="__('Please read these terms carefully.')">
    <div class="auth-terms">
        @if ($hasTerms)
            <article class="auth-terms-body prose prose-sm max-w-none dark:prose-invert">
                {!! $html !!}
            </article>
        @else
            <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                {{ __('Terms and conditions are not published yet. Contact support if you need a copy.') }}
            </p>
        @endif
    </div>

    <x-slot:footer>
        <a href="javascript:history.back()" class="auth-text-link underline">{{ __('Go back') }}</a>
        @guest
            <span class="text-neutral-300 dark:text-white/20">·</span>
            <a href="{{ route('login') }}" class="auth-text-link underline">{{ __('auth.already_registered') }}</a>
        @endguest
    </x-slot:footer>
</x-auth.shell>
