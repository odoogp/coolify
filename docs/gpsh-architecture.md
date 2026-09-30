# GPSH — auditoría de arquitectura (fase 0)

Este documento describe el motor que ya corre y cómo debe crecer la capa Odoo sin duplicarlo. No es un plan de implementación. Las fases 1 en adelante no empiezan hasta aceptar este mapa.

El nombre visible del producto es GPSH (`product_name()` en `bootstrap/helpers/shared.php`). Los identificadores técnicos siguen siendo los de Coolify: modelos, rutas, tablas, colas y variables.

Hay otro conjunto de notas en `docs/v5/`. Habla de un control plane futuro (coold, flux). No es el runtime actual y no es el diseño de la capa Odoo. No mezclar ambos.

## 1. Qué existe

GPSH es Coolify: Laravel 12 con estructura de Laravel 10, Livewire 3, Tailwind 4, colas Redis/Horizon, proxy Traefik por servidor, terminal por SSH. La UI vive en Livewire, no en un frontend separado.

La jerarquía real es:

```
Team
 └── Project
      └── Environment          (al crear el proyecto se crea uno llamado production)
           ├── Application     (Git, imagen o compose de una app)
           ├── Service         (stack de plantilla, aquí vive Odoo hoy)
           │    ├── ServiceApplication
           │    └── ServiceDatabase
           └── Standalone*     (Postgres, MySQL, Redis, …)
```

Un servidor (`Server`) pertenece a un team y tiene destinos Docker. El proxy, los volúmenes y los backups cuelgan de esos recursos, no de un “proyecto Odoo” aparte.

### Modelos que importan

| Pieza | Dónde | Qué guarda |
| --- | --- | --- |
| Proyecto | `app/Models/Project.php` | nombre, descripción, `team_id`, `created_by`. Al crearse genera `ProjectSetting` y un `Environment` `production`. |
| Ambiente | `app/Models/Environment.php` | nombre libre, recursos del proyecto. No hay un tipo production/staging en base de datos: el nombre `production` es una convención del `booted()`. |
| Aplicación | `app/Models/Application.php` | Git (`git_repository`, `git_branch`, `git_commit_sha`), build pack, dominio, health check, source, destino, comandos pre/post deploy. |
| Cola de deploy | `ApplicationDeploymentQueue` + `app/Jobs/ApplicationDeploymentJob.php` | un deploy de aplicación. |
| Servicio | `app/Models/Service.php` | compose de plantilla. Odoo de la plantilla es un servicio (`odoo` + `postgresql`), no una Application. `jupyter_enabled` es el único campo Odoo/Jupyter en base de datos. La versión no es una columna: se lee de la imagen (`odoo:17` … `odoo:20`) y `app/Support/OdooVersion.php` reescribe solo esa etiqueta. |
| Fuente GitHub | `app/Models/GithubApp.php` | GitHub App del team: `app_id`, `installation_id`, clave privada, `webhook_secret`. También existe `GitlabApp`. |
| Login OAuth | `OauthSetting` + `app/Http/Controllers/OauthController.php` | Socialite para entrar a GPSH. No lista repositorios ni despliega. |
| Backup de base | `ScheduledDatabaseBackup` + `app/Jobs/DatabaseBackupJob.php` | dump programado, retención, S3 opcional, ejecuciones. |
| Backup de volumen | `ScheduledVolumeBackup` + `app/Jobs/VolumeBackupJob.php` | backup del volumen persistente. |
| Servidor y storage | `Server`, `S3Storage` | infraestructura. Crear servidor o storage S3 ya está limitado al owner. |
| Equipo | `Team` + pivot de miembros | rol `owner`, `admin` o `member` (`app/Enums/Role.php`). |

### Despliegue, Git y webhooks

- Una **Application** con source GitHub se crea desde `app/Livewire/Project/New/GithubPrivateRepository.php` (y las variantes de deploy key, GitLab, repo público, imagen, compose).
- El deploy corre en `ApplicationDeploymentJob`. Los estados reales son `queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user` (`ApplicationDeploymentStatus`). No existen los nombres `running` ni `success`; la capa Odoo debe usar estos valores.
- Un **Service** (el Odoo de plantilla) se despliega con `app/Actions/Service/StartService.php` y `DeployServiceApplication.php`. No pasa por `ApplicationDeploymentJob`.
- Los webhooks de Git están en `routes/webhooks.php`: `POST /source/github/events` y `/manual`, más GitLab, Gitea y Bitbucket. Un GitHub App tiene un webhook de instalación. Coolify enruta el push a las aplicaciones que escuchan ese repo y esa rama. No hace falta un webhook nuevo por proyecto.
- El rollback de aplicación ya existe (`app/Livewire/Project/Application/Rollback.php`). Los previews de PR también (`ApplicationPreview`).

