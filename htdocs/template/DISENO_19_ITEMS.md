# Diseño — Distribución de los 19 ítems en Fortalezas / Debilidades

Documento de diseño para la generación del Informe de Monitoreo a partir de
`info_templeate.docx`. Define **solo la lógica de los 19 ítems**; el botón
"Generar Informe" se implementa después.

## 1. Origen de los datos

Por cada IE (registro de ficha `fm_registros.id`):

- **Ítems**: `fm_items` donde `id_version_ficha = <versión del registro>`,
  `estado = 'ACTIVO'` y `tipo_respuesta = 'ESCALA_1_4'`. Son los 19 ítems.
- **Respuesta de cada ítem** (escala 1–4 + observación): se prefiere la
  **evaluación del especialista**; si la IE aún no fue evaluada, se cae a la
  autoevaluación del director.

| Prioridad | Tabla de respuestas | Vínculo | Campos usados |
|-----------|---------------------|---------|---------------|
| 1 (preferida) | `fm_respuestas_especialista` | vía `fm_evaluaciones` (`estado_evaluacion='FINALIZADA'`) | `respuesta_escala`, `observacion` |
| 2 (fallback)  | `fm_respuestas_director`     | vía `fm_registros` (`activo=1`, último envío) | `respuesta_escala`, `observacion` |

> Misma preferencia que aplica `vista_reportes.php` (especialista → director).
> La escala, las observaciones, las fortalezas/debilidades y el puntaje salen
> todos de la **misma fuente** (la del especialista cuando existe).

## 2. Texto de cada bullet

El texto del bullet = **`fm_items.pregunta`** (la descripción completa del ítem,
tal como aparece en el formulario).

- La columna `fm_items.titulo` **NO** se usa: en el sistema se guarda como
  `"Ítem 1"`, `"Ítem 2"`… (placeholder genérico, sin contenido).
- Cero mantenimiento: si la ficha cambia, los bullets se actualizan solos.

## 3. Regla de clasificación (escala → nivel → destino)

`respuesta_escala` es 1–4. Mapeo oficial del sistema (`$ruLabels`):

| escala | nivel      | destino en el informe |
|:------:|------------|-----------------------|
| 4      | DESTACADO  | **Fortaleza** (`{{FORTALEZA}}`) |
| 3      | LOGRADO    | **Fortaleza** (`{{FORTALEZA}}`) |
| 2      | PROCESO    | **Debilidad** (`{{DEBILIDAD}}`) |
| 1      | INICIO     | **Debilidad** (`{{DEBILIDAD}}`) |

Coincide con los encabezados de la plantilla:
*Fortalezas (Destacado/Logrado)* y *Debilidades (Inicio/Proceso)*.

## 4. Reglas de inclusión / orden

- Se **excluye** todo ítem con `no_aplica = 1` o sin `respuesta_escala`
  → por eso pueden salir **menos de 19** bullets en total.
- Orden dentro de cada grupo: **por escala** (decisión confirmada):
  - Fortalezas: DESTACADO → LOGRADO (escala 4 → 3), luego `numero_item`.
  - Debilidades: INICIO → PROCESO (escala 1 → 2), luego `numero_item`.
- Si un grupo queda vacío, se emite **un solo bullet con el texto literal
  `[VACIO]`** (placeholder visible, estilo `[Añadir]`).

## 5. Manejo de la plantilla (bullets dinámicos)

`info_templeate.docx` debe quedar con **un solo** bullet plantilla por grupo:

```
Fortalezas (Destacado/Logrado):
  • {{FORTALEZA}}        ← un único bullet plantilla

Debilidades (Inicio/Proceso):
  • {{DEBILIDAD}}        ← un único bullet plantilla
```

El generador **clona ese párrafo-bullet** una vez por cada ítem del grupo,
conservando estilo, viñeta y formato. Así soporta cantidades variables
(p. ej. 11 fortalezas + 8 debilidades) sin tocar la plantilla.

> Acción sobre la plantilla: reducir los 4 bullets `{{FORTALEZA}}` y los
> ~6 `{{DEBILIDAD}}` actuales a **1 + 1**.

## 6. Pseudocódigo

