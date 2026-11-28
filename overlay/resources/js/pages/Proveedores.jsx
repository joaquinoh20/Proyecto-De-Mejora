import { useState } from 'react';
import { api } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Cargando, Dialogo, Insignia, useApi, useAvisos } from '../ui.jsx';

const VACIO = { id: 0, razon_social: '', ruc: '', tipo: 'equipos', contacto: '', telefono: '', correo: '', direccion: '' };

export default function Proveedores() {
  const { puede } = useAuth();
  const { avisar } = useAvisos();
  const { datos: d, recargar } = useApi('/proveedores');
  const [f, setF] = useState(null);
  const [error, setError] = useState(null);
  const editar = puede('proveedores.editar');
  if (!d) return <Cargando />;
  const c = (k) => ({ value: f[k] ?? '', onChange: (e) => setF({ ...f, [k]: e.target.value }) });

  async function guardar(e) {
    e.preventDefault();
    const r = await api('/proveedores', { datos: f });
    if (!r.ok) { setError(r.error); return; }
    avisar('ok', 'Proveedor guardado.');
    setF(null);
    recargar(true);
  }
  async function cambiarEstado(p) {
    const r = await api(`/proveedores/${p.id}/estado`, { datos: {} });
    if (r.ok) { avisar('ok', r.activo ? 'Proveedor activado.' : 'Proveedor desactivado.'); recargar(true); } else avisar('error', r.error);
  }

  return (
    <>
      <div className="fila-btn mt">{editar && <button className="btn pri" type="button" onClick={() => { setError(null); setF(VACIO); }}>Nuevo proveedor</button>}</div>
      <div className="panel tw mt">
        <table>
          <thead><tr><th>Proveedor</th><th>RUC</th><th>Tipo</th><th>Contacto</th><th>Estaciones</th><th>Componentes</th><th>Estado</th>{editar && <th />}</tr></thead>
          <tbody>
            {d.proveedores.map((p) => (
              <tr key={p.id} className={p.activo ? '' : 'retirado'}>
                <td><strong>{p.razon_social}</strong><br /><span className="t2 peq">{p.direccion ?? ''}</span></td>
                <td className="nw">{p.ruc ?? '—'}</td>
                <td>{d.tipos[p.tipo] ?? p.tipo}</td>
                <td className="peq">{p.contacto ?? '—'}<br />{p.telefono ?? ''}<br />{p.correo ?? ''}</td>
                <td className="num">{p.n_est}</td><td className="num">{p.n_comp}</td>
                <td><Insignia texto={p.activo ? 'Activo' : 'Inactivo'} clase={p.activo ? 'ok' : 'off'} /></td>
                {editar && (
                  <td className="nw">
                    <button className="btn peq" type="button" onClick={() => { setError(null); setF({ ...VACIO, ...Object.fromEntries(Object.entries(p).map(([k, v]) => [k, v ?? ''])) }); }}>Editar</button>{' '}
                    <button className="btn peq" type="button" onClick={() => cambiarEstado(p)}>{p.activo ? 'Desactivar' : 'Activar'}</button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Dialogo abierto={!!f} onCerrar={() => setF(null)}>
        {f && (
          <form className="form" onSubmit={guardar}>
            <h2 className="ancho">Proveedor</h2>
            {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
            <label className="ancho">Razón social<input type="text" maxLength={150} required {...c('razon_social')} /></label>
            <label>RUC<input type="text" maxLength={11} pattern="\d{11}" inputMode="numeric" {...c('ruc')} /></label>
            <label>Tipo<select {...c('tipo')}>{Object.entries(d.tipos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
            <label>Contacto<input type="text" maxLength={100} {...c('contacto')} /></label>
            <label>Teléfono<input type="text" maxLength={30} {...c('telefono')} /></label>
            <label className="ancho">Correo<input type="email" maxLength={120} {...c('correo')} /></label>
            <label className="ancho">Dirección<input type="text" maxLength={200} {...c('direccion')} /></label>
            <div className="ancho fila-btn"><button className="btn pri" type="submit">Guardar</button><button className="btn" type="button" onClick={() => setF(null)}>Cancelar</button></div>
          </form>
        )}
      </Dialogo>
    </>
  );
}
