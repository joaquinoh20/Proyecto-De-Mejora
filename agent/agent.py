#!/usr/bin/env python3
"""Agente de AlfaVía: reporta el estado de la PC al servidor y, SOLO si un supervisor lo
pide (con motivo, tiempo limitado y auditado), envía capturas de pantalla.

Mientras se envía la pantalla muestra un aviso visible en esta PC. Si no puede mostrarlo
NO envía nada (a menos que se use --sin-aviso-visual, solo para pruebas).

Uso:  python agent.py --config config.json [--demo] [--sin-aviso-visual]
"""
import argparse, io, json, platform, re, socket, subprocess, sys, threading, time, urllib.request, urllib.error, uuid
from pathlib import Path

import psutil

VERSION = "1.0"


# ---------- inventario y métricas ----------
def _run(cmd, timeout=8):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout).stdout
    except Exception:
        return ""


def temperatura():
    try:
        t = psutil.sensors_temperatures()
        v = [x.current for lista in t.values() for x in lista if x.current]
        return max(v) if v else None
    except Exception:
        return None


def hardware():
    disco = psutil.disk_usage(Path.home().anchor or "/")
    modelo = platform.processor() or ""
    if platform.system() == "Linux":
        m = re.search(r"model name\s*:\s*(.+)", Path("/proc/cpuinfo").read_text(errors="ignore")) if Path("/proc/cpuinfo").exists() else None
        modelo = m.group(1).strip() if m else modelo
    return {"cpu_modelo": modelo[:120], "cpu_nucleos": psutil.cpu_count(logical=False) or psutil.cpu_count(),
            "ram_gb": round(psutil.virtual_memory().total / 2**30), "disco_gb": round(disco.total / 2**30)}


def ip_local():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.connect(("10.255.255.255", 1)); ip = s.getsockname()[0]; s.close(); return ip
    except Exception:
        return None


def mac_local():
    m = "%012x" % uuid.getnode()
    return ":".join(m[i:i + 2] for i in range(0, 12, 2))


def metricas():
    disco = psutil.disk_usage(Path.home().anchor or "/")
    return {"cpu_pct": psutil.cpu_percent(interval=1), "ram_pct": psutil.virtual_memory().percent,
            "temp_c": temperatura(), "disco_usado_pct": disco.percent,
            "uptime_h": round((time.time() - psutil.boot_time()) / 3600, 1)}
    # disco_salud_pct requiere SMART (smartctl); si no hay, se omite y el servidor lo ignora.


def dispositivos():
    """USB / periféricos visibles. Se compara en el servidor para detectar desconexiones."""
    out = []
    sistema = platform.system()
    if sistema == "Linux":
        for l in _run(["lsusb"]).splitlines():
            m = re.match(r"Bus (\d+) Device (\d+): ID (\w+:\w+) (.*)", l)
            if m and "root hub" not in m.group(4).lower():
                out.append({"id": "usb-" + m.group(3), "nombre": m.group(4).strip() or m.group(3), "categoria": categoria(m.group(4))})
    elif sistema == "Windows":
        ps = "Get-PnpDevice -PresentOnly -Class USB,Mouse,Keyboard,Camera,Image,AudioEndpoint,Bluetooth | Select-Object InstanceId,FriendlyName | ConvertTo-Json -Compress"
        try:
            datos = json.loads(_run(["powershell", "-NoProfile", "-Command", ps], 20) or "[]")
            for d in datos if isinstance(datos, list) else [datos]:
                n = d.get("FriendlyName") or ""
                if n and "hub" not in n.lower():
                    out.append({"id": d["InstanceId"][:120], "nombre": n, "categoria": categoria(n)})
        except Exception:
            pass
    elif sistema == "Darwin":
        for l in _run(["system_profiler", "SPUSBDataType"], 20).splitlines():
            if l.strip().endswith(":") and not l.strip().startswith(("USB", "Host")):
                n = l.strip()[:-1]
                out.append({"id": "usb-" + n, "nombre": n, "categoria": categoria(n)})
    return out


def categoria(nombre):
    n = nombre.lower()
    for k, c in (("mouse", "mouse"), ("ratón", "mouse"), ("keyboard", "teclado"), ("teclado", "teclado"), ("head", "audio"), ("audio", "audio"),
                 ("camera", "camara"), ("webcam", "camara"), ("storage", "almacenamiento"), ("flash", "almacenamiento"), ("bluetooth", "bluetooth")):
        if k in n:
            return c
    return "otro"


