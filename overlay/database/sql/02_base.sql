-- Datos mínimos para que el sistema funcione (roles, permisos, políticas, ubicaciones).
-- Se carga SIEMPRE, también en producción.
BEGIN;

INSERT INTO roles (nombre, descripcion) VALUES
 ('Administrador del Sistema', 'Gestiona usuarios, políticas de seguridad, proveedores y la auditoría'),
 ('Jefe de Monitoreo',         'Supervisa estaciones, ve pantallas con motivo auditado y consulta reportes'),
 ('Ingeniero de Sistemas',     'Administra inventario y componentes, registra mantenimiento y atiende alertas');

INSERT INTO permisos (codigo, descripcion) VALUES
 ('dashboard.ver',        'Ver el panel de monitoreo con IA'),
 ('estaciones.ver',       'Ver estaciones y sus características'),
 ('estaciones.editar',    'Crear y editar estaciones, generar tokens del agente'),
 ('componentes.editar',   'Agregar, retirar e intercambiar componentes'),
 ('mantenimiento.registrar','Registrar mantenimientos e incidencias'),
 ('historial.ver',        'Ver el historial de movimientos'),
 ('pantallas.ver',        'Ver las pantallas de las estaciones (queda auditado)'),
 ('proveedores.ver',      'Ver proveedores'),
 ('proveedores.editar',   'Crear y editar proveedores'),
 ('alertas.atender',      'Marcar alertas como atendidas'),
 ('reportes.ver',         'Generar y exportar reportes'),
 ('politicas.ver',        'Ver las políticas de seguridad'),
 ('politicas.editar',     'Modificar las políticas de seguridad'),
 ('usuarios.gestionar',   'Crear usuarios, asignar roles, desbloquear y restablecer claves'),
 ('auditoria.ver',        'Consultar el registro de auditoría');

-- Administrador del Sistema: todo
INSERT INTO roles_permisos
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p WHERE r.nombre = 'Administrador del Sistema';

-- Jefe de Monitoreo
INSERT INTO roles_permisos
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN
 ('dashboard.ver','estaciones.ver','historial.ver','pantallas.ver','proveedores.ver',
  'alertas.atender','reportes.ver','politicas.ver','auditoria.ver')
WHERE r.nombre = 'Jefe de Monitoreo';

-- Ingeniero de Sistemas
INSERT INTO roles_permisos
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN
 ('dashboard.ver','estaciones.ver','estaciones.editar','componentes.editar','mantenimiento.registrar',
  'historial.ver','proveedores.ver','proveedores.editar','alertas.atender','reportes.ver','politicas.ver')
WHERE r.nombre = 'Ingeniero de Sistemas';

INSERT INTO ubicaciones (nombre, descripcion) VALUES
 ('Piso 1',           'Recepción y administración'),
 ('Piso 2 - OSIPTEL', 'Estaciones de atención OSIPTEL'),
 ('Piso 3 - TI',      'Área de TI y monitoreo');

-- Políticas de seguridad (editables por el Administrador del Sistema)
INSERT INTO politicas_seguridad (clave, valor, tipo, categoria, descripcion, min_valor, max_valor) VALUES
 ('clave_longitud_min',     '10',   'int',  'Accesos', 'Longitud mínima de la contraseña', 8, 64),
 ('clave_complejidad',      'true', 'bool', 'Accesos', 'Exigir mayúscula, minúscula, número y símbolo', NULL, NULL),
 ('clave_vigencia_dias',    '90',   'int',  'Accesos', 'Días antes de que la contraseña venza y deba cambiarse', 30, 365),
 ('intentos_max',           '5',    'int',  'Accesos', 'Intentos fallidos antes de bloquear la cuenta', 3, 10),
 ('bloqueo_minutos',        '15',   'int',  'Accesos', 'Minutos que dura el bloqueo de la cuenta', 5, 240),
 ('sesion_inactividad_min', '20',   'int',  'Accesos', 'Minutos de inactividad antes de cerrar la sesión', 5, 120),
 ('pantalla_requiere_motivo','true','bool', 'Vista de pantallas', 'Exigir un motivo escrito para ver una pantalla', NULL, NULL),
 ('pantalla_duracion_max_min','15', 'int',  'Vista de pantallas', 'Duración máxima de una vista de pantalla (minutos)', 1, 60),
 ('pantalla_aviso_visible', 'true', 'bool', 'Vista de pantallas', 'El agente muestra un aviso visible en la estación mientras se comparte la pantalla (si no puede mostrarlo, no envía imagen)', NULL, NULL),
 ('ia_enviar_a_gemini',     'true', 'bool', 'Inteligencia artificial', 'Permitir enviar resúmenes técnicos (sin capturas ni nombres de personas) a la API de Gemini', NULL, NULL),
 ('ia_umbral_atencion',     '0.25', 'num',  'Inteligencia artificial', 'Probabilidad de falla desde la que una estación pasa a «atención»', 0.05, 0.95),
 ('ia_umbral_critico',      '0.50', 'num',  'Inteligencia artificial', 'Probabilidad de falla desde la que una estación pasa a «crítico»', 0.10, 0.99),
 ('ia_ciclo_segundos',      '60',   'int',  'Inteligencia artificial', 'Cada cuántos segundos se recalculan las predicciones', 15, 3600),
 ('latido_intervalo_seg',   '30',   'int',  'Agentes', 'Cada cuántos segundos reporta cada agente', 10, 300),
 ('offline_tras_seg',       '90',   'int',  'Agentes', 'Segundos sin latido para considerar la estación desconectada', 30, 900),
 ('token_agente_vigencia_dias','180','int', 'Agentes', 'Días de vigencia recomendada del token de un agente (luego se marca para rotar)', 30, 730),
 ('telemetria_retencion_dias','90', 'int',  'Datos', 'Días que se conserva la telemetría detallada', 30, 730),
 ('auditoria_retencion_dias','365', 'int',  'Datos', 'Días que se conserva el registro de auditoría', 90, 3650);

COMMIT;
