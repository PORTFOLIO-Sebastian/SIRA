-- Agrega precision de geolocalizacion a bases donde ya existen latitud y longitud.
-- Usar si ya ejecutaste fm_geolocalizacion_migration.sql antes de esta actualizacion.

ALTER TABLE fm_registros
  ADD COLUMN precision_metros DECIMAL(10,2) DEFAULT NULL AFTER longitud;

ALTER TABLE fm_evaluaciones
  ADD COLUMN precision_metros DECIMAL(10,2) DEFAULT NULL AFTER longitud;
