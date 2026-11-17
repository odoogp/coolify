# GPSH — Fases 3 a 10

Cada environment de Odoo tiene su propio `Service` y su propia `Application` marcada `is_odoo_addons`. El volumen de addons es `{uuid del service}_odoo-extra-addons`. El de filestore es `{uuid}_odoo-web-data`. Dos environments no pueden usar el mismo dominio.

Un push sigue entrando por `POST /source/github/events`. Si ya existe la Application de addons, la cola existente llama a `SyncOdooAddonsJob` y no al build de imagen. Los estados son `queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user`.

`OdooBackup` queda `complete` solo cuando la ejecución de la base y la del volumen están en `success`. Restaurar o clonar datos sin ese par se niega. El clon escribe el volumen de staging y lee el de production.

La rama de staging se mueve con un merge de GitHub, sin `force`. Si la API no puede, queda `failed` y production no se toca.

La vista del proyecto muestra dominio, versión, workers, ruta de addons, Jupyter y el último estado. El owner concede abilities `odoo.*` a un member. Eso no abre servidores ni S3.

La API vive en `/api/v1/projects/{uuid}/odoo` y llama a las mismas acciones.
