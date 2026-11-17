"""Atlas content. Every current-state claim carries a file/class/method/endpoint/table reference.
Everything that does not exist in the repository is labelled PROPUESTA (gray)."""

from __future__ import annotations

from html import escape

import d_current as C
import d_future as F
from layout import DOC_NAME, kind_tag, panel, tag, ul
from svgflow import Diagram, legend_svg

T_ORIG = "ORIGINAL COOLIFY"
T_44 = "COOLIFY 4.4 (SIN COMMIT)"
T_OWN = "PERSONALIZACIÓN PROPIA"
T_MOD = "MODIFICACIÓN"
T_INT = "INTEGRACIÓN"
T_PROP = "PROPUESTA"
T_PEND = "PENDIENTE DE VERIFICACIÓN"


def c(text: str) -> str:
    return f"<code>{escape(text)}</code>"


def tags(*labels: str) -> str:
    return "".join(tag(label) for label in labels)


def table(headers: list[str], rows: list[list[str]], cls: str = "", widths: list[str] | None = None) -> str:
    cols = ""
    if widths:
        cols = "<colgroup>" + "".join(f'<col style="width:{w}">' for w in widths) + "</colgroup>"
    head = "".join(f"<th>{h}</th>" for h in headers)
    body = "".join("<tr>" + "".join(f"<td>{cell}</td>" for cell in row) + "</tr>" for row in rows)
    return f'<table class="{cls}">{cols}<thead><tr>{head}</tr></thead><tbody>{body}</tbody></table>'


