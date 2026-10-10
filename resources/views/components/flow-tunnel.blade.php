@props([
    'poster' => 'media/moon-walk.jpg',
    'video' => 'media/moon-walk.mp4',
    'width' => 2888,
    'height' => 2160,
])

<div class="flow-tunnel absolute inset-0 h-full w-full -z-10 pointer-events-none"
    x-data="{
        playing: false,
        init() {
            const video = this.$refs.video;
            if (! video) {
                return;
            }
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            video.src = video.dataset.src;
            const start = () => {
                video.play().catch(() => {});
            };
            if (video.readyState >= 3) {
                start();
            } else {
                video.addEventListener('canplay', start, { once: true });
            }
        },
        destroy() {
            const video = this.$refs.video;
            if (! video) {
                return;
            }
            video.pause();
            video.removeAttribute('src');
            video.load();
        },
    }"
    :class="playing && 'is-playing'"
    aria-hidden="true">
    @if (filled($poster))
        <img src="{{ asset($poster) }}" alt="">
    @endif
    <video x-ref="video" data-src="{{ asset($video) }}" @if (filled($poster)) poster="{{ asset($poster) }}" @endif
        width="{{ $width }}" height="{{ $height }}" muted loop playsinline preload="auto"
        @playing="playing = true"></video>
</div>
