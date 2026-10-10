@props([
    'poster' => 'media/moon-walk.jpg',
    'mobilePoster' => null,
    'video' => 'media/moon-walk.mp4',
    'mobileVideo' => null,
    'width' => 2888,
    'height' => 2160,
])

<div class="flow-tunnel absolute inset-0 h-full w-full -z-10 pointer-events-none"
    data-src="{{ asset($video) }}"
    @if (filled($mobileVideo)) data-mobile-src="{{ asset($mobileVideo) }}" @endif
    x-data="{
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
            const posterEl = this.$el.querySelector('.flow-tunnel-poster');
            const posterFor = (narrow) => {
                if (! posterEl) {
                    return '';
                }
                const mobilePoster = posterEl.dataset.mobileSrc;
                if (narrow && mobilePoster) {
                    return mobilePoster;
                }
                return posterEl.dataset.src || posterEl.getAttribute('src') || '';
            };
            const keepPlaying = () => {
                if (video.dataset.stop === '1') {
                    return;
                }
                if (Number.isFinite(video.duration) && video.duration > 0 && (video.ended || video.currentTime >= video.duration - 0.05)) {
                    try {
                        video.currentTime = 0;
                    } catch (e) {}
                }
                video.play().catch(() => {});
            };
            video.addEventListener('ended', keepPlaying);
            video.addEventListener('pause', keepPlaying);
            const boot = (url) => {
                const posterUrl = posterFor(query.matches);
                if (posterEl && posterUrl) {
                    posterEl.src = posterUrl;
                }
                if (posterUrl) {
                    video.poster = posterUrl;
                }
                video.loop = true;
                video.muted = true;
                video.defaultMuted = true;
                video.playsInline = true;
                video.setAttribute('playsinline', '');
                video.setAttribute('webkit-playsinline', '');
                video.playbackRate = 1;
                if (video.dataset.loaded !== url) {
                    video.src = url;
                    video.dataset.loaded = url;
                    video.load();
                }
                const start = () => {
                    keepPlaying();
                };
                if (video.readyState >= 2) {
                    start();
                } else {
                    video.addEventListener('canplay', start, { once: true });
                }
            };
            const onChange = () => {
                boot(pick());
            };
            this.releaseViewport = () => query.removeEventListener('change', onChange);
            boot(pick());
            query.addEventListener('change', onChange);
        },
        destroy() {
            this.releaseViewport?.();
            const video = this.$refs.video;
            if (! video) {
                return;
            }
            video.dataset.stop = '1';
            video.pause();
            video.removeAttribute('src');
            video.load();
        },
    }"
    aria-hidden="true">
    @if (filled($poster))
        <img class="flow-tunnel-poster" alt=""
            @if (filled($mobilePoster))
                data-src="{{ asset($poster) }}"
                data-mobile-src="{{ asset($mobilePoster) }}"
            @else
                src="{{ asset($poster) }}"
            @endif>
    @endif
    <div class="flow-tunnel-fade">
        <video x-ref="video" autoplay muted loop playsinline webkit-playsinline preload="auto"
            width="{{ $width }}" height="{{ $height }}"
            @if (filled($poster) && blank($mobilePoster)) poster="{{ asset($poster) }}" @endif
            disablepictureinpicture>
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
            var poster = root.querySelector('.flow-tunnel-poster');
            if (poster) {
                var mobilePoster = poster.dataset.mobileSrc;
                var posterSrc = narrow && mobilePoster ? mobilePoster : (poster.dataset.src || poster.getAttribute('src') || '');
                if (posterSrc) {
                    poster.src = posterSrc;
                    video.poster = posterSrc;
                }
            }
            var src = narrow && mobile ? mobile : root.dataset.src;
            video.loop = true;
            video.muted = true;
            video.defaultMuted = true;
            video.playsInline = true;
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            video.playbackRate = 1;
            var keepPlaying = function () {
                if (video.dataset.stop === '1') {
                    return;
                }
                if (Number.isFinite(video.duration) && video.duration > 0 && video.ended) {
                    try {
                        video.currentTime = 0;
                    } catch (e) {}
                }
                video.play().catch(function () {});
            };
            video.addEventListener('ended', keepPlaying);
            video.addEventListener('pause', keepPlaying);
            if (src && video.getAttribute('src') !== src) {
                video.src = src;
                video.dataset.loaded = src;
            }
            var start = function () {
                keepPlaying();
            };
            if (video.readyState >= 2) {
                start();
            } else {
                video.addEventListener('canplay', start, { once: true });
            }
        })();
    </script>
</div>
