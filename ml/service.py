#!/usr/bin/env python3
"""
Servicio HTTP del modelo de ML. Solo escucha en 127.0.0.1; PHP le envía las
variables de cada estación y recibe probabilidad de falla, anomalía y factores.

  GET  /health    estado y métricas del modelo
  POST /predict   {"items":[{"id":7,"features":{...}}]}
  POST /recargar  vuelve a leer el archivo del modelo (después de entrenar)

Se protege con el encabezado X-ML-Token (variable ML_TOKEN del .env).
"""
import hmac
import os
import threading

import joblib
import numpy as np
import pandas as pd
from flask import Flask, jsonify, request

from comun import CAUSAS, ETIQUETAS, FEATURES, GRUPOS, MODELO_PATH, cargar_env

env = cargar_env()
TOKEN = env.get("ML_TOKEN", "")
app = Flask(__name__)
_lock = threading.Lock()
_modelo = None


def cargar_modelo():
    global _modelo
    with _lock:
        _modelo = joblib.load(MODELO_PATH) if MODELO_PATH.exists() else None
    return _modelo


@app.before_request
def verificar_token():
    if not TOKEN:
        return jsonify(error="ML_TOKEN no está configurado"), 500
    enviado = request.headers.get("X-ML-Token", "")
    if not hmac.compare_digest(enviado, TOKEN):
        return jsonify(error="no autorizado"), 401


def valor_legible(feature, v):
    etiqueta, unidad = ETIQUETAS[feature]
    if feature in ("desconexiones_7d", "dispositivos_7d", "cambios_90d"):
        return f"{int(round(v))}"
    return f"{v:.1f}{unidad}"


def predecir(m, items):
    filas = []
    for it in items:
        f = it.get("features") or {}
        filas.append([np.nan if f.get(c) is None else float(f[c]) for c in FEATURES])
    X = pd.DataFrame(filas, columns=FEATURES)
    probs = m["pipeline"].predict_proba(X)[:, 1]
    if m.get("calib") is not None:
        pc = np.clip(probs, 1e-4, 1 - 1e-4)
        probs = m["calib"].predict_proba(np.log(pc / (1 - pc)).reshape(-1, 1))[:, 1]
    Xi = pd.DataFrame(m["pipeline"].named_steps["imp"].transform(X), columns=FEATURES)
    Z = (Xi.values - m["media"]) / m["desv"]
    anom = -m["iso"].score_samples(Z)
    ref = m["puntajes_ref"]

    resultados = []
    for i, it in enumerate(items):
        # Solo cuentan las variables que se alejan más de 1 desviación en el sentido de riesgo
        aporte = m["importancias"] * np.maximum(0.0, m["direccion"] * Z[i] - 1.0)
        total = float(aporte.sum())
        factores = []
        causa = None
        if total > 0:
            orden = np.argsort(-aporte)[:3]
            top_total = float(aporte[orden].sum())
            for j in orden:
                if aporte[j] <= 0:
                    continue
                c = FEATURES[j]
                factores.append({
                    "variable": c,
                    "etiqueta": ETIQUETAS[c][0],
                    "valor": valor_legible(c, Xi.iloc[i, j]),
                    "aporte": round(float(aporte[j]) / top_total, 2),
                })
            por_grupo = {}
            for j, c in enumerate(FEATURES):
                por_grupo[GRUPOS[c]] = por_grupo.get(GRUPOS[c], 0.0) + float(aporte[j])
            mejor = max(por_grupo, key=por_grupo.get)
            if por_grupo[mejor] > 0:
                causa = CAUSAS[mejor]
        resultados.append({
            "id": it.get("id"),
            "prob_falla": round(float(probs[i]), 4),
            "anomalia": round(float(np.searchsorted(ref, anom[i]) / len(ref)), 3),
            "causa_probable": causa,
            "factores": factores,
            "modelo_version": m["meta"]["version"],
        })
    return resultados


@app.get("/health")
def health():
    m = _modelo
    if m is None:
        return jsonify(ok=False, error="No hay modelo entrenado. Ejecuta: python train.py"), 503
    return jsonify(ok=True, modelo=m["meta"])


@app.post("/predict")
def predict():
    m = _modelo
    if m is None:
        return jsonify(error="No hay modelo entrenado"), 503
    cuerpo = request.get_json(silent=True) or {}
    items = cuerpo.get("items")
    if not isinstance(items, list) or not items or len(items) > 500:
        return jsonify(error="items debe ser una lista de 1 a 500 elementos"), 400
    return jsonify(resultados=predecir(m, items))


@app.post("/recargar")
def recargar():
    m = cargar_modelo()
    if m is None:
        return jsonify(ok=False, error="No hay modelo entrenado"), 503
    return jsonify(ok=True, version=m["meta"]["version"])


cargar_modelo()

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=int(os.environ.get("ML_PORT", env.get("ML_PORT", "5001"))),
            threaded=True)
