# GPSH — Fase 1: perfil Odoo y stagings

Un proyecto Odoo sigue siendo un `Project` de Coolify. El perfil no copia el nombre, el team ni el uuid.

## OdooProfile

Tabla `odoo_profiles`, una fila por proyecto.

| Campo | Qué es |
| --- | --- |
| `odoo_version` | `17`, `18`, `19` o `20` |
| `max_staging_environments` | Columna vieja del proyecto. Ya no decide el cupo. |
| `unlimited_staging_environments` | Columna vieja del proyecto. Ya no decide el cupo. |

Si el ilimitado está apagado, el proyecto puede tener como máximo esa cantidad de environments de staging. Si está encendido, el número no se aplica.

Los proyectos que ya tenían perfil quedan en `1` y `false`: el mismo tope de un staging, hasta que alguien lo cambie.

## Environments

Siguen siendo `Environment`. No hay un modelo `OdooStagingEnvironment`.

Un staging es un environment del proyecto cuyo nombre es `staging` o `staging-N`. `production` no cuenta.

Al activar Odoo, si no hay ninguno y el límite lo permite, se crea `staging-1` vacío. No se despliega un Service, ni Postgres, ni volúmenes.

Si ya existe `staging`, se reutiliza y no se renombra. El siguiente que se cree se llama `staging-2`.

La regla vive en `App\Support\OdooStaging::canCreateStagingEnvironment()`:

```
sin perfil                         → no
member                             → no
owner, o admin sin número          → sí
admin con número                   → sus stagings en el equipo < max_staging_branches
```

Ese número lo escribe solo el owner, en el miembro del equipo. No es un límite del proyecto. Producción no cuenta.

`Project::createNextStagingEnvironment()` es el único sitio que crea el siguiente, y llama a esa regla. Clonar production llama a ese mismo método.

Un proyecto sin perfil no gana un staging. Sigue siendo solo `production`.

## Clonar production

Si el proyecto ya tiene su ambiente `production`, clonar crea un solo staging (`staging-1`, o el siguiente número libre). No crea otro `production`. Si el usuario que lanza ya llegó a su cupo, no crea nada. El owner asocia a ese usuario la cuenta de GitHub que usa al lanzar.

Ese clon no copia la base, el filestore ni la rama de GitHub. Production se queda igual.

## Fuera de esta fase

Webhooks, checkout, deploy y el Service Odoo siguen fuera. La rama de GitHub de cada environment está en la fase 2 (`docs/gpsh-odoo-phase-2.md`). El nombre del environment no es la rama.
