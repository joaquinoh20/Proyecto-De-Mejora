import { useState } from 'react';
import { qs, urlApi } from '../api.js';
import { Cargando, useApi } from '../ui.jsx';

const hoy = () => new Date().toISOString().slice(0, 10);

export default function Reportes() {
  const [fecha, setFecha] = useState(hoy());
  const [ver, setVer] = useState(hoy());
  const { datos: d } = useApi(`/reporte${qs({ fecha: ver })}`);
  const KPIS = [['total', 'Estaciones'], ['desconexiones', 'Desconexiones'], ['disp_desc', 'Dispositivos desconectados'], ['cambios', 'Cambios de componentes'], ['alertas', 'Alertas'], ['vistas', 'Vistas de pantalla']];

  return (
    <>
      <form className="panel form mt sin-imprimir" onSubmit={(e) => { e.preventDefault(); setVer(fecha); }}>
        <label>Fecha<input type="date" value={fecha} max={hoy()} onChange={(e) => setFecha(e.target.value)} /></label>
        <div className="fila-btn">
          <button className="btn" type="submit">Ver</button>
          <a className="btn" href={urlApi(`/reporte${qs({ fecha: ver, formato: 'csv' })}`)}>Descargar CSV</a>
          <button className="btn pri" type="button" onClick={() => window.print()}>Imprimir / guardar PDF</button>
        </div>
      </form>
      {!d ? <Cargando /> : (
        <section className="panel mt hoja">
          <h2>AlfaVía S.A.C. — Monitoreo OSIPTEL · {d.fecha_texto}</h2>
          <div className="kpis mt">
            {KPIS.map(([k, et]) => <div className="kpi" key={k}><span className="t2 peq">{et}</span><strong>{d.resumen[k] ?? 0}</strong></div>)}
          </div>
          <h3 className="mt">Estaciones con riesgo (IA)</h3>
          <table><thead><tr><th>Estación</th><th>Ubicación</th><th>Prob. de falla (7 días)</th><th>Nivel</th><th>Causa probable</th></tr></thead><tbody>
            {d.riesgo.map((r) => <tr key={r.codigo_activo}><td>{r.codigo_activo}</td><td>{r.ubicacion}</td><td className="num">{Math.round(r.prob_falla * 100)}%</td><td>{r.nivel}</td><td>{r.causa_probable ?? ''}</td></tr>)}
            {!d.riesgo.length && <tr><td colSpan={5} className="t2">Ninguna estación en riesgo.</td></tr>}
          </tbody></table>
          <h3 className="mt">Movimientos del día</h3>
          <table><thead><tr><th>Hora</th><th>Estación</th><th>Evento</th><th>Detalle</th></tr></thead><tbody>
            {d.eventos.map((e, i) => <tr key={i}><td className="nw">{new Date(e.fecha).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}</td><td>{e.codigo_activo}</td><td>{e.titulo}</td><td className="peq">{e.detalle}</td></tr>)}
            {!d.eventos.length && <tr><td colSpan={4} className="t2">Sin movimientos este día.</td></tr>}
          </tbody></table>
          <h3 className="mt">Alertas</h3>
          <table><thead><tr><th>Hora</th><th>Estación</th><th>Severidad</th><th>Mensaje</th><th>Atendida</th></tr></thead><tbody>
            {d.alertas.map((a, i) => <tr key={i}><td className="nw">{new Date(a.creada_en).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}</td><td>{a.codigo_activo ?? '—'}</td><td>{a.severidad}</td><td className="peq">{a.mensaje}</td><td>{a.atendida_en ? 'Sí' : 'No'}</td></tr>)}
            {!d.alertas.length && <tr><td colSpan={5} className="t2">Sin alertas este día.</td></tr>}
          </tbody></table>
          <p className="peq t2 mt">Generado por {d.generado_por} el {d.generado_en}. Las probabilidades son estimaciones del modelo, no certezas.</p>
        </section>
      )}
    </>
  );
}
