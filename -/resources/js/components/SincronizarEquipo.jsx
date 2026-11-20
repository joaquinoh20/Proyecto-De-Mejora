import { useState } from 'react';
import { api } from '../api.js';
import { Dialogo, Insignia, useApi, useAvisos } from '../ui.jsx';

/** Convierte base64 en un archivo descargable (sin pasar por otra petición). */
function descargar(nombre, base64) {
  const bin = atob(base64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  const url = URL.createObjectURL(new Blob([bytes], { type: 'application/zip' }));
  const a = document.createElement('a');
  a.href = url; a.download = nombre; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

function Contenido({ id, onCerrar }) {
  const { avisar } = useAvisos();
  // Consulta cada 3 s: así se ve en vivo cuando la PC empieza a reportar (no alarga la sesión)
  const { datos: s } = useApi(`/estaciones/${id}/sync`, { cada: 3000 });
  const [servidor, setServidor] = useState(null);
  const [bajando, setBajando] = useState(false);
  const [diag, setDiag] = useState(null);
  const [pensando, setPensando] = useState(false);

  if (!s) return <p className="vacio" role="status">Cargando…</p>;
  const url = servidor ?? s.servidor;
  const local = /^https?:\/\/(127\.0\.0\.1|localhost)/i.test(url);
  const op = s.operatividad;

  async function bajar() {
    if (s.estacion.tiene_token && !window.confirm('Se generará un token nuevo: si esta PC ya tenía un agente instalado, dejará de funcionar hasta que uses el paquete nuevo. ¿Continuar?')) return;
    setBajando(true);
    const r = await api(`/estaciones/${id}/agente`, { datos: { servidor: url } });
    setBajando(false);
    if (!r.ok) { avisar('error', r.error); return; }
    descargar(r.nombre, r.base64);
    avisar('ok', `Paquete descargado: ${r.nombre}`);
  }
  async function diagnosticar() {
    setPensando(true);
    setDiag(null);
    const r = await api(`/estaciones/${id}/diagnostico`, { datos: {} });
    setPensando(false);
    if (!r.ok) { avisar('error', r.error); return; }
    setDiag(r);
  }

  return (
    <div className="form">
      <h2 className="ancho">Sincronizar equipo · {s.estacion.codigo}</h2>
      <p className="ancho t2 sin-margen">{s.estacion.host} · {s.estacion.piso}{s.estacion.proveedor ? ` · Proveedor: ${s.estacion.proveedor}` : ''}</p>

      <div className="ancho">
        <h3>1. Descarga el agente de esta PC</h3>
        <p className="t2 peq">Entra al sistema web desde la computadora que vas a conectar, o pasa el archivo por USB. El paquete ya trae su token y la dirección del servidor.</p>
      </div>
      <label className="ancho">Dirección del servidor (la que verá esa PC)
        <input type="text" value={url} onChange={(e) => setServidor(e.target.value)} placeholder="http://192.168.1.20:8000" />
      </label>
      {local && <p className="ancho flash" role="note">Esa dirección solo funciona en <strong>este</strong> equipo. Para otra PC usa la IP del servidor (por ejemplo <code>http://192.168.1.20:8000</code>) y enciende Laravel con <code>php artisan serve --host=0.0.0.0</code>.</p>}
      <div className="ancho fila-btn"><button className="btn pri" type="button" disabled={bajando} onClick={bajar}>{bajando ? 'Preparando…' : 'Descargar agente configurado'}</button></div>

      <div className="ancho">
        <h3>2. Ejecútalo en la PC</h3>
        <p className="t2 peq">Extrae el zip y haz doble clic en <code>iniciar.bat</code> (necesita Python 3.10 o superior). Si ya tienes Python, la primera vez instala sus librerías solo.</p>
      </div>

      <div className="ancho">
        <h3>3. Conexión en vivo</h3>
        <p>
          {s.conectado ? <Insignia texto="Conectado" clase="ok" /> : <Insignia texto="Esperando conexión…" clase="off" />}{' '}
          <span className="t2 peq">{s.estacion.hace_latido ? `Último reporte ${s.estacion.hace_latido}` : 'Aún no ha reportado'}</span>
        </p>
        {s.ultima && <p className="t2 peq">CPU {Math.round(s.ultima.cpu ?? 0)}% · RAM {Math.round(s.ultima.ram ?? 0)}% · {s.ultima.temp != null ? `${Math.round(s.ultima.temp)} °C` : 'sin sensor de temperatura'} · salud del disco {s.ultima.salud != null ? `${Math.round(s.ultima.salud)}%` : '—'}</p>}
      </div>

      <div className="ancho">
        <h3>4. Operatividad y diagnóstico</h3>
        <p><Insignia texto={op.etiqueta} clase={op.clase} /></p>
        <ul className="peq">{op.motivos.map((m, i) => <li key={i}>{m}</li>)}</ul>
        <div className="fila-btn"><button className="btn" type="button" disabled={pensando} onClick={diagnosticar}>{pensando ? 'Consultando a Gemini…' : 'Diagnosticar con Gemini'}</button></div>
        {diag && (
          <div className="mt">
            <p className="ia-texto" style={{ whiteSpace: 'pre-wrap' }}>{diag.texto}</p>
            <div className="ia-pie">
              <span className="insignia ia">{diag.origen === 'gemini' ? `Redactado por Gemini (${diag.modelo})` : 'Diagnóstico automático (sin Gemini)'}</span>
              {diag.aviso && <span>{diag.aviso}</span>}
            </div>
          </div>
        )}
      </div>
      <div className="ancho fila-btn"><button className="btn" type="button" onClick={onCerrar}>Cerrar</button></div>
    </div>
  );
}

export default function SincronizarEquipo({ id, onCerrar }) {
  return <Dialogo abierto={!!id} onCerrar={onCerrar}>{id ? <Contenido id={id} onCerrar={onCerrar} /> : null}</Dialogo>;
}
