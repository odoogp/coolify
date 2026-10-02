@props([
    'title',
    'description' => null,
])

<section class="auth-shell application-settings-form">
    <div class="auth-stage" aria-hidden="true">
        <span class="auth-stage-glow"></span>
        <span class="auth-stage-glow auth-stage-glow-green"></span>
        <span class="auth-stage-orbit"></span>
        <span class="auth-stage-orbit auth-stage-orbit-b"></span>
        <span class="auth-stage-shard auth-stage-shard-a"></span>
        <span class="auth-stage-shard auth-stage-shard-b"></span>
        <span class="auth-stage-shard auth-stage-shard-c"></span>
        <span class="auth-stage-shard auth-stage-shard-d"></span>
        <div class="auth-stage-mark-slot">
        <svg class="auth-stage-mark" viewBox="0 0 200 200">
            <defs>
                <linearGradient id="auth-crystal" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#ffffff" stop-opacity="0.92"/>
                    <stop offset="0.45" stop-color="#c9bdff" stop-opacity="0.55"/>
                    <stop offset="1" stop-color="#6d7dff" stop-opacity="0.28"/>
                </linearGradient>
            </defs>
            <polygon points="100,16 168,54 168,132 100,170 32,132 32,54" fill="url(#auth-crystal)" stroke="rgba(255,255,255,0.72)" stroke-width="2"/>
            <polygon points="100,46 136,66 136,112 100,132 64,112 64,66" fill="rgba(255,255,255,0.16)" stroke="rgba(255,255,255,0.4)"/>
            <path d="M78 108 L100 68 L122 108 L110 108 L100 90 L90 108 Z" fill="rgba(255,255,255,0.92)"/>
        </svg>
        </div>
        <span class="auth-stage-ridge auth-stage-ridge-a"></span>
        <span class="auth-stage-ridge auth-stage-ridge-b"></span>
    </div>
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
