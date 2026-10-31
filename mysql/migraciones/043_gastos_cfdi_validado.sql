-- ===========================================================================
-- 043_gastos_cfdi_validado.sql — gastos con XML del SAT quedan «validado»
--   Repara los gastos que ya traen CFDI con folio fiscal (UUID) y quedaron en
--   «por_validar» antes de que existiera Gastos::marcarValidadoPorCfdi().
--   No toca «no_deducible» (decisión del contador) ni gastos sin UUID (PDF/foto).
--   El motor fiscal ya no filtra por revisión fiscal: esto solo alinea la
--   etiqueta que se ve en la lista de gastos y en el cierre.
--   No tiene reversión automática: volver a «por_validar» un CFDI auténtico
--   sería un dato incorrecto.
-- ===========================================================================
UPDATE gastos
   SET revision_fiscal = 'validado'
 WHERE estado = 'vigente'
   AND comprobante_fiscal = 1
   AND uuid_fiscal IS NOT NULL
   AND uuid_fiscal <> ''
   AND revision_fiscal = 'por_validar';
