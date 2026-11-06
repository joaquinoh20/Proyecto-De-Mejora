import { useEffect, useState } from 'react';
import { api } from '../api.js';
import { Cargando, Insignia, useApi, useAvisos } from '../ui.jsx';

export default function Politicas() {
  const { avisar } = useAvisos();
  const { datos: d, recargar } = useApi('/politicas');
  const [p, setP] = useState({});
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!d) return;
    const v = {};
    for (const lista of Object.values(d.grupos)) for (const x of lista) v[x.clave] = x.tipo === 'bool' ? x.valor === 'true' : x.valor;
    setP(v);
  }, [d]);

  if (!d) return <Cargando />;
  const g = d.gemini;
  const gemTexto = g.permitida ? 'Activo' : g.configurada ? 'Desactivado por política' : 'Sin API key';

  async function guardar(e) {
    e.preventDefault();
    setError(null);
    const r = await api('/politicas', { datos: { p } });
    if (!r.ok) { setError(r.error); return; }
    avisar('ok', r.mensaje);
    recargar(true);
  }

  return (
    <>
      {error && <div className="flash flash-error" role="alert">{error}</div>}
      <div className="cols">
        <form className="panel" onSubmit={guardar}>
          {Object.entries(d.grupos).map(([cat, lista]) => (
            <div key={cat}>
              <h2 className="mt">{cat}</h2>
              <div className="form mt">
                {lista.map((x) => x.tipo === 'bool' ? (
                  <label key={x.clave} className="casilla ancho"><input type="checkbox" checked={!!p[x.clave]} disabled={!d.puede_editar}
                    onChange={(e) => setP({ ...p, [x.clave]: e.target.checked })} /> {x.descripcion}</label>
                ) : (
                  <label key={x.clave}>{x.descripcion}
                    <input type="number" value={p[x.clave] ?? ''} min={x.min ?? undefined} max={x.max ?? undefined} step={x.tipo === 'num' ? '0.05' : '1'}
                      disabled={!d.puede_editar} onChange={(e) => setP({ ...p, [x.clave]: e.target.value })} /></label>
                ))}
              </div>
            </div>
          ))}
          {d.puede_editar && <div className="fila-btn mt"><button className="btn pri" type="submit">Guardar políticas</button></div>}
        </form>

        <div>
          <section className="panel" aria-labelledby="t-cum">
            <h2 id="t-cum">Estado de cumplimiento</h2>
            {d.cumplimiento.map((c) => (
              <div className="alerta" key={c.nombre}>
                <div><p>{c.nombre}</p><span className="t2 peq">{c.ayuda}</span></div>
                <Insignia texto={String(c.n)} clase={c.n > 0 ? 'wn' : 'ok'} />
              </div>
            ))}
            <div className="alerta"><div><p>Resúmenes con Gemini</p><span className="t2 peq">Modelo {g.modelo}</span></div><Insignia texto={gemTexto} clase={g.permitida ? 'ok' : 'off'} /></div>
            <div className="alerta"><div><p>Conexión del sitio</p></div><Insignia texto={d.https ? 'HTTPS' : 'Sin HTTPS'} clase={d.https ? 'ok' : 'wn'} /></div>
          </section>

          <section className="panel" aria-labelledby="t-uso">
            <h2 id="t-uso">Política de uso y monitoreo</h2>
            <p className="peq">Este sistema monitorea el <strong>estado técnico</strong> de los equipos de AlfaVía S.A.C.: carga de CPU y RAM, temperatura, salud del disco, conexión y dispositivos conectados.</p>
            <ul className="peq">
              <li>La pantalla de una estación solo se ve bajo demanda, con motivo escrito, por un tiempo limitado y con un aviso visible en esa estación.</li>
              <li>Cada vista de pantalla, cambio de política, ingreso y acción sensible queda registrada en la auditoría.</li>
              <li>No se guardan grabaciones: solo el último fotograma durante la vista, que se borra al terminar.</li>
              <li>A la API de Gemini solo se envían datos técnicos resumidos (códigos de equipo, eventos, métricas). Nunca capturas de pantalla ni nombres de personas.</li>
              <li>El personal debe estar informado de este monitoreo conforme a la normativa de protección de datos personales aplicable.</li>
            </ul>
          </section>
        </div>
      </div>
    </>
  );
}