```
items = SELECT i.numero_item, i.pregunta, resp.respuesta_escala, resp.no_aplica
        FROM fm_items i
        LEFT JOIN <respuestas preferidas> resp ON resp.id_item = i.id
        WHERE i.id_version_ficha = :vid
          AND i.estado = 'ACTIVO'
          AND i.tipo_respuesta = 'ESCALA_1_4'
        ORDER BY i.orden, i.numero_item

fortalezas = []   // escala 3 ó 4
debilidades = []  // escala 1 ó 2

for it in items:
    if it.no_aplica == 1 or it.respuesta_escala is null: continue
    if it.respuesta_escala >= 3: fortalezas.push(it)
    else:                         debilidades.push(it)

// Orden por escala
fortalezas.sort_by(escala DESC, numero_item ASC)   // 4 (DESTACADO) → 3 (LOGRADO)
debilidades.sort_by(escala ASC,  numero_item ASC)  // 1 (INICIO)   → 2 (PROCESO)

// Grupo vacío → bullet único "[VACIO]"
if fortalezas.isEmpty():  fortalezas  = ["[VACIO]"]
if debilidades.isEmpty(): debilidades = ["[VACIO]"]

// En el .docx: clonar el bullet {{FORTALEZA}} por cada fortalezas[i].pregunta
//              clonar el bullet {{DEBILIDAD}} por cada debilidades[i].pregunta
```

## 6b. Bloque narrativo de los 19 ítems

Cada uno de los 19 párrafos narrativos (sección ANÁLISIS) tiene la forma:

```
<pregunta del ítem (fija)>, está en {{ESCALA_ITEMn}}, {{OBS_ITEMn}}
```

- `{{ESCALA_ITEMn}}` → etiqueta INICIO/PROCESO/LOGRADO/DESTACADO del ítem n.
- `{{OBS_ITEMn}}` → `observacion` del ítem n (texto del especialista en la BD).
- La **pregunta** queda como texto fijo (es constante para esta ficha); solo
  escala y observación son dinámicas. *(Si se quisiera soportar varias fichas,
  habría que templar también la pregunta como `{{PREGUNTA_ITEMn}}`.)*

## 7. Decisiones confirmadas

- ✅ Fuente de datos = **especialista → director** (fallback). Misma fuente para
  escala, observaciones, fortalezas/debilidades y puntaje.
- ✅ Observaciones por ítem → `{{OBS_ITEM1..19}}` desde `observacion` de la BD.
- ✅ Texto de fortaleza/debilidad = `fm_items.pregunta` (descripción completa).
- ✅ Fortaleza = escala 3–4; Debilidad = escala 1–2.
- ✅ Orden por escala: fortalezas 4→3, debilidades 1→2.
- ✅ Grupo vacío → bullet único con texto literal `[VACIO]`.
- ✅ Bullets dinámicos: 1 bullet plantilla por grupo, clonado por ítem.
- ✅ Nº del INFORME principal → `[Añadir]` + `{{ANIO}}` (manual/dinámico).
- ✅ Cabecera del OFICIO y la REF (`OFÍCIO Nº …-2026`, `INFORME Nº 022 - 2026`,
  `R.M.`/`R.D.`) quedan **fijas**: son referencias de reglamentos y NO se
  templan. El generador no las toca.

## 9. Implementación

- **Generador**: `generar_informe_docx.php?id_registro=N` — carga datos
  (especialista→director), calcula puntaje/%/escala/crítico, reparte
  fortalezas/debilidades, clona los bullets `{{FORTALEZA}}`/`{{DEBILIDAD}}`,
  reemplaza todos los marcadores y descarga el `.docx` (usa `ZipArchive`).
- **Botón**: columna "Informe Word" en la tabla *Fichas con evaluación
  finalizada* de `especialista_fichas.php` (usa `$f['id']` = id_registro).
- **Backups de plantilla**: `info_templeate.orig.docx` (original),
  `info_templeate.pre_obs.docx` (antes de añadir OBS).

## 8. Contexto de la sección RESULTADOS (referencia)

- 19 ítems × 4 puntos = **puntaje máximo 76**.
- Escala valorativa → valoración:
  0–25% INICIO · 26–50% PROCESO · 51–75% LOGRADO · 76–100% DESTACADO.
