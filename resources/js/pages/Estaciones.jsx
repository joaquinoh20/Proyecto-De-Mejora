import { useState } from 'react';
import { Link } from 'react-router-dom';
import { qs } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Cargando, Insignia, etiquetaEstado, etiquetaNivel, useApi } from '../ui.jsx';

export default function Estaciones() {
  const { puede } = useAuth();
  const [f, setF] = useState({ q: '', ubicacion: '0', estado: '', proveedor: '0' });
  const [aplicado, setAplicado] = useState(f);
  const { datos: d } = useApi(`/estaciones${qs(aplicado)}`);
  const cambiar = (k) => (e) => setF({ ...f, [k]: e.target.value });

  return (
    <>
      <form className="filtros" onSubmit={(e) => { e.preventDefault(); setAplicado(f); }}>
        <label>Buscar<input type="search" value={f.q} onChange={cambiar('q')} placeholder="Código, host, marca…" /></label>
        <label>Ubicación<select value={f.ubicacion} onChange={cambiar('ubicacion')}><option value="0">Todas</option>
          {d?.ubicaciones.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></label>
        <label>Estado<select value={f.estado} onChange={cambiar('estado')}><option value="">Todos</option>
          <option value="en_linea">En línea</option><option value="sin_conexion">Sin conexión</option>
          <option value="mantenimiento">Mantenimiento</option><option value="baja">De baja</option></select></label>
        <label>Proveedor<select value={f.proveedor} onChange={cambiar('proveedor')}><option value="0">Todos</option>
          {d?.proveedores.map((x) => <option key={x.id} value={x.id}>{x.razon_social}</option>)}</select></label>
        <button className="btn" type="submit">Filtrar</button>
        {puede('estaciones.editar') && <Link className="btn pri" to="/estaciones/nueva">Nueva estación</Link>}
      </form>
      {!d ? <Cargando /> : (
        <>
          <div className="panel tw">
            <table>
              <thead><tr><th>Activo</th><th>Host</th><th>Ubicación</th><th>Equipo</th><th>CPU · RAM · Disco</th><th>Proveedor</th><th>Estado</th><th>Riesgo (7 d)</th></tr></thead>
              <tbody>
                {d.estaciones.map((e) => {
                  const [et, cl] = etiquetaEstado(e.estado);
                  const [nt, nc] = etiquetaNivel(e.nivel);
                  return (
                    <tr key={e.id}>
                      <td className="nw"><Link to={`/estaciones/${e.id}`}><strong>{e.codigo_activo}</strong></Link></td>
                      <td>{e.nombre_host}</td>
                      <td>{e.ubicacion}</td>
                      <td>{`${e.marca ?? ''} ${e.modelo ?? ''}`.trim()}</td>
                      <td className="peq">{e.cpu_modelo ?? '—'}<br />{e.ram_gb ?? '—'} GB · {e.disco_tipo ?? ''} {e.disco_gb ?? '—'} GB</td>
                      <td>{e.proveedor ?? '—'}</td>
                      <td><Insignia texto={et} clase={cl} /></td>
                      <td className="nw">{e.prob_falla !== null && e.estado !== 'sin_conexion' ? <Insignia texto={`${Math.round(e.prob_falla * 100)}% · ${nt}`} clase={nc} /> : '—'}</td>
                    </tr>
                  );
                })}
                {!d.estaciones.length && <tr><td colSpan={8} className="vacio">No hay estaciones con esos filtros.</td></tr>}
              </tbody>
            </table>
          </div>
          <p className="t2 peq">{d.estaciones.length} estaciones.</p>
        </>
      )}
    </>
  );
}
