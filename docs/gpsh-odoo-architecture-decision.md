# Decisión: GitHub → extra-addons, sin un segundo motor de deploy

Este documento cierra los riesgos de la fase 0. No implementa la fase 1.

La opción elegida es **A**, con un límite: la `Application` es el recurso Git que el webhook ya sabe encontrar. `ApplicationDeploymentJob` no se copia y tampoco se usa entero, porque su éxito es construir una imagen y arrancar otro contenedor. Odoo sigue siendo el `Service`.

## 1. Arquitectura elegida

```
GithubApp                          (source ya instalada en el team)
        │
        │  webhook POST /source/github/events
        ▼
Application  (una por environment, misma App, mismo repo, distinta git_branch)
        │
        │  ApplicationDeploymentQueue   (el registro de deploy que ya existe)
        ▼
SyncOdooAddonsJob                  (orquestador nuevo, corto)
        │
        ├─ Application::generateGitImportCommands()
        │     clona con generateGithubInstallationToken()
        │     el árbol queda en el workdir de build, no en Odoo
        │
        ├─ copia ese árbol al volumen Docker del Service
        │     {serviceUuid}_odoo-extra-addons  →  /mnt/extra-addons
        │
        └─ reinicia solo el contenedor odoo de ese Service
              (Postgres no se recrea)
```

No hay un segundo clone, un segundo token ni un segundo webhook.

### Por qué no B

B volvería a escribir checkout, autenticación de la GitHub App, matching de rama y cola. Eso ya está en `Application`, `generateGitImportCommands()` y `app/Http/Controllers/Webhook/Github.php`.

### Por qué no el job completo

`ApplicationDeploymentJob::clone_repository()` clona dentro del contenedor de build, en `/artifacts/{deployment_uuid}` (`Application::generateBaseDir()`). Ese directorio es contexto de build. Al terminar, `cleanup_git()` borra `.git` y el job construye y sustituye el contenedor de la **Application**.

El volumen de addons del Odoo no es ese directorio. El parser lo crea como volumen Docker con nombre `{uuid del Service}_odoo-extra-addons` (`bootstrap/helpers/parsers.php`, slug del source `odoo-extra-addons`). Vive en el `Service`, no en la Application.

Si se dejara correr el job completo, GPSH levantaría un contenedor paralelo y el ERP no vería el código.

### Qué se reutiliza

| Capacidad | Dónde |
| --- | --- |
| GitHub App, installation token, repos privados y orgs | `GithubApp`, `generateGithubInstallationToken()` en `bootstrap/helpers/github.php` |
| Clone y checkout de un commit | `Application::generateGitImportCommands()` |
| Webhook y match repo + rama | `Webhook\Github`: `repository_project_id` + `source_id` + `git_branch` |
| Cola, estados, logs, commit | `ApplicationDeploymentQueue`, `queue_application_deployment()`, estados `queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user` |
| Rollback de commit | el SHA guardado en la cola; un redeploy de la Application de addons con ese SHA |
| Arranque del stack Odoo | `StartService`, `DeployServiceApplication` |
| Reinicio del contenedor odoo | `RestartServiceApplication` |
| Backup de Postgres | `ScheduledDatabaseBackup` + `DatabaseBackupJob` |
| Backup de filestore | `ScheduledVolumeBackup` + `VolumeBackupJob` sobre el volumen `odoo-web-data` |

### Qué se crea, y nada más

| Pieza | Rol |
| --- | --- |
| Perfil Odoo | versión, puntero al `Service` de cada environment, puntero a la `Application` de addons, dominios. No duplica `Project`. |
| `SyncOdooAddonsJob` | orquesta clone existente → copia al volumen del Service → restart del contenedor odoo. Escribe en `ApplicationDeploymentQueue`. |
| `SyncStagingBranchJob` | merge o fast-forward en GitHub. No toca el disco de production. |
| `CloneProductionDataJob` | backup de staging, luego copia de base y filestore. No para production. |
| `OdooBackup` | fila que agrupa una ejecución de `DatabaseBackupJob` y una de `VolumeBackupJob`. |
| Abilities `odoo.*` | encima del rol de team. No sustituyen `ServerPolicy` ni `S3StoragePolicy`. |

