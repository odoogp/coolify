@extends('design-preview.layout')

@section('heading', 'Ajustes')
@section('summary', 'Configuración de la instancia.')

@section('page')
    <div>
        <div class="tabs" data-tabs>
            <button type="button" class="tab is-active" data-tab="general">General</button>
            <button type="button" class="tab" data-tab="correo">Correo</button>
            <button type="button" class="tab" data-tab="odoo">Odoo</button>
            <button type="button" class="tab" data-tab="actualizaciones">Actualizaciones</button>
        </div>
        <main>
            <div data-panel="general" class="split">
                <nav class="side-nav" aria-label="Secciones">
                    <span class="tab is-active">Instancia</span>
                </nav>
                <form class="card" action="#" method="get" onsubmit="return false">
                    <div class="form-grid">
                        <label>
                            Nombre
                            <input class="control" value="GPSH" aria-label="Nombre">
                        </label>
                        <label>
                            Zona horaria
                            <input class="control" value="America/Mexico_City" aria-label="Zona horaria">
                        </label>
                        <label class="wide">
                            Dominio público
                            <input class="control" value="gpsh.example.com" aria-label="Dominio público">
                        </label>
                    </div>
                    <div class="card-foot" style="margin-top:16px">
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
            <div data-panel="correo" hidden>
                <form class="card" action="#" method="get" onsubmit="return false" style="max-width:640px">
                    <div class="form-grid">
                        <label>
                            Servidor SMTP
                            <input class="control" value="mail.example.com" aria-label="Servidor SMTP">
                        </label>
                        <label>
                            Puerto
                            <input class="control" value="587" aria-label="Puerto">
                        </label>
                        <label class="wide">
                            Remitente
                            <input class="control" value="hola@gpsh.example.com" aria-label="Remitente">
                        </label>
                    </div>
                    <div class="card-foot" style="margin-top:16px">
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
            <div data-panel="odoo" hidden>
                <article class="card" style="max-width:640px">
                    <h3>Versiones disponibles</h3>
                    <p class="meta">El proyecto elige una al crearse. Aquí se ven las plantillas de la instancia.</p>
                    <div class="version-pills">
                        <span>17</span>
                        <span>18</span>
                        <span class="choice is-selected">19</span>
                        <span>20</span>
                    </div>
                </article>
            </div>
            <div data-panel="actualizaciones" hidden>
                <article class="card" style="max-width:640px">
                    <h3>Canal local</h3>
                    <p class="meta">Esta muestra no consulta actualizaciones reales.</p>
                    <div class="card-foot">
                        <button type="button" class="btn btn-primary">Buscar actualizaciones</button>
                    </div>
                </article>
            </div>
        </main>
    </div>
@endsection
