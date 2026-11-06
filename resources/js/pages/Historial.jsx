import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { qs, urlApi } from '../api.js';
import { Cargando, Insignia, Paginador, fecha, useApi } from '../ui.jsx';

const CATS = [['', 'Todo'], ['conexion', 'Conexión de estaciones'], ['dispositivo', 'Dispositivos'], ['componente', 'Componentes']];

export default function Historial() {
  const [params, setParams] = useSearchParams();
  const f = { cat: params.get('cat') || '', estacion: params.get('estacion') || '0', desde: params.get('desde') || '', hasta: params.get('hasta') || '', p: Number(params.get('p')) || 1 };
  const [borrador, setBorrador] = useState(f);
  const { datos: d } = useApi(`/historial${qs({ ...f, p: f.p > 1 ? f.p : '' })}`);
  const poner = (nuevo) => setParams(Object.fromEntries(Object.entries(nuevo).filter(([k, v]) => v !== '' && v !== '0' && v !== 0 && !(k === 'p' && v === 1))));
  const cambiar = (k) => (e) => setBorrador({ ...borrador, [k]: e.target.value });

  return (
    <>
      <nav className="pestanas" aria-label="Categoría">
        {CATS.map(([k, n]) => (
          <a key={k || 'todo'} href="#" aria-current={f.cat === k ? 'page' : undefined}
            onClick={(e) => { e.preventDefault(); setBorrador({ ...borrador, cat: k }); poner({ ...f, cat: k, p: 1 }); }}>{n}</a>
        ))}
      </nav>
      <form className="filtros" onSubmit={(e) => { e.preventDefault(); poner({ ...borrador, p: 1 }); }}>
        <label>Estación<select value={borrador.estacion} onChange={cambiar('estacion')}><option value="0">Todas</option>
          {d?.estaciones.map((x) => <option key={x.id} value={x.id}>{x.codigo_activo}</option>)}</select></label>
        <label>Desde<input type="date" value={borrador.desde} onChange={cambiar('desde')} /></label>
        <label>Hasta<input type="date" value={borrador.hasta} onChange={cambiar('hasta')} /></label>
        <button className="btn" type="submit">Filtrar</button>
        <a className="btn" href={urlApi(`/historial${qs({ ...f, p: '', csv: '1' })}`)}>Descargar CSV</a>
      </form>
      {!d ? <Cargando /> : (
        <>
          <div className="panel tw">
            <table>
              <thead><tr><th>Fecha</th><th>Estación</th><th>Movimiento</th><th>Detalle</th></tr></thead>
              <tbody>
                {d.movimientos.map((m, i) => {
                  const mal = ['desconectada', 'desconectado', 'retirado'].includes(m.subtipo);
                  return (
                    <tr key={i}>
                      <td className="nw">{fecha(m.fecha)}</td>
                      <td className="nw"><Link to={`/estaciones/${m.estacion_id}`}>{m.codigo_activo}</Link></td>
                      <td className="nw"><Insignia texto={m.titulo} clase={mal ? 'wn' : 'ok'} /></td>
                      <td>{m.detalle}</td>
                    </tr>
                  );
                })}
                {!d.movimientos.length && <tr><td colSpan={4} className="vacio">No hay movimientos con esos filtros.</td></tr>}
              </tbody>
            </table>
          </div>
          <Paginador pagina={d.pagina} paginas={d.paginas} total={d.total} etiqueta="movimientos" onCambiar={(p) => poner({ ...f, p })} />
        </>
      )}
    </>
  );
}
