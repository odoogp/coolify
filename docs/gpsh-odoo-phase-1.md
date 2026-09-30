# GPSH — Fase 1: perfil Odoo y stagings

Un proyecto Odoo sigue siendo un `Project` de Coolify. El perfil no copia el nombre, el team ni el uuid.

## OdooProfile

Tabla `odoo_profiles`, una fila por proyecto.

| Campo | Qué es |
| --- | --- |
| `odoo_version` | `17`, `18`, `19` o `20` |
| `max_staging_environments` | Entero. Por defecto `1`. No puede ser negativo. |
| `unlimited_staging_environments` | Boolean. Por defecto `false`. |

Si el ilimitado está apagado, el proyecto puede tener como máximo esa cantidad de environments de staging. Si está encendido, el número no se aplica.

Los proyectos que ya tenían perfil quedan en `1` y `false`: el mismo tope de un staging, hasta que alguien lo cambie.

## Environments

Siguen siendo `Environment`. No hay un modelo `OdooStagingEnvironment`.

Un staging es un environment del proyecto cuyo nombre es `staging` o `staging-N`. `production` no cuenta.

Al activar Odoo, si no hay ninguno y el límite lo permite, se crea `staging-1` vacío. No se despliega un Service, ni Postgres, ni volúmenes.

Si ya existe `staging`, se reutiliza y no se renombra. El siguiente que se cree se llama `staging-2`.

La regla vive en `App\Support\OdooStaging::canCreateStagingEnvironment()`:

```
sin perfil        → no
ilimitado         → sí
si no             → cantidad de stagings < max_staging_environments
```

`Project::createNextStagingEnvironment()` es el único sitio que crea el siguiente, y llama a esa regla.

Un proyecto sin perfil no gana un staging. Sigue siendo solo `production`.

## Fuera de esta fase

Webhooks, checkout, deploy y el Service Odoo siguen fuera. La rama de GitHub de cada environment está en la fase 2 (`docs/gpsh-odoo-phase-2.md`). El nombre del environment no es la rama.
