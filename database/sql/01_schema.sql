-- =====================================================================
-- AlfaVía S.A.C. · Sistema web de monitoreo con IA
-- Esquema PostgreSQL (>= 13)
-- Uso:  psql -U postgres -c "CREATE DATABASE alfavia_monitoreo ENCODING 'UTF8'"
--       psql -U postgres -d alfavia_monitoreo -f 01_schema.sql
-- =====================================================================
BEGIN;

-- ---------- Accesos y seguridad (Paquete 1) --------------------------
CREATE TABLE roles (
    id          smallserial PRIMARY KEY,
    nombre      text NOT NULL UNIQUE,
    descripcion text NOT NULL
);

CREATE TABLE permisos (
    id          smallserial PRIMARY KEY,
    codigo      text NOT NULL UNIQUE,
    descripcion text NOT NULL
);

CREATE TABLE roles_permisos (
    rol_id     smallint NOT NULL REFERENCES roles(id)    ON DELETE CASCADE,
    permiso_id smallint NOT NULL REFERENCES permisos(id) ON DELETE CASCADE,
    PRIMARY KEY (rol_id, permiso_id)
);

CREATE TABLE usuarios (
    id                 serial PRIMARY KEY,
    usuario            text NOT NULL,
    nombre_completo    text NOT NULL,
    correo             text,
    clave_hash         text NOT NULL,
    rol_id             smallint NOT NULL REFERENCES roles(id),
    activo             boolean NOT NULL DEFAULT true,
    intentos_fallidos  integer NOT NULL DEFAULT 0,
    bloqueado_hasta    timestamptz,
    clave_cambiada_en  timestamptz NOT NULL DEFAULT now(),
    debe_cambiar_clave boolean NOT NULL DEFAULT true,
    ultimo_ingreso     timestamptz,
    tema               text NOT NULL DEFAULT 'auto' CHECK (tema IN ('auto','claro','oscuro')),
    creado_en          timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX usuarios_usuario_uq ON usuarios (lower(usuario));

CREATE TABLE politicas_seguridad (
    clave          text PRIMARY KEY,
    valor          text NOT NULL,
    tipo           text NOT NULL CHECK (tipo IN ('int','num','bool')),
    categoria      text NOT NULL,
    descripcion    text NOT NULL,
    min_valor      numeric,
    max_valor      numeric,
    actualizado_por integer REFERENCES usuarios(id) ON DELETE SET NULL,
    actualizado_en timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE auditoria (
    id             bigserial PRIMARY KEY,
    usuario_id     integer REFERENCES usuarios(id) ON DELETE SET NULL,
    usuario_nombre text,
    accion         text NOT NULL,
    entidad        text,
    entidad_id     text,
    detalle        jsonb NOT NULL DEFAULT '{}'::jsonb,
    ip             inet,
    agente_http    text,
    creado_en      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX auditoria_fecha_ix  ON auditoria (creado_en DESC);
CREATE INDEX auditoria_accion_ix ON auditoria (accion, creado_en DESC);

-- ---------- Inventario físico y trazabilidad (Paquete 2) -------------
CREATE TABLE ubicaciones (
    id          smallserial PRIMARY KEY,
    nombre      text NOT NULL UNIQUE,
    descripcion text
);

CREATE TABLE proveedores (
    id           serial PRIMARY KEY,
    razon_social text NOT NULL,
    ruc          char(11) UNIQUE CHECK (ruc ~ '^[0-9]{11}$'),
    tipo         text NOT NULL CHECK (tipo IN ('equipos','perifericos','repuestos','software','servicios')),
    contacto     text,
    telefono     text,
    correo       text,
    direccion    text,
    activo       boolean NOT NULL DEFAULT true,
    creado_en    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE estaciones (
    id                  serial PRIMARY KEY,
    codigo_activo       text NOT NULL UNIQUE,
    nombre_host         text NOT NULL,
    ubicacion_id        smallint NOT NULL REFERENCES ubicaciones(id),
    puesto              text,
    responsable         text,
    proveedor_id        integer REFERENCES proveedores(id),
    fecha_compra        date,
    garantia_hasta      date,
    marca               text,
    modelo              text,
    numero_serie        text,
    cpu_modelo          text,
    cpu_nucleos         smallint,
    ram_gb              smallint,
    disco_tipo          text,
    disco_gb            integer,
    gpu                 text,
    so                  text,
    ip                  inet,
    mac                 macaddr,
    estado              text NOT NULL DEFAULT 'sin_conexion'
                        CHECK (estado IN ('en_linea','sin_conexion','mantenimiento','baja')),
    ultimo_latido       timestamptz,
    agente_version      text,
    agente_token_hash   char(64) UNIQUE,
    agente_token_creado timestamptz,
    activo              boolean NOT NULL DEFAULT true,
    creado_en           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX estaciones_ubicacion_ix ON estaciones (ubicacion_id);

CREATE TABLE componentes (
    id                serial PRIMARY KEY,
    estacion_id       integer REFERENCES estaciones(id) ON DELETE SET NULL,
    tipo              text NOT NULL CHECK (tipo IN
                      ('procesador','ram','disco','diadema','mouse','teclado','monitor','otro')),
    marca             text,
    modelo            text,
    numero_serie      text,
    proveedor_id      integer REFERENCES proveedores(id),
    fecha_instalacion date,
    estado            text NOT NULL DEFAULT 'optimo'
                      CHECK (estado IN ('optimo','desgaste','critico','retirado')),
    notas             text,
    creado_en         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX componentes_estacion_ix ON componentes (estacion_id);

-- Historial de componentes que se sacaron o se intercambiaron
CREATE TABLE movimientos_componentes (
    id             bigserial PRIMARY KEY,
    componente_id  integer REFERENCES componentes(id) ON DELETE SET NULL,
    estacion_id    integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    accion         text NOT NULL CHECK (accion IN ('instalado','retirado','intercambiado')),
    descripcion    text NOT NULL,           -- "Diadema Logitech H390 → Diadema nueva"
    motivo         text,
    usuario_id     integer REFERENCES usuarios(id) ON DELETE SET NULL,
    fecha          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mov_comp_fecha_ix ON movimientos_componentes (estacion_id, fecha DESC);

CREATE TABLE mantenimientos (
    id          serial PRIMARY KEY,
    estacion_id integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    tipo        text NOT NULL CHECK (tipo IN ('preventivo','correctivo')),
    descripcion text NOT NULL,
    tecnico_id  integer REFERENCES usuarios(id) ON DELETE SET NULL,
    fecha       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mant_estacion_ix ON mantenimientos (estacion_id, fecha DESC);

-- Fallas reales: son las etiquetas con las que aprende el modelo de ML
CREATE TABLE fallas (
    id          serial PRIMARY KEY,
    estacion_id integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    fecha       timestamptz NOT NULL,
    tipo        text NOT NULL CHECK (tipo IN ('disco','sobrecalentamiento','ram','otro')),
    descripcion text
);
CREATE INDEX fallas_estacion_ix ON fallas (estacion_id, fecha);

-- ---------- Monitoreo en tiempo real y diagnóstico con IA (Paquete 3) ----
CREATE TABLE telemetria (
    id              bigserial PRIMARY KEY,
    estacion_id     integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    tomado_en       timestamptz NOT NULL DEFAULT now(),
    cpu_pct         real,
    ram_pct         real,
    temp_c          real,
    disco_usado_pct real,
    disco_salud_pct real,
    uptime_h        real
);
CREATE INDEX telemetria_estacion_ix ON telemetria (estacion_id, tomado_en DESC);

-- Historial de movimiento: la estación se conectó / se desconectó
CREATE TABLE eventos_conexion (
    id           bigserial PRIMARY KEY,
    estacion_id  integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    tipo         text NOT NULL CHECK (tipo IN ('conectada','desconectada')),
    detectado_en timestamptz NOT NULL DEFAULT now(),
    duracion_seg integer,                    -- solo en 'conectada': cuánto estuvo caída
    detalle      text
);
CREATE INDEX ev_con_ix ON eventos_conexion (estacion_id, detectado_en DESC);
CREATE INDEX ev_con_fecha_ix ON eventos_conexion (detectado_en DESC);

-- Dispositivos (periféricos) vistos por el agente en cada estación
CREATE TABLE dispositivos_detectados (
    id            bigserial PRIMARY KEY,
    estacion_id   integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    identificador text NOT NULL,
    nombre        text NOT NULL,
    categoria     text NOT NULL DEFAULT 'otro',
    presente      boolean NOT NULL DEFAULT true,
    visto_primera timestamptz NOT NULL DEFAULT now(),
    visto_ultima  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (estacion_id, identificador)
);

-- Historial de movimiento: se conectó / desconectó un dispositivo
CREATE TABLE eventos_dispositivo (
    id            bigserial PRIMARY KEY,
    estacion_id   integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    identificador text NOT NULL,
    nombre        text NOT NULL,
    categoria     text NOT NULL DEFAULT 'otro',
    tipo          text NOT NULL CHECK (tipo IN ('conectado','desconectado')),
    detectado_en  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ev_dis_ix ON eventos_dispositivo (estacion_id, detectado_en DESC);
CREATE INDEX ev_dis_fecha_ix ON eventos_dispositivo (detectado_en DESC);

CREATE TABLE predicciones (
    id             bigserial PRIMARY KEY,
    estacion_id    integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    calculado_en   timestamptz NOT NULL DEFAULT now(),
    prob_falla     real NOT NULL CHECK (prob_falla BETWEEN 0 AND 1),
    nivel          text NOT NULL CHECK (nivel IN ('normal','atencion','critico')),
    anomalia       real,
    causa_probable text,
    factores       jsonb NOT NULL DEFAULT '[]'::jsonb,
    modelo_version text NOT NULL
);
CREATE INDEX pred_estacion_ix ON predicciones (estacion_id, calculado_en DESC);

CREATE TABLE alertas (
    id           bigserial PRIMARY KEY,
    estacion_id  integer REFERENCES estaciones(id) ON DELETE CASCADE,
    tipo         text NOT NULL CHECK (tipo IN ('prediccion','desconexion','dispositivo','seguridad')),
    severidad    text NOT NULL CHECK (severidad IN ('info','media','alta')),
    mensaje      text NOT NULL,
    creada_en    timestamptz NOT NULL DEFAULT now(),
    atendida_por integer REFERENCES usuarios(id) ON DELETE SET NULL,
    atendida_en  timestamptz
);
CREATE INDEX alertas_abiertas_ix ON alertas (creada_en DESC) WHERE atendida_en IS NULL;

-- Vista de pantallas: cada sesión queda registrada (quién, a quién, por qué)
CREATE TABLE sesiones_pantalla (
    id                 bigserial PRIMARY KEY,
    estacion_id        integer NOT NULL REFERENCES estaciones(id) ON DELETE CASCADE,
    solicitado_por     integer NOT NULL REFERENCES usuarios(id),
    motivo             text,
    inicio             timestamptz NOT NULL DEFAULT now(),
    expira_en          timestamptz NOT NULL,
    fin                timestamptz,
    ultima_captura_en  timestamptz,
    motivo_fin         text
);
CREATE INDEX ses_pant_ix ON sesiones_pantalla (estacion_id) WHERE fin IS NULL;

-- Textos generados por IA (Gemini o resumen local), guardados para no repetir llamadas
CREATE TABLE resumenes_ia (
    id          bigserial PRIMARY KEY,
    tipo        text NOT NULL CHECK (tipo IN ('ingreso','estacion','reporte')),
    usuario_id  integer REFERENCES usuarios(id) ON DELETE SET NULL,
    estacion_id integer REFERENCES estaciones(id) ON DELETE CASCADE,
    desde       timestamptz,
    hasta       timestamptz,
    texto       text NOT NULL,
    origen      text NOT NULL CHECK (origen IN ('gemini','local')),
    modelo      text,
    creado_en   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX res_ia_ix ON resumenes_ia (tipo, estacion_id, creado_en DESC);

CREATE TABLE ciclo_estado (
    clave text PRIMARY KEY,
    valor timestamptz NOT NULL DEFAULT now(),
    nota  text
);

-- ---------- Vistas y funciones --------------------------------------
-- Historial unificado de movimientos (estaciones, dispositivos y componentes)
CREATE VIEW v_movimientos AS
SELECT 'conexion'::text AS categoria, c.id, c.detectado_en AS fecha, c.estacion_id,
       CASE c.tipo WHEN 'desconectada' THEN 'Estación desconectada' ELSE 'Estación reconectada' END AS titulo,
       COALESCE(c.detalle, '') ||
         CASE WHEN c.duracion_seg IS NOT NULL
              THEN ' (estuvo caída ' || round(c.duracion_seg / 60.0)::int || ' min)' ELSE '' END AS detalle,
       c.tipo AS subtipo
FROM eventos_conexion c
UNION ALL
SELECT 'dispositivo', d.id, d.detectado_en, d.estacion_id,
       CASE d.tipo WHEN 'desconectado' THEN 'Dispositivo desconectado' ELSE 'Dispositivo conectado' END,
       d.nombre || ' (' || d.categoria || ')', d.tipo
FROM eventos_dispositivo d
UNION ALL
SELECT 'componente', m.id, m.fecha, m.estacion_id,
       CASE m.accion WHEN 'retirado' THEN 'Componente retirado'
                     WHEN 'instalado' THEN 'Componente instalado'
                     ELSE 'Componente intercambiado' END,
       m.descripcion || COALESCE(' · ' || m.motivo, ''), m.accion
FROM movimientos_componentes m;

-- Última predicción de cada estación
CREATE VIEW v_ultima_prediccion AS
SELECT DISTINCT ON (estacion_id) *
FROM predicciones
ORDER BY estacion_id, calculado_en DESC;

-- Variables de entrada del modelo de ML (las mismas para entrenar y para predecir)
CREATE FUNCTION fn_features_estacion(p_estacion integer, p_corte timestamptz)
RETURNS TABLE (
    cpu_prom real, cpu_p95 real, ram_prom real, ram_max real,
    temp_prom real, temp_max real, disco_usado_max real, disco_salud_min real,
    desconexiones_7d integer, dispositivos_7d integer,
    edad_meses real, cambios_90d integer, muestras integer
)
LANGUAGE sql STABLE AS $$
    WITH t AS (
        SELECT * FROM telemetria
        WHERE estacion_id = p_estacion
          AND tomado_en >  p_corte - interval '24 hours'
          AND tomado_en <= p_corte
    )
    SELECT
        avg(cpu_pct)::real,
        (percentile_cont(0.95) WITHIN GROUP (ORDER BY cpu_pct))::real,
        avg(ram_pct)::real, max(ram_pct)::real,
        avg(temp_c)::real,  max(temp_c)::real,
        max(disco_usado_pct)::real, min(disco_salud_pct)::real,
        (SELECT count(*)::int FROM eventos_conexion c
          WHERE c.estacion_id = p_estacion AND c.tipo = 'desconectada'
            AND c.detectado_en >  p_corte - interval '7 days' AND c.detectado_en <= p_corte),
        (SELECT count(*)::int FROM eventos_dispositivo d
          WHERE d.estacion_id = p_estacion AND d.tipo = 'desconectado'
            AND d.detectado_en >  p_corte - interval '7 days' AND d.detectado_en <= p_corte),
        (SELECT COALESCE(EXTRACT(epoch FROM (p_corte - e.fecha_compra::timestamptz)) / 2629800, 0)::real
           FROM estaciones e WHERE e.id = p_estacion),
        (SELECT count(*)::int FROM movimientos_componentes m
          WHERE m.estacion_id = p_estacion
            AND m.fecha >  p_corte - interval '90 days' AND m.fecha <= p_corte),
        count(*)::int
    FROM t;
$$;

COMMIT;
