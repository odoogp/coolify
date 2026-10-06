@props([
    'title',
    'description' => null,
    'wide' => false,
    'scroll' => false,
])

<section @class(['auth-shell application-settings-form', 'auth-shell-wide' => $wide]) @if ($scroll) style="overflow: auto" @endif>
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
            <span class="auth-stage-flare"></span>
            <svg class="auth-stage-mark" viewBox="0 0 200 200">
                <defs>
                    <linearGradient id="auth-glass" x1="28" y1="8" x2="172" y2="188" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#ffffff" stop-opacity="0.94"/>
                        <stop offset="0.32" stop-color="#efeaff" stop-opacity="0.5"/>
                        <stop offset="0.68" stop-color="#8ea0ff" stop-opacity="0.26"/>
                        <stop offset="1" stop-color="#241a4a" stop-opacity="0.18"/>
                    </linearGradient>
                    <linearGradient id="auth-rim" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#ffffff" stop-opacity="0.95"/>
                        <stop offset="0.5" stop-color="#c9bdff" stop-opacity="0.4"/>
                        <stop offset="1" stop-color="#ffffff" stop-opacity="0.12"/>
                    </linearGradient>
                    <filter id="auth-crystal-blur" x="-40%" y="-40%" width="180%" height="180%">
                        <feGaussianBlur stdDeviation="8"/>
                    </filter>
                </defs>
                <polygon points="100,18 166,56 166,130 100,168 34,130 34,56" fill="#b7a6ff" opacity="0.45" filter="url(#auth-crystal-blur)"/>
                <polygon points="100,16 168,54 168,132 100,170 32,132 32,54" fill="url(#auth-glass)" stroke="url(#auth-rim)" stroke-width="2.4"/>
                <polygon points="100,16 168,54 100,92 32,54" fill="rgb(255 255 255 / 0.28)"/>
                <polygon points="100,48 134,67 134,110 100,130 66,110 66,67" fill="rgb(255 255 255 / 0.08)" stroke="rgb(255 255 255 / 0.62)" stroke-width="1.5"/>
                <path d="M78 112 L100 70 L122 112 L109 112 L100 94 L91 112 Z" fill="#ffffff"/>
            </svg>
        </div>
        <span class="auth-stage-ridge auth-stage-ridge-a"></span>
        <span class="auth-stage-ridge auth-stage-ridge-b"></span>
    </div>
    <div class="auth-shell-content">
        <div @class(['auth-card', 'auth-card-wide' => $wide])>
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
