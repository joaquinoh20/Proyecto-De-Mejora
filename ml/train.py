#!/usr/bin/env python3
"""
Entrena el modelo de machine learning que estima la probabilidad de que una
estación falle en los próximos 7 días.

  · Modelo supervisado: GradientBoostingClassifier (scikit-learn).
    Aprende de la telemetría y de las fallas reales registradas (tabla `fallas`).
  · Detector de comportamiento inusual: IsolationForest (no supervisado),
    entrenado solo con muestras «sanas».

Uso:   python train.py
Salida: models/modelo_falla.joblib  y  models/metricas.json
Luego avisa al servicio: curl -X POST -H "X-ML-Token: ..." http://127.0.0.1:5001/recargar
"""
import json
import sys
from datetime import datetime

import joblib
import numpy as np
import pandas as pd
from sklearn.ensemble import GradientBoostingClassifier, IsolationForest
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import average_precision_score, roc_auc_score
from sklearn.model_selection import GroupKFold
from sklearn.pipeline import Pipeline

from comun import FEATURES, MODELO_PATH, cargar_env, leer_sql

MIN_FALLAS = 5
VENTANA_DIAS = 7

SQL = f"""
WITH lim AS (
  SELECT date_trunc('day', min(tomado_en)) + interval '9 days 12 hours' AS ini,
         date_trunc('day', now()) - interval '{VENTANA_DIAS} days' + interval '12 hours' AS fin
  FROM telemetria)
SELECT e.id AS estacion_id, d.corte,
       {', '.join('f.' + c for c in FEATURES)},
       (EXISTS (SELECT 1 FROM fallas x
                 WHERE x.estacion_id = e.id AND x.fecha > d.corte
                   AND x.fecha <= d.corte + interval '{VENTANA_DIAS} days'))::int AS y
FROM estaciones e
CROSS JOIN lim
CROSS JOIN LATERAL generate_series(lim.ini, lim.fin, interval '1 day') AS d(corte)
CROSS JOIN LATERAL fn_features_estacion(e.id, d.corte) f
WHERE f.muestras >= 12
"""


def construir_modelo():
    return Pipeline([
        ("imp", SimpleImputer(strategy="median")),
        ("gb", GradientBoostingClassifier(
            n_estimators=120, max_depth=2, learning_rate=0.05,
            subsample=0.8, min_samples_leaf=25, random_state=7)),
    ])


def main():
    cargar_env()
    print("Leyendo telemetría y fallas desde PostgreSQL…")
    df = leer_sql(SQL)
    n_pos = int(df["y"].sum())
    print(f"  {len(df)} muestras (estación × día), {n_pos} dentro de una ventana previa a una falla")
    n_fallas = int(leer_sql("SELECT count(*) AS n FROM fallas")["n"][0])
    if n_fallas < MIN_FALLAS or n_pos == 0:
        sys.exit(f"No hay suficientes fallas registradas ({n_fallas}). "
                 f"Se necesitan al menos {MIN_FALLAS} para entrenar un modelo útil.")

    X = df[FEATURES]
    y = df["y"].values
    grupos = df["estacion_id"].values

    # Validación: cada estación completa queda fuera del entrenamiento en algún pliegue
    oof = np.zeros(len(df))
    for tr, te in GroupKFold(n_splits=5).split(X, y, grupos):
        m = construir_modelo().fit(X.iloc[tr], y[tr])
        oof[te] = m.predict_proba(X.iloc[te])[:, 1]
    auc = float(roc_auc_score(y, oof))
    ap = float(average_precision_score(y, oof))
    print(f"  Validación por estación → AUC {auc:.3f} · precisión promedio {ap:.3f} (base {y.mean():.3f})")

    # Calibración (Platt) con las predicciones fuera de muestra: que «70 %» signifique cerca de 70 %
    logit = lambda p: np.log(np.clip(p, 1e-4, 1 - 1e-4) / (1 - np.clip(p, 1e-4, 1 - 1e-4))).reshape(-1, 1)
    calib = LogisticRegression(C=10.0).fit(logit(oof), y)
    cal_oof = calib.predict_proba(logit(oof))[:, 1]
    print(f"  Tras calibrar: probabilidad media {cal_oof.mean():.3f} (real {y.mean():.3f}); > 0.70 en {(cal_oof > 0.7).mean() * 100:.1f}% de las muestras")

    # Modelo final con todos los datos
    modelo = construir_modelo().fit(X, y)
    Xi = pd.DataFrame(modelo.named_steps["imp"].transform(X), columns=FEATURES)
    media = Xi.mean().values
    desv = Xi.std(ddof=0).replace(0, 1e-6).values
    importancias = modelo.named_steps["gb"].feature_importances_
    direccion = np.array([np.sign(np.corrcoef(Xi[c], y)[0, 1]) if Xi[c].std() > 0 else 0.0
                          for c in FEATURES])
    direccion = np.nan_to_num(direccion)

    # Detector de comportamiento inusual con muestras sin falla cercana
    Xs = (Xi.values - media) / desv
    iso = IsolationForest(n_estimators=200, random_state=7).fit(Xs[y == 0])
    puntajes = np.sort(-iso.score_samples(Xs[y == 0]))

    version = "gb-" + datetime.now().strftime("%Y%m%d-%H%M")
    meta = {
        "version": version,
        "entrenado_en": datetime.now().isoformat(timespec="seconds"),
        "muestras": int(len(df)),
        "muestras_positivas": n_pos,
        "fallas_registradas": n_fallas,
        "ventana_dias": VENTANA_DIAS,
        "auc_validacion": round(auc, 3),
        "precision_promedio": round(ap, 3),
        "tasa_base": round(float(y.mean()), 3),
        "variables": FEATURES,
        "importancias": {c: round(float(i), 4) for c, i in zip(FEATURES, importancias)},
    }
    joblib.dump({
        "pipeline": modelo, "calib": calib, "iso": iso, "media": media, "desv": desv,
        "importancias": importancias, "direccion": direccion,
        "puntajes_ref": puntajes, "meta": meta,
    }, MODELO_PATH)
    (MODELO_PATH.parent / "metricas.json").write_text(
        json.dumps(meta, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"Modelo guardado en {MODELO_PATH} (versión {version})")
    top = sorted(meta["importancias"].items(), key=lambda kv: -kv[1])[:4]
    print("  Variables más influyentes:", ", ".join(f"{k} ({v:.2f})" for k, v in top))


if __name__ == "__main__":
    main()
