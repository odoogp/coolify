@props([
    'title',
    'description' => null,
])

<section class="auth-shell application-settings-form">
    <div class="auth-shell-content">
        <div class="auth-card">
            <div class="auth-card-heading">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1>{{ $title }}</h1>
                        @if ($description)
                            <p>{{ $description }}</p>
                        @endif
                    </div>
                    <x-locale-switcher variant="compact" />
                </div>
            </div>

            <div class="auth-card-body">
                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="auth-card-footer">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</section>