def build(book, ev) -> None:
    counts = ev["counts"]
    up = ev["upstream"]
    wt = ev["worktree"]

    def own(area: str) -> int:
        return counts[area].get("PROPIO", 0)

    def coolify(area: str) -> int:
        a = counts[area]
        return a.get("ORIGINAL", 0) + a.get("UPSTREAM_4.4", 0)

    def modified(area: str) -> int:
        a = counts[area]
        return a.get("MODIFICADO", 0) + a.get("UPSTREAM_4.4_MODIFICADO", 0)

    # ------------------------------------------------------------------ 1. Portada
    book.add("Portada", "Portada", f"""
<div style="position:absolute;left:24mm;top:30mm;right:24mm">
  <div style="font-size:14px;font-weight:700;color:#7C3AED;letter-spacing:.08em">AUDITORÍA FUNCIONAL · ATLAS VISUAL DE EVOLUCIÓN</div>
  <div style="font-size:64px;font-weight:750;letter-spacing:-.035em;line-height:1.02;margin-top:8mm;max-width:300mm">
    Plataforma getcloud.sh<br><span style="color:#64748B">sobre Coolify v4</span></div>
  <div class="lead" style="margin-top:10mm;max-width:250mm;font-size:18px">
    Qué existe hoy en el repositorio, qué es de Coolify, qué es nuestro, qué limita al motor y cómo evolucionar hacia una
    experiencia tipo Railway sin romper la compatibilidad con Coolify.</div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6mm;margin-top:18mm;max-width:330mm">
    <div class="kpi"><span>Rama auditada</span><b style="font-size:20px">{escape(ev['branch'])}</b>
      <span>HEAD {c(ev['head']['sha'])} · {ev['head']['date']}</span></div>
    <div class="kpi"><span>Versión declarada</span><b style="font-size:20px">{escape(ev['version'])}</b>
      <span>constante local de {c('config/constants.php')}</span></div>
    <div class="kpi"><span>Base Coolify de referencia</span><b style="font-size:20px">{ev['base']['sha'][:9]}</b>
      <span>{ev['base']['date']} · padre del primer commit propio</span></div>
    <div class="kpi"><span>Upstream comparado</span><b style="font-size:20px">{escape(up['ref'])} · 4.4</b>
      <span>{up['date']} · {up['commits_not_merged']} commits sin fusionar</span></div>
  </div>
  <div style="display:flex;gap:3mm;margin-top:16mm;flex-wrap:wrap">
    {kind_tag('coolify', 'Azul · Coolify')}{kind_tag('own', 'Morado · desarrollo propio')}{kind_tag('ext', 'Verde · externo')}
    {kind_tag('partial', 'Naranja · parcial')}{kind_tag('risk', 'Rojo · error o riesgo')}{kind_tag('proposal', 'Gris · propuesta')}
  </div>
  <div class="small" style="margin-top:6mm">Documento de solo lectura, generado el 15 nov 2026 a partir de {c('docs/functional-audit/tools/')}.
    No se modificó la aplicación, la configuración ni las bases de datos.</div>
</div>""", raw=True, cls="cover", toc="")

    # ------------------------------------------------------------------ Leyenda y método
    book.add("Cómo leer el atlas", "Leyenda, símbolos y método de clasificación", f"""
<div class="stack">
  <div class="diagram" style="padding:4mm 10mm;height:82mm;flex:0 0 auto">{legend_svg()}</div>
  <div class="three fill">
    {panel('Cómo se decide si algo es de Coolify o nuestro', ul([
        f"Cada archivo se compara con dos referencias Git: la <b>BASE</b> {c(ev['base']['sha'][:9])} (último commit de Coolify antes del primer commit propio, {ev['base']['date']}) y el <b>UPSTREAM</b> {c(up['ref'])} (origin/main, versión 4.4, {up['date']}).",
        "<b>ORIGINAL</b>: idéntico a la BASE. <b>MODIFICADO</b>: existía en la BASE y cambió. <b>PROPIO</b>: no existe ni en la BASE ni en el upstream.",
        "<b>COOLIFY 4.4</b>: no existía en la BASE pero sí, idéntico, en el upstream 4.4. Es código de Coolify, aunque haya entrado por una sincronización local. <b>4.4 MODIFICADO</b>: viene del upstream y además lo tocamos.",
        f"Herramienta: {c('docs/functional-audit/tools/collect_evidence.py')} → {c('build/evidence.json')}.",
    ]))}
    {panel('Etiquetas funcionales usadas en tablas', ul([
        tags(T_ORIG) + " comportamiento del motor Coolify sin cambios relevantes.",
        tags(T_44) + " código 4.4 presente en el árbol de trabajo sin commit.",
        tags(T_MOD) + " lógica de Coolify alterada por nosotros.",
        tags(T_OWN) + " desarrollo nuestro, sin equivalente en Coolify.",
        tags(T_INT) + " dependencia de un servicio externo.",
        tags(T_PROP) + " no existe; es una propuesta de este documento.",
        tags(T_PEND) + " no se pudo comprobar en el backend.",
    ]))}
    {panel('Reglas aplicadas', ul([
        "Nada se da por hecho porque aparezca en la interfaz: cada capacidad actual cita archivo, clase, método, endpoint o tabla.",
        "Las páginas del estado actual llevan la insignia azul ESTADO ACTUAL; las propuestas llevan la gris ARQUITECTURA FUTURA.",
        "Railway se describe solo con su documentación pública (docs.railway.com).",
    ]))}
  </div>
</div>""", toc="Leyenda y método")

    # ------------------------------------------------------------------ 2. Resumen ejecutivo
    kpis = [
        (ev["commits"]["own"], "commits propios desde la BASE"),
        (up["commits_not_merged"], "commits de Coolify 4.4 sin fusionar"),
        (len(ev["own_migrations"]), "migraciones propias"),
        (own("services") + own("support") + own("domain"), "clases propias de servicios, soporte y dominio"),
        (own("feature_tests") + own("unit_tests"), "tests propios (feature + unit)"),
        (wt["M"] + wt["D"] + wt["??"], "cambios sin commit en el árbol"),
    ]
    kpi_html = "".join(f'<div class="kpi"><b>{v}</b><span>{escape(t)}</span></div>' for v, t in kpis)
    book.add("Resumen ejecutivo", "Resumen ejecutivo", f"""
<div class="stack">
  <div class="kpis">{kpi_html}</div>
  <div class="three fill big">
    {panel('Qué es hoy la plataforma', ul([
        "Un <b>fork monolítico de Coolify v4</b> (Laravel 12 + Livewire 3): el motor y nuestra capa de producto viven en el mismo contenedor y la misma base Postgres.",
        "Nuestra capa llama directamente a las Actions y Jobs de Coolify, no a su API. No existe un adaptador ni un backend separado.",
        "El valor propio está en el <b>runtime Odoo</b> (staging, Git, addons, backups zip, migraciones), en <b>GetOdoo</b> (planes, regiones, reventa de servidores, pagos Wompi/N1co), en las cuotas por miembro, el catálogo de plantillas, los avisos GPSH y la interfaz (panel, Deploys, búsqueda).",
        "El motor de despliegue, proxy, variables, backups, métricas, multi-servidor y API son de Coolify.",
    ]))}
    {panel('Hallazgos críticos', ul([
        f"<b>Deriva del upstream:</b> {up['commits_not_merged']} commits de 4.4 sin fusionar y una sincronización parcial sin commit ({wt['M']} modificados, {wt['??']} sin seguimiento). Es el mayor riesgo técnico.",
        "<b>Sin permisos por proyecto/entorno:</b> " + c("ProjectPolicy::view") + " solo comprueba pertenencia al equipo.",
        "<b>Despliegue sin blue-green ni canary.</b> El rolling update se desactiva con puertos fijos, nombre de contenedor fijo o previews.",
        "<b>Sin red privada entre servidores</b>, sin placement automático ni failover.",
        "<b>Observabilidad corta:</b> Sentinel guarda métricas unos días; los logs de runtime se leen por SSH y no se retienen.",
        "<b>Variables:</b> no hay ámbito workspace propio ni referencias tipadas entre servicios; el helper no resuelve " + c("{{server.*}}") + ".",
    ]))}
    {panel('Decisiones recomendadas', ul([
        "<b>1. Estabilizar antes de construir:</b> cerrar la sincronización con Coolify 4.4 en una rama propia, con commit y tests, antes de cualquier rediseño.",
        "<b>2. Monolito modular:</b> crear ahora un <b>adaptador interno</b> (" + c("app/Platform/Coolify") + ", propuesta) como única puerta hacia el motor. Separar en servicio aparte solo si hace falta.",
        "<b>3. Adoptar de Railway</b> el canvas, los cambios en revisión, las referencias de variables, los roles por proyecto y los marcadores de deploy en métricas.",
        "<b>4. No perseguir</b> serverless, autoescalado, regiones globales ni BD gestionadas con PITR a corto plazo: necesitan infraestructura que Coolify no ofrece.",
        "<b>5. Observabilidad</b> con Loki/Vector y una TSDB como servicios del propio Coolify, consultados por nuestro backend.",
    ]), grow=True)}
  </div>
</div>""")

    # ------------------------------------------------------------------ 3. Mapa funcional actual
    fmap = [
        ["Equipos (workspace actual)", tags(T_ORIG, T_MOD), "Team + " + c("team_user.role") + "; cuotas por miembro añadidas", c("app/Models/Team.php") + " " + c("team_user.max_*")],
        ["Proyectos", tags(T_ORIG, T_MOD), "Crea entorno production automático; alta con cuota y servicio inicial", c("Project::create") + " " + c("Livewire\\Project\\AddEmpty") + " " + c("AdminCreationQuota")],
        ["Entornos con rol", tags(T_OWN), "production / staging / development con creador", c("environments.role") + " " + c("Project::createCatalogLaunchEnvironment")],
        ["Clonar a staging", tags(T_OWN), "Copia BD + filestore (Odoo) o servicios (resto)", c("CloneOdooStagingJob") + " " + c("CloneEnvironmentStagingJob")],
        ["Apps desde Git / imagen", tags(T_44), "Nixpacks, Railpack, Dockerfile, Compose, estático, imagen", c("ApplicationDeploymentJob::decide_what_to_do")],
        ["Servicios de plantilla", tags(T_ORIG, T_OWN), "Plantillas de Coolify + catálogo propio con overrides", c("ServiceTemplateCatalog::composeFor") + " " + c("service_template_overrides")],
        ["Bases de datos (9 motores)", tags(T_ORIG), "Postgres, MySQL, MariaDB, Mongo, Redis, KeyDB, Dragonfly, ClickHouse, SQLite", c("app/Actions/Database/Start*") + " " + c("standalone_*")],
        ["Cola de despliegues", tags(T_ORIG, T_MOD), "1 por app; " + c("concurrent_builds") + " por servidor; ruta Odoo addons", c("queue_application_deployment()") + " " + c("application_deployment_queues")],
        ["Historial global de deploys", tags(T_OWN), "Línea temporal del equipo, filtro por proyecto", c("Livewire\\Deployments\\Index") + " " + c("/deployments")],
        ["Rollback", tags(T_ORIG), "Reutiliza una imagen previa; no restaura variables", c("Rollback::rollbackImage") + " " + c("docker_images_to_keep")],
        ["Previews de PR", tags(T_ORIG), "Entorno por PR vía webhook GitHub/GitLab", c("ApplicationPreview") + " " + c("Webhook\\Github")],
        ["Variables + compartidas", tags(T_44), "Cifradas; flags build/runtime/literal/preview; equipo, proyecto, entorno, servidor", c("EnvironmentVariable") + " " + c("SharedEnvironmentVariable")],
        ["Dominios y SSL", tags(T_ORIG, T_MOD), "Labels Traefik/Caddy, ACME, wildcard/sslip", c("fqdnLabelsForTraefik") + " " + c("tlsCertResolverForHost")],
        ["Subdominio por entorno Odoo", tags(T_OWN), "{sub}.{base} y {sub}-{env}-{id}.{base}", c("OdooDomains::projectHost")],
        ["Proxy externo (EasyPanel)", tags(T_OWN, T_MOD), "Conecta proxy ajeno a redes de Coolify", c("connectExternalProxyToNetworks()") + " " + c("bootstrap/helpers/proxy.php")],
        ["Backups de BD", tags(T_ORIG, T_MOD), "Programados, retención local/S3, notificación", c("DatabaseBackupJob") + " " + c("scheduled_database_backups")],
        ["Backups Odoo zip", tags(T_OWN), "dump.sql + filestore + manifest, restore", c("CreateOdooBackupJob") + " " + c("RestoreOdooBackupJob") + " " + c("odoo_backups")],
        ["Backups de volúmenes / imports", tags(T_44), "tar.gz de volúmenes; import de dumps", c("VolumeBackupJob") + " " + c("StartDatabaseImport")],
    ]
    fmap2 = [
        ["Métricas", tags(T_ORIG, T_PEND), "Sentinel empuja CPU/RAM; retención configurable (por defecto días)", c("POST /api/v1/sentinel/push") + " " + c("HasMetrics")],
        ["Estado de contenedores", tags(T_44), "Agrega running/healthy/exited; difunde por Reverb", c("ContainerStatusAggregator") + " " + c("PushServerUpdateJob")],
        ["Notificaciones", tags(T_ORIG), "Email, Discord, Telegram, Slack, Pushover, Webhook", c("HasNotificationSettings") + " " + c("app/Notifications")],
        ["Logs de runtime", tags(T_ORIG, T_MOD), "docker logs por SSH al abrir; sin retención", c("Livewire\\Project\\Shared\\GetLogs")],
        ["Terminal", tags(T_ORIG, T_MOD), "Terminal web + shell Odoo para admin/miembro", c("Terminal\\Index") + " gate " + c("canAccessTerminal")],
        ["Multi-servidor", tags(T_ORIG), "Servidores SSH, build server, Swarm, destinos adicionales", c("Server") + " " + c("is_build_server") + " " + c("additional_destinations")],
        ["Nubes", tags(T_ORIG, T_OWN, T_INT), "Hetzner/Vultr/DO + 8 nubes propias", c("AdditionalCloudCatalog") + " " + c("HetznerService")],
        ["Reventa GetOdoo", tags(T_OWN, T_INT), "Servidores Hetzner cobrados con Wompi / N1co", c("ByGetOdoo") + " " + c("/payments/wompi") + " " + c("/payments/n1co")],
        ["Planes, regiones, alta", tags(T_OWN, T_INT), "Planes Odoo, región por IP (ip-api.com)", c("GetOdooPlans") + " " + c("get_odoo_*")],
        ["Roles y cuotas", tags(T_ORIG, T_OWN), "member/admin/owner + " + c("max_*") + ", " + c("odoo_abilities") + ", " + c("can_add_servers"), c("Enums\\Role") + " " + c("team_user")],
        ["Autenticación", tags(T_ORIG), "Fortify + 2FA TOTP + OAuth (GitHub, GitLab, Google, Azure, Authentik…)", c("FortifyServiceProvider") + " " + c("OauthSettingSeeder")],
        ["API y tokens", tags(T_ORIG, T_44), "Sanctum con abilities read/write/deploy/root", c("ApiAbility") + " " + c("routes/api.php") + f" ({ev['routes']['api']['total']} rutas)"],
        ["API Odoo", tags(T_OWN), "7 rutas " + c("/projects/{uuid}/odoo*"), c("Api\\OdooProjectController")],
        ["Auditoría", tags(T_44, T_MOD, T_OWN), "audit_events del upstream + bitácora Odoo propia", c("AuditEvent") + " " + c("odoo_audit_logs")],
        ["MCP para agentes", tags(T_ORIG, T_MOD), "Servidor MCP con herramientas por equipo", c("routes/ai.php")],
        ["Avisos GPSH, Jupyter, Beszel", tags(T_OWN), "Campana de avisos, Jupyter del owner, monitor Beszel", c("GpshNotices") + " " + c("OdooJupyter") + " " + c("OdooMonitor")],
        ["Panel, búsqueda, marca", tags(T_OWN, T_MOD), "DashboardOverview, búsqueda global, tema Obsidian, es.json", c("DashboardOverview") + " " + c("GlobalSearch")],
        ["Canvas, workspace multi-equipo, ACL por proyecto, referencias de variables, logs centralizados", tags(T_PROP), "No existen en el repositorio (búsqueda de canvas/topología sin resultados)", "—"],
    ]
    hdr = ["Capacidad", "Clasificación", "Cómo funciona hoy", "Evidencia"]
    wid = ["17%", "21%", "34%", "28%"]
    book.add("Mapa funcional actual", "Mapa funcional actual · 1 de 2: proyectos, despliegue, datos",
             table(hdr, fmap, "tight", wid), state="actual",
             sub="Cada fila se verificó en el backend; la etiqueta indica de quién es el código.", toc="Mapa funcional actual")
    book.add("Mapa funcional actual", "Mapa funcional actual · 2 de 2: operación, negocio, acceso",
             table(hdr, fmap2, "tight", wid), state="actual", toc="")

    # ------------------------------------------------------------------ 4. Arquitectura actual
    book.diagram("Arquitectura actual", "Diagrama 1 · Arquitectura actual", "actual", C.current_architecture(), [
        "Un solo contenedor Laravel sirve UI, API, MCP, webhooks y Reverb. Horizon ejecuta los jobs sobre Redis.",
        "La capa propia (morado) llama a las Actions de Coolify en proceso: no pasa por la API.",
        "Todo cambio en servidores sale por SSH multiplexado; Sentinel es el único agente que empuja datos.",
    ], [
        c("routes/web.php") + f" ({ev['routes']['web']['total']} rutas, {ev['routes']['web']['added']} nuevas)",
        c("routes/api.php") + f" ({ev['routes']['api']['total']} rutas, {ev['routes']['api']['added']} nuevas; 7 propias)",
        c("routes/webhooks.php") + " · " + c("routes/channels.php") + " · " + c("routes/ai.php"),
        c("app/Support/*") + f" ({own('support')} propias) · " + c("app/Domain/Odoo") + f" ({own('domain')})",
        c("app/Jobs") + f" ({counts['jobs']['total']}, {own('jobs')} propios) · " + c("instant_remote_process()"),
    ], [
        "Acoplamiento: cualquier cambio de firma en una Action de Coolify rompe nuestra capa sin aviso.",
        "Una sola BD para motor y producto: las migraciones propias conviven con las del upstream.",
    ], sub="Monolito: el motor Coolify y la capa propia comparten proceso, base de datos y colas.")

    # ------------------------------------------------------------------ 5. Personalizaciones e integraciones
    domains = [
        ["Runtime Odoo", tags(T_OWN), "Git por entorno, staging, addons, backups zip, migraciones, terminal, dominios", c("OdooGit") + " " + c("app/Domain/Odoo") + " " + c("odoo_profiles") + " " + c("odoo_migrations")],
        ["GetOdoo", tags(T_OWN, T_INT), "Planes, regiones, alta, facturación, reventa Hetzner", c("GetOdooPlans") + " " + c("get_odoo_*") + " " + c("ByGetOdoo")],
        ["Cuotas y permisos finos", tags(T_OWN), "Límites por miembro; quién puede agregar servidores o usar el servidor de instancia", c("team_user.max_*") + " " + c("can_launch_on_instance_server")],
        ["Catálogo de plantillas", tags(T_OWN), "Overrides de plantillas, lanzamiento one-click", c("ServiceTemplateCatalog") + " " + c("service_template_overrides")],
        ["Avisos y comunicación", tags(T_OWN), "Campana GPSH, WhatsApp, términos, marca", c("GpshNotices") + " " + c("Notifications\\Center")],
        ["Herramientas del owner", tags(T_OWN), "Jupyter, Beszel, terminal Odoo", c("OdooJupyter") + " " + c("OdooMonitor")],
        ["Interfaz", tags(T_OWN, T_MOD), "Panel, Deploys, búsqueda, tema, español", c("DashboardOverview") + " " + c("lang/es.json") + f" · {modified('livewire')} componentes Livewire modificados"],
        ["Coexistencia EasyPanel", tags(T_OWN, T_MOD), "Proxy externo conectado a redes Coolify", c("connectExternalProxyToNetworks()")],
        ["Nubes adicionales", tags(T_OWN), "8 proveedores más allá de Hetzner/Vultr/DO", c("AdditionalCloudCatalog")],
        ["Herramientas de desarrollo", tags(T_OWN), "Instancias por rama, VMs QEMU/Lima, documentación GPSH", c("scripts/dev") + " " + c("php artisan dev:qemu")],
    ]
    integ = [
        ["GitHub API (App por manifiesto)", tags(T_ORIG, T_MOD), "Repos, ramas, push; permisos contents/administration para Odoo", c("GithubApp") + " " + c("OdooGit")],
        ["GitLab", tags(T_ORIG), "Webhook con secreto", c("/source/gitlab/events")],
        ["Bitbucket / Gitea", tags(T_ORIG), "Solo webhook manual", c("/source/bitbucket/events/manual")],
        ["Odoo del cliente", tags(T_OWN, T_INT), "HTTP a endpoints " + c("/_odoo/paas/*") + "; no se encontró XML-RPC de facturación", c("app/Support/Odoo*")],
        ["Let's Encrypt", tags(T_ORIG, T_INT), "HTTP-01 vía Traefik/Caddy", c("tlsCertResolverForHost")],
        ["Wompi · N1co", tags(T_OWN, T_INT), "Webhooks de pago", c("/payments/wompi") + " " + c("/payments/n1co")],
        ["Stripe", tags(T_ORIG, T_INT), "Suscripciones de Coolify Cloud (upstream)", c("app/Actions/Stripe")],
        ["Hetzner · Vultr · DO", tags(T_ORIG, T_INT), "Creación de servidores por API", c("HetznerService")],
        ["ip-api.com · wa.me", tags(T_OWN, T_INT), "Región por IP; enlaces de WhatsApp", c("get_odoo_regions")],
        ["S3 compatible", tags(T_ORIG, T_INT), "Destino de backups", c("S3Storage")],
    ]
    book.add("Personalizaciones e integraciones", "Personalizaciones propias e integraciones externas", f"""
<div class="two" style="height:100%">
  <div>{panel('Dominios propios (morado)', table(['Dominio', 'Tipo', 'Qué aporta', 'Evidencia'], domains, 'tight', ['18%', '20%', '32%', '30%']))}</div>
  <div class="stack">
    {panel('Integraciones (verde = externo)', table(['Servicio', 'Tipo', 'Uso', 'Evidencia'], integ, 'tight', ['20%', '22%', '33%', '25%']))}
    <div class="callout risk"><b>Corrección de atribución:</b> la auditoría ({c('audit_events')}), los imports de BD, los backups de volúmenes, el secret manager
    y los integration tokens <b>no son nuestros</b>: llegaron con Coolify 4.4 y están sin commit en el árbol. De las {ev['routes']['api']['added']} rutas API nuevas, solo las 7 de
    {c('/projects/{uuid}/odoo*')} son propias.</div>
  </div>
</div>""", state="actual")

    # ------------------------------------------------------------------ 6. Capacidades reales de Coolify
    caps = [
        ["Despliegue", "Cola por app, build en servidor dedicado, registry, rolling update con health check, cancelación", "Sin blue-green, canary ni pausa; rolling se omite con puertos fijos, nombre fijo, PR o IP propia; Swarm sin health gating", c("ApplicationDeploymentJob::rolling_update")],
        ["Rollback", "Vuelve a una imagen anterior conservada", "No restaura variables ni configuración; depende de " + c("docker_images_to_keep"), c("Rollback::rollbackImage")],
        ["Git", "GitHub App con HMAC, GitLab con secreto, deploy keys, previews de PR, watch paths", "Bitbucket/Gitea solo manual; sin espera de CI", c("Webhook\\Github::normal")],
        ["Variables", "Cifradas, build/runtime, compartidas por equipo/proyecto/entorno/servidor, magic SERVICE_*", "Sin referencias tipadas a otros servicios; " + c("{{server.*}}") + " no resuelto en el helper; sin cambios en revisión", c("get_real_environment_variables_internal()")],
        ["Proxy y dominios", "Traefik o Caddy por servidor, ACME, wildcard, sslip", "Un proxy por servidor; sin CDN ni balanceo entre servidores", c("ProxyTypes") + " " + c("StartProxy")],
        ["Redes", "Red Docker por destino; DNS interno por nombre; proxy TCP para BD pública", "Sin red privada entre servidores; túnel Cloudflare solo para SSH", c("StandaloneDocker") + " " + c("StartDatabaseProxy")],
        ["Datos", "9 motores, backups programados a S3, import, backups de volúmenes", "Sin PITR, réplicas, HA ni migración de volúmenes entre servidores", c("DatabaseBackupJob") + " " + c("VolumeBackupJob")],
        ["Observabilidad", "Sentinel (CPU/RAM/disco), estado de contenedores, notificaciones", "Sin logs centralizados ni retención; métricas por días; sin alertas por umbral de app", c("SentinelController") + " " + c("HasMetrics")],
        ["Multi-servidor", "Muchos servidores, build server, Swarm, destinos adicionales", "Sin placement automático, failover ni capacidad agregada", c("Server") + " " + c("SwarmDocker")],
        ["Acceso", "Equipos, 3 roles, 2FA, OAuth, tokens con abilities, MCP", "Sin roles por proyecto/entorno, sin SSO SAML, sin grupos de acceso", c("ProjectPolicy") + " " + c("Enums\\Role")],
    ]
    book.add("Capacidades reales de Coolify", "Capacidades reales del motor Coolify y sus límites verificados",
             table(["Área", "Lo que Coolify hace (verificado)", "Límite real", "Evidencia"],
                   [[f"<b>{a}</b>", b, f'<span class="cell-part">{l}</span>', e] for a, b, l, e in caps], "",
                   ["11%", "34%", "35%", "20%"]),
             state="actual", sub="Base para decidir qué se construye sobre Coolify y qué requiere desarrollo propio o infraestructura adicional.")

    # ------------------------------------------------------------------ 7. Railway
    rw = [
        ["Workspace con roles Admin · Member · Deployer", "ok", "Adaptar", "Mapear a Team; añadir rol Deployer (solo despliega)."],
        ["Roles por proyecto Owner · Editor · Viewer", "ok", "Adaptar", "Requiere ACL propia; hoy no existe."],
        ["Canvas con servicios, grupos y líneas de referencia", "ok", "Adaptar", "Frontend propio; datos desde modelos Coolify + layout propio."],
        ["Cambios en revisión (staged changes + diff → Deploy)", "ok", "Adaptar", "Tabla de borradores propia, aplicar con Actions."],
        ["Entornos duplicables, de PR, sincronizables", "part", "Parcial", "Clonación propia y previews de Coolify ya existen; falta sincronizar."],
        ["Variables " + c("${{Svc.VAR}}") + " y " + c("${{shared.KEY}}"), "ok", "Adaptar", "Resolver propio encima de las magic variables."],
        ["Variables selladas", "part", "Parcial", c("is_shown_once") + " es similar."],
        ["Estados Initializing → Building → Deploying → Active / Failed / Crashed", "ok", "Adaptar", "Mapear estados de la cola y del contenedor."],
        ["Health check en deploy + política de reinicio", "part", "Ya existe", "Health check y " + c("restart_count") + " en Coolify."],
        ["Rollback con imagen y variables", "ok", "Adaptar", "Snapshot propio de variables por deploy."],
        ["Pre-deploy command", "part", "Ya existe", c("pre_deployment_command") + " en Coolify."],
        ["Log Explorer y métricas con marcadores de deploy", "ok", "Adaptar", "Necesita Loki/Vector + TSDB."],
        ["Wait for CI · plantillas · CLI", "ok", "Adaptar", "Webhook check_suite; catálogo propio ya existe; CLI sobre API."],
        ["Red privada " + c("svc.railway.internal") + " sobre WireGuard", "no", "No ahora", "En un servidor ya hay DNS Docker; entre servidores exige malla."],
        ["Serverless (sleep), regiones, autoescalado", "no", "No encaja", "Requiere proxy con wake-up y flota propia."],
        ["Volúmenes migrables, BD con PITR y HA", "no", "No encaja", "Coolify no lo ofrece; desarrollo de infraestructura."],
        ["Config as code " + c("railway.json") + " (obsoleto desde 1 dic 2026) → IaC TypeScript", "part", "Evaluar", "Fase 6; exportar/importar proyecto primero."],
    ]
    cls = {"ok": "cell-ok", "part": "cell-part", "no": "cell-no"}
    book.add("Railway como referencia", "Railway como referencia de experiencia: qué adaptar y qué no encaja", f"""
<div class="grid" style="grid-template-columns:1.55fr 1fr">
  <div>{table(['Concepto de Railway', 'Encaje', 'Cómo lo traducimos'], [[a, f'<span class="{cls[k]}">{v}</span>', d] for a, k, v, d in rw], 'tight', ['45%', '13%', '42%'])}</div>
  <div class="side">
    {panel('Ideas de experiencia que sí trasladamos', ul([
        "El proyecto <b>es</b> un canvas: todo se abre desde el nodo del servicio.",
        "Ningún cambio se aplica sin pasar por <b>revisión + Deploy</b>.",
        "Las variables se <b>conectan</b> entre servicios por referencia, no se copian.",
        "Cada deploy muestra su <b>línea de estados</b> y sus logs en el mismo panel.",
        "Las métricas llevan <b>marcadores de deploy</b> para relacionar causa y efecto.",
        "Los entornos se <b>duplican</b> y se comparan; los PR crean entornos efímeros.",
        "Permisos <b>por proyecto</b> para invitar clientes sin exponer el equipo.",
    ]))}
    {panel('Lo que depende de infraestructura propia de Railway', ul([
        "Regiones globales, edge, autoescalado y facturación por uso.",
        "Red privada multi-región, serverless con wake-up, migración de volúmenes.",
        "BD gestionadas con PITR. Ninguna de estas existe en Coolify.",
    ]))}
    {panel('Fuente', '<p>Documentación pública de Railway (docs.railway.com), consultada durante esta auditoría. No se ejecutó Railway.</p>', grow=True)}
  </div>
</div>""", state="referencia", toc="Railway como referencia")

    # ------------------------------------------------------------------ 8. Arquitectura objetivo
    book.diagram("Arquitectura objetivo", "Diagrama 2 · Arquitectura objetivo", "futuro", F.target_architecture(), [
        "Usuario → Frontend propio → Backend de plataforma → Adaptador Coolify → Motor Coolify → Servidores.",
        "Gris = no existe. Morado = propio que ya existe y se conserva. Azul = Coolify sin cambios.",
        "El adaptador es la única pieza que conoce las clases de Coolify; el resto habla con contratos propios.",
    ], [
        "Reutiliza: " + c("Livewire\\Deployments\\Index") + ", " + c("app/Domain/Odoo") + ", " + c("GetOdooPlans") + ".",
        "Motor: " + c("app/Actions") + ", " + c("app/Jobs") + ", " + c("routes/api.php") + ", " + c("routes/channels.php") + ".",
        "Propuesta: " + c("app/Platform/*") + " (no existe).",
    ], [
        "Separar el backend en otro servicio duplica autenticación y latencia; hacerlo solo si se necesita escalar el producto aparte del motor.",
    ], sub="Recomendación: monolito modular ahora, con un límite claro que permita separar el adaptador más adelante.")

    resp = [
        ["Frontend de producto", "Canvas, workspace, deploys, variables, observabilidad, Cmd+K", "Livewire actual por pantallas; nueva vista canvas (Alpine + SVG o isla JS)", tags(T_PROP)],
        ["Backend de plataforma", "Workspaces, permisos por proyecto, borradores, grafo, referencias de variables, actividad", c("app/Platform/*") + " (nuevo) + dominios Odoo/GetOdoo existentes", tags(T_PROP, T_OWN)],
        ["Adaptador Coolify", "Traducir comandos a Actions/API; ingerir eventos; reconciliar estado", "Envolver " + c("queue_application_deployment") + ", " + c("StartService") + ", " + c("StartDatabase") + ", eventos *StatusChanged", tags(T_PROP)],
        ["Motor Coolify", "Build, deploy, proxy, SSH, backups, métricas, API", "Sin cambios funcionales; parches mínimos y documentados", tags(T_ORIG)],
    ]
    data = [
        ["En BD propia (tablas nuevas)", "workspaces, permisos por proyecto/entorno, layout del canvas, grupos, borradores y diff, snapshots de variables por deploy, actividad unificada, alertas, preferencias"],
        ["Solo en Coolify (fuente de verdad)", "servidores, destinos, aplicaciones, servicios, BD, variables, dominios, cola de despliegues, backups"],
        ["Derivado / caché", "estado agregado del proyecto, contadores, últimos deploys: se recalcula desde eventos, nunca se edita"],
    ]
    book.add("Arquitectura objetivo", "Arquitectura objetivo · responsabilidades, datos y compatibilidad", f"""
<div class="grid" style="grid-template-columns:1.3fr 1fr">
  <div class="stack">
    {panel('Responsabilidad de cada capa', table(['Capa', 'Responsable de', 'Implementación', 'Estado'], resp, 'tight', ['17%', '35%', '33%', '15%']))}
    {panel('Qué datos viven dónde (evitar duplicación)', table(['Dónde', 'Datos'], data, 'tight', ['26%', '74%']))}
    <div class="callout">Regla anti-duplicación: el backend propio guarda <b>solo lo que Coolify no modela</b> y referencia los recursos de Coolify por <b>UUID</b>.
    Nunca copia nombres, variables ni estados como fuente propia.</div>
  </div>
  <div class="side">
    {panel('Sincronización, tiempo real y eventos', ul([
        "Comandos: el adaptador llama a Actions en proceso (hoy) o a la API (si se separa).",
        "Eventos: suscripción a " + c("ApplicationStatusChanged") + ", " + c("ServiceStatusChanged") + ", " + c("DatabaseStatusChanged") + " y al estado de la cola.",
        "Reconciliador cada pocos minutos: compara UUIDs y detecta recursos creados o borrados fuera del producto.",
        "UI en vivo con Reverb en " + c("team.{id}") + ", ya existente.",
    ]))}
    {panel('Compatibilidad con futuras versiones de Coolify', ul([
        "Fijar el motor a una versión etiquetada; actualizar con una rama de sincronización y la suite de tests.",
        "Contratos del adaptador con tests propios: si Coolify cambia una firma, falla el test del adaptador y no la UI.",
        "Ningún archivo propio dentro de " + c("app/Actions") + " o " + c("app/Jobs") + " de Coolify; las modificaciones al motor se listan y se reducen.",
    ]))}
    {panel('Endpoints que faltan en la API de Coolify', ul([
        "Grafo de un proyecto con relaciones entre servicios.",
        "Historial de deploys por entorno con filtros y paginación por cursor.",
        "Logs de runtime con rango de tiempo (hoy solo por SSH).",
        "Snapshot de variables por deploy y rollback con variables.",
        "Webhooks salientes de eventos para un backend separado. " + tags(T_PEND),
    ]))}
    {panel('Desarrollo independiente obligatorio', ul([
        "Workspaces y ACL por proyecto, canvas, borradores, referencias de variables, actividad unificada, logs centralizados y alertas.",
    ]), grow=True)}
  </div>
</div>""", state="futuro", toc="")

    # ------------------------------------------------------------------ Experiencia objetivo 3.1 – 3.12
    exp1 = [
        ["3.1 Workspace", "Team con 3 roles y cuotas por miembro", "Workspace = Team con identidad, plan, miembros, roles Admin/Member/Deployer, invitaciones por proyecto", "Backend propio"],
        ["3.2 Proyectos", "Lista, entorno production automático, alta con servicio", "Tarjetas con estado, último deploy, coste; el proyecto abre el canvas", "Frontend propio"],
        ["3.3 Entornos", "production/staging/development; clonación (propio)", "Pestañas por entorno, duplicar, comparar, entornos de PR efímeros, sincronizar cambios", "Mixto"],
        ["3.4 Servicios", "Apps, servicios y BD como recursos separados", "Un único tipo \u00abservicio\u00bb en la UI con origen (repo, imagen, plantilla, BD) y tipo web / worker / cron", "Frontend propio"],
    ]
    exp2 = [
        ["3.5 Despliegues", "Cola, estados, logs, cancelación, rollback por imagen", "Git → Commit → Build → Imagen → Deploy → Health check → Running, con línea de estados, historial por entorno, rollback con variables y estrategia rolling/recreate visible", "Mixto"],
        ["3.6 Variables", "Recurso + compartidas (equipo, proyecto, entorno, servidor)", "Workspace → proyecto → entorno → servicio; prioridad del más específico; referencias " + c("${{svc.VAR}}") + "; editor RAW; cambios en revisión", "Backend propio"],
        ["3.7 Redes y dominios", "Labels Traefik/Caddy, ACME, subdominios Odoo", "Panel de red por servicio: dominio, puerto, privado/público, estado del certificado", "Frontend propio"],
        ["3.8 BD y volúmenes", "9 motores, backups S3, imports, volúmenes", "Nodo de BD con conexión copiable como referencia, backups visibles, restaurar en entorno nuevo", "Mixto"],
    ]
    exp3 = [
        ["3.9 Canvas", "No existe", "Grafo del entorno con servicios, BD, volúmenes, dominios y relaciones; drag, grupos, estado en vivo", "Desarrollo propio"],
        ["3.10 Observabilidad", "Sentinel (días), logs por SSH, notificaciones", "Logs centralizados con búsqueda, métricas con marcadores de deploy, alertas", "Infra extra (Loki, TSDB)"],
        ["3.11 Multi-servidor", "Servidores, build server, Swarm, destinos", "Selector de servidor por servicio con capacidad visible; sin failover automático (límite real)", "Mixto"],
        ["3.12 Usuarios y seguridad", "Roles de equipo, 2FA, OAuth, tokens, auditoría 4.4", "Roles por proyecto/entorno, auditoría unificada, 2FA obligatorio por workspace, tokens por proyecto", "Backend propio"],
    ]
    h = ["Área", "Hoy (verificado)", "Experiencia objetivo " + tag(T_PROP), "Depende de"]
    w = ["12%", "28%", "45%", "15%"]
    book.add("Experiencia objetivo", "Experiencia objetivo · 3.1 a 3.12", f"""
<div class="stack">
  {table(h, exp1 + exp2 + exp3, '', w)}
  <div class="callout blue">Precedencia de variables propuesta: <b>servicio</b> &gt; <b>entorno</b> &gt; <b>proyecto</b> &gt; <b>workspace</b>.
  Hoy Coolify solo resuelve por referencia explícita ({c('{{team.KEY}}')}, {c('{{project.KEY}}')}, {c('{{environment.KEY}}')}); no hay herencia implícita.</div>
</div>""", state="comparacion", sub="Columna «Hoy» = estado actual verificado. Columna «Experiencia objetivo» = propuesta.")

    # ------------------------------------------------------------------ 9. Atlas actual
    S9 = "Atlas actual"
    book.diagram(S9, "Diagrama 3 · Navegación", "comparacion", C.navigation(), [
        "Izquierda: el menú lateral actual. Derecha: navegación propuesta por contexto.",
        "Hoy, ver un deploy y sus logs exige saltar entre Proyecto, Recurso y Deploys.",
        "En la propuesta, el canvas concentra el trabajo diario y la infraestructura pasa a un apartado propio.",
    ], [c("resources/views/components/navbar.blade.php"), c("routes/web.php") + " (" + c("deployments.index") + ")", c("Livewire\\GlobalSearch")],
        ["El rediseño de navegación no cambia permisos: la ACL por proyecto debe llegar antes o a la vez."], toc="")
    book.diagram(S9, "Diagrama 5 · Creación de proyectos", "actual", C.project_creation(), [
        "La cuota se valida antes de crear; el entorno production se crea siempre.",
        "Elegir Odoo abre el asistente de versión, GitHub o migración; las plantillas abren la configuración sin arrancar.",
    ], [c("Livewire\\Project\\AddEmpty"), c("AdminCreationQuota::assertWithinLimits"), c("Project::create"), c("ServiceTemplateCatalog::composeFor"), c("LaunchOdooProjectJob"), c("environments.role")],
        ["El arranque es explícito: un proyecto puede quedar creado sin servicios en ejecución."])
    book.diagram(S9, "Diagrama 6 · Entornos", "actual", C.environments(), [
        "Crear entorno valida la cuota de staging. Clonar exige al menos un servicio.",
        "Odoo copia BD + filestore y neutraliza; el resto copia definiciones de servicio.",
        "Borrar production borra también sus staging.",
    ], [c("Livewire\\Project\\Show"), c("Project::createCatalogLaunchEnvironment"), c("CloneOdooStagingJob"), c("CloneEnvironmentStagingJob"), c("odoo_environment_branches")],
        ["Sin sincronizar cambios entre entornos ni comparar diferencias.", "Volúmenes huérfanos si no se marcan al borrar."])
    book.diagram(S9, "Diagrama 7 · Servicios", "actual", C.services(), [
        "Coolify tiene tres modelos distintos: Application, Service (compose) y Standalone* (BD).",
        "No existe un tipo worker: es una app o servicio sin dominio. Los cron son ScheduledTask dentro de un contenedor.",
    ], [c("Livewire\\Project\\New\\Select"), c("app/Models/Application.php"), c("app/Models/Service.php"), c("app/Models/Standalone*.php"), c("ScheduledTask")],
        ["Tres modelos obligan a la UI a tres flujos de configuración diferentes."])
    book.diagram(S9, "Diagrama 8 · Git", "actual", C.git_flow(), [
        "GitHub usa App por manifiesto y webhook firmado (HMAC).",
        "Si el repositorio es de addons Odoo, el push no hace build: reclona addons y reinicia Odoo.",
        "Los PR crean previews si están habilitados.",
    ], [c("POST /source/github/events") + " → " + c("Webhook\\Github::normal"), c("Application::isDeployable()"), c("SyncOdooAddonsJob"), c("OdooGit::queueBranchUpdate"), c("ApplicationPreview")],
        ["Bitbucket y Gitea: solo webhook manual.", "No hay espera de CI antes de desplegar."])
    book.diagram(S9, "Diagrama 9 · Despliegue", "actual", C.deploy_flow(), [
        "La cola deduplica commits y respeta el límite por servidor.",
        "Rolling update: arranca el nuevo, espera health check, para el viejo.",
        "Si no hay rolling posible, se para y reemplaza (con corte).",
    ], [c("queue_application_deployment()"), c("ApplicationDeploymentJob::rolling_update"), c("::health_check"), c("::stop_running_container"), c("DeployController::cancel_deployment"), c("application_deployment_queues")],
        ["Sin blue-green, canary ni ventana de mantenimiento.", "Swarm despliega sin esperar health check."])
    book.diagram(S9, "Diagrama 10 · Variables", "actual", C.variables(), [
        "Las variables del recurso pueden referenciar compartidas con {{team|project|environment.KEY}}.",
        "En build gana el usuario sobre las de Coolify; en runtime se escribe el .env del contenedor.",
    ], [c("EnvironmentVariable"), c("SharedEnvironmentVariable"), c("get_real_environment_variables_internal()"), c("generate_buildtime_environment_variables"), c("save_runtime_environment_variables")],
        [c("{{server.*}}") + " existe en el modelo pero el helper no lo resuelve. " + tag(T_PEND), "Sin referencias entre servicios ni revisión de cambios."])
    book.diagram(S9, "Diagrama 11 · Dominios y SSL", "actual", C.domains(), [
        "El dominio se convierte en labels del proxy del servidor; ACME emite el certificado.",
        "Odoo usa subdominios propios por entorno y no da por terminado el lanzamiento sin HTTPS real.",
    ], [c("fqdnLabelsForTraefik"), c("tlsCertResolverForHost"), c("OdooDomains::projectHost"), c("connectExternalProxyToNetworks()"), c("server_settings.wildcard_domain")],
        ["Con EasyPanel delante, un fallo deja el certificado por defecto y un 404."])
    book.diagram(S9, "Diagrama 12 · Redes", "actual", C.networking(), [
        "Dentro de un servidor, los contenedores se ven por DNS de Docker.",
        "Entre servidores no hay red privada: solo IP pública o túnel manual.",
    ], [c("StandaloneDocker::network"), c("ConnectProxyToNetworksJob"), c("StartDatabaseProxy"), c("connect_to_docker_network"), c("is_cloudflare_tunnel")],
        ["Exponer una BD a Internet es la única vía para consumirla desde otro servidor."])
    book.diagram(S9, "Diagrama 13 · Bases de datos y volúmenes", "actual", C.data_volumes(), [
        "Los backups programados hacen dump por SSH y suben a S3.",
        "Las pilas Odoo con plan generan un zip propio con filestore.",
    ], [c("StartDatabase"), c("ScheduledDatabaseBackup"), c("DatabaseBackupJob"), c("CreateOdooBackupJob"), c("VolumeBackupJob"), c("scheduled_database_backup_executions")],
        ["Sin PITR, réplicas ni HA.", "Conectar BD y app es copiar una URL a mano."])
    book.diagram(S9, "Diagrama 14 · Logs y monitoreo", "actual", C.logs_monitoring(), [
        "Sentinel empuja métricas; ServerCheckJob sondea. Ambos alimentan el agregador de estado.",
        "Los cambios se difunden por Reverb y disparan notificaciones.",
    ], [c("POST /api/v1/sentinel/push"), c("ServerCheckJob"), c("PushServerUpdateJob"), c("ContainerStatusAggregator"), c("HighDiskUsage"), c("GetLogs")],
        ["Logs de runtime sin retención ni búsqueda.", "Sin alertas por latencia o errores de app."])
    book.diagram(S9, "Diagrama 15 · Multi-servidor", "actual", C.multi_server(), [
        "Agregar servidores depende de " + c("can_add_servers") + " (propio). Se valida SSH, SO y Docker.",
        "El destino se elige a mano al crear el recurso.",
    ], [c("ServerPolicy::create"), c("Server\\New\\ByIp"), c("ValidateServer::run"), c("InstallDocker"), c("AdditionalCloudCatalog"), c("ByGetOdoo")],
        ["Sin placement automático, failover, red privada ni capacidad agregada."])
    book.diagram(S9, "Diagrama 16 · Usuarios y roles", "actual", C.users_roles(), [
        "El rol del equipo decide; las cuotas y abilities propias limitan a admin y member.",
        "No hay decisión por proyecto o entorno: todo miembro ve todos los proyectos del equipo.",
    ], [c("FortifyServiceProvider"), c("Enums\\Role"), c("AuthServiceProvider") + f" ({counts['policies']['total']} policies)", c("ProjectPolicy::view"), c("team_user.odoo_abilities"), c("ApiAbility")],
        ["Invitar a un cliente a un solo proyecto no es posible hoy."])
    book.diagram(S9, "Diagrama 17 · Errores y recuperación", "actual", C.errors_recovery(), [
        "Cada tipo de fallo tiene su propia vía de recuperación y su propia evidencia.",
        "No existe una vista única de incidentes.",
    ], [c("ApplicationDeploymentJob::failDeployment"), c("Rollback::rollbackImage"), c("stoppedAfterRestartLimit()"), c("server_unreachable"), c("BackupFailed")],
        ["Rollback sin variables; servidor caído sin failover."])

    # ------------------------------------------------------------------ 10. Atlas futuro
    S10 = "Atlas futuro"
    book.diagram(S10, "Diagrama 4 · Workspace", "futuro", F.workspace_flow(), [
        "El workspace se apoya en Team; añade roles de proyecto e invitaciones acotadas.",
    ], ["Base: " + c("Team") + ", " + c("team_user") + ", " + c("TeamInvitation") + ".", "Nuevo: tablas de workspace y permisos (propuesta)."],
        ["Migrar equipos existentes sin perder cuotas propias."], toc="")
    book.diagram(S10, "Diagrama 9b · Despliegue objetivo", "futuro", F.target_deploy_flow(), [
        "Cambios en revisión, espera de CI, snapshot de variables y rollback completo.",
    ], ["Se apoya en " + c("queue_application_deployment()") + " y " + c("rolling_update") + "."],
        ["Blue-green real exige dos contenedores con puertos distintos y cambio de labels."])
    book.diagram(S10, "Diagrama 10b · Variables objetivo", "futuro", F.target_variables(), [
        "Herencia workspace → proyecto → entorno → servicio y referencias entre servicios.",
    ], ["Se apoya en " + c("SharedEnvironmentVariable") + " y en las magic variables " + c("SERVICE_*") + "."],
        ["El resolver debe ejecutarse antes de escribir el .env para no romper Coolify."])
    book.diagram(S10, "Diagrama 14b · Observabilidad objetivo", "futuro", F.target_observability(), [
        "Vector/Loki y una TSDB desplegados como servicios de Coolify; consultas desde nuestro backend.",
    ], ["Fuente actual: Sentinel y " + c("ContainerStatusAggregator") + "."],
        ["Coste de almacenamiento y retención por plan."])
    book.diagram(S10, "Diagrama 18 · Canvas", "futuro", F.canvas_flow(), [
        "El canvas lee el grafo del adaptador y guarda solo layout y grupos.",
    ], ["Nodos desde " + c("Application") + ", " + c("Service") + ", " + c("Standalone*") + ".", "Layout en BD propia (propuesta)."],
        ["Las relaciones no existen en Coolify: se infieren de variables y redes."])
    book.diagram(S10, "Diagrama 19 · Sincronización backend ↔ Coolify", "futuro", F.sync_flow(), [
        "Comandos hacia el motor, eventos de vuelta, reconciliador para el drift.",
    ], ["Eventos actuales: " + c("*StatusChanged") + " por " + c("team.{id}") + "."],
        ["Sin reconciliador, los cambios hechos por API o MCP no se reflejan en el canvas."])
    book.diagram(S10, "Diagrama 20 · Comparación actual vs objetivo", "comparacion", C.comparison(), [
        "El mismo trabajo, desplegar un cambio y verificarlo, hoy y en la propuesta.",
    ], ["Pantallas actuales: " + c("Project\\Show") + ", " + c("Resource\\Index") + ", " + c("Deployments\\Index") + "."],
        ["La mejora depende del canvas y del panel de servicio."])

    # ------------------------------------------------------------------ 11. Comparativa
    Y, P, N, Pr = "cell-ok", "cell-part", "cell-no", "cell-prop"
    matrix = [
        ["Workspaces", (P, "Team, 3 roles, cuotas propias"), (Y, "Workspace, Admin/Member/Deployer"), (Pr, "Workspace sobre Team + rol Deployer")],
        ["Projects", (Y, "Proyectos con entornos"), (Y, "Proyecto = canvas"), (Pr, "Tarjetas con estado; abre canvas")],
        ["Environments", (P, "Roles y clonación propios; previews PR"), (Y, "Duplicar, PR, sync, focused"), (Pr, "Comparar y sincronizar entornos")],
        ["Services", (P, "3 modelos separados"), (Y, "Servicio único"), (Pr, "Tipo único en UI; web/worker/cron")],
        ["Deployments", (P, "Cola, rolling con health check, rollback por imagen"), (Y, "Estados, revisión, rollback con variables"), (Pr, "Revisión + snapshot de variables")],
        ["Variables", (P, "Compartidas por referencia explícita"), (Y, "Referencias entre servicios, selladas"), (Pr, "Herencia + referencias tipadas")],
        ["Domains", (Y, "Traefik/Caddy + ACME, wildcard"), (Y, "Dominios + TCP proxy"), (Pr, "Panel de red con estado SSL")],
        ["Databases", (P, "9 motores, backups S3"), (Y, "Plantillas, backups, PITR"), (Pr, "Conexión como referencia; restore a entorno")],
        ["Volumes", (P, "Volúmenes Docker + backup 4.4"), (Y, "1 por servicio, backups"), (Pr, "Visibles en canvas; sin migración")],
        ["Canvas", (N, "No existe"), (Y, "Canvas con grupos"), (Pr, "Canvas propio")],
        ["Observability", (P, "Sentinel corto, logs por SSH"), (Y, "Log Explorer, métricas, monitores"), (Pr, "Loki/TSDB + alertas")],
        ["Multi-server", (P, "Manual, Swarm, build server"), (Y, "Regiones gestionadas"), (P, "Selector con capacidad; sin failover")],
        ["Usuarios", (P, "Roles de equipo, 2FA, OAuth"), (Y, "Roles de proyecto, SSO, auditoría"), (Pr, "ACL por proyecto + auditoría unificada")],
    ]
    rows = [[f"<b>{a}</b>"] + [f'<span class="{k}">●</span> {escape(t)}' for k, t in (b, r, o)] for a, b, r, o in matrix]
    book.add("Comparativa", "Matriz comparativa · Coolify actual · Railway · nuestra plataforma", f"""
<div class="stack">
  {table(['Área', 'Coolify actual (verificado en este repo)', 'Railway (documentación pública)', 'Nuestra plataforma ' + tag(T_PROP)], rows, '', ['12%', '30%', '28%', '30%'])}
  <div class="small"><span class="cell-ok">● completo</span> &nbsp; <span class="cell-part">● parcial</span> &nbsp; <span class="cell-no">● no existe</span> &nbsp;
  <span class="cell-prop">● propuesta</span></div>
</div>""", state="comparacion")

    # ------------------------------------------------------------------ 12. Canvas
    book.add("Canvas visual", "Canvas visual · cómo se vería y de dónde sale cada dato", f"""
<div class="grid" style="grid-template-columns:1.5fr 1fr">
  <div class="diagram" style="padding:4mm">{canvas_mock()}</div>
  <div class="side">
    {panel('Qué muestra', ul([
        "Nodos: servicios, BD, volúmenes y dominios del entorno activo.",
        "Aristas: referencias de variables y conexiones de red.",
        "Estado en vivo por nodo (running, building, failed) con el último deploy.",
        "Banner de <b>cambios en revisión</b> con diff y botón Deploy.",
    ]))}
    {panel('Origen de los datos', ul([
        "Nodos y estados: modelos de Coolify (" + c("Application") + ", " + c("Service") + ", " + c("Standalone*") + ") por el adaptador.",
        "Posición, grupos y notas: BD propia (propuesta).",
        "Aristas: inferidas de variables que apuntan a otro servicio y de redes Docker compartidas.",
    ]))}
    {panel('Riesgos', ul([
        "Proyectos con decenas de servicios: agrupar y colapsar.",
        "Coolify no modela dependencias; si se infieren mal, el usuario debe poder corregirlas.",
    ]), grow=True)}
  </div>
</div>""", state="futuro", sub="Maqueta conceptual. Nada de esta página existe en el repositorio.")

    # ------------------------------------------------------------------ 13. Multi-server y observabilidad
    obs = [
        ["Métricas CPU/RAM/disco por servidor y contenedor", tags(T_ORIG), "Sentinel", "Retención larga y comparación entre deploys", "TSDB (VictoriaMetrics/Prometheus)"],
        ["Estado de contenedores en vivo", tags(T_44), "Agregador + Reverb", "—", "—"],
        ["Logs de deploy", tags(T_ORIG), "Fila de la cola", "Búsqueda entre deploys", "—"],
        ["Logs de runtime", tags(T_ORIG), "SSH al abrir", "Retención, búsqueda, filtros", "Vector + Loki"],
        ["Alertas de servidor (disco, caída)", tags(T_ORIG), "Notificaciones", "Alertas de app (latencia, 5xx)", "Métricas de proxy + reglas"],
        ["Trazas / APM", tags(T_PROP), "No existe", "Todo", "OpenTelemetry collector"],
    ]
    ms = [
        ["Agregar servidores SSH, validar e instalar Docker", tags(T_ORIG), "Sí"],
        ["Build server dedicado y registry", tags(T_ORIG), "Sí"],
        ["Misma app en varios servidores (destinos adicionales)", tags(T_ORIG), "Sí, manual, sin balanceo"],
        ["Swarm manager/worker", tags(T_ORIG), "Sí, sin health gating"],
        ["Placement automático por capacidad", tags(T_PROP), "No"],
        ["Failover y red privada entre servidores", tags(T_PROP), "No; requiere malla (WireGuard/Tailscale)"],
    ]
    book.add("Multi-server y observabilidad", "Multi-servidor y observabilidad: qué da Coolify y qué requiere infraestructura adicional", f"""
<div class="grid" style="grid-template-columns:1.35fr 1fr">
  {panel('Observabilidad', table(['Señal', 'Origen', 'Hoy', 'Falta', 'Infra adicional'], obs, 'tight', ['28%', '18%', '16%', '20%', '18%']))}
  <div class="stack">
    {panel('Multi-servidor', table(['Capacidad', 'Origen', 'Estado real'], ms, 'tight', ['50%', '25%', '25%']))}
    <div class="callout risk">Límite estructural: Coolify orquesta servidores <b>independientes</b>. Un servidor caído deja sus recursos sin control hasta que vuelve
    ({c('server_unreachable')}); nada se reprograma en otro servidor.</div>
    <div class="callout blue">Recomendación: desplegar Loki, Vector y la TSDB <b>como servicios de Coolify</b> en un servidor de observabilidad; nuestro backend solo consulta.</div>
  </div>
</div>""", state="actual")

    # ------------------------------------------------------------------ 14. Riesgos
    risks = [
        ["Deriva del upstream", "Alta", f"{up['commits_not_merged']} commits 4.4 sin fusionar; sincronización parcial sin commit ({wt['M']} M, {wt['D']} D, {wt['??']} sin seguimiento)", "Rama de sincronización, commit atómico, suite completa"],
        ["Acoplamiento al motor", "Alta", f"Capa propia llama a Actions directamente; {modified('livewire')} componentes Livewire de Coolify modificados", "Adaptador interno; reducir modificaciones en vistas de Coolify"],
        ["Permisos insuficientes", "Alta", c("ProjectPolicy::view") + " solo comprueba el equipo", "ACL por proyecto/entorno + tests de acceso cruzado"],
        ["Rollback incompleto", "Media", "No restaura variables", "Snapshot de variables por deploy"],
        ["Downtime en deploy", "Media", "Rolling desactivado con puertos fijos o nombre fijo", "Mostrar la estrategia antes de desplegar; blue-green propio"],
        ["Pérdida de logs", "Media", "Runtime sin retención", "Loki + Vector"],
        ["Servidor único", "Media", "Sin failover ni red privada", "Backups probados; malla privada en fase 6"],
        ["Variables " + c("{{server.*}}"), "Baja", "Modelo sí, helper no " + tag(T_PEND), "Test que lo confirme y corrección en upstream"],
        ["Tests preexistentes fallando", "Baja", c("SidebarNavigationMarkupTest") + ", " + c("MobileResourceMenuTest"), "Arreglar antes del rediseño de navegación"],
        ["Dependencia de terceros", "Media", "Wompi, N1co, Hetzner, ip-api, Let's Encrypt", "Timeouts, reintentos, avisos GPSH"],
    ]
    book.add("Riesgos y limitaciones", "Riesgos y limitaciones",
             table(["Riesgo", "Severidad", "Evidencia", "Mitigación propuesta"],
                   [[f"<b>{a}</b>", f'<span class="{ {"Alta": N, "Media": P, "Baja": Pr}[s] }">{s}</span>', e, m] for a, s, e, m in risks], "",
                   ["17%", "9%", "42%", "32%"]), state="actual")

    # ------------------------------------------------------------------ 15. Roadmap
    book.diagram("Roadmap", "Roadmap · seis fases y dependencias", "futuro", roadmap_svg(), [
        "Cada fase depende de la anterior; la fase 1 es obligatoria antes de cualquier rediseño.",
        "Morado = construido sobre lo propio existente. Gris = desarrollo nuevo. Verde = infraestructura externa.",
    ], ["Detalle por iniciativa en la página siguiente."], ["Saltar la fase 1 multiplica el coste de cada actualización de Coolify."], toc="")
    init = [
        ["1 Foundation", "Cerrar sync Coolify 4.4", "Parcial sin commit", "Árbol limpio, versión fijada", "—", "Alta", "P0", "Coolify"],
        ["1 Foundation", "Adaptador interno Coolify", "No existe", "Una sola puerta al motor con tests", "Sync 4.4", "Media", "P0", "Propio"],
        ["1 Foundation", "ACL por proyecto/entorno", "Solo equipo", "Invitar clientes a un proyecto", "Adaptador", "Media", "P0", "Propio"],
        ["2 Developer Exp.", "Línea de estados + historial por entorno", "Deploys globales (propio)", "Estados tipo Railway", "Adaptador", "Baja", "P1", "Propio"],
        ["2 Developer Exp.", "Cambios en revisión + diff", "No existe", "Nada se aplica sin Deploy", "Adaptador", "Media", "P1", "Propio"],
        ["2 Developer Exp.", "Variables con herencia y referencias", "Compartidas explícitas", "Precedencia + " + c("${{svc.VAR}}"), "Revisión", "Alta", "P1", "Propio"],
        ["2 Developer Exp.", "Rollback con variables · Wait for CI", "Rollback por imagen", "Rollback completo", "Snapshots", "Media", "P2", "Propio + Coolify"],
        ["3 Infrastructure", "Panel de red, dominios y SSL", "Labels + ACME", "Estado del certificado visible", "Adaptador", "Baja", "P2", "Coolify"],
        ["3 Infrastructure", "BD y volúmenes en el servicio", "Pantallas separadas", "Conectar por referencia, restore", "Variables", "Media", "P2", "Mixto"],
        ["4 Visual", "Canvas del entorno", "No existe", "Proyecto = grafo", "Adaptador, variables", "Alta", "P1", "Propio"],
        ["4 Visual", "Navegación por contexto + Cmd+K", "Menú lateral", "Menos saltos", "Canvas", "Media", "P2", "Propio"],
        ["5 Observability", "Logs centralizados", "SSH sin retención", "Búsqueda y retención", "Servidor de observabilidad", "Alta", "P2", "Infra"],
        ["5 Observability", "Métricas con marcadores · alertas", "Sentinel corto", "Causa-efecto visible", "TSDB", "Media", "P2", "Infra + propio"],
        ["6 Advanced", "Placement, red privada, IaC, CLI", "Manual", "Plataforma multi-servidor", "Fases 1–5", "Alta", "P3", "Propio + infra"],
    ]
    book.add("Roadmap", "Roadmap · iniciativas, dependencias y prioridad",
             table(["Fase", "Iniciativa", "Estado actual", "Resultado esperado", "Depende de", "Complejidad", "Prioridad", "Coolify / propio"],
                   init, "tight", ["10%", "19%", "14%", "17%", "13%", "8%", "7%", "12%"]),
             state="futuro", sub="Prioridad P0 = bloqueante. Complejidad: baja, media, alta.", toc="")

    # ------------------------------------------------------------------ 16. Conclusiones
    book.add("Conclusiones y decisiones", "Conclusiones y decisiones recomendadas", f"""
<div class="three big" style="height:100%">
  {panel('Conclusiones', ul([
      "La plataforma ya tiene un diferencial claro (Odoo + GetOdoo) construido sobre un motor sólido.",
      "La experiencia de producto (canvas, revisión, variables conectadas) no existe y no la dará Coolify: es desarrollo propio.",
      "El riesgo principal no es funcional sino de mantenimiento: deriva del upstream y acoplamiento directo.",
      "Railway sirve como referencia de UX, no de infraestructura.",
  ]))}
  {panel('Decisiones a tomar', ul([
      "<b>D1.</b> Aprobar la fase 1 (sync 4.4, adaptador, ACL) como bloqueante.",
      "<b>D2.</b> Monolito modular ahora; revisar la separación del backend al terminar la fase 4.",
      "<b>D3.</b> Aceptar que serverless, regiones y PITR quedan fuera del alcance.",
      "<b>D4.</b> Elegir la pila de observabilidad (Loki + Vector + TSDB) y su coste por plan.",
      "<b>D5.</b> Definir la tecnología del canvas (SVG + Alpine o isla JS) en la fase 4.",
  ]))}
  {panel('Pendientes de verificación', ul([
      tag(T_PEND) + " Resolución de " + c("{{server.*}}") + " en runtime.",
      tag(T_PEND) + " Retención real de Sentinel por servidor en producción.",
      tag(T_PEND) + " Webhooks salientes de eventos en Coolify 4.4.",
      tag(T_PEND) + " Comportamiento de la sincronización 4.4 sin commit en producción.",
  ]), grow=True)}
</div>""")

    # ------------------------------------------------------------------ Trazabilidad
    kf = sorted(ev["key_files"].items())
    label = {"ORIGINAL": T_ORIG, "MODIFICADO": T_MOD, "PROPIO": T_OWN, "UPSTREAM_4.4": T_44, "UPSTREAM_4.4_MODIFICADO": T_44}
    third = (len(kf) + 2) // 3

    def kf_table(items):
        return table(["Archivo", "Clasificación"], [[c(p.removeprefix("app/")), tag(label.get(v, T_PEND)) + (tag(T_MOD) if v == "UPSTREAM_4.4_MODIFICADO" else "")]
                                                     for p, v in items], "tight dense", ["66%", "34%"])
    book.add("Trazabilidad", "Anexo A · Clasificación de archivos clave", f'<div class="three">{kf_table(kf[:third])}{kf_table(kf[third:2 * third])}{kf_table(kf[2 * third:])}</div>',
             state="actual", sub=f"Comparación contra BASE {ev['base']['sha'][:9]} y UPSTREAM {up['ref']}. Rutas relativas a app/ salvo indicación.", toc="Trazabilidad")

    area_rows = []
    names = {"models": "Modelos", "jobs": "Jobs", "actions": "Actions", "livewire": "Livewire", "api_controllers": "Controladores API",
             "policies": "Policies", "events": "Eventos", "support": "Support", "domain": "Domain", "services": "Services",
             "migrations": "Migraciones", "views": "Vistas Blade", "feature_tests": "Tests feature", "unit_tests": "Tests unit"}
    for key, name in names.items():
        a = counts.get(key, {})
        others = sum(v for k, v in a.items() if k not in ("total", "ORIGINAL"))
        area_rows.append([name, str(a.get("total", 0)), str(a.get("ORIGINAL", a.get("total", 0) - others)), str(a.get("UPSTREAM_4.4", 0)),
                          str(a.get("MODIFICADO", 0) + a.get("UPSTREAM_4.4_MODIFICADO", 0)), f"<b>{a.get('PROPIO', 0)}</b>"])
    api_added = ev["routes"]["api"]["added_paths"]
    api_rows = [[c(p), tag(T_OWN) if "odoo" in p else tag(T_44)] for p in api_added]
    book.add("Trazabilidad", "Anexo B · Recuento por área y rutas API añadidas", f"""
<div class="grid" style="grid-template-columns:1fr 1fr">
  {panel('Archivos por área y origen', table(['Área', 'Total', 'Original', 'Coolify 4.4', 'Modificados', 'Propios'], area_rows, 'tight'))}
  {panel(f'Rutas API añadidas desde la BASE ({len(api_added)})', table(['Ruta', 'Origen'], api_rows, 'tight', ['75%', '25%']))}
</div>""", state="actual", toc="")

    mig = ev["own_migrations"]
    cols = [mig[i::3] for i in range(3)]
    mig_html = "".join("<div>" + "".join(f"<div class='small' style='padding:.6mm 0'>{c(m)}</div>" for m in col) + "</div>" for col in cols)
    web_added = ev["routes"]["web"]["added_paths"]
    book.add("Trazabilidad", f"Anexo C · Migraciones propias ({len(mig)}) y rutas web añadidas ({len(web_added)})", f"""
<div class="grid" style="grid-template-columns:2.2fr 1fr">
  {panel('Migraciones propias (no existen en BASE ni en 4.4)', f'<div class="three" style="gap:3mm">{mig_html}</div>')}
  {panel('Rutas web añadidas', ''.join(f"<div class='small' style='padding:.6mm 0'>{c(p)}</div>" for p in web_added))}
</div>""", state="actual", toc="")

    # ------------------------------------------------------------------ Índice (se inserta tras la portada)
    insert_toc(book)


