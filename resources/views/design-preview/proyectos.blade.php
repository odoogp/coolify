@extends('design-preview.layout')

@section('heading', 'Mi Empresa')
@section('summary', 'Un proyecto Odoo. Producción y staging, cada uno con su dominio.')

@section('actions')
    <button type="button" class="btn btn-primary">Abrir entorno</button>
@endsection

@section('page')
    <main>
        <div class="mosaic">
            <article class="tile tile-forest">
                <div class="tile-kicker">Producción · Odoo 19</div>
                <div>
                    <div class="tile-value" style="font-size:32px">En línea</div>
                    <div class="tile-note">https://mi-empresa.example.com</div>
                </div>
            </article>
            <article class="tile tile-plum">
                <div class="tile-kicker">Staging · Odoo 19</div>
                <div>
                    <div class="tile-value" style="font-size:32px">En curso</div>
                    <div class="tile-note">https://staging.mi-empresa.example.com</div>
                </div>
            </article>
            <article class="tile tile-night tile-span-2">
                <div class="tile-kicker">Repositorio</div>
                <div>
                    <div class="tile-value" style="font-size:28px">equipo/mi-empresa</div>
                    <div class="tile-note">Un repositorio. Cada entorno guarda su rama.</div>
                </div>
            </article>
        </div>
    </main>
@endsection
