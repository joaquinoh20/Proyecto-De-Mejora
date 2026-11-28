-- =====================================================================
-- DATOS SINTÉTICOS DE DEMOSTRACIÓN (no son mediciones reales)
-- 90 días de telemetría por hora de las 25 estaciones, con fallas pasadas que
-- se anuncian con señales (disco que se degrada, temperatura que sube, RAM saturada),
-- más movimientos recientes para que el resumen «desde tu último ingreso» tenga contenido.
-- Sirve para entrenar y probar el modelo de ML. En producción se entrena con datos reales.
-- Requiere haber cargado 01, 02 y 03. No cargar en producción.
-- =====================================================================
BEGIN;
SELECT setseed(0.2026);

CREATE TEMP TABLE _drift (estacion_id integer, fecha timestamptz, tipo text, pasada boolean) ON COMMIT DROP;

-- Fallas pasadas (la mitad de las estaciones, una vez cada una)
INSERT INTO _drift
SELECT e.id,
       date_trunc('hour', now() - interval '10 days' - random() * interval '72 days'),
       (ARRAY['disco','sobrecalentamiento','ram'])[1 + floor(random() * 3)::int],
       true
FROM estaciones e WHERE random() < 0.5;

-- Tres estaciones que hoy se están deteriorando (todavía no fallan)
INSERT INTO _drift
SELECT e.id, date_trunc('hour', now()) + x.dias, x.tipo, false
FROM estaciones e
JOIN (VALUES ('PC-007', interval '2 days', 'disco'),
             ('PC-013', interval '3 days', 'sobrecalentamiento'),
             ('PC-019', interval '4 days', 'ram')) AS x(codigo, dias, tipo)
  ON x.codigo = e.codigo_activo;

-- Telemetría horaria de 90 días
INSERT INTO telemetria (estacion_id, tomado_en, cpu_pct, ram_pct, temp_c, disco_usado_pct, disco_salud_pct, uptime_h)
SELECT b.id, b.t,
  round(LEAST(99, GREATEST(2,
     20 + (b.id * 7 % 18) + CASE WHEN b.lab THEN 18 ELSE -6 END + (random() - 0.5) * 10
     + COALESCE(b.d, 0) * CASE b.tipo WHEN 'disco' THEN 5 WHEN 'sobrecalentamiento' THEN 14 WHEN 'ram' THEN 8 ELSE 0 END))::numeric, 1),
  round(LEAST(99, GREATEST(10,
     38 + (b.id * 5 % 22) + CASE WHEN b.lab THEN 8 ELSE -4 END + (random() - 0.5) * 6
     + CASE WHEN b.tipo = 'ram' THEN 38 * COALESCE(b.d, 0) ELSE 0 END))::numeric, 1),
  round(LEAST(95, GREATEST(30,
     43 + (b.id * 3 % 7) + CASE WHEN b.lab THEN 6 ELSE 0 END + (random() - 0.5) * 3
     + CASE WHEN b.tipo = 'sobrecalentamiento' THEN 30 * power(COALESCE(b.d, 0), 1.5) ELSE 0 END))::numeric, 1),
  round(LEAST(99, 55 + (b.id * 11 % 25) + EXTRACT(epoch FROM (b.t - (now() - interval '90 days'))) / 86400 * 0.05)::numeric, 1),
  round(GREATEST(5, 99 - (b.id % 3) + (random() - 0.5)
     - CASE WHEN b.tipo = 'disco' THEN 42 * power(COALESCE(b.d, 0), 1.2) ELSE 0 END)::numeric, 1),
  round((24 + random() * 200)::numeric, 1)
FROM (
  SELECT e.id, g.t, dr.tipo, dr.d,
         (EXTRACT(isodow FROM g.t) < 6
          AND EXTRACT(hour FROM g.t AT TIME ZONE 'America/Lima') BETWEEN 8 AND 18) AS lab
  FROM estaciones e
  CROSS JOIN generate_series(date_trunc('hour', now()) - interval '90 days',
                             date_trunc('hour', now()), interval '1 hour') AS g(t)
  LEFT JOIN LATERAL (
      SELECT f.tipo, GREATEST(0, 1 - EXTRACT(epoch FROM (f.fecha - g.t)) / (6 * 86400.0)) AS d
      FROM _drift f
      WHERE f.estacion_id = e.id AND f.fecha > g.t AND f.fecha <= g.t + interval '6 days'
      ORDER BY f.fecha LIMIT 1
  ) dr ON true
  WHERE NOT EXISTS (SELECT 1 FROM _drift f
                    WHERE f.estacion_id = e.id AND f.pasada
                      AND g.t >= f.fecha AND g.t < f.fecha + interval '8 hours')
) b;

-- Fallas pasadas y todo lo que dejaron: caída, reposición, mantenimiento e intercambio de pieza
INSERT INTO fallas (estacion_id, fecha, tipo, descripcion)
SELECT estacion_id, fecha, tipo,
  CASE tipo WHEN 'disco' THEN 'Disco con sectores dañados; se reemplazó por una unidad nueva'
            WHEN 'sobrecalentamiento' THEN 'Apagado por temperatura; se renovó el ventilador y la pasta térmica'
            ELSE 'Memoria RAM defectuosa; se reemplazó el módulo' END
FROM _drift WHERE pasada;

INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, detalle)
SELECT estacion_id, 'desconectada', fecha, 'Sin latidos del agente' FROM _drift WHERE pasada;

INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, duracion_seg, detalle)
SELECT estacion_id, 'conectada', fecha + interval '8 hours', 8 * 3600, 'Estación repuesta tras la falla' FROM _drift WHERE pasada;

