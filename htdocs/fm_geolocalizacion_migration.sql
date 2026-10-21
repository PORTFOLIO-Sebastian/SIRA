-- Agrega coordenadas a fichas de monitoreo y evaluaciones.
-- Ejecutar una sola vez en bases existentes antes de generar nuevos PDFs.

ALTER TABLE fm_registros
  ADD COLUMN latitud DECIMAL(10,8) DEFAULT NULL AFTER fecha_envio,
  ADD COLUMN longitud DECIMAL(11,8) DEFAULT NULL AFTER latitud,
  ADD COLUMN precision_metros DECIMAL(10,2) DEFAULT NULL AFTER longitud;

ALTER TABLE fm_evaluaciones
  ADD COLUMN latitud DECIMAL(10,8) DEFAULT NULL AFTER fecha_inicio,
  ADD COLUMN longitud DECIMAL(11,8) DEFAULT NULL AFTER latitud,
  ADD COLUMN precision_metros DECIMAL(10,2) DEFAULT NULL AFTER longitud;