def insert_toc(book) -> None:
    rows = []
    last = None
    for index, page in enumerate(book.pages[1:], start=3):
        section = page["section"]
        if section != last:
            rows.append(f'<div class="h"><span>{escape(section)}</span><span>{index}</span></div>')
            last = section
        if page["toc"] and page["toc"] != section:
            rows.append(f"<div><span>{escape(page['toc'])}</span><span>{index}</span></div>")
    diagrams = [(p["title"], i + 1) for i, p in enumerate(book.pages, start=1) if p["title"].startswith("Diagrama")]
    drows = "".join(f"<div><span>{escape(t)}</span><span>{n}</span></div>" for t, n in diagrams)
    body = f"""
<div class="grid" style="grid-template-columns:1fr 1fr">
  <div class="toc" style="columns:1">{''.join(rows)}</div>
  <div class="toc" style="columns:1"><div class="h"><span>Índice de diagramas</span></div>{drows}</div>
</div>"""
    book.pages.insert(1, {"section": "Índice", "title": "Índice", "body": body, "sub": "", "state": None, "toc": "", "raw": False, "cls": ""})


def canvas_mock() -> str:
    d = Diagram(1000, 640, cw=150, rh=90)
    d.g("Entorno production · proyecto acme-erp (maqueta)", -0.25, -0.7, 4.6, 5.55, "proposal")
    d.n("dom", "erp.acme.com · SSL válido", 2.0, 0.0, "para", "ext", w=1.25)
    d.n("redis", "redis · Building", 0.3, 1.4, "rect", "partial", w=0.95)
    d.n("odoo", "odoo-web · Running · deploy a1b2c3", 2.0, 1.4, "rect", "own", w=1.25)
    d.n("worker", "odoo-worker · Running", 3.8, 1.4, "rect", "own", w=1.0)
    d.n("pg", "postgres-16 · 2,1 GB", 1.2, 3.0, "cyl", "coolify", w=1.1, h=1.3)
    d.n("vol", "filestore · volumen", 2.9, 3.0, "cyl", "coolify", w=1.05, h=1.3)
    d.n("draft", "3 cambios en revisión → Ver diff · Deploy", 2.0, 4.3, "note", "proposal", w=2.2)
    d.e("dom", "odoo", "443", route="v")
    d.e("redis", "odoo", "${{redis.URL}}", style="dash")
    d.e("odoo", "worker", "comparte BD", style="dash")
    d.e("odoo", "pg", "${{postgres.URL}}", route="vh", style="dash")
    d.e("odoo", "vol", route="vh", style="dash")
    return d.svg()


