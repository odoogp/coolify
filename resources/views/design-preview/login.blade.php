@extends('design-preview.layout')

@section('heading', 'Iniciar sesión')

@section('page')
    <div class="login">
        <section class="stage" aria-hidden="true">
            <span class="orbit"></span>
            <span class="shard shard-a"></span>
            <span class="shard shard-b"></span>
            <span class="shard shard-c"></span>
            <svg class="mark" viewBox="0 0 200 200">
                <defs>
                    <linearGradient id="crystal" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#ffffff" stop-opacity="0.92"/>
                        <stop offset="0.45" stop-color="#c9bdff" stop-opacity="0.55"/>
                        <stop offset="1" stop-color="#6d7dff" stop-opacity="0.28"/>
                    </linearGradient>
                </defs>
                <polygon points="100,16 168,54 168,132 100,170 32,132 32,54" fill="url(#crystal)" stroke="rgba(255,255,255,0.72)" stroke-width="2"/>
                <polygon points="100,46 136,66 136,112 100,132 64,112 64,66" fill="rgba(255,255,255,0.16)" stroke="rgba(255,255,255,0.4)"/>
                <path d="M78 108 L100 68 L122 108 L110 108 L100 90 L90 108 Z" fill="rgba(255,255,255,0.92)"/>
            </svg>
            <span class="ridge ridge-a"></span>
            <span class="ridge ridge-b"></span>
            <p class="stage-caption">GPSH · proyectos Odoo, staging y servidores</p>
        </section>
        <section class="login-pane">
            <div class="login-bar">
                <a class="brand" href="{{ route('design-preview', ['screen' => 'panel']) }}">
                    <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-hex"/></svg>
                    GPSH
                </a>
                <button type="button" class="theme-toggle" data-theme-toggle>
                    <span class="when-dark">Modo claro</span>
                    <span class="when-light">Modo oscuro</span>
                </button>
            </div>
            <div class="auth-card">
                <h1>Iniciar sesión</h1>
                <p class="lede">Entra para gestionar proyectos, entornos y servidores.</p>
                <form action="#" method="get" onsubmit="return false">
                    <div class="field">
                        <svg width="18" height="18" aria-hidden="true"><use href="#i-user"/></svg>
                        <input type="email" value="propietario@example.com" autocomplete="username" aria-label="Correo">
                    </div>
                    <div class="field">
                        <svg width="18" height="18" aria-hidden="true"><use href="#i-lock"/></svg>
                        <input type="password" value="password" autocomplete="current-password" aria-label="Contraseña">
                        <button type="button" class="icon-btn" data-reveal aria-pressed="false" aria-label="Mostrar contraseña">
                            <svg width="18" height="18" aria-hidden="true"><use href="#i-eye"/></svg>
                        </button>
                    </div>
                    <div class="row-end">
                        <a class="text-link" href="#" onclick="return false">Olvidé mi contraseña</a>
                    </div>
                    <button class="btn-gradient" type="submit">Entrar</button>
                </form>
            </div>
            <p class="fine">
                Muestra visual. No inicia sesión.
                <a class="text-link" href="{{ route('design-preview', ['screen' => 'panel']) }}">Ver el panel</a>
            </p>
        </section>
    </div>
@endsection
