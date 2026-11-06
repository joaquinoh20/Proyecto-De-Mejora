import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api } from './api.js';

/* ---------- Avisos (flash) ---------- */
const AvisosCtx = createContext({ avisar: () => {} });
export const useAvisos = () => useContext(AvisosCtx);

export function AvisosProvider({ children }) {
  const [lista, setLista] = useState([]);
  const avisar = useCallback((tipo, msg) => {
    const id = Math.random();
    setLista((l) => [...l, { id, tipo, msg }]);
    setTimeout(() => setLista((l) => l.filter((x) => x.id !== id)), 7000);
  }, []);
  return (
    <AvisosCtx.Provider value={{ avisar }}>
      {children}
      <div className="avisos" aria-live="polite">
        {lista.map((a) => <div key={a.id} className={`flash flash-${a.tipo}`} role="status">{a.msg}</div>)}
      </div>
    </AvisosCtx.Provider>
  );
}

/* ---------- Elementos pequeños ---------- */
export const Insignia = ({ texto, clase }) => <span className={`insignia ${clase}`}>{texto}</span>;

export const claseSeveridad = (s) => (s === 'alta' ? 'rk' : s === 'media' ? 'wn' : 'off');

export function etiquetaEstado(estado) {
  return { en_linea: ['En línea', 'ok'], sin_conexion: ['Sin conexión', 'off'], mantenimiento: ['Mantenimiento', 'wn'], baja: ['De baja', 'off'] }[estado] || [estado, 'off'];
}
export function etiquetaNivel(nivel) {
  return { critico: ['Crítico', 'rk'], atencion: ['Atención', 'wn'], normal: ['Normal', 'ok'] }[nivel] || ['Sin dato', 'off'];
}

export const fecha = (ts, seg = false) => {
  if (!ts) return '—';
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return String(ts);
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}${seg ? `:${p(d.getSeconds())}` : ''}`;
};
export const soloFecha = (ts) => (ts ? fecha(`${String(ts).slice(0, 10)}T12:00:00`).slice(0, 10) : '—');

export const Cargando = ({ texto = 'Cargando…' }) => <p className="vacio" role="status">{texto}</p>;

/** Barra de un medidor: el ancho se aplica por JavaScript (compatible con CSP estricta). */
export function Medidor({ nombre, valor, unidad, aviso, critico, invertido = false }) {
  if (valor === null || valor === undefined) {
    return <div className="medidor"><div className="medidor-cab"><span>{nombre}</span><span className="t2">sin dato</span></div></div>;
  }
  const v = Number(valor);
  const mal = invertido ? (v <= critico ? 'rk' : v <= aviso ? 'wn' : '') : (v >= critico ? 'rk' : v >= aviso ? 'wn' : '');
  return (
    <div className="medidor">
      <div className="medidor-cab"><span>{nombre}</span><strong className="num">{Math.round(v)}{unidad}</strong></div>
      <div className="pista"><i className={mal} style={{ width: `${Math.max(0, Math.min(100, v))}%` }} /></div>
    </div>
  );
}

/** Diálogo modal (elemento <dialog> nativo). */
export function Dialogo({ abierto, onCerrar, children }) {
  const ref = useRef(null);
  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (abierto && !d.open) d.showModal();
    if (!abierto && d.open) d.close();
  }, [abierto]);
  return <dialog ref={ref} onClose={onCerrar}>{abierto ? children : null}</dialog>;
}

/** Carga datos de la API y permite recargar. Con `cada` (ms) se actualiza solo, sin alargar la sesión. */
export function useApi(ruta, { cada = 0, activo = true } = {}) {
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState(null);
  const cargar = useCallback(async (silencioso = false) => {
    const r = await api(ruta, { actividad: !silencioso });
    if (r.ok) { setDatos(r); setError(null); } else if (!silencioso) setError(r.error);
    return r;
  }, [ruta]);
  useEffect(() => {
    if (!activo || !ruta) return undefined;
    setDatos(null);
    cargar();
    if (!cada) return undefined;
    const t = setInterval(() => { if (!document.hidden) cargar(true); }, cada);
    return () => clearInterval(t);
  }, [ruta, cada, activo, cargar]);
  return { datos, error, recargar: cargar };
}

export function Paginador({ pagina, paginas, total, etiqueta = 'registros', onCambiar }) {
  return (
    <div className="pager">
      <span>{total} {etiqueta} · página {pagina} de {paginas}</span>
      {pagina > 1 && <button className="btn peq" type="button" onClick={() => onCambiar(pagina - 1)}>← Anterior</button>}
      {pagina < paginas && <button className="btn peq" type="button" onClick={() => onCambiar(pagina + 1)}>Siguiente →</button>}
    </div>
  );
}

/** Gráfico de línea en SVG (sin librerías). */
export function Grafico({ titulo, serie, min = 0, max = 100, unidad = '' }) {
  const W = 300, H = 96, P = { l: 28, r: 6, t: 6, b: 14 };
  const validos = serie.filter((p) => p.v !== null && p.v !== undefined);
  if (validos.length < 2) return <div className="grafico"><h3>{titulo}</h3><p className="vacio">Sin datos suficientes</p></div>;
  const vs = validos.map((p) => p.v);
  const lo = Math.min(min, Math.floor(Math.min(...vs) / 5) * 5);
  const hi = Math.max(max, Math.ceil(Math.max(...vs) / 5) * 5);
  const t0 = serie[0].t, t1 = serie[serie.length - 1].t;
  const x = (t) => P.l + ((t - t0) / (t1 - t0 || 1)) * (W - P.l - P.r);
  const y = (v) => P.t + (1 - (v - lo) / (hi - lo || 1)) * (H - P.t - P.b);
  const ultimo = validos[validos.length - 1];
  return (
    <div className="grafico">
      <h3>{titulo}</h3>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Último valor ${ultimo.v}${unidad}`}>
        {[lo, (lo + hi) / 2, hi].map((g) => (
          <g key={g}>
            <line className="ej" x1={P.l} x2={W - P.r} y1={y(g)} y2={y(g)} />
            <text x="2" y={y(g) + 3}>{Math.round(g)}</text>
          </g>
        ))}
        <polyline className="l" points={validos.map((p) => `${x(p.t).toFixed(1)},${y(p.v).toFixed(1)}`).join(' ')} />
      </svg>
      <div className="peq t2">Último: {ultimo.v}{unidad} · mín {Math.min(...vs)}{unidad} · máx {Math.max(...vs)}{unidad}</div>
    </div>
  );
}
