-- Ejecutar una sola vez si ya se habían creado los botones iniciales.
-- Tijeral se agregó automáticamente en la primera versión de Parte diario;
-- ahora solo se crean Camión Grúa y Montacargas por defecto.
DELETE FROM cobranzas_parte_diario_maquinarias WHERE nombre = 'Tijeral';
