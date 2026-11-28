#!/usr/bin/env python3
"""
Agente de AlfaVía: se instala en cada computadora y envía datos REALES.
  · Cada 2 s: rendimiento en vivo (CPU, memoria, discos, red, GPU, procesos) para el panel «en vivo».
  · Cada 15 s: latido con telemetría y periféricos (alimenta historial, alertas y el modelo de IA).
  · Al iniciar y cada hora: hardware real (placa base, BIOS, procesador, módulos de RAM, GPU, discos).
  · Solo si alguien autorizado observa la pantalla: muestra un AVISO VISIBLE y envía capturas.

Uso:  py agent.py --config config.json
Requisitos: Python 3.9+, pip install -r requirements.txt  (psutil, requests, pillow)
"""
import argparse, base64, io, json, os, platform, re, shutil, socket, subprocess, sys, time

ES_WIN = platform.system() == "Windows"
SIN_VENTANA = {"creationflags": 0x08000000} if ES_WIN else {}      # evita que parpadee una consola


# ------------------------------------------------------------------ aviso visible (misma ventana de siempre)
def modo_aviso(observador, motivo):
    import tkinter as tk
    w = tk.Tk(); w.overrideredirect(True); w.attributes("-topmost", True); w.configure(bg="#d7191f")
    w.geometry(f"{w.winfo_screenwidth()}x30+0+0")
    tk.Label(w, bg="#d7191f", fg="white", font=("Segoe UI", 11, "bold"),
             text=f"AlfaVía: tu pantalla está siendo observada por {observador}. Motivo: {motivo}").pack(expand=True)
    w.mainloop()


if len(sys.argv) >= 2 and sys.argv[1] == "--aviso":
    modo_aviso(sys.argv[2] if len(sys.argv) > 2 else "", sys.argv[3] if len(sys.argv) > 3 else "")
    sys.exit(0)

import psutil, requests  # noqa: E402

ap = argparse.ArgumentParser()
ap.add_argument("--config", default="config.json")
ARGS = ap.parse_args()
BASE = os.path.dirname(os.path.abspath(__file__))
CFG = json.load(open(ARGS.config if os.path.isabs(ARGS.config) else os.path.join(BASE, ARGS.config), encoding="utf-8"))
SERVIDOR = (CFG.get("servidor") or CFG.get("url") or "").rstrip("/")
TOKEN = CFG["token"]
EQUIPO = CFG.get("equipo", "")
if not SERVIDOR:
    sys.exit("config.json no tiene «servidor».")
API = SERVIDOR if SERVIDOR.endswith("/api/agent") else (SERVIDOR[:-4] if SERVIDOR.endswith("/api") else SERVIDOR) + "/api/agent"
H = {"Authorization": "Bearer " + TOKEN}
if EQUIPO:
    H["X-Equipo"] = EQUIPO
VIVO_SEG = max(1.0, float(CFG.get("intervalo_vivo_seg", 2)))
LATIDO_SEG = max(5, int(CFG.get("intervalo_seg", 15)))
VERSION = "2.0.0"
BASURA = ("o.e.m", "default string", "to be filled", "system manufacturer", "system product name", "none", "not applicable", "n/a", "0000")


def limpio(s):
    s = (str(s) if s is not None else "").strip()
    return None if not s or any(b in s.lower() for b in BASURA) else s


def lista(x):
    return x if isinstance(x, list) else ([] if x is None else [x])


# ------------------------------------------------------------------ hardware (una vez por hora)
PS_HW = r"""
$ErrorActionPreference='SilentlyContinue'
[Console]::OutputEncoding=[Text.Encoding]::UTF8
$o=[ordered]@{}
$o.cs=Get-CimInstance Win32_ComputerSystem | Select-Object Manufacturer,Model,TotalPhysicalMemory
$o.board=Get-CimInstance Win32_BaseBoard | Select-Object Manufacturer,Product,Version,SerialNumber
$o.bios=Get-CimInstance Win32_BIOS | Select-Object Manufacturer,SMBIOSBIOSVersion,SerialNumber,@{n='Fecha';e={if($_.ReleaseDate){$_.ReleaseDate.ToString('yyyy-MM-dd')}}}
$o.cpu=Get-CimInstance Win32_Processor | Select-Object Name,NumberOfCores,NumberOfLogicalProcessors,MaxClockSpeed
$o.ram=Get-CimInstance Win32_PhysicalMemory | Select-Object Manufacturer,PartNumber,Capacity,Speed,ConfiguredClockSpeed,DeviceLocator
$o.gpu=Get-CimInstance Win32_VideoController | Select-Object Name,AdapterRAM,DriverVersion
$o.disk=Get-PhysicalDisk | Select-Object FriendlyName,MediaType,Size,HealthStatus
$o.os=Get-CimInstance Win32_OperatingSystem | Select-Object Caption,Version,OSArchitecture
$o | ConvertTo-Json -Depth 4 -Compress
"""