## 2. GitHub → extra-addons

```
push a la rama
        │
        ▼
POST /source/github/events
        │
        │  Github.php
        │  Application
        │    where repository_project_id = payload.repository.id
        │    where source_id            = github_app.id
        │    where git_branch           = refs/heads/<rama>
        ▼
queue_application_deployment(application, commit)
        │
        ▼
SyncOdooAddonsJob          (solo si la Application está marcada como addons de Odoo)
        │
        ├─ generateGitImportCommands(commit)
        ├─ el árbol se copia a {serviceUuid}_odoo-extra-addons
        └─ RestartServiceApplication del servicio odoo de ESE environment
```

La marca es un campo del perfil, no un build pack nuevo. Una Application normal del mismo team sigue entrando a `ApplicationDeploymentJob` y no a este job.

El volumen de destino se resuelve por el `Service` del mismo `environment_id`, no por un nombre fijo `odoo-extra-addons`. Así staging no escribe en el volumen de production: cada Service tiene su uuid.

Después de copiar, se reinicia el contenedor `odoo` para que cargue módulos nuevos. Postgres no se reinicia. Jupyter, si está habilitado, ya monta ese mismo volumen; no hace falta redeploy del notebook para ver los archivos.

## 3. Production

```
Project
 └── Environment "production"
      ├── Service Odoo A
      │    ├── contenedor odoo          dominio erp.cliente.com
      │    ├── ServiceDatabase          Postgres A, volumen {uuidA}_postgresql-data
      │    ├── volumen {uuidA}_odoo-web-data          filestore
      │    └── volumen {uuidA}_odoo-extra-addons      addons
      └── Application addons A
           ├── source_id              = GithubApp del team
           ├── repository_project_id  = id del repo
           └── git_branch             = production_branch
```

Un push a `production_branch` solo selecciona esta Application. El job copia a `{uuidA}_odoo-extra-addons` y reinicia el odoo de A.

## 4. Staging

Igual, con otro `Environment`, otro `Service` (uuid B) y otra `Application` cuya `git_branch` es `staging_branch`.

```
Project
 ├── Environment production     Service uuid A    volúmenes A    Application rama production
 └── Environment staging        Service uuid B    volúmenes B    Application rama staging
```

No comparten base, filestore, addons, red de compose ni dominio.

El aislamiento ya sale del modelo:

- Un `Service` pertenece a un `environment_id`.
- El nombre del volumen lleva el uuid del Service, no el del proyecto.
- La red de compose del Service es ese uuid.
- Los secrets del stack son `EnvironmentVariable` del Service (`resourceable` morph).

El punto que sí cruza environments es una `SharedEnvironmentVariable` de tipo project (o de team). Una contraseña de base definida ahí la verían production y staging. El perfil Odoo debe guardar los secrets de cada stack en el Service, no en variables compartidas del proyecto.

Coolify no impide que dos recursos lleven el mismo `fqdn`. El alta Odoo tiene que exigir dominios distintos. No es un cruce automático; es una validación que hay que añadir.

## 5. Webhook y ramas

No se registra otro webhook. La GitHub App ya apunta a `/source/github/events`.

El match está en `Github::normal()`:

```
Application::where('repository_project_id', $id)
    ->where('source_id', $github_app->id)
    ->where('git_branch', $branch)
```

Dos Applications, mismo repo y misma App, ramas distintas: el push entra solo a la que coincide. `[skip ci]` y `watch_paths` siguen aplicando.

Los Services no están en esa query. Por eso el listener tiene que ser la Application de addons, no el Service Odoo.

## 6. Sync production → staging

No se copia el working tree de production. No hay `git reset --hard`, `git push --force` ni borrado de rama.

