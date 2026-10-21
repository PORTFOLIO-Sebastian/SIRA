-- ============================================================
--  INSPECCIONAR ESTRUCTURA DE LA BASE DE DATOS DESTINO
--  Pega esto en la pestaña "SQL" de phpMyAdmin.
--
--  Aquí el nombre de la tabla va calificado con la base
--  `4582023_ugel` para evitar el error "#1046 - No database selected".
--  Si tu BD destino es OTRA, cambia 4582023_ugel por el nombre correcto.
--
--  SHOW CREATE TABLE devuelve la definición COMPLETA (columnas, tipos,
--  ENUM de rol/estado, llaves foráneas). Cópiame la columna "Create Table".
--  Ejecútalas de una en una.
-- ============================================================

SHOW CREATE TABLE `4582023_ugel`.`usuarios`;

SHOW CREATE TABLE `4582023_ugel`.`instituciones`;

SHOW CREATE TABLE `4582023_ugel`.`asignaciones`;

SHOW CREATE TABLE `4582023_ugel`.`asignaciones_especialistas`;
