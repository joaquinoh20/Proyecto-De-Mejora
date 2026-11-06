import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api.js';
import { Cargando, Insignia, claseSeveridad, useApi, useAvisos } from '../ui.jsx';

function Resumen() {
  const [r, setR] = useState(null);
  const [fallo, setFallo] = useState(false);
  useEffect(() => { api('/resumen').then((x) => (x.ok ? setR(x) : setFallo(true))); }, []);
  return (
    <section className="panel ia" aria-labelledby="t-resumen">
      <div className="panel-cab">
        <h2 id="t-resumen">Desde tu último ingreso</h2>
        <span className="t2 peq">{r ? `${r.desde_texto} → ahora` : ''}</span>
      </div>
      <p className={`ia-texto${r || fallo ? '' : ' cargando'}`}>
        {fallo ? 'No se pudo generar el resumen.' : r ? r.texto : 'La IA está revisando los movimientos'}
      </p>
      {r && (
        <>
          <div className="ia-pie">
            <span className="insignia ia">{r.origen === 'gemini' ? `Redactado por Gemini (${r.modelo})` : 'Resumen automático (sin Gemini)'}</span>
            {r.aviso && <span>{r.aviso}</span>}
            <span>Desde {r.desde_texto}</span>
          </div>
          <ul className="mov">
            {r.movimientos.map((m, i) => (
              <li key={i}><span><strong>{m.estacion} · {m.titulo}</strong> {m.detalle}</span><time>{m.cuando}</time></li>
            ))}
            {!r.movimientos.length && <li className="t2">Sin movimientos en este periodo.</li>}
          </ul>
        </>
      )}
      <p className="peq"><Link to="/historial">Ver el historial completo</Link></p>
    </section>
  );
}

export default function Dashboard() {
  const { datos: d, recargar } = useApi('/dashboard', { cada: 10000 });
  const { avisar } = useAvisos();
  const [ocupada, setOcupada] = useState(null);

  async function atender(id) {
    setOcupada(id);
    const r = await api(`/alertas/${id}/atender`, { datos: {} });
    if (!r.ok) avisar('error', r.error || 'No se pudo atender la alerta.');
    setOcupada(null);
    recargar(true);
  }

  const grupos = {};
  for (const e of d?.estaciones || []) (grupos[e.ubicacion] ||= []).push(e);

  return (
    <>
      <Resumen />
      {!d ? <Cargando /> : (
        <>
          <div className="kpis">
            <div className="kpi"><b>{d.kpis.linea}</b><span>Estaciones en línea</span></div>
            <div className="kpi"><b>{d.kpis.alertas}</b><span>Alertas abiertas</span></div>
            <div className="kpi"><b>{d.kpis.atencion}</b><span>Estaciones con riesgo según el modelo</span></div>
            <div className="kpi"><b>{d.kpis.riesgo}</b><span>Riesgo promedio de falla (7 días)</span></div>
          </div>
          <div className="cols">
            <section className="panel" aria-labelledby="t-mapa">
              <div className="panel-cab">
                <h2 id="t-mapa">Estaciones · probabilidad de falla</h2>
                <span className={`insignia ${d.ml.clase}`}>{d.ml.texto}</span>
              </div>
              {Object.entries(grupos).map(([ubic, lista]) => (
                <div key={ubic}>
                  <h3 className="mt">{ubic}</h3>
                  <div className="mosaico">
                    {lista.map((e) => (
                      <Link key={e.id} className={`tile ${e.clase}`} to={`/estaciones/${e.id}`} title={e.titulo}>
                        <small>{e.codigo}</small><b>{e.valor}</b><small className="est">{e.estado}</small>
                      </Link>
                    ))}
                  </div>
                </div>
              ))}
              <p className="t2 peq mt">El porcentaje es la probabilidad de falla en los próximos 7 días calculada por el modelo de machine learning.</p>
            </section>
            <section className="panel" aria-labelledby="t-alertas">
              <h2 id="t-alertas">Alertas tempranas</h2>
              {d.alertas.map((a) => (
                <div className="alerta" key={a.id}>
                  <div><p>{a.mensaje}</p><span className="t2 peq">{a.cuando}</span></div>
                  <div className="fila-btn">
                    <Insignia texto={a.severidad} clase={claseSeveridad(a.severidad)} />
                    {d.puede_atender && <button className="btn peq" type="button" disabled={ocupada === a.id} onClick={() => atender(a.id)}>Atender</button>}
                  </div>
                </div>
              ))}
              {!d.alertas.length && <p className="vacio">Sin alertas abiertas.</p>}
            </section>
          </div>
        </>
      )}
    </>
  );
}