```
Application production.git_commit_sha     (commit desplegado)
        │
        ▼
GitHub API, con el installation token que ya emite la App
        │
        ├─ staging puede hacer fast-forward a ese commit
        │     → actualizar la rama staging en el remoto
        │
        ├─ si no, merge sin force
        │     → si hay conflictos, parar y decir por qué
        │
        └─ el push resultante lo recibe el webhook actual
              → SyncOdooAddonsJob solo del environment staging
```

Se guarda commit anterior de staging, commit nuevo, usuario y resultado. Si la API no puede actualizar la rama sin reescribir historia, la operación termina en `failed` y staging sigue en su commit.

Production no se despliega otra vez por este flujo: su `git_branch` no es la rama que se movió.

## 7. Clone de datos (aparte del código)

```
Staging
  1. DatabaseBackupJob del Postgres B        tiene que terminar finished
  2. VolumeBackupJob del filestore B         tiene que terminar finished
  3. si uno falla, no se sigue
  4. lectura de Postgres A (sin parar el contenedor)
     y escritura en Postgres B
  5. lectura del volumen filestore A
     y escritura en el volumen filestore B
  6. health check del odoo B
  7. registro
```

Production no se apaga y sus volúmenes no se borran. El contenedor de copia monta el volumen A en solo lectura.

Código y datos no van en el mismo botón. Sync de rama no toca la base. Clone de datos no mueve la rama.

## 8. Backup

No hay scheduler nuevo ni otro disco. Los cron que ya disparan `DatabaseBackupJob` y `VolumeBackupJob` siguen.

`OdooBackup` es una fila de coordinación:

```
OdooBackup
 ├── environment_id
 ├── database_backup_execution_id     ScheduledDatabaseBackupExecution
 ├── volume_backup_execution_id       ScheduledVolumeBackupExecution
 └── status   pending | complete | partial | failed
```

`complete` solo si las dos ejecuciones terminaron bien. Una restauración exige `complete`. Una sola pata no se restaura en silencio.

El backup manual encola los dos jobs existentes y crea la fila. El programado deja que cada schedule corra como hoy; un observer de esas ejecuciones cierra la fila del par cuando las dos han acabado.

## 9. Restore

```
OdooBackup complete
        │
        ├─ restaurar el dump en el Postgres de ESE environment
        ├─ restaurar el volumen de filestore de ESE environment
        └─ reiniciar el contenedor odoo de ESE environment
```

El restore de production no lee ni escribe staging, y al revés. Antes de restaurar production se exige un `OdooBackup` `complete` reciente de production, salvo que el owner lo desactive en esa operación.

## 10. Permisos

El rol sigue en el pivot del team: `owner`, `admin`, `member`.

Las abilities nuevas no llaman a `ServerPolicy` ni a `S3StoragePolicy`. Esas policies siguen exigiendo `isOwner()` / `isOwnerOfTeam()`. Un member con `odoo.production.deploy` no puede crear un servidor ni un storage.

| Ability | owner | admin | member |
| --- | --- | --- | --- |
| `odoo.project.view` | sí | sí | sí, en su team |
| `odoo.project.update` | sí | sí | no |
| `odoo.repository.configure` | sí | sí | no |
| `odoo.staging.deploy` | sí | sí | solo si se le concede |
| `odoo.production.deploy` | sí | sí | solo si se le concede |
| `odoo.staging.sync` | sí | sí | solo si se le concede |
| `odoo.backup.create` | sí | sí | solo si se le concede |
| `odoo.backup.restore` | sí | sí | no por defecto; el owner puede concederla en staging |

`server.create`, `server.update`, `server.delete`, `s3.create`, `s3.update` y `s3.delete` no forman parte de este conjunto. Terminal sigue en `canAccessTerminal` (admin u owner) hasta que una fase posterior lo separe a propósito.

## 11. Migraciones

Cuando se implemente, en este orden, todas reversibles:

1. Tabla de perfil Odoo: `project_id`, `environment_id`, `service_id`, `addons_application_id`, versión, dominios. Sin copiar el nombre del proyecto.
2. Marca en la Application de addons (o en el perfil) para que la cola llame a `SyncOdooAddonsJob` en lugar del build de imagen.
3. Tabla `odoo_backups` con las dos llaves de ejecución, nullable hasta que cada job termina.
4. Tabla o columna de concesión de abilities por miembro. No se cambia el enum `Role`.

