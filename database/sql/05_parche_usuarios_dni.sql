-- Parche para una base YA importada: usuarios con formato nombre.apellido#alfavia y contraseña = DNI.
-- Reemplaza a los 3 usuarios demo antiguos (admin, jmonitoreo, isistemas) conservando su historial y roles.
-- DNI de ejemplo: 70000001, 70000002, 70000003. Crea tus usuarios reales con:
--   php artisan alfavia:crear-usuario Nombre Apellido 12345678 --rol="Administrador del Sistema"
BEGIN;
UPDATE usuarios SET usuario='carlos.mendoza#alfavia', nombre_completo='Carlos Mendoza', correo='carlos.mendoza@alfavia.example',
  clave_hash='$2y$12$/3fRiQzhmYcwAHVhrXwjLOHH0YpLgM4pw1tQN2ojGm9znLDV4MLlq' WHERE lower(usuario)='admin';
UPDATE usuarios SET usuario='lucia.torres#alfavia', nombre_completo='Lucia Torres', correo='lucia.torres@alfavia.example',
  clave_hash='$2y$12$41Tt0fBLq9Yp6dsIk8WZee5tlZwSyE9Eb/WoGUeCe4Vg2.yIpOB9q' WHERE lower(usuario)='jmonitoreo';
UPDATE usuarios SET usuario='diego.quispe#alfavia', nombre_completo='Diego Quispe', correo='diego.quispe@alfavia.example',
  clave_hash='$2y$12$wmoKP/DMdBGUIOEECT8piObBEm8lEyaAWZIKkxw/KX98QIPG8EDAG' WHERE lower(usuario)='isistemas';
-- La contraseña ya no se cambia ni vence
UPDATE usuarios SET debe_cambiar_clave=false, clave_cambiada_en=now(), intentos_fallidos=0, bloqueado_hasta=NULL;
COMMIT;
SELECT usuario, nombre_completo, activo FROM usuarios ORDER BY id;
