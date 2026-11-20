import { useState } from 'react';
import { qs, urlApi } from '../api.js';
import { Cargando, Insignia, useApi } from '../ui.jsx';

const hoy = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const enlace = (fecha, formato) => urlApi(`/reporte${qs({ fecha, formato })}`);

/** Botones de descarga: el archivo se genera en el momento con los datos actuales de la base. */
function Descargas({ fecha }) {
  return (
    <span className="fila-btn">
      <a className="btn peq pri" href={enlace(fecha, 'pdf')} download>PDF</a>
      <a className="btn peq pri" href={enlace(fecha, 'xlsx')} download>Excel</a>
      <a className="btn peq" href={enlace(fecha, 'csv')} download>CSV</a>
    </span>
  );
}

export default function Reportes() {
  const [fecha, setFecha] = useState(hoy());
  const [ver, setVer] = useState(hoy());
  const esHoy = ver === hoy();
  // El día actual se refresca solo cada 15 s (sin alargar la sesión); los días pasados no cambian
  const { datos: d } = useApi(`/reporte${qs({ fecha: ver })}`, { cada: esHoy ? 15000 : 0 });
  const { datos: h } = useApi('/reporte/historial', { cada: 30000 });
  const KPIS = [['total', 'Estaciones'], ['desconexiones', 'Desconexiones'], ['disp_desc', 'Dispositivos desconectados'], ['cambios', 'Cambios de componentes'], ['alertas', 'Alertas'], ['vistas', 'Vistas de pantalla']];
  const elegir = (f) => { setFecha(f); setVer(f); window.scrollTo({ top: 0, behavior: 'smooth' }); };

  return (
    <>
      <form className="panel form mt sin-imprimir" onSubmit={(e) => { e.preventDefault(); setVer(fecha); }}>
        <label>Fecha<input type="date" value={fecha} max={hoy()} onChange={(e) => setFecha(e.target.value)} /></label>
        <div className="fila-btn">
          <button className="btn" type="submit">Ver</button>
          <Descargas fecha={ver} />
          <button className="btn" type="button" onClick={() => window.print()}>Imprimir</button>
          {esHoy && <Insignia texto={d ? `En vivo · ${d.generado_en.slice(11)}` : 'En vivo'} clase="ok" />}
        </div>
      </form>

      {!d ? <Cargando /> : (
        <section className="panel mt hoja">
          <h2>AlfaVía S.A.C. — Monitoreo OSIPTEL · {d.fecha_texto}</h2>
          <div className="kpis mt">
            {KPIS.map(([k, et]) => <div className="kpi" key={k}><b>{d.resumen[k] ?? 0}</b><span>{et}</span></div>)}
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

      <section className="panel mt sin-imprimir" aria-labelledby="t-hist">
        <div className="panel-cab">
          <h2 id="t-hist">Historial de reportes</h2>
          <span className="t2 peq">{h ? `Actualizado ${h.ahora.slice(11)}` : ''}</span>
        </div>
        <p className="t2 peq">Un reporte por día (últimos 30). Al descargarlo se genera en ese momento con los datos de la base, así que el de hoy siempre está al día.</p>
        {!h ? <Cargando /> : (
          <div className="tw"><table>
            <thead><tr><th>Día</th><th className="num">Desconexiones</th><th className="num">Disp. desconectados</th><th className="num">Cambios</th><th className="num">Alertas</th><th className="num">Vistas</th><th>Descargar</th></tr></thead>
            <tbody>
              {h.dias.map((x) => (
                <tr key={x.fecha}>
                  <td className="nw"><button className="btn peq" type="button" onClick={() => elegir(x.fecha)}>{x.texto}</button> {x.hoy && <Insignia texto="Hoy" clase="ok" />}</td>
                  <td className="num">{x.desconexiones}</td><td className="num">{x.disp_desc}</td><td className="num">{x.cambios}</td><td className="num">{x.alertas}</td><td className="num">{x.vistas}</td>
                  <td><Descargas fecha={x.fecha} /></td>
                </tr>
              ))}
            </tbody>
          </table></div>
        )}
        {h && h.descargas.length > 0 && (
          <>
            <h3 className="mt">Últimas descargas</h3>
            <div className="tw"><table>
              <thead><tr><th>Cuándo</th><th>Reporte del día</th><th>Formato</th><th>Descargado por</th></tr></thead>
              <tbody>{h.descargas.map((x, i) => <tr key={i}><td className="nw">{x.cuando}</td><td>{x.fecha}</td><td>{x.formato}</td><td>{x.usuario}</td></tr>)}</tbody>
            </table></div>
          </>
        )}
      </section>
    </>
  );
}
