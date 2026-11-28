-- =====================================================================
-- DATOS DE EJEMPLO (solo para demostración, NO cargar en producción)
-- Proveedores, 25 estaciones, componentes y 3 usuarios de prueba.
-- Los proveedores, RUC y teléfonos son ficticios: reemplázalos por los reales.
-- Usuarios de prueba: nombre.apellido#alfavia, contraseña = DNI (ver al final del archivo).
-- Token de agente de cada estación de prueba: demo-token-PC-001 ... demo-token-PC-025
-- =====================================================================
BEGIN;

INSERT INTO proveedores (razon_social, ruc, tipo, contacto, telefono, correo, direccion) VALUES
 ('Distribuidora TecnoAndina S.A.C.', '20999999001', 'equipos',     'Área comercial', '(01) 555-0101', 'ventas@tecnoandina.example', 'Av. Ejemplo 100, Lima'),
 ('Compusur Perú S.A.C.',             '20999999002', 'equipos',     'Mesa de ayuda',  '(01) 555-0102', 'soporte@compusur.example',   'Jr. Muestra 250, Lima'),
 ('Periféricos y Audio Lima E.I.R.L.','20999999003', 'perifericos', 'Ventas',         '(01) 555-0103', 'ventas@perifericos.example', 'Av. Demo 480, Lima'),
 ('Soporte Integral IT S.A.C.',       '20999999004', 'repuestos',   'Servicio técnico','(01) 555-0104','taller@soporteit.example',   'Calle Prueba 12, Lima');

INSERT INTO estaciones
 (codigo_activo, nombre_host, ubicacion_id, puesto, proveedor_id, fecha_compra, garantia_hasta,
  marca, modelo, numero_serie, cpu_modelo, cpu_nucleos, ram_gb, disco_tipo, disco_gb, gpu, so,
  ip, mac, estado, ultimo_latido, agente_version, agente_token_hash, agente_token_creado)
SELECT
  'PC-' || lpad(n::text, 3, '0'),
  'ALF-' || CASE WHEN n <= 4 THEN 'P1' WHEN n <= 22 THEN 'P2' ELSE 'P3' END || '-' || lpad(n::text, 3, '0'),
  CASE WHEN n <= 4 THEN 1 WHEN n <= 22 THEN 2 ELSE 3 END,
  CASE WHEN n <= 4 THEN 'Administración' WHEN n <= 22 THEN 'Atención OSIPTEL' ELSE 'Área de TI' END || ' · puesto ' || n,
  CASE WHEN n % 3 = 2 THEN 2 ELSE 1 END,
  date '2021-03-01' + ((n * 37) % 900),
  date '2021-03-01' + ((n * 37) % 900) + 1095,
  (ARRAY['HP','Lenovo','Dell'])[1 + n % 3],
  (ARRAY['ProDesk 400 G7','ThinkCentre M70s','OptiPlex 3080'])[1 + n % 3],
  'SN' || upper(substr(md5('estacion' || n), 1, 9)),
  CASE WHEN n >= 23 THEN 'Intel Core i7-10700' ELSE (ARRAY['Intel Core i5-10500','Intel Core i5-10400','Intel Core i3-10100'])[1 + n % 3] END,
  CASE WHEN n >= 23 THEN 8 ELSE (ARRAY[6,6,4])[1 + n % 3] END,
  CASE WHEN n >= 23 THEN 32 ELSE (ARRAY[16,16,8])[1 + n % 3] END,
  'SSD',
  CASE WHEN n >= 23 THEN 1000 ELSE (ARRAY[480,480,256])[1 + n % 3] END,
  'Intel UHD Graphics 630',
  CASE WHEN n % 5 = 0 THEN 'Windows 10 Pro 22H2' ELSE 'Windows 11 Pro 23H2' END,
  ('10.20.' || CASE WHEN n <= 4 THEN 1 WHEN n <= 22 THEN 2 ELSE 3 END || '.' || (10 + n))::inet,
  ('00:1A:2B:' || lpad(to_hex(n * 7 % 256), 2, '0') || ':' || lpad(to_hex(n * 13 % 256), 2, '0') || ':' || lpad(to_hex(n * 29 % 256), 2, '0'))::macaddr,
  'en_linea', now(), '1.0.0',
  encode(sha256(('demo-token-PC-' || lpad(n::text, 3, '0'))::bytea), 'hex'),
  now()
FROM generate_series(1, 25) AS n;

-- Componentes de cada estación
INSERT INTO componentes (estacion_id, tipo, marca, modelo, numero_serie, proveedor_id, fecha_instalacion, estado)
SELECT e.id, c.tipo, c.marca, c.modelo, 'SN-' || upper(c.tipo) || '-' || e.id, c.prov, e.fecha_compra + c.dias, 'optimo'
FROM estaciones e
CROSS JOIN LATERAL (VALUES
  ('procesador', 'Intel',    e.cpu_modelo,                                   e.proveedor_id, 0),
  ('ram',        'Kingston', 'ValueRAM DDR4 3200 ' || e.ram_gb || ' GB',     e.proveedor_id, 0),
  ('disco',      'Kingston', 'A400 SSD ' || e.disco_gb || ' GB',             e.proveedor_id, 0),
  ('diadema',    'Logitech', 'H390 USB',                                     3,              90),
  ('mouse',      'HP',       'X500 óptico USB',                              3,              90),
  ('teclado',    'HP',       'K1500 USB',                                    3,              90)
) AS c(tipo, marca, modelo, prov, dias);

-- Línea base de dispositivos (lo que el agente verá conectado)
INSERT INTO dispositivos_detectados (estacion_id, identificador, nombre, categoria)
SELECT e.id, d.ident, d.nombre, d.cat
FROM estaciones e
CROSS JOIN (VALUES
  ('usb:046d:0a38', 'Logitech H390 (diadema)', 'audio'),
  ('usb:03f0:094a', 'HP X500 (mouse)',         'mouse'),
  ('usb:03f0:0024', 'HP K1500 (teclado)',      'teclado')
) AS d(ident, nombre, cat);

-- Usuarios de prueba. Usuario = nombre.apellido#alfavia y contraseña = DNI (DNI de ejemplo, no reales). La contraseña no se cambia.
INSERT INTO usuarios (usuario, nombre_completo, correo, clave_hash, rol_id, debe_cambiar_clave, ultimo_ingreso)
SELECT u.usuario, u.nombre, u.correo, u.hash, r.id, false, now() - interval '2 days'
FROM (VALUES
  ('carlos.mendoza#alfavia', 'Carlos Mendoza', 'carlos.mendoza@alfavia.example', 'Administrador del Sistema', '$2y$12$/3fRiQzhmYcwAHVhrXwjLOHH0YpLgM4pw1tQN2ojGm9znLDV4MLlq'),  -- DNI 70000001
  ('lucia.torres#alfavia',   'Lucia Torres',   'lucia.torres@alfavia.example',   'Jefe de Monitoreo',         '$2y$12$41Tt0fBLq9Yp6dsIk8WZee5tlZwSyE9Eb/WoGUeCe4Vg2.yIpOB9q'),  -- DNI 70000002
  ('diego.quispe#alfavia',   'Diego Quispe',   'diego.quispe@alfavia.example',   'Ingeniero de Sistemas',     '$2y$12$wmoKP/DMdBGUIOEECT8piObBEm8lEyaAWZIKkxw/KX98QIPG8EDAG')   -- DNI 70000003
) AS u(usuario, nombre, correo, rol, hash)
JOIN roles r ON r.nombre = u.rol;

COMMIT;