def ps_json(script):
    cod = base64.b64encode(script.encode("utf-16-le")).decode()
    out = subprocess.run(["powershell", "-NoProfile", "-NonInteractive", "-EncodedCommand", cod],
                         capture_output=True, timeout=60, **SIN_VENTANA).stdout.decode("utf-8", "replace")
    return json.loads(out[out.index("{"):])


def gb(n):
    return round(float(n) / 1024 ** 3, 1) if n else None


def mac_principal():
    try:
        for nombre, dirs in psutil.net_if_addrs().items():
            st = psutil.net_if_stats().get(nombre)
            if not st or not st.isup or nombre == "lo" or re.search(r"loopback|virtual|vmware|vbox|hyper-v|bluetooth|docker|veth", nombre, re.I):
                continue
            for d in dirs:
                if d.family == psutil.AF_LINK and re.match(r"^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$", d.address) and d.address.strip("0:-") != "":
                    return d.address.replace("-", ":").upper()
    except Exception:
        pass
    return None


def hardware_windows():
    r = ps_json(PS_HW)
    cs, bd, bi, cpu = r.get("cs") or {}, r.get("board") or {}, r.get("bios") or {}, lista(r.get("cpu"))
    rams, gpus, discos, so = lista(r.get("ram")), lista(r.get("gpu")), lista(r.get("disk")), r.get("os") or {}
    cpu0 = cpu[0] if cpu else {}
    hw = {
        "equipo": {"fabricante": limpio(cs.get("Manufacturer")) or limpio(bd.get("Manufacturer")), "modelo": limpio(cs.get("Model")) or limpio(bd.get("Product")),
                   "serie": limpio(bi.get("SerialNumber")) or limpio(bd.get("SerialNumber"))},
        "placa": {"fabricante": limpio(bd.get("Manufacturer")), "modelo": limpio(bd.get("Product")), "version": limpio(bd.get("Version")), "serie": limpio(bd.get("SerialNumber"))},
        "bios": {"fabricante": limpio(bi.get("Manufacturer")), "version": limpio(bi.get("SMBIOSBIOSVersion")), "fecha": bi.get("Fecha")},
        "cpu": {"nombre": re.sub(r"\s+", " ", cpu0.get("Name", "")).strip() or None, "nucleos": cpu0.get("NumberOfCores"),
                "hilos": cpu0.get("NumberOfLogicalProcessors"), "mhz_max": cpu0.get("MaxClockSpeed")},
        "ram": {"total_gb": gb(cs.get("TotalPhysicalMemory")),
                "modulos": [{"fabricante": limpio(m.get("Manufacturer")), "parte": limpio(m.get("PartNumber")), "gb": gb(m.get("Capacity")),
                             "mhz": m.get("ConfiguredClockSpeed") or m.get("Speed"), "ranura": limpio(m.get("DeviceLocator"))} for m in rams]},
        "gpus": [{"nombre": g.get("Name"), "vram_gb": gb(g.get("AdapterRAM")), "driver": g.get("DriverVersion")} for g in gpus
                 if not re.search(r"basic|remote|virtual|parsec|displaylink", g.get("Name") or "", re.I)],
        "discos": [{"nombre": d.get("FriendlyName"), "tipo": d.get("MediaType") if d.get("MediaType") in ("SSD", "HDD") else "—",
                    "gb": round(float(d.get("Size") or 0) / 1e9), "salud": d.get("HealthStatus")} for d in discos],
        "so": {"nombre": re.sub(r"^Microsoft\s+", "", so.get("Caption") or ""), "version": so.get("Version"), "arq": so.get("OSArchitecture")},
        "mac": mac_principal(),
    }
    return hw