No se alteran columnas de `servers`, `s3_storages`, ni las tablas centinela `id = 0`.

## 12. Jobs, actions, UI

Jobs nuevos, todos en cola, ninguno en el request:

- `SyncOdooAddonsJob`
- `SyncStagingBranchJob`
- `CloneProductionDataJob`
- `CreateOdooBackupJob` (dispara los dos jobs de backup ya existentes)
- `RestoreOdooBackupJob`

Actions nuevos, finos, llamados por la UI y por la API futura:

- resolver el volumen `extra-addons` de un Service
- encolar el deploy de addons
- pedir el merge a GitHub
- agrupar y comprobar el par de backups

UI, más adelante, no ahora:

- alta: GitHub opcional; sin repositorio, JupyterLab sobre la carpeta de addons; si se conecta, repositorio con el nombre del proyecto y cada entorno como rama
- proyecto: production y staging, cada uno con deploy, logs (la cola actual), backup y dominio
- botones separados: sync de rama, clone de datos
- el inventario de servidores no se mueve

## 13. Riesgos que siguen abiertos

1. Copiar el árbol al volumen tiene que ser el único efecto del deploy de addons. Si alguien despliega esa Application desde la pantalla genérica, el job completo no debe construir un segundo Odoo. La marca tiene que desviarlo antes del build.
2. `docker volume rm` al borrar la Application no debe borrar `{serviceUuid}_odoo-extra-addons`. Ese volumen pertenece al Service. La Application de addons no registra ese nombre como `LocalPersistentVolume` suyo.
3. El token de instalación aparece en el comando de clone, igual que hoy. El orquestador no lo loguea. Los logs de la cola tienen que seguir enmascarando la URL.
4. Reiniciar Odoo corta sesiones de ese environment. No se reinicia el otro.
5. Un merge con conflictos se detiene. No hay estrategia automática de recrear la rama en esta decisión.

## Architecture Decision

- **Cómo llega el código Git al volumen extra-addons.** La Application de ese environment es el recurso Git. `SyncOdooAddonsJob` usa `generateGitImportCommands()` para clonar el commit y copia el árbol al volumen Docker `{serviceUuid}_odoo-extra-addons`, el mismo que el Service monta en `/mnt/extra-addons`.
- **Qué componente ejecuta el deployment.** La cola existente (`ApplicationDeploymentQueue` / `queue_application_deployment`). El trabajo que corre es `SyncOdooAddonsJob`, no una copia de `ApplicationDeploymentJob` y no el build de imagen de ese job.
- **Cómo se diferencian production y staging.** Dos `Environment` del mismo `Project`. Cada uno tiene su `Service` (uuid propio, volúmenes propios, Postgres propio, dominio propio) y su `Application` (misma `GithubApp`, mismo `repository_project_id`, distinta `git_branch`).
- **Cómo se dispara el webhook.** El webhook actual `POST /source/github/events`. `Github.php` elige la Application con `repository_project_id` + `source_id` + `git_branch`. No se crea otro webhook.
- **Cómo se hace sync.** `SyncStagingBranchJob` mueve la rama staging en GitHub hasta el commit desplegado de production, por fast-forward o merge. Si no puede hacerlo sin force ni sin conflictos, se detiene. El push lo despliega el webhook, solo en staging.
- **Cómo se hace el clone de datos.** `CloneProductionDataJob`, separado del sync de código. Primero backup completo de staging (base y filestore). Si no hay backup válido, no sigue. Luego copia lectura de production a escritura de staging. Production no se apaga.
- **Cómo se coordinan database y filestore.** Una fila `OdooBackup` apunta a una `ScheduledDatabaseBackupExecution` y a una `ScheduledVolumeBackupExecution`. El almacenamiento y el cron siguen siendo los de Coolify. Restore solo con las dos patas en éxito.
