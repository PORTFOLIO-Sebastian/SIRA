# Modulo de Asistencia

Este modulo vive aislado en `htdocs/asistencia/` y se integra con el sistema actual sin modificar las tablas existentes de usuarios, instituciones ni envios.

## Instalacion

1. Importe primero la base principal:

   ```sql
   awardspace_mysql8_ugel.sql
   ```

2. Importe despues la migracion del modulo:

   ```sql
   htdocs/asistencia/schema_asistencia.sql
   ```

   Si necesita revertir solo las tablas del modulo, use:

   ```sql
   htdocs/asistencia/rollback_asistencia.sql
   ```

3. Instale la dependencia de Excel desde `htdocs/`:

   ```bash
   composer require phpoffice/phpspreadsheet
   ```

4. Entre al modulo desde:

   ```text
   enviar_documento.php?tipo=Mensual
   ```

## Tablas nuevas

- `asistencia_personal`: trabajadores por institucion educativa.
- `asistencia_mensual`: cabecera del periodo mensual.
- `asistencia_detalle`: codigos diarios por trabajador.
- `asistencia_auditoria`: trazabilidad de cambios.

## Integracion

El modulo reutiliza `instituciones`, `usuarios` y `envios`. Al generar el envio mensual, inserta un registro `tipo_documento = 'Mensual'` en `envios`; por eso los paneles actuales de revision, estado y constancias continuan funcionando.

## Validaciones

- La IE siempre se obtiene desde `$_SESSION['id_ie']`.
- Solo se permiten codigos `A`, `I`, `J`, `L`, `P`, `T`, `H`.
- Sabados y domingos se bloquean visualmente en esta primera version.
- El envio no se genera si faltan registros en dias laborables.
- Personal con reportes se desactiva con baja logica; no se elimina fisicamente desde la interfaz.
