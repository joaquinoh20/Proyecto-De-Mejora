#!/usr/bin/env python3
"""Simula los agentes de las 25 estaciones de demostración para ver el panel con vida.
Tokens de demo: demo-token-PC-001 ... demo-token-PC-025 (ver database/03_demo_inventario.sql).
Uso: python simulador_flota.py [--servidor http://127.0.0.1:8080] [--una-vez]
     Teclas de prueba: --caer PC-017  (no reporta esa PC)   --quitar "PC-011:mouse"  (quita un dispositivo)
Solo biblioteca estándar. Atiende también las vistas de pantalla pidiendo cuadros sintéticos."""
import argparse, io, json, random, time, urllib.request, urllib.error

def post(url, token, cuerpo, tipo="application/json", extra=None):
    h = {"Content-Type": tipo, "X-Agent-Token": token}; h.update(extra or {})
    try:
        with urllib.request.urlopen(urllib.request.Request(url, data=cuerpo, headers=h, method="POST"), timeout=15) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        try: return json.loads(e.read())
        except Exception: return {"ok": False, "error": e.code}
    except Exception as e:
        return {"ok": False, "error": str(e)}

def jpeg_demo(codigo):
    try:
        from PIL import Image, ImageDraw
        img = Image.new("RGB", (960, 540), (24, 32, 48)); d = ImageDraw.Draw(img)
        d.rectangle((0, 0, 960, 30), fill=(215, 25, 31)); d.text((10, 10), f"DEMOSTRACION - {codigo} - {time.strftime('%H:%M:%S')}", fill="white")
        b = io.BytesIO(); img.save(b, "JPEG", quality=60); return b.getvalue()
    except ImportError:
        return None

ap = argparse.ArgumentParser()
ap.add_argument("--servidor", default="http://127.0.0.1:8080"); ap.add_argument("--una-vez", action="store_true")
ap.add_argument("--caer", action="append", default=[]); ap.add_argument("--quitar", action="append", default=[])
a = ap.parse_args()
base = a.servidor.rstrip("/")
quitar = {}
for q in a.quitar:
    c, n = q.split(":", 1); quitar.setdefault(c, []).append(n.lower())
# Equipos que el historial demo marca como en deterioro: se simulan con valores peores
malos = {"PC-007": dict(disco_salud_pct=62, disco_usado_pct=93, cpu_pct=70), "PC-013": dict(temp_c=84, cpu_pct=88), "PC-019": dict(ram_pct=94, cpu_pct=75)}
base_disp = [("usb:046d:0a38", "Logitech H390 (diadema)", "audio"), ("usb:03f0:094a", "HP X500 (mouse)", "mouse"), ("usb:03f0:0024", "HP K1500 (teclado)", "teclado")]
quitar.setdefault("PC-003", []).append("diadema")  # coincide con el historial demo: esa diadema sigue desconectada
while True:
    for i in range(1, 26):
        cod = f"PC-{i:03d}"
        if cod in a.caer: continue
        m = dict(cpu_pct=random.uniform(15, 45), ram_pct=random.uniform(35, 65), temp_c=random.uniform(42, 58), disco_usado_pct=random.uniform(40, 70), disco_salud_pct=random.uniform(94, 100), uptime_h=random.uniform(2, 300))
        m.update({k: v + random.uniform(-2, 2) for k, v in malos.get(cod, {}).items()})
        disp = [{"id": x, "nombre": n, "categoria": c} for x, n, c in base_disp if not any(q in n.lower() for q in quitar.get(cod, []))]
        tok = f"demo-token-{cod}"
        r = post(base + "/api/agent/latido.php", tok, json.dumps({"agente_version": "sim", "metricas": {k: round(v, 1) for k, v in m.items()}, "dispositivos": disp}).encode())
        if not r.get("ok"): print(cod, "->", r.get("error")); continue
        if r.get("pantalla", {}).get("activa"):
            for _ in range(40):
                j = jpeg_demo(cod)
                if not j: break
                p = post(base + "/api/agent/pantalla.php", tok, j, "image/jpeg", {"X-Aviso-Visible": "1"})
                if not p.get("continuar"): break
                time.sleep(1.5)
    print(time.strftime("%H:%M:%S"), "latidos enviados")
    if a.una_vez: break
    time.sleep(30)
