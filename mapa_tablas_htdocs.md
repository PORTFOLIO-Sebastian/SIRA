# Mapa de tablas reconstruido desde `htdocs/`

## Resumen

No se encontró un dump `.sql` real dentro del ZIP. La estructura fue inferida desde consultas `SELECT`, `INSERT`, `UPDATE`, `DELETE` y `JOIN` usadas en los archivos PHP de `htdocs/`.

## Tablas principales

| Tabla | Uso dentro del sistema |
|---|---|
| `usuarios` | Login, administración de usuarios, perfil, roles. |
| `instituciones` | Instituciones educativas asociadas a usuarios y envíos. |
| `asignaciones` | Relación usuario director/sub-director/docente con institución. |
| `asignaciones_especialistas` | Relación especialista con instituciones supervisadas. |
| `envios` | Documentos enviados por instituciones: mensual, gestión, innovación, ECRE. |
| `comunicados` | Comunicados/oficios/normas publicados por usuarios autorizados. |
| `comunicados_vistos` | Marca de lectura de comunicados por institución. |
| `data_direccion` | Catálogo auxiliar de personas/dirección usado en administración. |

## Relaciones inferidas

```text
usuarios.id ──< asignaciones.id_usuario
instituciones.id ──< asignaciones.id_ie

usuarios.id ──< asignaciones_especialistas.id_usuario
instituciones.id ──< asignaciones_especialistas.id_institucion

instituciones.id ──< envios.id_ie
usuarios.id ──< envios.id_usuario_envio

usuarios.id ──< comunicados.id_usuario
comunicados.id ──< comunicados_vistos.id_comunicado
instituciones.id ──< comunicados_vistos.id_ie
```

## Roles vistos en el código

```text
administrador
director
sub-director
docente
administrativo
especialista
inactivo
```

## Tipos de documento vistos en el código

```text
Mensual
RI
PAT
PCI
PEI
RD_CONEI
DocGestion
Innovacion
ECRE INICIO
ECRE PROCESO
ECRE SALIDA
```

## Archivos clave

| Archivo | Qué hace |
|---|---|
| `conexion.php` | Conexión MySQLi a la BD. |
| `login.php` | Autenticación por DNI y password plano. |
| `dashboard.php` | Panel de director/docente según asignación activa. |
| `administrador.php` | CRUD de usuarios, instituciones, asignaciones y revisión de envíos. |
| `procesar_usuario.php` | Inserta/actualiza usuarios. |
| `procesar_envio.php` | Registra envíos de documentos. |
| `enviar_documento.php` | Muestra estado de documentos enviados. |
| `ver_envios.php` | Vista de especialista para revisar envíos. |
| `procesar_evaluacion.php` | Actualiza estado/observación de envíos. |
| `Publicacion_Comunicados.php` | Publicación, edición y eliminación de comunicados. |
| `comunicados.php` | Listado de comunicados para institución. |
| `marcar_visto.php` | Registra lectura de comunicado. |
| `ver_constancia.php` | Genera vista de constancia de envío. |
```

## Nota importante

El SQL generado es una aproximación funcional para desarrollo local. No debe tratarse como esquema oficial de producción.
