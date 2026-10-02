@extends('design-preview.layout')

@section('heading', 'localhost')
@section('summary', 'Servidor de la instancia. Proxy Traefik y dos recursos Odoo.')

@section('actions')
    <button type="button" class="btn">Detener</button>
    <button type="button" class="btn btn-primary">Reiniciar</button>
@endsection

@section('page')
    <div data-tab-root>
        <div class="tabs" data-tabs>
            <button type="button" class="tab is-active" data-tab="general">General</button>
            <button type="button" class="tab" data-tab="proxy">Proxy</button>
            <button type="button" class="tab" data-tab="recursos">Recursos</button>
        </div>
        <main>
            <div data-panel="general">
                <div class="mosaic">
                    <article class="tile tile-forest">
                        <div class="tile-kicker">Estado</div>
                        <div class="gauge-row">
                            <div class="gauge" style="--p: 92%"><b>92%</b></div>
                            <div class="tile-note">En línea. Comprobado ahora.</div>
                        </div>
                    </article>
                    <article class="tile tile-plum">
                        <div class="tile-kicker">Recursos</div>
                        <div>
                            <div class="tile-value">2</div>
                            <div class="tile-note">Odoo de producción y de staging</div>
                        </div>
                    </article>
                </div>
            </div>
            <div data-panel="proxy" hidden>
                <article class="tile tile-night" style="max-width:520px">
                    <div class="tile-kicker">Traefik</div>
                    <div>
                        <div class="tile-value" style="font-size:32px">En línea</div>
                        <div class="tile-note">Los dominios de producción y staging salen por HTTPS en este proxy.</div>
                    </div>
                </article>
            </div>
            <div data-panel="recursos" hidden>
                <div class="card table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Recurso</th>
                                <th>Proyecto</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Odoo producción</td>
                                <td>Mi Empresa</td>
                                <td><span class="pill">En línea</span></td>
                            </tr>
                            <tr>
                                <td>Odoo staging</td>
                                <td>Mi Empresa</td>
                                <td><span class="pill pill-progress">En curso</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
@endsection
