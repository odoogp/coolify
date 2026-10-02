# Dirección visual profesional de GPSH

El acceso usa la escena y la tarjeta de `x-auth.shell`. El resto de la
aplicación usa el mismo acabado: lienzo, acento violeta y tarjetas de color
en proyectos, servidores, orígenes y ajustes. No hay una segunda interfaz.

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
acento amarillo en oscuro. El producto usa este otro acabado:

- Lienzo claro cálido y lienzo oscuro azul profundo.
- Tarjetas a 16px, anillo fino y sombra suave.
- Acento violeta en los dos temas. El amarillo de oscuro no entra.
- El panel usa cristal neutro, del tipo vidrio esmerilado: el fondo se
  transparenta, el borde lleva una línea de luz y el texto queda oscuro en
  claro y claro en oscuro. El color fuerte se reserva al botón principal.
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

## Dónde está

El cascarón, las tarjetas de recurso y los estados vacíos viven en
`resources/css/app.css`. El tema personalizado sigue usando el color que
elige la persona y no pisa ese degradado.
