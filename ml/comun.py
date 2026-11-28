"""Código compartido por train.py y service.py."""
import io
import os
import subprocess
from pathlib import Path

import pandas as pd

RAIZ = Path(__file__).resolve().parent.parent
MODELO_PATH = Path(__file__).resolve().parent / "models" / "modelo_falla.joblib"

# Variables de entrada (las calcula fn_features_estacion en PostgreSQL)
FEATURES = [
    "cpu_prom", "cpu_p95", "ram_prom", "ram_max", "temp_prom", "temp_max",
    "disco_usado_max", "disco_salud_min", "desconexiones_7d", "dispositivos_7d",
    "edad_meses", "cambios_90d",
]

ETIQUETAS = {
    "cpu_prom": ("Uso de CPU promedio 24 h", "%"),
    "cpu_p95": ("Picos de CPU (p95) 24 h", "%"),
    "ram_prom": ("Uso de RAM promedio 24 h", "%"),
    "ram_max": ("Pico de RAM 24 h", "%"),
    "temp_prom": ("Temperatura promedio 24 h", " °C"),
    "temp_max": ("Temperatura máxima 24 h", " °C"),
    "disco_usado_max": ("Espacio de disco usado", "%"),
    "disco_salud_min": ("Salud del disco (mínima 24 h)", "%"),
    "desconexiones_7d": ("Desconexiones de la estación en 7 días", ""),
    "dispositivos_7d": ("Dispositivos desconectados en 7 días", ""),
    "edad_meses": ("Antigüedad del equipo", " meses"),
    "cambios_90d": ("Cambios de componentes en 90 días", ""),
}

# Cada variable pertenece a un grupo; el grupo con más aporte da la «causa probable»
GRUPOS = {
    "cpu_prom": "carga", "cpu_p95": "carga",
    "ram_prom": "memoria", "ram_max": "memoria",
    "temp_prom": "temperatura", "temp_max": "temperatura",
    "disco_usado_max": "almacenamiento", "disco_salud_min": "almacenamiento",
    "desconexiones_7d": "conectividad", "dispositivos_7d": "conectividad",
    "edad_meses": "desgaste", "cambios_90d": "desgaste",
}
CAUSAS = {
    "carga": "Carga de CPU sostenida",
    "memoria": "Saturación de memoria RAM",
    "temperatura": "Sobrecalentamiento",
    "almacenamiento": "Degradación del disco",
    "conectividad": "Conectividad inestable",
    "desgaste": "Desgaste por antigüedad",
}


def cargar_env():
    """Lee ../.env y deja las variables PG* para psql / psycopg2."""
    ruta = RAIZ / ".env"
    datos = {}
    if ruta.exists():
        for linea in ruta.read_text(encoding="utf-8").splitlines():
            linea = linea.strip()
            if not linea or linea.startswith("#") or "=" not in linea:
                continue
            k, v = linea.split("=", 1)
            datos[k.strip()] = v.strip().strip('"').strip("'")
    for k, v in os.environ.items():
        datos.setdefault(k, v)
    # Acepta los nombres de Laravel (DB_DATABASE/DB_USERNAME/DB_PASSWORD) y los antiguos (DB_NAME/DB_USER/DB_PASS)
    mapa = [("DB_HOST", "PGHOST"), ("DB_PORT", "PGPORT"),
            ("DB_DATABASE", "PGDATABASE"), ("DB_NAME", "PGDATABASE"),
            ("DB_USERNAME", "PGUSER"), ("DB_USER", "PGUSER"),
            ("DB_PASSWORD", "PGPASSWORD"), ("DB_PASS", "PGPASSWORD")]
    for origen, destino in mapa:
        if origen in datos and destino not in os.environ:
            os.environ[destino] = datos[origen]
    return datos


def leer_sql(sql: str) -> pd.DataFrame:
    """Ejecuta una consulta. Usa psycopg2 si está instalado; si no, el cliente psql."""
    try:
        import psycopg2  # type: ignore
        with psycopg2.connect("") as conn:  # toma PGHOST, PGDATABASE... del entorno
            return pd.read_sql_query(sql, conn)
    except ImportError:
        psql = os.environ.get("PSQL_BIN", "psql")
        cmd = [psql, "-X", "-q", "-v", "ON_ERROR_STOP=1",
               "-c", f"COPY ({sql}) TO STDOUT WITH CSV HEADER"]
        r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8")
        if r.returncode != 0:
            raise RuntimeError(f"psql falló: {r.stderr.strip()}")
        return pd.read_csv(io.StringIO(r.stdout))