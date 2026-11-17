{{--
  Cinematic NASA Artemis stage — one complete render + living starfield.
  Image: Artemis III EVA / Starship HLS (nasa.gov).
  Open: Apple-style black aperture → sky → hero settle → UI (anime.js).
  Sky: multi-depth stars + blinks + occasional meteors.
--}}
@props(['uid' => 'default'])

@php
    /**
     * Deterministic-ish scatter so Blade stays stable across renders
     * while still looking irregular (not a grid).
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    $scatterStars = static function (int $count, float $minR, float $maxR, int $seed, float $ySpan = 4500.0): array {
        $stars = [];
        $s = $seed;
        for ($i = 0; $i < $count; $i++) {
            $s = ($s * 1103515245 + 12345) & 0x7fffffff;
            $x = ($s % 10000) / 10; // 0–999.9
            $s = ($s * 1103515245 + 12345) & 0x7fffffff;
            $y = ($s % (int) $ySpan) / 10;
            $s = ($s * 1103515245 + 12345) & 0x7fffffff;
            $r = $minR + (($s % 1000) / 1000) * ($maxR - $minR);
            $stars[] = [round($x, 1), round($y, 1), round($r, 2)];
        }

        return $stars;
    };

    // Keep scatter in the upper sky band of the 560 viewBox (not over the suit/ground).
    $farStars = $scatterStars(96, 0.35, 0.8, 17, 2800);
    $midStars = $scatterStars(58, 0.5, 1.1, 91, 2600);
    $nearStars = $scatterStars(32, 0.7, 1.45, 233, 2400);
@endphp

<div class="auth-orbit" data-auth-orbit="{{ $uid }}">
    <div class="auth-orbit-void"></div>

    <div class="auth-orbit-hero-wrap auth-orbit-layer">
        <img
            class="auth-orbit-hero"
            src="{{ asset('brand/illustrations/space/mission-hero.jpg') }}"
            alt=""
            width="3840"
            height="2160"
            decoding="async"
        >
        <div class="auth-orbit-hero-shade"></div>
        <div class="auth-orbit-visor-glow" aria-hidden="true"></div>
    </div>

    {{-- Living sky: depth layers + meteor pool. Blend + mask keep them in black space. --}}
    <div class="auth-orbit-sky" aria-hidden="true">
        <div class="auth-orbit-sky-glow"></div>

        <svg class="auth-orbit-stars auth-orbit-stars--far" viewBox="0 0 1000 560" preserveAspectRatio="xMidYMin slice">
            @foreach ($farStars as $i => [$x, $y, $r])
                <circle class="auth-orbit-star auth-orbit-star--far" cx="{{ $x }}" cy="{{ $y }}" r="{{ $r }}" data-star-i="{{ $i }}"/>
            @endforeach
        </svg>

        <svg class="auth-orbit-stars auth-orbit-stars--mid" viewBox="0 0 1000 560" preserveAspectRatio="xMidYMin slice">
            @foreach ($midStars as $i => [$x, $y, $r])
                <circle class="auth-orbit-star auth-orbit-star--mid" cx="{{ $x }}" cy="{{ $y }}" r="{{ $r }}" data-star-i="{{ $i }}"/>
            @endforeach
        </svg>

        <svg class="auth-orbit-stars auth-orbit-stars--near" viewBox="0 0 1000 560" preserveAspectRatio="xMidYMin slice">
            @foreach ($nearStars as $i => [$x, $y, $r])
                <circle class="auth-orbit-star auth-orbit-star--near" cx="{{ $x }}" cy="{{ $y }}" r="{{ $r }}" data-star-i="{{ $i }}"/>
            @endforeach
        </svg>

        <div class="auth-orbit-meteors">
            @for ($m = 0; $m < 3; $m++)
                <span class="auth-orbit-meteor" data-meteor="{{ $m }}">
                    <span class="auth-orbit-meteor-streak">
                        <span class="auth-orbit-meteor-head"></span>
                        <span class="auth-orbit-meteor-trail"></span>
                    </span>
                </span>
            @endfor
        </div>
    </div>

    <div class="auth-orbit-vignette"></div>

    {{-- Apple-style aperture: black plate lifts to open the scene. --}}
    <div class="auth-orbit-open-veil" aria-hidden="true"></div>
</div>