INSERT INTO mantenimientos (estacion_id, tipo, descripcion, tecnico_id, fecha)
SELECT f.estacion_id, 'correctivo', f.descripcion, (SELECT id FROM usuarios WHERE usuario = 'isistemas'), f.fecha + interval '5 hours'
FROM fallas f;

INSERT INTO movimientos_componentes (componente_id, estacion_id, accion, descripcion, motivo, usuario_id, fecha)
SELECT c.id, f.estacion_id, 'intercambiado',
       initcap(c.tipo) || ' ' || c.marca || ' ' || c.modelo || ' → repuesto nuevo',
       'Reemplazo tras falla de ' || f.tipo,
       (SELECT id FROM usuarios WHERE usuario = 'isistemas'), f.fecha + interval '6 hours'
FROM fallas f
JOIN componentes c ON c.estacion_id = f.estacion_id
 AND c.tipo = CASE f.tipo WHEN 'disco' THEN 'disco' WHEN 'ram' THEN 'ram' ELSE 'procesador' END;

UPDATE componentes c SET fecha_instalacion = f.fecha::date
FROM fallas f
WHERE c.estacion_id = f.estacion_id
  AND c.tipo = CASE f.tipo WHEN 'disco' THEN 'disco' WHEN 'ram' THEN 'ram' ELSE 'procesador' END;

-- ---------------- Movimientos recientes (los verá quien ingrese hoy) ----------------
-- PC-017 está desconectada desde hace 55 minutos
DELETE FROM telemetria WHERE estacion_id = (SELECT id FROM estaciones WHERE codigo_activo = 'PC-017')
                         AND tomado_en > now() - interval '55 minutes';
UPDATE estaciones SET estado = 'sin_conexion', ultimo_latido = now() - interval '55 minutes'
 WHERE codigo_activo = 'PC-017';
INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, detalle)
SELECT id, 'desconectada', now() - interval '53 minutes', 'Sin latidos del agente' FROM estaciones WHERE codigo_activo = 'PC-017';

-- PC-022 se cayó ayer 29 minutos
INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, detalle)
SELECT id, 'desconectada', date_trunc('day', now()) - interval '14 hours 48 minutes', 'Sin latidos del agente' FROM estaciones WHERE codigo_activo = 'PC-022';
INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, duracion_seg, detalle)
SELECT id, 'conectada', date_trunc('day', now()) - interval '14 hours 19 minutes', 29 * 60, 'El agente volvió a reportar' FROM estaciones WHERE codigo_activo = 'PC-022';

-- PC-003: se desconectó la diadema y sigue desconectada
INSERT INTO eventos_dispositivo (estacion_id, identificador, nombre, categoria, tipo, detectado_en)
SELECT id, 'usb:046d:0a38', 'Logitech H390 (diadema)', 'audio', 'desconectado', now() - interval '26 hours' FROM estaciones WHERE codigo_activo = 'PC-003';
UPDATE dispositivos_detectados SET presente = false, visto_ultima = now() - interval '26 hours'
 WHERE identificador = 'usb:046d:0a38' AND estacion_id = (SELECT id FROM estaciones WHERE codigo_activo = 'PC-003');

-- PC-011: el mouse se desconectó y volvió
INSERT INTO eventos_dispositivo (estacion_id, identificador, nombre, categoria, tipo, detectado_en)
SELECT id, 'usb:03f0:094a', 'HP X500 (mouse)', 'mouse', t.tipo, t.cuando
FROM estaciones e CROSS JOIN (VALUES ('desconectado', now() - interval '6 hours'), ('conectado', now() - interval '5 hours 40 minutes')) AS t(tipo, cuando)
WHERE e.codigo_activo = 'PC-011';

-- PC-009: se retiró una diadema; PC-005: se intercambió otra
UPDATE componentes SET estado = 'retirado'
 WHERE tipo = 'diadema' AND estacion_id = (SELECT id FROM estaciones WHERE codigo_activo = 'PC-009');
INSERT INTO movimientos_componentes (componente_id, estacion_id, accion, descripcion, motivo, usuario_id, fecha)
SELECT c.id, e.id, 'retirado', 'Diadema Logitech H390 USB', 'Almohadilla dañada; pendiente de reposición',
       (SELECT id FROM usuarios WHERE usuario = 'isistemas'), now() - interval '30 hours'
FROM estaciones e JOIN componentes c ON c.estacion_id = e.id AND c.tipo = 'diadema' WHERE e.codigo_activo = 'PC-009';
INSERT INTO movimientos_componentes (componente_id, estacion_id, accion, descripcion, motivo, usuario_id, fecha)
SELECT c.id, e.id, 'intercambiado', 'Diadema Logitech H390 USB → diadema nueva', 'Micrófono sin señal',
       (SELECT id FROM usuarios WHERE usuario = 'isistemas'), now() - interval '20 hours'
FROM estaciones e JOIN componentes c ON c.estacion_id = e.id AND c.tipo = 'diadema' WHERE e.codigo_activo = 'PC-005';

-- Alertas abiertas correspondientes
INSERT INTO alertas (estacion_id, tipo, severidad, mensaje, creada_en)
SELECT e.id, a.tipo, a.sev, a.msg, a.cuando
FROM estaciones e JOIN (VALUES
  ('PC-017', 'desconexion', 'alta',  'La estación dejó de reportar hace 55 minutos',                        now() - interval '53 minutes'),
  ('PC-003', 'dispositivo', 'media', 'Se desconectó el dispositivo Logitech H390 (diadema)',               now() - interval '26 hours')
) AS a(codigo, tipo, sev, msg, cuando) ON a.codigo = e.codigo_activo;

COMMIT;
ANALYZE;
