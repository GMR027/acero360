-- Reversión de 048_pagos_gasto.sql — quita los abonos de gastos y sus vínculos.
DROP TABLE IF EXISTS complemento_pagos_gasto;
DROP TABLE IF EXISTS pagos_gasto;

DELETE FROM schema_migraciones WHERE nombre = '048_pagos_gasto.sql';