def hardware_linux():
    def leer(p):
        try:
            return open(p).read().strip()
        except Exception:
            return None
    d = "/sys/class/dmi/id/"
    return {"equipo": {"fabricante": limpio(leer(d + "sys_vendor")), "modelo": limpio(leer(d + "product_name")), "serie": limpio(leer(d + "product_serial"))},
            "placa": {"fabricante": limpio(leer(d + "board_vendor")), "modelo": limpio(leer(d + "board_name")), "version": limpio(leer(d + "board_version")), "serie": None},
            "bios": {"fabricante": limpio(leer(d + "bios_vendor")), "version": limpio(leer(d + "bios_version")), "fecha": leer(d + "bios_date")},
            "cpu": {"nombre": platform.processor() or None, "nucleos": psutil.cpu_count(logical=False), "hilos": psutil.cpu_count(), "mhz_max": None},
            "ram": {"total_gb": gb(psutil.virtual_memory().total), "modulos": []}, "gpus": [], "discos": [],
            "so": {"nombre": platform.system(), "version": platform.release(), "arq": platform.machine()}, "mac": mac_principal()}


def inventario(hw, gpu_nv):
    """Resumen para rellenar la ficha de la estación con datos reales."""
    tipos = sorted({d["tipo"] for d in hw["discos"] if d["tipo"] in ("SSD", "HDD")})
    gpus = [g["nombre"] for g in hw["gpus"] if g.get("nombre")]
    if gpu_nv and gpu_nv.get("nombre") and gpu_nv["nombre"] not in gpus:
        gpus.insert(0, gpu_nv["nombre"])
    so = hw["so"]
    return {"marca": hw["equipo"]["fabricante"], "modelo": hw["equipo"]["modelo"], "numero_serie": hw["equipo"]["serie"],
            "cpu_modelo": hw["cpu"]["nombre"], "cpu_nucleos": hw["cpu"]["nucleos"],
            "ram_gb": int(round(hw["ram"]["total_gb"])) if hw["ram"]["total_gb"] else None,
            "disco_tipo": " + ".join(tipos) or None, "disco_gb": sum(d["gb"] for d in hw["discos"]) or None,
            "gpu": " / ".join(gpus) or None, "so": (f"{so['nombre']} {so['version'] or ''}").strip() or None, "mac": hw.get("mac")}


# ------------------------------------------------------------------ rendimiento en vivo
_nv = {"t": 0, "v": None}
_net = {"t": None, "b": None}
_io = {"t": None, "b": None}
_top = {"t": 0, "cpu": [], "ram": []}
_temp = {"t": 0, "v": None}
_nv_exe = shutil.which("nvidia-smi") or next((p for p in (r"C:\Windows\System32\nvidia-smi.exe", r"C:\Program Files\NVIDIA Corporation\NVSMI\nvidia-smi.exe") if os.path.exists(p)), None)


def gpu_nvidia():
    if not _nv_exe:
        return None
    if time.time() - _nv["t"] < 3:
        return _nv["v"]
    _nv["t"] = time.time()
    try:
        out = subprocess.run([_nv_exe, "--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw",
                              "--format=csv,noheader,nounits"], capture_output=True, text=True, timeout=5, **SIN_VENTANA).stdout.strip().splitlines()[0]
        n, u, mu, mt, t, p = [x.strip() for x in out.split(",")]
        num = lambda v: None if v in ("", "[N/A]", "N/A") else float(v)
        _nv["v"] = {"nombre": n, "pct": num(u), "vram_usada_mb": num(mu), "vram_total_mb": num(mt), "temp": num(t), "watts": num(p)}
    except Exception:
        _nv["v"] = None
    return _nv["v"]


def temperatura_cpu():
    """Linux/macOS: sensores de psutil. Windows: sensor ACPI (suele requerir ejecutar como administrador; si no, queda sin dato)."""
    try:
        v = [x.current for l in psutil.sensors_temperatures().values() for x in l if x.current]
        if v:
            return round(max(v), 1)
    except Exception:
        pass
    if ES_WIN and time.time() - _temp["t"] > 30:
        _temp["t"] = time.time()
        try:
            s = "(Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature | ForEach-Object { [math]::Round($_.CurrentTemperature/10-273.15,1) } | Measure-Object -Maximum).Maximum"
            o = subprocess.run(["powershell", "-NoProfile", "-Command", s], capture_output=True, text=True, timeout=10, **SIN_VENTANA).stdout.strip()
            _temp["v"] = float(o.replace(",", ".")) if o and 0 < float(o.replace(",", ".")) < 120 else None
        except Exception:
            _temp["v"] = None
    return _temp["v"] if ES_WIN else None