# ---------- red ----------
def post(url, token, cuerpo, tipo="application/json", extra=None, timeout=15):
    h = {"Content-Type": tipo, "X-Agent-Token": token}
    h.update(extra or {})
    req = urllib.request.Request(url, data=cuerpo, headers=h, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        try:
            return json.loads(e.read())
        except Exception:
            return {"ok": False, "error": f"HTTP {e.code}"}
    except Exception as e:
        return {"ok": False, "error": str(e)}


# ---------- pantalla ----------
def capturar_jpeg(demo=False, calidad=55, ancho=1280):
    from PIL import Image, ImageDraw
    if demo:
        img = Image.new("RGB", (1280, 720), (24, 32, 48))
        d = ImageDraw.Draw(img)
        d.rectangle((0, 0, 1280, 40), fill=(215, 25, 31))
        d.text((16, 14), "AlfaVía - pantalla de DEMOSTRACION (no es una captura real)", fill="white")
        d.text((40, 120), time.strftime("%H:%M:%S"), fill=(200, 220, 255))
        d.text((40, 160), socket.gethostname(), fill=(160, 180, 210))
    else:
        try:
            import mss
            with mss.mss() as s:
                m = s.monitors[1]; raw = s.grab(m); img = Image.frombytes("RGB", raw.size, raw.bgra, "raw", "BGRX")
        except ImportError:
            from PIL import ImageGrab
            img = ImageGrab.grab().convert("RGB")
    if img.width > ancho:
        img = img.resize((ancho, int(img.height * ancho / img.width)))
    b = io.BytesIO(); img.save(b, "JPEG", quality=calidad); return b.getvalue()


class Aviso:
    """Cartel rojo siempre visible mientras alguien ve esta pantalla."""
    def __init__(self):
        self.ok = False; self._hilo = None; self._parar = threading.Event(); self._listo = threading.Event()

    def mostrar(self):
        if self._hilo and self._hilo.is_alive():
            return self.ok
        self._parar.clear(); self._listo.clear(); self.ok = False
        self._hilo = threading.Thread(target=self._ui, daemon=True); self._hilo.start(); self._listo.wait(5)
        return self.ok

    def ocultar(self):
        self._parar.set()

    def _ui(self):
        try:
            import tkinter as tk
            r = tk.Tk(); r.overrideredirect(True); r.attributes("-topmost", True)
            r.geometry(f"{r.winfo_screenwidth()}x34+0+0"); r.configure(bg="#d7191f")
            tk.Label(r, text="⚠ Un supervisor de AlfaVía está viendo esta pantalla (monitoreo autorizado y auditado)", bg="#d7191f", fg="white", font=("Arial", 11, "bold")).pack(expand=True)
            self.ok = True; self._listo.set()
            def chequear():
                if self._parar.is_set():
                    r.destroy()
                else:
                    r.after(300, chequear)
            chequear(); r.mainloop()
        except Exception as e:
            print("No se pudo mostrar el aviso visible:", e, file=sys.stderr); self.ok = False; self._listo.set()
        finally:
            self.ok = False


def enviar_pantalla(base, token, aviso, args):
    """Envía cuadros mientras el servidor diga 'continuar'."""
    visible = args.demo_sin_ui or args.sin_aviso_visual or aviso.mostrar()
    if not visible:
        print("Sin aviso visible: no se envía la pantalla."); return
    try:
        while True:
            r = post(base + "/api/agent/pantalla.php", token, capturar_jpeg(args.demo), "image/jpeg", {"X-Aviso-Visible": "1"})
            if not r.get("continuar"):
                break
            time.sleep(1.5)
    finally:
        aviso.ocultar()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default=str(Path(__file__).with_name("config.json")))
    ap.add_argument("--demo", action="store_true", help="envía una pantalla sintética en vez de capturar la real")
    ap.add_argument("--sin-aviso-visual", action="store_true", help="SOLO PRUEBAS: no muestra el cartel (en producción NO usar)")
    ap.add_argument("--una-vez", action="store_true")
    args = ap.parse_args()
    args.demo_sin_ui = args.demo and args.sin_aviso_visual
    cfg = json.loads(Path(args.config).read_text(encoding="utf-8"))
    base, token = cfg["servidor"].rstrip("/"), cfg["token"]
    intervalo = int(cfg.get("intervalo_seg", 30))
    aviso = Aviso()
    hw = hardware()
    while True:
        cuerpo = {"agente_version": VERSION, "so": f"{platform.system()} {platform.release()}"[:80], "ip": ip_local(), "mac": mac_local(),
                  "hardware": hw, "metricas": metricas(), "dispositivos": dispositivos()}
        r = post(base + "/api/agent/latido.php", token, json.dumps(cuerpo).encode())
        if r.get("ok"):
            intervalo = int(r.get("intervalo", intervalo))
            if r.get("pantalla", {}).get("activa"):
                enviar_pantalla(base, token, aviso, args)
        else:
            print("Servidor:", r.get("error"), file=sys.stderr)
        if args.una_vez:
            break
        time.sleep(max(5, intervalo))


if __name__ == "__main__":
    main()
