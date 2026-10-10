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
        dim: false,
        primed: false,
        init() {
            const video = this.$refs.video;
            if (! video) {
                return;
            }
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            const query = window.matchMedia('(max-width: 900px)');
            const pick = () => {
                const mobile = this.$el.dataset.mobileSrc;
                if (query.matches && mobile) {
                    return mobile;
                }
                return this.$el.dataset.src;
            };
            const boot = (url) => {
                video.muted = true;
                video.defaultMuted = true;
                video.playsInline = true;
                video.setAttribute('playsinline', '');
                video.setAttribute('webkit-playsinline', '');
                video.playbackRate = 0.75;
                if (video.dataset.loaded !== url) {
                    video.src = url;
                    video.dataset.loaded = url;
                    video.load();
                }
                const start = () => {
                    video.play().catch(() => {});
                };
                if (video.readyState >= 2) {
                    start();
                } else {
                    video.addEventListener('canplay', start, { once: true });
                }
            };
            const onChange = () => {
                this.dim = false;
                this.primed = false;
                boot(pick());
            };
            this.releaseViewport = () => query.removeEventListener('change', onChange);
            boot(pick());
            query.addEventListener('change', onChange);
        },
        tick() {
            const video = this.$refs.video;
            if (! video || ! Number.isFinite(video.duration) || video.duration < 1) {
                return;
            }
            const left = video.duration - video.currentTime;
            const lead = 2.8 * (video.playbackRate || 0.75);
            if (! this.dim && left < lead && left > 0.08) {
                this.dim = true;
                this.primed = true;
                return;
            }
            if (this.dim && this.primed && video.currentTime < 0.35 && left > lead) {
                this.dim = false;
            }
        },
        destroy() {
            this.releaseViewport?.();
            const video = this.$refs.video;
            if (! video) {
                return;
            }
            video.pause();
            video.removeAttribute('src');
            video.load();
        },
    }"
    aria-hidden="true">
    @if (filled($poster))
        <img src="{{ asset($poster) }}" alt="">
    @endif
    <div class="flow-tunnel-fade" :class="dim && 'is-dim'">
        <video x-ref="video" autoplay muted loop playsinline webkit-playsinline preload="auto"
            width="{{ $width }}" height="{{ $height }}"
            @if (filled($poster)) poster="{{ asset($poster) }}" @endif
            disablepictureinpicture
            @timeupdate="tick()">
        </video>
    </div>
    <script>
        (function () {
            var video = document.currentScript.previousElementSibling.querySelector('video');
            if (! video) {
                return;
            }
            var root = video.closest('.flow-tunnel');
            var mobile = root && root.dataset.mobileSrc;
            var narrow = window.matchMedia('(max-width: 900px)').matches;
            var src = narrow && mobile ? mobile : root.dataset.src;
            video.muted = true;
            video.defaultMuted = true;
            video.playsInline = true;
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            video.playbackRate = 0.75;
            if (src && video.getAttribute('src') !== src) {
                video.src = src;
                video.dataset.loaded = src;
            }
            var start = function () {
                video.play().catch(function () {});
            };
            if (video.readyState >= 2) {
                start();
            } else {
                video.addEventListener('canplay', start, { once: true });
            }
        })();
    </script>
</div>
