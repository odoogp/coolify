<div class="flow-tunnel absolute inset-0 h-full w-full -z-10 pointer-events-none"
    x-data="{
        playing: false,
        init() {
            const video = this.$refs.video;
            if (! video) {
                return;
            }
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                video.removeAttribute('autoplay');
                return;
            }
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
    <img src="{{ asset('media/moon-walk.jpg') }}" alt="">
    <video x-ref="video" src="{{ asset('media/moon-walk.mp4') }}" poster="{{ asset('media/moon-walk.jpg') }}"
        muted loop playsinline autoplay preload="auto" @playing="playing = true"></video>
</div>