def roadmap_svg() -> str:
    d = Diagram(1200, 560, cw=180, rh=95)
    phases = [
        ("f1", "FASE 1 · Foundation: sync Coolify 4.4, adaptador, ACL", "partial"),
        ("f2", "FASE 2 · Developer Experience: estados, revisión, variables, rollback", "proposal"),
        ("f3", "FASE 3 · Infrastructure Experience: red, dominios, BD y volúmenes", "proposal"),
        ("f4", "FASE 4 · Visual Experience: canvas, navegación, Cmd+K", "proposal"),
        ("f5", "FASE 5 · Observability: logs, métricas, alertas", "ext"),
        ("f6", "FASE 6 · Advanced: placement, red privada, IaC, CLI", "proposal"),
    ]
    for i, (key, label, kind) in enumerate(phases):
        d.n(key, label, i, 0, "rect", kind, w=0.92, h=1.6)
    d.chain("f1", "f2", "f3", "f4", "f5", "f6")
    d.n("own", "Reutiliza: Odoo, GetOdoo, Deploys, catálogo", 1.5, 1.8, "rect", "own", w=2.2)
    d.n("risk", "Bloqueante: sin fase 1, cada versión de Coolify rompe el producto", 0, 1.8, "rect", "risk", w=0.95)
    d.n("infra", "Servidor de observabilidad (Loki, Vector, TSDB)", 4.0, 1.8, "cyl", "ext", w=1.2, h=1.2)
    d.e("risk", "f1", route="v", style="err")
    d.e("own", "f2", route="v", style="dash")
    d.e("infra", "f5", route="v", style="dash")
    return d.svg()
