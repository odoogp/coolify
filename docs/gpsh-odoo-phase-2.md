# GPSH — Fase 2: GitHub opcional, Jupyter si no hay repositorio

GitHub no es obligatorio. En el asistente de un proyecto nuevo se elige el servicio. Si es Odoo, ahí mismo se elige la versión 17–20 y se puede conectar GitHub. Si se deja apagado, el proyecto igual se crea y JupyterLab muestra los archivos. Conectar GitHub abre el registro existente de la GitHub App. Se puede saltar. Una fila sin `installation_id` no cuenta como conectada. No se pide `app_id`, `installation_id` ni la llave privada.

Sin repositorio, JupyterLab es el manejo de archivos y queda activo al crear o al arrancar el servicio Odoo. Los archivos son una sola carpeta: Odoo la lee en `/mnt/extra-addons` y JupyterLab abre la misma en `/workspace/addons`. No hay una copia aparte.

Si más adelante se conecta GitHub, se puede hacer desde el servicio que ya existe: repositorio nuevo, con el nombre del proyecto, o un repositorio que ya existe. Al elegir uno existente, una búsqueda filtra los repositorios ya cargados por nombre. Reevaluar vuelve a pedirlos, hasta cinco páginas. Un proyecto nuevo, si el usuario ya tiene la app instalada, solo crea el repositorio con el nombre del proyecto y no registra otra app. Si ese repositorio existe, su rama se clona en la carpeta de addons que comparten Odoo y JupyterLab. El entorno queda en la rama elegida. El primer lanzamiento con cuenta nueva crea el repositorio (`Mi Empresa` queda `mi-empresa`) y la rama con el nombre del entorno. Esa sincronización entra en la misma carpeta de addons. No sustituye a Jupyter ni despliega un Service en ese paso. Cómo empujar el contenido que ya está en la carpeta hacia el repositorio nuevo queda para cuando se conecte: el gancho es el mismo volumen, no un segundo árbol.

La categoría (producción o staging) se elige al lanzar el entorno. No se pregunta otra vez en el botón de deploy. No se crea una Application en ese paso.

Dentro del proyecto hay una sola lista. Una fila se selecciona con un clic; el único botón de clonado está en la cabecera y dice que el destino es un staging. Mientras corre, la pantalla dice que está creando ese staging y lanzando Odoo. El asistente pregunta si el staging nuevo copia los addons o nace sin módulos. El clonado elige la rama (el nombre del staging) solo, copia el servicio de producción y lo despliega con los mismos logs. Con GitHub, copiar crea otra rama del mismo repositorio a partir de la rama de producción; no reutiliza esa rama. Sin repositorio, el staging lleva el mismo servicio y los addons siguen en Jupyter de producción hasta que haya repositorio.

Un repositorio de GitHub no puede quedar en dos proyectos. La rama del entorno es el nombre del entorno; no se elige en un desplegable. Crear o asociar el repositorio lanza la instancia en ese mismo paso.

La base se llama con el slug del proyecto, un guion bajo y el slug de la rama (`mi_empresa_staging_1`) y queda creada al arrancar. Odoo arranca con `--no-database-list` y un filtro a esa base, así que la página pública no enlista las demás. Cada arranque escribe `web.base.url` en `https://` directo en Postgres, para que Odoo no devuelva el navegador a HTTP. Abrir la dirección pública entra ya con la sesión de `admin`. En Odoo 20 esa sesión se guarda con `authenticate` y `save_session` antes de abrir `/odoo`. En el servicio, Enlistar bases muestra solo las bases que Postgres tiene con las conexiones cerradas. El enlace público sale en `https://` sin el puerto 8069. La redirección a HTTPS no incluye `/.well-known/acme-challenge/`. En el servicio, Comprobar certificado dice si Let's Encrypt ya lo aplicó. Odoo arranca con `--proxy-mode`. La contraseña de `admin` queda en la pantalla del servicio.

La GitHub App que abre Conectar GitHub se llama `gpsh`. Si ese nombre ya está en el equipo, el siguiente es `gpsh-2`. Esa instalación queda en el usuario que la conectó (`team_user.github_app_id`). La pantalla muestra el login de GitHub de esa cuenta y sus repositorios, no la lista de apps. Se puede cambiar la cuenta; el cambio se guarda en el mismo usuario. El repositorio nuevo se llama como el proyecto, en slug, y el manifiesto de esa conexión pide permiso para crearlo.

## Qué se guarda

En `odoo_profiles`, solo cuando hay GitHub:

- `github_app_id`
- `repository_id`
- `git_repository` (`owner/name`)

En `odoo_environment_branches`, una fila por environment que ya tiene rama:

- `environment_id`
- `git_branch` (igual al nombre del environment)

Un entorno lanzado sin GitHub no tiene fila de rama. El panel lo muestra como JupyterLab.

Dos environments del mismo proyecto no pueden usar la misma rama.

El manifiesto que abre Conectar GitHub desde el proyecto pide `contents: write` y `administration: write`. Una GitHub App creada desde el flujo normal de Coolify sigue en lectura.

No se registra otro webhook. El que ya existe, `POST /source/github/events`, busca la rama guardada cuando el repositorio ya está conectado.
