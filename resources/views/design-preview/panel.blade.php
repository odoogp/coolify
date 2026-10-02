@extends('design-preview.layout')

@section('heading', 'Panel')
@section('summary', 'Proyectos y servidores de este espacio.')

@section('actions')
    <button type="button" class="btn btn-primary">Nuevo</button>
@endsection

@section('page')
    <main>
        <div class="mosaic">
            <a class="tile tile-coral" href="{{ route('design-preview', ['screen' => 'proyectos']) }}">
                <div class="tile-kicker">Proyectos</div>
                <div>
                    <div class="tile-value">2</div>
                    <div class="tile-note">1 con staging en curso</div>
                </div>
            </a>
            <a class="tile tile-olive" href="{{ route('design-preview', ['screen' => 'proyectos']) }}">
                <div class="tile-kicker">Despliegue siguiente</div>
                <div>
                    <div class="tile-value">Staging</div>
                    <div class="tile-note">Mi Empresa · ahora</div>
                </div>
            </a>
            <a class="tile tile-plum" href="{{ route('design-preview', ['screen' => 'servidor']) }}">
                <div class="tile-kicker">Servidores</div>
                <div>
                    <div class="tile-value">1</div>
                    <div class="tile-note">localhost · Traefik</div>
                </div>
            </a>
            <article class="tile tile-forest">
                <div class="tile-kicker">Salud</div>
                <div class="gauge-row">
                    <div class="gauge" style="--p: 78%"><b>78%</b></div>
                    <div class="tile-note">Producción estable. Staging arrancando.</div>
                </div>
            </article>
            <article class="tile tile-night tile-span-2">
                <div>
                    <div class="tile-kicker">Despliegues activos</div>
                    <div class="segments" aria-hidden="true">
                        <span style="flex:3;background:#3dd68c"></span>
                        <span style="flex:1;background:#b7a6ff"></span>
                    </div>
                </div>
                <div class="legend">
                    <span><i style="background:#3dd68c"></i>Terminado · producción, hace 12 min</span>
                    <span><i style="background:#b7a6ff"></i>En curso · staging</span>
                </div>
            </article>
        </div>
    </main>
@endsection