### Permisos

El límite es el team. Quien pertenece al team puede ver los recursos de ese team.

| Acción | Quién, hoy |
| --- | --- |
| Ver proyecto, app, servicio, base | miembro del team (`*Policy::view`) |
| Crear aplicación | admin u owner (`ApplicationPolicy::create` usa `isAdmin()`, que incluye owner) |
| Crear o modificar servidor | solo owner del team (`ServerPolicy`) |
| Crear o modificar storage S3 | solo owner (`S3StoragePolicy`) |
| Terminal | `Gate::canAccessTerminal`: admin u owner. Un member no entra. |
| Proxy y sentinel del servidor | owner del team |

No hay permisos del estilo `project.deploy` o `staging.sync`. El rol vive en el pivot del team, no por proyecto.

### Backups, proxy, colas, notificaciones

- Postgres de una instancia Odoo de plantilla es un `ServiceDatabase`. Su backup entra por `ScheduledDatabaseBackup` si se programa sobre esa base.
- El filestore de Odoo es un volumen (`odoo-web-data` → `/var/lib/odoo`). Su backup entra por `ScheduledVolumeBackup`. Hoy nadie los ata en una sola operación.
- El proxy del servidor (Traefik por defecto, también Caddy o Nginx) publica los dominios. Los labels los genera el parser de compose (`fqdnLabelsForTraefik`).
- Colas: Horizon sobre Redis. Los jobs largos ya no corren en el request.
- Notificaciones: email, Slack, Discord, Telegram, Pushover y webhook, con modelos `*NotificationSettings`. Los eventos de deploy y backup ya disparan ese sistema.
- Auditoría: `Application` importa el activity log de Spatie, pero no hay un audit log de producto (quién sincronizó staging, quién restauró). No reutilizar esa tabla como log de negocio sin revisarla: puede contener salida de comandos.

### Odoo y Jupyter, ya en el árbol

La plantilla `odoo` en `templates/service-templates-latest.json` define `odoo:18` y `postgres:16-alpine`, con volúmenes `odoo-web-data` y `odoo-extra-addons`. El parser renombra el volumen a `{uuid}_odoo-extra-addons`.

En la pantalla del servicio, si el compose es Odoo, hay un selector de versión oficial: 17, 18, 19 y 20. Cambia `image` del servicio Odoo y deja PostgreSQL igual. El contenedor usa la imagen nueva solo después de redesplegar. Un salto de versión mayor no migra la base.

`app/Support/OdooJupyter.php` añade Jupyter solo si `jupyter_enabled` es verdadero. Comparte el source de addons (`/mnt/extra-addons` y `/workspace/addons`), deja el proceso en UID 100 GID 101 y desactiva el healthcheck de la imagen para que Traefik no descarte el contenedor. Los módulos oficiales siguen dentro de la imagen de Odoo. El volumen de extra-addons nace vacío.

## 2. Qué reutilizar tal cual

- `Team`, `Project`, `Environment` como contenedor de cliente y de ambientes. Un proyecto Coolify ya es “Cliente A”. Production y staging son dos `Environment` del mismo proyecto, no dos productos nuevos.
- `GithubApp` y las pantallas de source (`Livewire/Source/Github`, `Project/New/GithubPrivateRepository`). El acceso a repos privados, orgs y webhooks ya está ahí.
- `routes/webhooks.php` y el controlador `Webhook\Github`. Un push a `main` o a `staging` se distingue por la rama de cada recurso, no por otro endpoint.
- `ApplicationDeploymentJob` y `ApplicationDeploymentQueue` para el historial de deploys de lo que sea una Application.
- `StartService` / `DeployServiceApplication` para levantar el stack Odoo.
- `ScheduledDatabaseBackup` + `DatabaseBackupJob` y `ScheduledVolumeBackup` + `VolumeBackupJob`.
- Policies de team, el gate de terminal, y el límite owner de servidores y S3.
- Proxy, dominios (`fqdn`), health checks de aplicación, logs, terminal SSH y notificaciones.
- `OdooJupyter` y el flag `jupyter_enabled`. Sigue siendo opcional por servicio.

## 3. Qué extender

