import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, urlApi } from '../api.js';
import { Cargando, Dialogo, Insignia, fecha, useApi, useAvisos } from '../ui.jsx';

/** Muestra el último fotograma de la sesión y lo refresca cada 1,5 s (sin alargar la sesión del usuario). */
function VistaPantalla({ s, onTerminar }) {
  const [src, setSrc] = useState(null);
  const [estado, setEstado] = useState('Conectando…');
  useEffect(() => {
    let vivo = true;
    const traer = () => {
      if (document.hidden) return;
      const img = new Image();
      const url = urlApi(`/pantallas/${s.id}/frame?act=0&t=${Date.now()}`);
      img.onload = () => { if (vivo) { setSrc(url); setEstado(`Actualizado ${new Date().toLocaleTimeString('es-PE')}`); } };
      img.onerror = () => { if (vivo) setEstado('Esperando imagen de la estación…'); };
      img.src = url;
    };
    traer();
    const t = setInterval(traer, 1500);
    return () => { vivo = false; clearInterval(t); };
  }, [s.id]);
  return (
    <article className="pantalla">
      <div className="pantalla-cab">
        <div><strong>{s.codigo_activo}</strong> <span className="t2">{s.ubicacion}</span></div>
        <button className="btn peq peligro" type="button" onClick={() => onTerminar(s.id)}>Terminar</button>
      </div>
      <div className="pantalla-img">
        {src ? <img src={src} alt={`Pantalla de ${s.codigo_activo}`} /> : <span className="msg">Esperando la imagen de la estación</span>}
      </div>
      <div className="pantalla-pie"><span>{estado}</span> · termina a las {s.termina} · motivo: {s.motivo ?? '—'}</div>
    </article>
  );
}

export default function Pantallas() {
  const [params] = useSearchParams();
  const { avisar } = useAvisos();
  const { datos: d, recargar } = useApi('/pantallas');
  const [dest, setDest] = useState(null);       // estación elegida para ver
  const [f, setF] = useState({ motivo: '', minutos: 5 });
  const [error, setError] = useState(null);
  const [abrioAuto, setAbrioAuto] = useState(false);

  // /pantallas?estacion=ID abre el diálogo de esa estación (viene del botón «Ver pantalla» de la ficha)
  useEffect(() => {
    const pre = Number(params.get('estacion'));
    if (d && pre && !abrioAuto) {
      const e = d.estaciones.find((x) => x.id === pre && !x.mia);
      if (e) setDest(e);
      setAbrioAuto(true);
    }
  }, [d, params, abrioAuto]);

  if (!d) return <Cargando />;
  const { politica: pol } = d;

  async function iniciar(e) {
    e.preventDefault();
    setError(null);
    const r = await api('/pantallas/iniciar', { datos: { estacion: dest.id, motivo: f.motivo, minutos: Number(f.minutos) } });
    if (!r.ok) { setError(r.error); return; }
    avisar('ok', r.mensaje);
    setDest(null);
    setF({ motivo: '', minutos: Math.min(5, pol.max_min) });
    recargar(true);
  }
  async function terminar(id) {
    await api(`/pantallas/${id}/cerrar`, { datos: {} });
    avisar('ok', 'Vista terminada.');
    recargar(true);
  }

  return (
    <>
      <div className="aviso-ver">
        <strong>Uso responsable.</strong> Cada vista queda registrada con tu nombre, la estación y el motivo.{' '}
        {pol.aviso_visible ? 'La estación muestra un aviso visible mientras se comparte su pantalla. ' : ''}
        Dura como máximo {pol.max_min} minutos y solo se guarda el último fotograma, que se borra al terminar.
      </div>

      {d.activas.length > 0 && (
        <section aria-label="Vistas activas"><div className="pantallas">
          {d.activas.map((s) => <VistaPantalla key={s.id} s={s} onTerminar={terminar} />)}
        </div></section>
      )}

      <section className="panel mt" aria-labelledby="t-est">
        <h2 id="t-est">Estaciones en línea</h2>
        <div className="tw"><table>
          <thead><tr><th>Estación</th><th>Ubicación</th><th>Puesto</th><th /></tr></thead>
          <tbody>
            {d.estaciones.map((e) => (
              <tr key={e.id}>
                <td className="nw"><strong>{e.codigo_activo}</strong> <span className="t2">{e.nombre_host}</span></td>
                <td>{e.ubicacion}</td><td>{e.puesto ?? ''}</td>
                <td className="nw">{e.mia ? <Insignia texto="Viendo" clase="ok" /> : <button className="btn peq pri" type="button" onClick={() => { setError(null); setDest(e); }}>Ver pantalla</button>}</td>
              </tr>
            ))}
            {!d.estaciones.length && <tr><td colSpan={4} className="vacio">No hay estaciones en línea.</td></tr>}
          </tbody>
        </table></div>
      </section>

      {d.todas.length > 0 && (
        <section className="panel" aria-labelledby="t-todas">
          <h2 id="t-todas">Quién está viendo pantallas ahora</h2>
          <div className="tw"><table><thead><tr><th>Usuario</th><th>Estación</th><th>Desde</th><th>Motivo</th></tr></thead><tbody>
            {d.todas.map((s) => <tr key={s.id}><td>{s.nombre_completo}</td><td>{s.codigo_activo}</td><td className="nw">{fecha(s.inicio)}</td><td>{s.motivo ?? '—'}</td></tr>)}
          </tbody></table></div>
        </section>
      )}

      <Dialogo abierto={!!dest} onCerrar={() => setDest(null)}>
        <form className="form" onSubmit={iniciar}>
          <h2 className="ancho">Ver pantalla</h2>
          <p className="ancho t2 sin-margen">{dest && `${dest.codigo_activo} · ${dest.ubicacion}`}</p>
          {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
          <label className="ancho">Motivo de la vista{pol.motivo_obligatorio ? ' (obligatorio)' : ''}
            <input type="text" maxLength={300} required={pol.motivo_obligatorio} minLength={pol.motivo_obligatorio ? 10 : undefined}
              placeholder="Ej.: verificar la incidencia reportada por el usuario" value={f.motivo} onChange={(e) => setF({ ...f, motivo: e.target.value })} /></label>
          <label>Duración (minutos)<input type="number" min={1} max={pol.max_min} value={f.minutos} onChange={(e) => setF({ ...f, minutos: e.target.value })} /></label>
          <div className="ancho fila-btn"><button className="btn pri" type="submit">Iniciar vista</button><button className="btn" type="button" onClick={() => setDest(null)}>Cancelar</button></div>
        </form>
      </Dialogo>
    </>
  );
}