def procesos_top():
    if time.time() - _top["t"] < 4:
        return _top
    _top["t"] = time.time()
    n = psutil.cpu_count() or 1
    filas = []
    for p in psutil.process_iter(["name", "memory_info"]):
        try:
            filas.append((p.info["name"] or "?", p.cpu_percent(None) / n, (p.info["memory_info"].rss if p.info["memory_info"] else 0) / 1048576))
        except Exception:
            pass
    _top["cpu"] = [{"nombre": a, "cpu": round(b, 1), "ram_mb": round(c)} for a, b, c in sorted(filas, key=lambda x: -x[1])[:5]]
    _top["ram"] = [{"nombre": a, "cpu": round(b, 1), "ram_mb": round(c)} for a, b, c in sorted(filas, key=lambda x: -x[2])[:5]]
    return _top


def tasa(estado, actual):
    """Bytes por segundo entre dos lecturas de un contador."""
    ahora = time.time()
    r = None
    if estado["t"] is not None and ahora > estado["t"]:
        dt = ahora - estado["t"]
        r = [max(0, (a - b) / dt) for a, b in zip(actual, estado["b"])]
    estado["t"], estado["b"] = ahora, actual
    return r


def rendimiento():
    nucleos = psutil.cpu_percent(interval=None, percpu=True)
    vm, sw = psutil.virtual_memory(), psutil.swap_memory()
    try:
        f = psutil.cpu_freq()
        mhz = round(f.current) if f else None
    except Exception:
        mhz = None
    discos = []
    for p in psutil.disk_partitions(all=False):
        if "cdrom" in p.opts or not p.fstype:
            continue
        try:
            u = psutil.disk_usage(p.mountpoint)
            discos.append({"punto": p.mountpoint, "pct": u.percent, "usado_gb": gb(u.used), "total_gb": gb(u.total)})
        except Exception:
            pass
    io = psutil.disk_io_counters()
    tio = tasa(_io, [io.read_bytes, io.write_bytes]) if io else None
    nt = psutil.net_io_counters()
    tnet = tasa(_net, [nt.bytes_sent, nt.bytes_recv])
    try:
        mbps = max([s.speed for s in psutil.net_if_stats().values() if s.isup and s.speed], default=None)
    except Exception:
        mbps = None
    top = procesos_top()
    return {
        "t": time.time(), "version_agente": VERSION,
        "cpu": {"pct": round(sum(nucleos) / len(nucleos), 1) if nucleos else 0, "nucleos": [round(x) for x in nucleos], "mhz": mhz,
                "procesos": len(psutil.pids()), "temp": temperatura_cpu()},
        "ram": {"pct": vm.percent, "usado_gb": gb(vm.total - vm.available), "total_gb": gb(vm.total), "disp_gb": gb(vm.available), "swap_pct": sw.percent},
        "discos": discos,
        "disco_io": {"lee_bps": tio[0] if tio else 0, "escribe_bps": tio[1] if tio else 0},
        "red": {"sube_bps": tnet[0] if tnet else 0, "baja_bps": tnet[1] if tnet else 0, "mbps": mbps},
        "gpu": gpu_nvidia(),
        "uptime_s": int(time.time() - psutil.boot_time()),
        "top_cpu": top["cpu"], "top_ram": top["ram"],
    }


# ------------------------------------------------------------------ comunicación con el servidor
def ip_local():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.connect((SERVIDOR.split("//")[-1].split(":")[0].split("/")[0] or "8.8.8.8", 80))
        ip = s.getsockname()[0]; s.close()
        return ip
    except Exception:
        return None


def perifericos():
    try:
        if ES_WIN:
            cmd = ["powershell", "-NoProfile", "-Command",
                   "Get-PnpDevice -PresentOnly -Class Keyboard,Mouse,AudioEndpoint,Camera,Monitor | Select-Object -ExpandProperty FriendlyName"]
        else:
            cmd = ["lsusb"]
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=20, **SIN_VENTANA).stdout
        return sorted({l.strip() for l in out.splitlines() if l.strip()})
    except Exception:
        return []


_salud = {"t": 0, "v": None}


