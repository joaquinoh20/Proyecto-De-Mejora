import { useEffect, useRef, useState } from 'react';
import { api } from '../api.js';

const N = 60;                       // puntos que se muestran (≈ 2 minutos)
const COLOR = { cpu: '#3b8de0', ram: '#a259d9', disco: '#3aa655', red: '#e08a2c', gpu: '#16a6a6' };

const num = (v, d = 0) => (v === null || v === undefined || Number.isNaN(Number(v)) ? '—' : Number(v).toFixed(d));
function bytes(b) {
  if (b === null || b === undefined) return '—';
  const u = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
  let i = 0, v = Number(b);
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
  return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${u[i]}`;
}
function duracion(s) {
  if (s === null || s === undefined) return '—';
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
  return `${d ? d + ' d ' : ''}${h} h ${m} min`;
}

/** Gráfico de líneas SVG. series = [{ datos: [..], color }], max fijo o automático. */
function Linea({ series, max, alto = 110 }) {
  const tope = max ?? Math.max(1, ...series.flatMap((s) => s.datos)) * 1.15;
  const x = (i, n) => (n <= 1 ? 0 : ((N - n + i) / (N - 1)) * 300);
  const y = (v) => 100 - Math.min(100, Math.max(0, (v / tope) * 100));
  return (
    <svg viewBox="0 0 300 100" preserveAspectRatio="none" style={{ width: '100%', height: alto, display: 'block', border: '1px solid rgba(128,128,128,.35)', borderRadius: 6 }} role="img" aria-label="Gráfico en vivo">
      {[25, 50, 75].map((g) => <line key={g} x1="0" x2="300" y1={g} y2={g} stroke="rgba(128,128,128,.2)" strokeWidth="0.5" vectorEffect="non-scaling-stroke" />)}
      {series.map((s, k) => {
        if (!s.datos.length) return null;
        const pts = s.datos.map((v, i) => `${x(i, s.datos.length).toFixed(1)},${y(v).toFixed(1)}`);
        return (
          <g key={k}>
            <polygon points={`${x(0, s.datos.length)},100 ${pts.join(' ')} ${x(s.datos.length - 1, s.datos.length)},100`} fill={s.color} opacity="0.15" />
            <polyline points={pts.join(' ')} fill="none" stroke={s.color} strokeWidth="1.6" vectorEffect="non-scaling-stroke" />
          </g>
        );
      })}
    </svg>
  );
}

function Mini({ datos, color, max }) {
  return <div style={{ width: 70 }}><Linea series={[{ datos, color }]} max={max} alto={34} /></div>;
}

function Dato({ k, v }) {
  return <div style={{ minWidth: 130 }}><div className="t2 peq">{k}</div><div style={{ fontSize: '1.15rem', fontWeight: 600 }}>{v}</div></div>;
}

function Barra({ pct, color }) {
  return <div style={{ height: 8, borderRadius: 4, background: 'rgba(128,128,128,.25)', overflow: 'hidden' }}><div style={{ width: `${Math.min(100, pct || 0)}%`, height: '100%', background: color }} /></div>;
}

function Fila({ k, v }) {
  return <div style={{ display: 'flex', gap: 8, justifyContent: 'space-between', padding: '3px 0', borderBottom: '1px solid rgba(128,128,128,.15)' }}><span className="t2">{k}</span><span style={{ textAlign: 'right' }}>{v ?? '—'}</span></div>;
}

function Hardware({ hw }) {
  if (!hw) return <p className="vacio">El hardware se lee al iniciar el agente y cada hora.</p>;
  const ram = hw.ram?.modulos ?? [];
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))', gap: 16 }}>
      <div>
        <h3>Placa base y BIOS</h3>
        <Fila k="Fabricante de la placa" v={hw.placa?.fabricante} />
        <Fila k="Modelo de la placa" v={hw.placa?.modelo} />
        <Fila k="Versión / serie" v={[hw.placa?.version, hw.placa?.serie].filter(Boolean).join(' · ') || null} />
        <Fila k="BIOS" v={[hw.bios?.fabricante, hw.bios?.version].filter(Boolean).join(' ') || null} />
        <Fila k="Fecha de la BIOS" v={hw.bios?.fecha} />
        <Fila k="Equipo" v={[hw.equipo?.fabricante, hw.equipo?.modelo].filter(Boolean).join(' ') || null} />
      </div>
      <div>
        <h3>Procesador y gráficos</h3>
        <Fila k="Procesador" v={hw.cpu?.nombre} />
        <Fila k="Núcleos / hilos" v={hw.cpu?.nucleos ? `${hw.cpu.nucleos} / ${hw.cpu.hilos}` : null} />
        <Fila k="Velocidad máxima" v={hw.cpu?.mhz_max ? `${(hw.cpu.mhz_max / 1000).toFixed(2)} GHz` : null} />
        {(hw.gpus ?? []).map((g, i) => <Fila key={i} k={`GPU ${i + 1}`} v={`${g.nombre}${g.driver ? ` · driver ${g.driver}` : ''}`} />)}
        <Fila k="Sistema operativo" v={[hw.so?.nombre, hw.so?.version, hw.so?.arq].filter(Boolean).join(' · ') || null} />
      </div>
      <div>
        <h3>Memoria y discos</h3>
        <Fila k="RAM total" v={hw.ram?.total_gb ? `${hw.ram.total_gb} GB` : null} />
        {ram.map((m, i) => <Fila key={i} k={m.ranura || `Módulo ${i + 1}`} v={`${m.gb ?? '?'} GB · ${m.mhz ?? '?'} MHz · ${[m.fabricante, m.parte].filter(Boolean).join(' ')}`} />)}
        {(hw.discos ?? []).map((d, i) => <Fila key={i} k={`Disco ${i + 1}`} v={`${d.nombre} · ${d.tipo} · ${d.gb} GB · ${d.salud ?? 'sin dato'}`} />)}
      </div>
    </div>
  );
}

function Procesos({ lista, titulo }) {
  return (
    <div>
      <h3>{titulo}</h3>
      {(lista ?? []).map((p, i) => <Fila key={i} k={p.nombre} v={`${num(p.cpu, 1)} % CPU · ${p.ram_mb} MB`} />)}
    </div>
  );
}

export default function RendimientoVivo({ id }) {
  const [d, setD] = useState(null);
  const [sel, setSel] = useState('cpu');
  const [error, setError] = useState(null);
  const h = useRef({ id: null, cpu: [], ram: [], lee: [], esc: [], baja: [], sube: [], gpu: [], ult: null });

  useEffect(() => {
    let vivo = true, timer;
    if (h.current.id !== id) h.current = { id, cpu: [], ram: [], lee: [], esc: [], baja: [], sube: [], gpu: [], ult: null };
    async function leer() {
      try {
        const x = await api(`/estaciones/${id}/rendimiento`);
        const v = x?.datos ?? x;
        if (!vivo) return;
        if (v && v.ok !== false) {
          setError(null);
          const r = v.rendimiento;
          if (r && r.t !== h.current.ult) {
            h.current.ult = r.t;
            const push = (a, val) => { a.push(Number(val) || 0); if (a.length > N) a.shift(); };
            push(h.current.cpu, r.cpu?.pct); push(h.current.ram, r.ram?.pct);
            push(h.current.lee, r.disco_io?.lee_bps); push(h.current.esc, r.disco_io?.escribe_bps);
            push(h.current.baja, r.red?.baja_bps); push(h.current.sube, r.red?.sube_bps);
            push(h.current.gpu, r.gpu?.pct);
          }
          setD(v);
        } else setError(v?.error || 'No se pudo leer el rendimiento.');
      } catch {
        if (vivo) setError('No se pudo conectar con el servidor.');
      } finally {
        if (vivo) timer = setTimeout(leer, document.hidden ? 6000 : 2000);
      }
    }
    leer();
    return () => { vivo = false; clearTimeout(timer); };
  }, [id]);

  const hs = h.current;
  const r = d?.rendimiento;
  const items = [
    ['cpu', 'CPU', r ? `${num(r.cpu.pct)} %` : '—', <Mini key="c" datos={hs.cpu} color={COLOR.cpu} max={100} />],
    ['ram', 'Memoria', r ? `${num(r.ram.usado_gb, 1)}/${num(r.ram.total_gb, 1)} GB (${num(r.ram.pct)} %)` : '—', <Mini key="m" datos={hs.ram} color={COLOR.ram} max={100} />],
    ['disco', 'Disco', r ? `L ${bytes(r.disco_io.lee_bps)} · E ${bytes(r.disco_io.escribe_bps)}` : '—', <Mini key="d" datos={hs.lee.map((v, i) => v + (hs.esc[i] || 0))} color={COLOR.disco} />],
    ['red', 'Red', r ? `↓ ${bytes(r.red.baja_bps)} · ↑ ${bytes(r.red.sube_bps)}` : '—', <Mini key="r" datos={hs.baja.map((v, i) => v + (hs.sube[i] || 0))} color={COLOR.red} />],
  ];
  if (r?.gpu) items.push(['gpu', 'GPU', `${num(r.gpu.pct)} %${r.gpu.temp ? ` · ${num(r.gpu.temp)} °C` : ''}`, <Mini key="g" datos={hs.gpu} color={COLOR.gpu} max={100} />]);

  let titulo = '', grande = null, datos = null;
  if (r) {
    if (sel === 'cpu') {
      titulo = d.hardware?.cpu?.nombre || 'Procesador';
      grande = <Linea series={[{ datos: hs.cpu, color: COLOR.cpu }]} max={100} alto={150} />;
      datos = (<>
        <Dato k="Utilización" v={`${num(r.cpu.pct)} %`} /><Dato k="Velocidad" v={r.cpu.mhz ? `${(r.cpu.mhz / 1000).toFixed(2)} GHz` : '—'} />
        <Dato k="Procesos" v={r.cpu.procesos} /><Dato k="Tiempo activo" v={duracion(r.uptime_s)} />
        <Dato k="Temperatura" v={r.cpu.temp ? `${num(r.cpu.temp)} °C` : 'sin sensor'} />
      </>);
    } else if (sel === 'ram') {
      titulo = 'Memoria RAM';
      grande = <Linea series={[{ datos: hs.ram, color: COLOR.ram }]} max={100} alto={150} />;
      datos = (<>
        <Dato k="En uso" v={`${num(r.ram.usado_gb, 1)} GB`} /><Dato k="Disponible" v={`${num(r.ram.disp_gb, 1)} GB`} />
        <Dato k="Total" v={`${num(r.ram.total_gb, 1)} GB`} /><Dato k="Archivo de paginación" v={`${num(r.ram.swap_pct)} %`} />
      </>);
    } else if (sel === 'disco') {
      titulo = 'Discos';
      grande = <Linea series={[{ datos: hs.lee, color: COLOR.disco }, { datos: hs.esc, color: '#c9a227' }]} alto={150} />;
      datos = (<>
        <Dato k="Lectura" v={bytes(r.disco_io.lee_bps)} /><Dato k="Escritura" v={bytes(r.disco_io.escribe_bps)} />
        <div style={{ flexBasis: '100%' }}>
          {r.discos.map((x) => (
            <div key={x.punto} style={{ margin: '6px 0' }}><div style={{ display: 'flex', justifyContent: 'space-between' }}><span>{x.punto}</span><span className="t2">{num(x.usado_gb, 1)} / {num(x.total_gb, 1)} GB · {num(x.pct)} %</span></div><Barra pct={x.pct} color={x.pct > 90 ? '#d7191f' : COLOR.disco} /></div>
          ))}
        </div>
      </>);
    } else if (sel === 'red') {
      titulo = 'Red';
      grande = <Linea series={[{ datos: hs.baja, color: COLOR.red }, { datos: hs.sube, color: '#7a5cff' }]} alto={150} />;
      datos = (<><Dato k="Recepción ↓" v={bytes(r.red.baja_bps)} /><Dato k="Envío ↑" v={bytes(r.red.sube_bps)} /><Dato k="Velocidad del enlace" v={r.red.mbps ? `${r.red.mbps} Mbps` : '—'} /></>);
    } else if (sel === 'gpu' && r.gpu) {
      titulo = r.gpu.nombre;
      grande = <Linea series={[{ datos: hs.gpu, color: COLOR.gpu }]} max={100} alto={150} />;
      datos = (<>
        <Dato k="Utilización" v={`${num(r.gpu.pct)} %`} /><Dato k="Memoria de video" v={`${num(r.gpu.vram_usada_mb)} / ${num(r.gpu.vram_total_mb)} MB`} />
        <Dato k="Temperatura" v={r.gpu.temp ? `${num(r.gpu.temp)} °C` : '—'} /><Dato k="Consumo" v={r.gpu.watts ? `${num(r.gpu.watts)} W` : '—'} />
      </>);
    }
  }

  const enVivo = d?.en_vivo;
  return (
    <section className="panel mt" aria-labelledby="t-viv">
      <div className="panel-cab">
        <h2 id="t-viv">Rendimiento en vivo y hardware</h2>
        <span className="t2" role="status" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <span style={{ width: 9, height: 9, borderRadius: '50%', background: enVivo ? '#2fb344' : '#9aa0a6' }} />
          {enVivo ? 'En vivo (se actualiza cada 2 s)' : d?.hay_datos ? `Sin señal hace ${duracion(d.edad_seg)}` : 'Sin datos'}
        </span>
      </div>
      {error && <p className="vacio" role="alert">{error}</p>}
      {d && !d.hay_datos && (
        <p className="vacio">Esta estación todavía no envía datos en vivo. Usa «Sincronizar equipo», descarga el paquete del agente y ejecútalo en la PC (con el agente nuevo).</p>
      )}
      {r && (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(210px,260px) 1fr', gap: 16, alignItems: 'start' }}>
          <div role="tablist" aria-label="Recursos" style={{ display: 'grid', gap: 6 }}>
            {items.map(([k, nombre, valor, mini]) => (
              <button key={k} type="button" role="tab" aria-selected={sel === k} onClick={() => setSel(k)}
                style={{ display: 'flex', gap: 10, alignItems: 'center', justifyContent: 'space-between', textAlign: 'left', padding: '8px 10px', cursor: 'pointer',
                  border: `1px solid ${sel === k ? COLOR[k] : 'rgba(128,128,128,.35)'}`, borderRadius: 8, background: sel === k ? 'rgba(128,128,128,.12)' : 'transparent', color: 'inherit', font: 'inherit' }}>
                <span><strong>{nombre}</strong><br /><span className="t2 peq">{valor}</span></span>{mini}
              </button>
            ))}
          </div>
          <div>
            <h3 style={{ margin: '0 0 6px' }}>{titulo}</h3>
            {grande}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px 28px', marginTop: 12 }}>{datos}</div>
            {sel === 'cpu' && (
              <div style={{ marginTop: 12 }}>
                <div className="t2 peq">Uso por núcleo lógico</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(54px,1fr))', gap: 6, marginTop: 4 }}>
                  {r.cpu.nucleos.map((p, i) => <div key={i} title={`Núcleo ${i}: ${p} %`}><Barra pct={p} color={COLOR.cpu} /><div className="t2 peq" style={{ textAlign: 'center' }}>{p}%</div></div>)}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
      {d?.hay_datos && (
        <div style={{ marginTop: 18 }}>
          <Hardware hw={d.hardware} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))', gap: 16, marginTop: 16 }}>
            <Procesos lista={r?.top_cpu} titulo="Procesos que más CPU usan" />
            <Procesos lista={r?.top_ram} titulo="Procesos que más memoria usan" />
          </div>
        </div>
      )}
    </section>
  );
}
