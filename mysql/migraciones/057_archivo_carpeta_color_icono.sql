-- ===========================================================================
-- 057_archivo_carpeta_color_icono.sql — Color e icono por carpeta del Archivo
--
-- Cada carpeta virtual puede identificarse visualmente con un color y un
-- icono propios:
--   • color: hex #rrggbb de una paleta cerrada (ArchivoCarpeta::COLORES).
--   • icono: nombre de Material Symbols (ArchivoCarpeta::GRUPOS_ICONOS).
-- Los valores vacíos o desconocidos se resuelven en el modelo con el color
-- azul acero y el icono de carpeta por defecto.
-- ===========================================================================

ALTER TABLE archivo_carpetas
    ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '' AFTER nombre,
    ADD COLUMN icono VARCHAR(40) NOT NULL DEFAULT 'folder' AFTER color;
