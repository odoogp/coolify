{{--
    Auth scene: one NASA Artemis III plate, a different camera shot per page.
    resources/js/auth-mission.js moves the camera, draws the sky and reacts to the form.
--}}
@props([
    'scene' => 'login',
])

@php
    $captions = [
        'login' => __('Lunar surface'),
        'register' => 'Starship HLS',
        'forgot' => __('Earth from the Moon'),
        'reset' => __('Landing pad'),
        'twofactor' => __('EVA visor'),
        'confirm' => __('EVA visor'),
        'verify' => __('Signal beacon'),
        'invitation' => __('Lunar surface'),
    ];
@endphp

<div class="auth-mission" data-auth-mission data-scene="{{ $scene }}" aria-hidden="true">
    <div class="auth-mission-camera" data-mission-camera>
        <div class="auth-mission-plate" data-mission-plate>
            <img src="{{ asset('brand/illustrations/space/mission-hero.jpg') }}"
                srcset="{{ asset('brand/illustrations/space/mission-hero-1920.jpg') }} 1920w, {{ asset('brand/illustrations/space/mission-hero.jpg') }} 3840w"
                sizes="(max-width: 1200px) 1920px, 3840px"
                width="3840" height="2160" alt="" decoding="async" fetchpriority="high" draggable="false">
            <span class="auth-mission-fx auth-mission-rocket-glass"></span>
            <span class="auth-mission-fx auth-mission-dome"></span>
            <span class="auth-mission-fx auth-mission-beacon"></span>
            <span class="auth-mission-fx auth-mission-earth"></span>
            <span class="auth-mission-fx auth-mission-visor"></span>
        </div>
    </div>
    <canvas class="auth-mission-sky" data-mission-sky></canvas>
    <span class="auth-mission-grade auth-mission-grade-right"></span>
    <span class="auth-mission-grade auth-mission-grade-left"></span>
    <span class="auth-mission-grade auth-mission-grade-bottom"></span>
    <span class="auth-mission-vignette"></span>
    <span class="auth-mission-dim"></span>
    <p class="auth-mission-caption"><i></i><span><b>{{ $captions[$scene] ?? $captions['login'] }}</b> · Artemis III · NASA</span></p>
</div>

<div class="auth-mission-aperture" data-mission-aperture hidden aria-hidden="true">
    <div class="auth-mission-aperture-mark" data-mission-mark>
        <img src="{{ product_mark_url() }}" alt="">
        <span data-mission-message></span>
    </div>
</div>
