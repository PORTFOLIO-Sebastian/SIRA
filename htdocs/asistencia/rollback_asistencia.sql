-- Rollback del modulo de asistencia.
-- Usar solo si necesita retirar la migracion `schema_asistencia.sql`.
-- Este script elimina las tablas propias del modulo y no modifica `envios`,
-- `usuarios`, `instituciones` ni otras tablas existentes.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS asistencia_detalle;
DROP TABLE IF EXISTS asistencia_auditoria;
DROP TABLE IF EXISTS asistencia_mensual;
DROP TABLE IF EXISTS asistencia_personal;

SET FOREIGN_KEY_CHECKS = 1;
