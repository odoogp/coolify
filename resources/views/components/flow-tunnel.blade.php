@props([
    'poster' => 'media/moon-walk.jpg',
    'video' => 'media/moon-walk.mp4',
    'mobileVideo' => null,
    'width' => 2888,
    'height' => 2160,
])

<div class="flow-tunnel absolute inset-0 h-full w-full -z-10 pointer-events-none"
    data-src="{{ asset($video) }}"
    @if (filled($mobileVideo)) data-mobile-src="{{ asset($mobileVideo) }}" @endif
    x-data="{
        playing: false,
        front: 'a',
        incoming: null,
        busy: false,
        init() {
            const a = this.$refs.a;
            const b = this.$refs.b;
            if (! a || ! b) {
                return;
            }
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            const query = window.matchMedia('(max-width: 900px)');
            let generation = 0;
            const pick = () => {
                const mobile = this.$el.dataset.mobileSrc;
                if (query.matches && mobile) {
                    return mobile;
                }
                return this.$el.dataset.src;
            };
            const load = (url) => {
                const current = ++generation;
                this.front = 'a';
                this.busy = false;
                this.playing = false;
                for (const video of [a, b]) {
                    video.pause();
                    if (video.dataset.loaded !== url) {
                        video.src = url;
                        video.dataset.loaded = url;
                    }
                }
                const start = () => {
                    if (current !== generation) {
                        return;
                    }
                    a.play().catch(() => {});
                };
                if (a.readyState >= 3) {
                    start();
                } else {
                    a.addEventListener('canplay', start, { once: true });
                }
            };
            const onChange = () => load(pick());
            this.releaseViewport = () => query.removeEventListener('change', onChange);
            load(pick());
            query.addEventListener('change', onChange);
        },
        tick(which) {
            const from = this.$refs[which];
            const next = which === 'a' ? 'b' : 'a';
            const to = this.$refs[next];
            if (! from || ! to) {
                return;
            }
            if (this.front === which && this.busy && this.incoming === which && from.currentTime > 1) {
                this.busy = false;
                this.incoming = null;
                to.pause();
            }
            if (this.front !== which || this.busy || ! Number.isFinite(from.duration)) {
                return;
            }
            const left = from.duration - from.currentTime;
            if (left > 0.85 || left < 0) {
                return;
            }
            this.busy = true;
            this.incoming = next;
            const begin = () => {
                to.currentTime = 0;
                to.play().catch(() => {});
                this.front = next;
            };
            if (to.readyState >= 2) {
                begin();
            } else {
                to.addEventListener('canplay', begin, { once: true });
            }
        },
        ended(which) {
            if (this.front !== which) {
                return;
            }
            const video = this.$refs[which];
            if (! video) {
                return;
            }
            this.busy = false;
            video.currentTime = 0;
            video.play().catch(() => {});
        },
        destroy() {
            this.releaseViewport?.();
            for (const video of [this.$refs.a, this.$refs.b]) {
                if (! video) {
                    continue;
                }
                video.pause();
                video.removeAttribute('src');
                video.load();
            }
        },
    }"
    :class="playing && 'is-playing'"
    aria-hidden="true">
    @if (filled($poster))
        <img src="{{ asset($poster) }}" alt="">
    @endif
    <video x-ref="a" class="flow-tunnel-layer" :class="front === 'a' && 'is-front'"
        @if (filled($poster)) poster="{{ asset($poster) }}" @endif
        width="{{ $width }}" height="{{ $height }}" muted playsinline preload="auto"
        @playing="playing = true" @timeupdate="tick('a')" @ended="ended('a')"></video>
    <video x-ref="b" class="flow-tunnel-layer" :class="front === 'b' && 'is-front'"
        width="{{ $width }}" height="{{ $height }}" muted playsinline preload="auto"
        @playing="playing = true" @timeupdate="tick('b')" @ended="ended('b')"></video>
</div>