- **Project / Environment.** El perfil Odoo guarda la versión y el tope de stagings. Cada environment (`production` o `staging`) apunta a una rama cuyo nombre es el de GitHub, no un alias. No copiar `name`, `team_id` ni `uuid` a otro modelo.
- **Alta de proyecto.** Al marcar un proyecto como Odoo, crear el primer environment de staging vacío si no hay ninguno (`staging-1`, o reutilizar un `staging` ya existente). El perfil dice cuántos stagings se permiten. Cada uno tendrá después su propio Service, Postgres y volúmenes. Nada compartido entre ellos.
- **Git de addons.** El repo del cliente no sustituye la imagen `odoo`. El deploy de la Application (o un checkout al volumen) tiene que terminar en el volumen `extra-addons` de ese environment. Production escucha la rama configurada; staging, la otra. Nombres de rama libres, validados contra el repo.
- **Webhook.** No registrar otro webhook. Asociar cada ambiente a la misma `GithubApp` y dejar que el evento existente dispare el deploy del recurso cuya `git_branch` coincide.
- **Backup Odoo.** Un coordinador que programe el backup de la base y el del volumen de filestore, guarde las dos ejecuciones bajo un mismo identificador y se niegue a restaurar solo una. El almacenamiento sigue siendo el de Coolify.
- **Policies.** Permisos finos (`staging.deploy`, `backup.restore`) como abilities encima del rol de team, no como un segundo sistema de usuarios. Owner conserva servidores y S3. Member no los gana.
- **UI.** Una vista de proyecto por encima de `Livewire/Project/*` para quien no debe ver el inventario crudo de Docker. Las pantallas actuales de servidor, proxy, source y servicio se quedan para el owner.

## 4. Qué hay que crear

Solo lo que Coolify no modela:

| Pieza | Por qué no existe |
| --- | --- |
| Perfil Odoo del proyecto | Fase 1: `odoo_profiles` guarda la versión 17–20 y el límite de environments de staging. Siguen faltando las ramas y el puntero “este service es el ERP”. |
| Operación “sync staging from production” | No hay un flujo que avance la rama staging al commit de production y despliegue solo staging. `git reset --hard` en el árbol de producción no es aceptable. La estrategia inicial tiene que ser no destructiva (merge o fast-forward en la rama staging del remoto) y quedar registrada. |
| Clone production → staging | No hay copia coordinada de base + filestore entre dos environments. Tiene que respaldar staging antes, no tocar production, y abortar si el health check falla. |
| `OdooBackup` como agrupador | Los dos jobs de backup existen sueltos. Falta el par database+filestore y la regla de no restaurar a medias. |
| Audit log de producto | Hace falta usuario, acción, proyecto, environment, resultado y metadata. Sin secrets. |
| Jobs de orquestación | `SyncStagingJob`, `CloneProductionToStagingJob`, `CreateOdooBackupJob`, `RestoreOdooBackupJob`. Por dentro llaman a los jobs y actions que ya existen. |
| API de proyecto | La API v1 actual habla de applications, services y databases. Los endpoints de proyecto Odoo deben llamar a los mismos actions que la UI. |

Detección de Odoo: un service cuya imagen es `odoo:` o contiene `/odoo:`, o una imagen custom marcada en el perfil. El resto de aplicaciones del team siguen siendo apps genéricas.

## 5. Qué no tocar

- Filas centinela `id = 0` (team raíz, servidor localhost, `InstanceSettings`, Postgres de la instancia, destino Docker local).
- Postgres, Redis, Soketi y el proxy del propio GPSH.
- El updater local, `COOLIFY_IMAGE=coolify-custom:local` y `COOLIFY_PULL_POLICY=never`.
- El parser de compose, salvo un punto de extensión ya usado (`OdooJupyter`). No cambiar el listen `0.0.0.0:8069` de Odoo ni el volumen compartido con Jupyter.
- Nombres de tablas, rutas `/project/...`, `/source/github/...`, `/api/v1/...` y el webhook `/source/github/events`.
- Instalaciones que no activen el perfil Odoo: tienen que seguir creando proyectos, apps y servicios como hoy.
- `docs/v5/` y el diseño coold/flux. Es otro esfuerzo.

## 6. Cómo se ve un cliente, sin modelo nuevo de proyecto

```
Project "Cliente A"          (team, nombre, descripción)
 ├── Environment production
 │    ├── Service odoo       (imagen odoo:20, dominio erp.cliente.com)
 │    ├── ServiceDatabase    (Postgres propio, volumen propio)
 │    ├── volumen filestore  ( ScheduledVolumeBackup )
 │    ├── volumen extra-addons  ← checkout de la rama production
 │    └── Jupyter opcional   (mismo volumen de addons)
 └── Environment staging
      ├── Service odoo       (otra copia, dominio staging-erp.cliente.com)
      ├── ServiceDatabase    (otra base)
      ├── volúmenes distintos
      └── addons de la rama staging
```

