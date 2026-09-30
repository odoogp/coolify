# GPSH — Fase 2: GitHub opcional, Jupyter si no hay repositorio

GitHub no es obligatorio. El proyecto Odoo arranca y un entorno se puede lanzar sin cuenta conectada. Conectar GitHub, en Ajustes del proyecto → Odoo, sigue abriendo el registro existente de la GitHub App. Se puede saltar. Una fila sin `installation_id` no cuenta como conectada. No se pide `app_id`, `installation_id` ni la llave privada.

Sin repositorio, JupyterLab es el manejo de archivos y queda activo al crear o al arrancar el servicio Odoo. Los archivos son una sola carpeta: Odoo la lee en `/mnt/extra-addons` y JupyterLab abre la misma en `/workspace/addons`. No hay una copia aparte.

Si más adelante se conecta GitHub, el primer lanzamiento con la cuenta crea un repositorio con el nombre del proyecto (`Mi Empresa` queda `mi-empresa`) y cada entorno pasa a ser una rama de ese repositorio (`production`, `staging-1`, `staging-2`). Esa sincronización entra en la misma carpeta de addons. No sustituye a Jupyter ni despliega un Service en ese paso. Cómo empujar el contenido que ya está en la carpeta hacia el repositorio nuevo queda para cuando se conecte: el gancho es el mismo volumen, no un segundo árbol.

La categoría (producción o staging) se elige al lanzar el entorno. No se pregunta otra vez en el botón de deploy. No se crea una Application en ese paso.

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
