{{--
    Auth scene: a NASA plate with a different camera shot per page.
    Sign-in pages use the Artemis III plate; the plan payment link uses the Artemis I launch.
    resources/js/auth-mission.js moves the camera, draws the sky and reacts to the form.
--}}
@props([
    'scene' => 'login',
])

@php
    $plate = in_array($scene, ['launch', 'payment'], true) ? 'launch' : 'artemis3';
    $plateFile = $plate === 'launch' ? 'launch-hero' : 'mission-hero';
    $mission = $plate === 'launch' ? 'Artemis I · NASA/Bill Ingalls' : 'Artemis III · NASA';
    $captions = [
        'login' => __('Lunar surface'),
        'register' => 'Starship HLS',
        'forgot' => __('Earth from the Moon'),
        'reset' => __('Landing pad'),
        'twofactor' => __('EVA visor'),
        'confirm' => __('EVA visor'),
        'verify' => __('Signal beacon'),
        'invitation' => __('Lunar surface'),
        'launch' => __('Liftoff'),
        'payment' => __('Ignition'),
    ];
@endphp

<div class="auth-mission" data-auth-mission data-scene="{{ $scene }}" data-plate="{{ $plate }}" aria-hidden="true">
    <div class="auth-mission-camera" data-mission-camera>
        <div class="auth-mission-plate" data-mission-plate>
            <img src="{{ asset('brand/illustrations/space/'.$plateFile.'.jpg') }}"
                srcset="{{ asset('brand/illustrations/space/'.$plateFile.'-1920.jpg') }} 1920w, {{ asset('brand/illustrations/space/'.$plateFile.'.jpg') }} 3840w"
                sizes="(max-width: 1200px) 1920px, 3840px"
                width="3840" height="2160" alt="" decoding="async" fetchpriority="high" draggable="false">
            @if ($plate === 'launch')
                <span class="auth-mission-fx auth-mission-ignition"></span>
                <span class="auth-mission-fx auth-mission-plume"></span>
            @else
                <span class="auth-mission-fx auth-mission-rocket-glass"></span>
                <span class="auth-mission-fx auth-mission-dome"></span>
                <span class="auth-mission-fx auth-mission-beacon"></span>
                <span class="auth-mission-fx auth-mission-earth"></span>
                <span class="auth-mission-fx auth-mission-visor"></span>
            @endif
        </div>
    </div>
    <canvas class="auth-mission-sky" data-mission-sky></canvas>
    <span class="auth-mission-grade auth-mission-grade-right"></span>
    <span class="auth-mission-grade auth-mission-grade-left"></span>
    <span class="auth-mission-grade auth-mission-grade-bottom"></span>
    <span class="auth-mission-vignette"></span>
    <span class="auth-mission-dim"></span>
    <p class="auth-mission-caption"><i></i><span><b>{{ $captions[$scene] ?? $captions['login'] }}</b> · {{ $mission }}</span></p>
</div>

<div class="auth-mission-aperture" data-mission-aperture data-entering="{{ __('Entering your dashboard…') }}" hidden aria-hidden="true">
    <div class="auth-mission-aperture-mark" data-mission-mark>
        <img src="{{ product_mark_url() }}" alt="">
        <span data-mission-message></span>
    </div>
</div>
