# Guía para Revertir o Desbloquear un Envío de Asistencia Mensual

Esta guía explica detalladamente cómo revertir un envío de asistencia mensual que ha sido registrado por error o que requiere correcciones. Una vez revertido, la grilla volverá a estar activa y lista para editar, y se podrán generar y registrar nuevos reportes en PDF y Excel.

---

## ¿Por qué se bloquea el mes?
Por seguridad, cuando un director hace clic en **"Generar y registrar envío"**:
1. Se crea un registro en la tabla `envios` con el estado `'Pendiente'`.
2. Se actualiza el estado del periodo en la tabla `asistencia_mensual` a `'enviado'`.
3. Esto bloquea la grilla de asistencia para prevenir alteraciones del documento oficial que ya se envió a la UGEL.

---

## Método 1: Desbloqueo Rápido vía SQL (Recomendado)

Si tienes acceso a **phpMyAdmin**, el método más rápido es ejecutar las siguientes consultas SQL. 

### Pasos:
1. Abre tu base de datos en **phpMyAdmin**.
2. Ve a la pestaña **SQL** en el menú superior.
3. Copia y pega las siguientes consultas, reemplazando los parámetros:
   * Reemplaza `'TU_CODIGO_LOCAL'` por el código modular del colegio (ej. `'567413_EBR'` o `'567413'`).
   * Reemplaza `6` por el número de mes a desbloquear (ej: `6` para Junio).
   * Reemplaza `2026` por el año.
4. Presiona **Continuar** para ejecutar las consultas.

```sql
-- 1. Cambia el estado de la asistencia mensual de 'enviado' a 'borrador' y desvincula el envío
UPDATE `asistencia_mensual` 
SET `estado` = 'borrador', `id_envio` = NULL 
WHERE `codigo_local` LIKE '%TU_CODIGO_LOCAL%' 
  AND `mes` = 6 
  AND `anio` = 2026;

-- 2. Elimina el envío registrado para permitir que se pueda volver a enviar el mes
DELETE FROM `envios` 
WHERE `id_ie` IN (SELECT `id` FROM `instituciones` WHERE `codigo_local` LIKE '%TU_CODIGO_LOCAL%') 
  AND `mes` = 6 
  AND `anio` = 2026 
  AND `tipo_documento` = 'Mensual';
```

---

## Método 2: Desbloqueo Visual desde phpMyAdmin

Si prefieres no usar código SQL, puedes hacerlo de forma manual en la interfaz gráfica de phpMyAdmin:

### Paso 1: Eliminar el envío en la tabla `envios`
1. Haz clic en la tabla **`envios`** en el menú izquierdo de phpMyAdmin.
2. Ve a la pestaña **Buscar** (Search) arriba.
3. Rellena los campos con los valores del envío que quieres revertir:
   * **`mes`**: `6` (Junio)
   * **`anio`**: `2026`
   * **`tipo_documento`**: `Mensual`
4. Presiona **Continuar**.
5. Ubica la fila de tu institución en el resultado y haz clic en **Borrar** (Delete) para confirmar la eliminación.

### Paso 2: Habilitar la edición en `asistencia_mensual`
1. Haz clic en la tabla **`asistencia_mensual`** en el menú izquierdo.
2. Ve a la pestaña **Buscar** (Search) y busca por:
   * **`codigo_local`**: El código modular del colegio (puedes usar `%567413%` para una búsqueda parcial).
   * **`mes`**: `6`
   * **`anio`**: `2026`
3. Presiona **Continuar**.
4. Haz clic en el botón **Editar** en la fila resultante.
5. Cambia el valor de la columna **`estado`** a `'borrador'`.
6. Limpia la columna **`id_envio`** (déjala en blanco o marca la casilla `NULL`).
7. Haz clic en **Continuar** para guardar los cambios.

---

## ⚠️ Advertencias Importantes
* **Documentos Oficiales:** No reviertas envíos que ya han sido revisados y marcados como **"Conforme"** por los especialistas de la UGEL, a menos que haya una solicitud explícita de corrección y subsanación.
* **Archivos Físicos:** Al hacer este proceso, los archivos PDF y Excel anteriores quedan desasociados de la base de datos. Al volver a enviar la asistencia, se generarán nuevos archivos actualizados que sobrescribirán o complementarán el expediente.
