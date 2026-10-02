# Dirección visual profesional de GPSH

El acceso real usa esta composición en `x-auth.shell`: escena a un lado y
tarjeta al otro, en claro y en oscuro. El panel, los proyectos y los ajustes
siguen el sistema de `DESIGN.md`. No hay una segunda interfaz de muestra.

El nombre visible sigue siendo GPSH. La composición del acceso sale de la
referencia de login: escena a un lado, tarjeta de cristal al otro, botón en
degradado. Aura y Letify aportan el lenguaje del resto: tarjetas con aire,
radio amplio, una sola jerarquía tipográfica y un acento violeta en claro y
en oscuro. No se copia la marca ajena, el texto de esa maqueta ni widgets de
banca.

## Qué se conserva

De `DESIGN.md` se mantiene la estructura, no el acabado:

- Rutas, permisos, confirmaciones y enlaces de Livewire.
- Barra lateral con las mismas secciones: espacio de trabajo, infraestructura
  y gestión.
- Barra superior con la identidad del recurso y la segunda capa de pestañas
  solo cuando hay rutas hermanas.
- Espacio de ajustes con navegación lateral y columna de contenido.
- Panel como resumen de proyectos y servidores, con los despliegues activos
  en una tabla compacta. No es un muro de métricas.
- Tablas densas cuando la colección crece. Tarjetas cuando la colección se
  recorre.
- Iconos de trazo, etiquetas en minúscula de frase y estados con pastilla.

## Qué cambia el acabado

`DESIGN.md` pide superficies casi neutras, radio de 8px, sin sombra marcada y
acento amarillo en oscuro. El acceso ya usa otro acabado. El resto del
producto sigue `DESIGN.md`:

- Lienzo claro cálido y lienzo oscuro azul profundo.
- Tarjetas a 16px, anillo fino y sombra suave.
- Acento violeta en los dos temas. El amarillo de oscuro no entra.
- En oscuro el panel es un mosaico de widgets: lienzo casi negro, tarjetas
  con degradado (coral, oliva, violeta, verde), número grande, barra o aro.
  El claro usa los mismos bloques con degradados claros y tinta oscura.
- El cristal del acceso se queda en el login. El resto no copia widgets de
  banca: los datos son proyectos, entornos, servidores y despliegues.

## Tokens

| Token | Claro | Oscuro | Uso |
|---|---|---|---|
| Lienzo | `#f6f4f1` | `#0e1220` | Fondo de página |
| Elevado | `#ffffff` | `#171c2e` | Tarjetas y barra |
| Tinta | `#1c1730` | `#f4f2fb` | Texto principal |
| Tenue | `#6d677c` | `#a39eb8` | Etiquetas y ayuda |
| Línea | `rgba(28, 23, 48, 0.08)` | `rgba(255, 255, 255, 0.08)` | Anillos y divisores |
| Acento | `#6d4dff` | `#8b7cff` | Acción, foco, activo |
| Degradado | `#7c5cff` → `#4f7dff` | el mismo | Botón principal del acceso |
| Radio | 16px tarjeta, 12px control | igual | Superficies |
| Sombra | `0 16px 40px rgba(40, 24, 80, 0.08)` | `0 16px 40px rgba(0, 0, 0, 0.35)` | Elevación |

Tipografía de interfaz: 14px. Título de página: 24px. Meta y etiquetas: 12px.
Controles: 40px de alto en el acceso y 36px en el panel.

## Acceso

Escritorio partido. A la izquierda, una marca geométrica de GPSH sobre un
fondo atmosférico. A la derecha, tarjeta con correo, contraseña, enlace de
olvido y botón violeta-azul. En ancho estrecho la escena se reduce a una
franja y el formulario ocupa la pantalla.

El claro usa la misma composición sobre un lienzo de niebla violeta y una
tarjeta blanca. El oscuro usa el lienzo azul y la tarjeta translúcida.

## Cascarón

El acceso comparte `x-auth.shell` con el registro, la recuperación y la
verificación. El tema es el de la aplicación (`theme` en `localStorage`).

## Segunda fase

El mosaico de widgets todavía no está en el panel, los proyectos ni los
ajustes. Cuando se apruebe, esos tokens pasan a
`resources/views/layouts/app.blade.php` y a las tarjetas de `DESIGN.md`.
