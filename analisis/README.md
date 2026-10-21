# Extracción e importación de datos — sia.ugelchincha.gob.pe

Extrae los datos del panel de administrador del sistema **en vivo** `sia.ugelchincha.gob.pe`
y genera el SQL para cargarlos en tu base de datos destino (esquema `4582023_ugel`).

## Resultado final

- **`import_sia_a_destino.sql`** ← *este es el archivo que importas en phpMyAdmin.*
  Contiene 251 usuarios + 221 instituciones + 207 asignaciones I.E. + 196 asignaciones de
  especialistas, listos para tu base. Es **aditivo e idempotente** (se puede re-ejecutar
  sin duplicar).

## Cómo importar (phpMyAdmin)

1. Entra a phpMyAdmin y selecciona tu base destino.
2. (Opcional, recomendado para BD limpia) Antes de importar, ejecuta en la pestaña **SQL**
   el bloque de limpieza de más abajo para quitar los datos de prueba.
3. Pestaña **Importar** → elige `import_sia_a_destino.sql` → **Continuar**.
4. Verifica:
   ```sql
   SELECT COUNT(*) FROM usuarios;                  -- ~251 (+ los que ya tenías)
   SELECT COUNT(*) FROM instituciones;             -- ~221
   SELECT COUNT(*) FROM asignaciones;              -- ~207
   SELECT COUNT(*) FROM asignaciones_especialistas;-- ~196
   ```
5. Abre tu `administrador.php` → los datos aparecen en las 4 vistas.

### Bloque opcional de limpieza (datos de prueba)

Tu base destino traía 6 usuarios y 3 IE simuladas. Si quieres que solo queden los datos
reales (y que no “tapen” a los reales por DNI/código repetido), ejecuta ANTES de importar:

```sql
DELETE FROM asignaciones_especialistas;
DELETE FROM asignaciones;
DELETE FROM instituciones;                         -- cascada a envíos ligados
DELETE FROM usuarios WHERE dni IN ('00000000','11111111','22222222','33333333','44444444','77777777');
```
> Si tu usuario admin actual es uno de esos DNI, quítalo de la lista para no perder acceso
> (el import vuelve a crear el admin real `11111111` de sia con su contraseña real).

## Decisiones aplicadas (puedes cambiarlas regenerando)

| Tema | Decisión |
|------|----------|
| Contraseña | **password = DNI** para todos los usuarios (solicitado). |
| Nombre completo | Separado correctamente en `nombres` / `ape_pat` / `ape_mat` leyendo el **formulario de edición** de sia (no por heurística), por lo que apellidos compuestos como “DE LA CRUZ” quedan bien. |
| FK de asignaciones | Resueltas por JOIN con `dni` (usuario) y `codigo_local` (IE), no por IDs — evita choques con los IDs que ya tengas. Integridad verificada: 0 huérfanos. |
| Mojibake | ~10 nombres venían corruptos en el origen (ej. `PEÃ‘ALOZA`); se **repararon** a UTF-8 correcto (`PEÑALOZA`). |
| Teléfono/correo vacío | El placeholder `-` se convierte en `NULL`. |
| IDs | No se reusan los IDs de sia; la BD destino autogenera los suyos. |

## Scripts (para re-ejecutar / actualizar)

| Script | Qué hace |
|--------|----------|
| `scrape_sia.php` | Login + recorre todas las páginas de las 4 vistas → `data/*.csv` y `*.json`. |
| `enrich_usuarios.php` | 2º pase: lee el formulario de edición de cada usuario → `data/usuarios_full.json` (nombres separados + contraseña real). |
| `generar_import_sql.php` | Genera `import_sia_a_destino.sql` desde los JSON. |
| `inspeccionar_estructura.sql` | `SHOW CREATE TABLE` para revisar el esquema destino. |

Re-ejecución completa:
```bash
cd analisis
php scrape_sia.php && php enrich_usuarios.php && php generar_import_sql.php
```
Credenciales por defecto en los scripts; se pueden sobreescribir con `SIA_DNI` / `SIA_PASS`.

## Archivos de datos crudos (`data/`)

- `usuarios.{csv,json}` — listado tal cual (nombre junto).
- `usuarios_full.{csv,json}` — **enriquecido** (nombres separados, contraseña, rol/estado exactos).
- `instituciones.{csv,json}`
- `asignaciones_ie.{csv,json}`
- `asignaciones_especialistas.{csv,json}`

## Totales verificados (coinciden con el panel)

| Vista | Filas |
|-------|-------|
| usuarios | 251 |
| instituciones | 221 |
| asignaciones I.E. | 207 |
| asignaciones especialistas | 196 |
