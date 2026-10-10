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
    <img src="{{ asset('media/moon-walk.jpg') }}" alt="">
    <video x-ref="video" data-src="{{ asset('media/moon-walk.mp4') }}" poster="{{ asset('media/moon-walk.jpg') }}"
        width="2888" height="2160" muted loop playsinline preload="auto" @playing="playing = true"></video>
</div>