def salud_disco():
    """100 = Healthy, 60 = Warning, 20 = Unhealthy (estado real que informa Windows)."""
    if not ES_WIN:
        return None
    if time.time() - _salud["t"] > 600:
        _salud["t"] = time.time()
        try:
            o = subprocess.run(["powershell", "-NoProfile", "-Command", "(Get-PhysicalDisk | Select-Object -ExpandProperty HealthStatus) -join ','"],
                               capture_output=True, text=True, timeout=20, **SIN_VENTANA).stdout.strip().split(",")
            pts = [{"Healthy": 100, "Warning": 60, "Unhealthy": 20}.get(x.strip()) for x in o if x.strip()]
            _salud["v"] = min(p for p in pts if p is not None) if any(p is not None for p in pts) else None
        except Exception:
            _salud["v"] = None
    return _salud["v"]


def latido(r):
    t0 = time.time()
    disco = next((d["pct"] for d in r["discos"] if d["punto"].upper().startswith("C")), r["discos"][0]["pct"] if r["discos"] else None)
    cuerpo = {"host": socket.gethostname(), "ip": ip_local(), "perifericos": perifericos(), "version_agente": VERSION,
              "telemetria": {"cpu": r["cpu"]["pct"], "ram": r["ram"]["pct"], "disco": disco, "disco_salud": salud_disco(),
                             "temperatura": r["cpu"]["temp"], "latencia": None, "uptime_h": round(r["uptime_s"] / 3600, 2)}}
    resp = requests.post(API + "/latido", json=cuerpo, headers=H, timeout=15)
    resp.raise_for_status()
    return resp.json(), int((time.time() - t0) * 1000)


def enviar_rendimiento(r, hw=None):
    cuerpo = {"rendimiento": r}
    if hw:
        cuerpo["hardware"] = hw
        cuerpo["inventario"] = inventario(hw, r.get("gpu"))
    resp = requests.post(API + "/rendimiento", json=cuerpo, headers=H, timeout=10)
    return resp.status_code


_aviso = None


def mostrar_aviso(observador, motivo):
    global _aviso
    if _aviso and _aviso.poll() is None:
        return
    _aviso = subprocess.Popen([sys.executable, os.path.abspath(__file__), "--aviso", observador or "", motivo or ""])


def ocultar_aviso():
    global _aviso
    if _aviso and _aviso.poll() is None:
        _aviso.terminate()
    _aviso = None


def enviar_captura():
    from PIL import ImageGrab
    img = ImageGrab.grab()
    img.thumbnail((1280, 720))
    b = io.BytesIO(); img.convert("RGB").save(b, "JPEG", quality=55)
    requests.post(API + "/pantalla", data=b.getvalue(), headers=H, timeout=15)


def leer_hardware():
    try:
        return hardware_windows() if ES_WIN else hardware_linux()
    except Exception as e:
        print("No se pudo leer el hardware:", e)
        return None


def main():
    print(f"AlfaVía agente {VERSION} → {API}")
    psutil.cpu_percent(interval=None, percpu=True)          # la primera lectura siempre es 0: se descarta
    procesos_top()
    time.sleep(1)
    ult_latido = ult_hw = 0.0
    ver, sin_ruta = False, 0.0
    while True:
        t = time.time()
        try:
            r = rendimiento()
            if t - ult_latido >= (8 if ver else LATIDO_SEG):
                resp, _ = latido(r)
                ult_latido = t
                ver = bool(resp.get("ver_pantalla"))
                if ver and resp.get("aviso", True):
                    mostrar_aviso(resp.get("observador"), resp.get("motivo"))
                if not ver:
                    ocultar_aviso()
            if ver:
                enviar_captura()
            if t >= sin_ruta:
                hw = leer_hardware() if t - ult_hw >= 3600 else None
                estado = enviar_rendimiento(r, hw)
                if hw and estado == 200:
                    ult_hw = t
                if estado == 404:
                    print("El servidor aún no tiene el módulo de rendimiento en vivo (aplica el parche). Se reintenta en 60 s.")
                    sin_ruta = t + 60
        except requests.RequestException as e:
            print("Sin conexión con el servidor:", e)
            time.sleep(5)
        except Exception as e:
            print("Error:", e)
        time.sleep(max(0.2, VIVO_SEG - (time.time() - t)))


if __name__ == "__main__":
    main()
