# GPSH — Fase 2: repositorio y ramas

El environment de Coolify sigue llamándose `production` o `staging` (`staging`, `staging-1`, `staging-2`…). Eso es la categoría.

La rama es otra cosa. Se guarda el nombre que tiene en GitHub, sin traducirlo. Si en GitHub la rama es `main`, aquí no puede quedar `produccion`. Si un staging usa `develop` o `feature/nueva-facturacion`, ese es el nombre que se guarda.

## Qué se guarda

En `odoo_profiles`:

- `github_app_id`
- `repository_id` (el id del repo en GitHub)
- `git_repository` (`owner/name`)

En `odoo_environment_branches`, una fila por environment:

- `environment_id`
- `git_branch`

No hay una sola columna `staging_branch`. Cada staging tiene la suya. Production también.

Al guardar, el nombre tiene que estar en la lista de ramas que devuelve esa GitHub App para ese repositorio. Dos environments del mismo proyecto no pueden usar la misma rama.

No se registra otro webhook. El que ya existe, `POST /source/github/events`, busca la rama guardada. Si el push es exactamente esa rama, la marca como `updating` y reinicia el contenedor `odoo` de ese environment cuando el servicio ya existe. Mientras tanto la pantalla dice que Odoo se está actualizando.

Si el equipo ya tiene una GitHub App instalada, con su llave privada y su webhook, se reutiliza. Si no, el entorno pide iniciar sesión con GitHub en Fuentes para crear esa llave y conectar el webhook.
