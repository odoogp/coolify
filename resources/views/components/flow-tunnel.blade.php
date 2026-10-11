@props([
    'poster' => 'media/moon-walk.jpg',
    'mobilePoster' => null,
    'video' => 'media/moon-walk.mp4',
    'mobileVideo' => null,
    'width' => 2888,
    'height' => 2160,
    'rate' => 1,
])

<div class="flow-tunnel absolute inset-0 h-full w-full -z-10 pointer-events-none"
    data-src="{{ asset($video) }}"
    data-rate="{{ $rate }}"
    @if (filled($mobileVideo)) data-mobile-src="{{ asset($mobileVideo) }}" @endif
    x-data="{
        destroy() {
            this.$el.dataset.stop = '1';
            if (this.$el._flowObjectUrl) {
                URL.revokeObjectURL(this.$el._flowObjectUrl);
                this.$el._flowObjectUrl = '';
            }
            this.$el.querySelectorAll('video').forEach((video) => {
                video.pause();
                video.removeAttribute('src');
                video.load();
            });
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
        <video class="flow-tunnel-layer" autoplay muted playsinline webkit-playsinline preload="none"
            width="{{ $width }}" height="{{ $height }}"
            @if (filled($poster) && blank($mobilePoster)) poster="{{ asset($poster) }}" @endif
            disablepictureinpicture></video>
        <video class="flow-tunnel-layer" autoplay muted playsinline webkit-playsinline preload="none"
            width="{{ $width }}" height="{{ $height }}" disablepictureinpicture></video>
    </div>
    <script>
        (function () {
            var root = document.currentScript.closest('.flow-tunnel');
            if (! root || root.dataset.booted === '1') {
                return;
            }
            root.dataset.booted = '1';
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            var videos = root.querySelectorAll('video');
            var primary = videos[0];
            var secondary = videos[1];
            if (! primary || ! secondary) {
                return;
            }
            var query = window.matchMedia('(max-width: 900px)');
            var rate = Number(root.dataset.rate || 1) || 1;
            var fadeSeconds = 2;
            var posterEl = root.querySelector('.flow-tunnel-poster');
            var front = primary;
            var back = secondary;
            var fading = false;
            var generation = 0;
            var download = null;
            var objectUrl = '';

            var pick = function () {
                var mobile = root.dataset.mobileSrc;
                if (query.matches && mobile) {
                    return mobile;
                }
                return root.dataset.src;
            };
            var posterFor = function (narrow) {
                if (! posterEl) {
                    return '';
                }
                var mobilePoster = posterEl.dataset.mobileSrc;
                if (narrow && mobilePoster) {
                    return mobilePoster;
                }
                return posterEl.dataset.src || posterEl.getAttribute('src') || '';
            };
            var whenReady = function (video, done) {
                var check = function () {
                    if (root.dataset.stop === '1') {
                        return;
                    }
                    if (video.readyState < 2) {
                        return;
                    }
                    video.removeEventListener('loadeddata', check);
                    video.removeEventListener('canplay', check);
                    done();
                };
                if (video.readyState >= 2) {
                    done();
                    return;
                }
                video.addEventListener('loadeddata', check);
                video.addEventListener('canplay', check);
            };
            var prepare = function (video) {
                video.loop = false;
                video.muted = true;
                video.defaultMuted = true;
                video.playsInline = true;
                video.setAttribute('playsinline', '');
                video.setAttribute('webkit-playsinline', '');
                video.preload = 'auto';
                video.playbackRate = rate;
            };
            var waitingForGesture = false;
            var playAtRate = function (video) {
                video.muted = true;
                video.setAttribute('muted', '');
                video.playsInline = true;
                video.playbackRate = rate;
                var attempt = video.play();
                if (! attempt || ! attempt.catch || waitingForGesture) {
                    return;
                }
                attempt.catch(function () {
                    if (waitingForGesture) {
                        return;
                    }
                    waitingForGesture = true;
                    var go = function () {
                        waitingForGesture = false;
                        document.removeEventListener('pointerdown', go);
                        document.removeEventListener('touchend', go);
                        playAtRate(video);
                    };
                    document.addEventListener('pointerdown', go);
                    document.addEventListener('touchend', go);
                });
            };
            var finishFade = function () {
                if (! fading) {
                    return;
                }
                fading = false;
                var outgoing = front;
                outgoing.pause();
                try {
                    outgoing.currentTime = 0;
                } catch (e) {}
                front = back;
                back = outgoing;
            };
            var beginFade = function () {
                if (fading || root.dataset.stop === '1' || back.readyState < 2) {
                    return;
                }
                fading = true;
                try {
                    back.currentTime = 0;
                } catch (e) {}
                back.playbackRate = rate;
                back.classList.add('is-on');
                playAtRate(back);
                front.classList.remove('is-on');
                window.setTimeout(finishFade, (fadeSeconds * 1000) + 60);
            };
            var onTime = function (event) {
                if (event.target !== front || fading || root.dataset.stop === '1') {
                    return;
                }
                var duration = front.duration;
                var speed = front.playbackRate || rate;
                if (! Number.isFinite(duration) || duration <= 0 || speed <= 0) {
                    return;
                }
                var wallLeft = (duration - front.currentTime) / speed;
                if (wallLeft <= fadeSeconds) {
                    beginFade();
                }
            };
            var onEnded = function (event) {
                if (event.target !== front) {
                    return;
                }
                beginFade();
            };
            primary.addEventListener('timeupdate', onTime);
            secondary.addEventListener('timeupdate', onTime);
            primary.addEventListener('ended', onEnded);
            secondary.addEventListener('ended', onEnded);
            primary.addEventListener('play', function () {
                primary.playbackRate = rate;
            });
            secondary.addEventListener('play', function () {
                secondary.playbackRate = rate;
            });

            var boot = function (url) {
                if (! url || root.dataset.stop === '1') {
                    return;
                }
                var ticket = ++generation;
                var posterUrl = posterFor(query.matches);
                if (posterEl && posterUrl) {
                    posterEl.src = posterUrl;
                }
                if (posterUrl) {
                    primary.poster = posterUrl;
                }
                root.classList.remove('is-ready');
                primary.classList.remove('is-on');
                secondary.classList.remove('is-on');
                fading = false;
                front = primary;
                back = secondary;
                prepare(primary);
                prepare(secondary);
                if (download) {
                    download.abort();
                }
                download = new AbortController();
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    objectUrl = '';
                    root._flowObjectUrl = '';
                }
                primary.removeAttribute('src');
                secondary.removeAttribute('src');
                primary.load();
                secondary.load();
                fetch(url, { signal: download.signal, credentials: 'same-origin' })
                    .then(function (response) {
                        if (! response.ok) {
                            throw new Error('video');
                        }
                        return response.blob();
                    })
                    .then(function (blob) {
                        if (ticket !== generation || root.dataset.stop === '1') {
                            return;
                        }
                        var type = blob.type && blob.type.indexOf('video/') === 0 ? blob.type : 'video/mp4';
                        var file = blob.type === type ? blob : new Blob([blob], { type: type });
                        objectUrl = URL.createObjectURL(file);
                        root._flowObjectUrl = objectUrl;
                        primary.dataset.loaded = url;
                        primary.src = objectUrl;
                        primary.load();
                        whenReady(primary, function () {
                            if (ticket !== generation || root.dataset.stop === '1') {
                                return;
                            }
                            root.classList.add('is-ready');
                            front.classList.add('is-on');
                            playAtRate(front);
                            secondary.dataset.loaded = url;
                            secondary.src = objectUrl;
                            secondary.load();
                            whenReady(secondary, function () {
                                if (ticket !== generation || root.dataset.stop === '1') {
                                    return;
                                }
                                if (front.ended) {
                                    beginFade();
                                }
                            });
                        });
                    })
                    .catch(function () {});
            };

            query.addEventListener('change', function () {
                boot(pick());
            });
            boot(pick());
        })();
    </script>
</div>