Production y staging no comparten base, volumen, filestore, dominio ni secrets. El aislamiento sale de crear dos environments con dos services, que es lo que Coolify ya hace cuando los recursos viven en environments distintos.

GitHub no se vuelve a autenticar. El team conecta un `GithubApp` (instalación, no el OAuth de login). El usuario elige repo y dos ramas. Cada environment queda suscrito a su rama por el webhook que la App ya tiene.

## 7. Riesgos

1. **Service contra Application.** Decidido en `docs/gpsh-odoo-architecture-decision.md`. La Application es el recurso Git que el webhook ya encuentra. `SyncOdooAddonsJob` reutiliza el clone y la cola, copia el árbol al volumen `{serviceUuid}_odoo-extra-addons` y reinicia solo el contenedor odoo. No se copia `ApplicationDeploymentJob` ni se construye una segunda imagen de Odoo.
2. **OAuth de login no sirve para clonar.** `OauthSetting` autoriza a una persona a entrar. Los repos salen de `GithubApp`. Pedir un token personal al lado de la App sería el sistema paralelo que no hay que crear.
3. **Sync de ramas.** Empujar production sobre staging puede reescribir historia. La primera estrategia tiene que ser fast-forward o merge, con commit anterior y nuevo guardados. Recrear la rama queda para después y solo con confirmación.
4. **Clone de datos.** Copiar la base y el filestore de production a staging es la operación que puede borrar staging. Production no se lee con un `down`. Hace falta backup previo de staging y un job que pueda fallar a la mitad sin tocar production.
5. **Permisos.** Bajar el terminal o el deploy a members cambia el gate actual. Hay que hacerlo con abilities nuevas, no abriendo `canAccessTerminal` a todo el team.
6. **Escala.** El dashboard no puede hacer un `docker ps` por proyecto. Tiene que leer estado ya guardado (deployments, backups, status de recursos) y paginar por team.
7. **Jupyter.** Sigue opcional y atado al service del environment. Un clone de staging no debe reutilizar el volumen de addons de production.

## 8. Fases siguientes, en orden

Cada fase sale con tests, una migration reversible si hay tablas, y una nota corta. No se adelantan pantallas de fases posteriores.

| Fase | Entrega | No incluye |
| --- | --- | --- |
| 1 | Hecha: perfil `odoo_profiles` (versión 17–20, límite de stagings o ilimitado). El primer staging vacío se crea si no hay ninguno. Puede haber varios (`staging` legado, `staging-1`, `staging-2`…). No crea services. Nota en `docs/gpsh-odoo-phase-1.md`. | Git, backups |
| 2 | Hecha: `GithubApp`, repositorio y una rama de GitHub por environment (`production` y cada staging). El nombre guardado es el de GitHub. Nota en `docs/gpsh-odoo-phase-2.md`. | Sync de ramas, deploy |
| 3 | Dos services aislados, dominios distintos, webhook de la App disparando la rama correcta | Clone de base |
| 4 | Historial de deploy reutilizando la cola o el deploy de service, con el mismo estado | UI de miembro completa |
| 5 | Backup coordinado database+filestore y restore que exige los dos | Clone production→staging |
| 6 | Vistas de proyecto para member y owner, sin nuevas pantallas de servidor | API pública nueva |
| 7 | Versión guardada en el perfil por environment (el selector 17–20 del servicio ya existe), workers, addons path, Jupyter opcional por environment | — |
| 8 | Audit log y notificaciones sobre los canales que ya existen | — |
| 9 | API que llama a los mismos actions | — |
| 10 | Tests de permiso (member no crea server ni S3; staging no escribe en production) | — |

Los documentos `docs/gpsh-projects.md`, `gpsh-github.md`, `gpsh-production-staging.md`, `gpsh-backups.md`, `gpsh-permissions.md`, `gpsh-odoo.md` y `gpsh-api.md` se escriben con la fase que los vuelve ciertos. Este archivo es el mapa de la fase 0. La decisión de Git → extra-addons está en `docs/gpsh-odoo-architecture-decision.md`. La fase 1 crea el perfil y permite varios environments de staging, con un límite. La fase 2 asocia cada environment a una rama real de GitHub. Las fases 3 a 10 están en `docs/gpsh-odoo-phases-3-10.md`.
