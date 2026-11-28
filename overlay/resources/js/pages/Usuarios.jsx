import { useState } from 'react';
import { api } from '../api.js';
import { Cargando, Dialogo, Insignia, fecha, useApi, useAvisos } from '../ui.jsx';

export default function Usuarios() {
  const { avisar } = useAvisos();
  const { datos: d, recargar } = useApi('/usuarios');
  const [nuevo, setNuevo] = useState(null);
  const [error, setError] = useState(null);
  const [temporal, setTemporal] = useState(null);
  if (!d) return <Cargando />;

  async function accion(ruta, datos = {}, confirmar = null) {
    if (confirmar && !window.confirm(confirmar)) return;
    const r = await api(ruta, { datos });
    if (!r.ok) { avisar('error', r.error); return; }
    if (r.temporal) setTemporal(r.temporal);
    recargar(true);
  }
  async function crear(e) {
    e.preventDefault();
    const r = await api('/usuarios', { datos: nuevo });
    if (!r.ok) { setError(r.error); return; }
    setTemporal(r.temporal);
    setNuevo(null);
    recargar(true);
  }

  return (
    <>
      {temporal && (
        <div className="flash flash-ok" role="alert">
          Contraseña temporal de <strong>{temporal.usuario}</strong>: <code>{temporal.clave}</code><br />
          <span className="peq">Cópiala ahora: no se vuelve a mostrar. La persona deberá cambiarla en su primer ingreso.</span>{' '}
          <button className="btn peq" type="button" onClick={() => setTemporal(null)}>Ya la copié</button>
        </div>
      )}
      <div className="fila-btn mt"><button className="btn pri" type="button" onClick={() => { setError(null); setNuevo({ usuario: '', nombre_completo: '', correo: '', rol_id: d.roles[0]?.id }); }}>Nuevo usuario</button></div>
      <div className="panel tw mt">
        <table>
          <thead><tr><th>Usuario</th><th>Rol</th><th>Último ingreso</th><th>Estado</th><th /></tr></thead>
          <tbody>
            {d.usuarios.map((u) => {
              const bloq = u.bloqueado_hasta && new Date(u.bloqueado_hasta) > new Date();
              const yo = u.id === d.yo;
              return (
                <tr key={u.id} className={u.activo ? '' : 'retirado'}>
                  <td><strong>{u.nombre_completo}</strong><br /><span className="t2 peq">{u.usuario} · {u.correo ?? ''}</span></td>
                  <td>
                    <select aria-label={`Rol de ${u.usuario}`} value={u.rol_id} disabled={yo}
                      onChange={(e) => accion(`/usuarios/${u.id}/rol`, { rol_id: Number(e.target.value) })}>
                      {d.roles.map((r) => <option key={r.id} value={r.id}>{r.nombre}</option>)}
                    </select>
                  </td>
                  <td className="peq">{fecha(u.ultimo_ingreso)}</td>
                  <td>
                    <Insignia texto={u.activo ? 'Activo' : 'Inactivo'} clase={u.activo ? 'ok' : 'off'} />
                    {bloq && <> <Insignia texto="Bloqueado" clase="rk" /></>}
                    {u.debe_cambiar_clave && <> <Insignia texto="Clave por cambiar" clase="wn" /></>}
                  </td>
                  <td className="nw">
                    <button className="btn peq" type="button" disabled={yo} onClick={() => accion(`/usuarios/${u.id}/estado`)}>{u.activo ? 'Desactivar' : 'Activar'}</button>{' '}
                    <button className="btn peq" type="button" disabled={yo} onClick={() => accion(`/usuarios/${u.id}/reiniciar`, {}, `¿Reiniciar la contraseña de ${u.usuario}?`)}>Reiniciar clave</button>{' '}
                    {bloq && <button className="btn peq" type="button" onClick={() => accion(`/usuarios/${u.id}/desbloquear`)}>Desbloquear</button>}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <Dialogo abierto={!!nuevo} onCerrar={() => setNuevo(null)}>
        {nuevo && (
          <form className="form" onSubmit={crear}>
            <h2 className="ancho">Nuevo usuario</h2>
            {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
            <label>Usuario<input type="text" maxLength={40} required pattern="[A-Za-z0-9._-]{3,40}" autoComplete="off" value={nuevo.usuario} onChange={(e) => setNuevo({ ...nuevo, usuario: e.target.value })} /></label>
            <label>Rol<select value={nuevo.rol_id} onChange={(e) => setNuevo({ ...nuevo, rol_id: Number(e.target.value) })}>{d.roles.map((r) => <option key={r.id} value={r.id}>{r.nombre}</option>)}</select></label>
            <label className="ancho">Nombre completo<input type="text" maxLength={120} required value={nuevo.nombre_completo} onChange={(e) => setNuevo({ ...nuevo, nombre_completo: e.target.value })} /></label>
            <label className="ancho">Correo (opcional)<input type="email" maxLength={120} value={nuevo.correo} onChange={(e) => setNuevo({ ...nuevo, correo: e.target.value })} /></label>
            <p className="ancho peq t2">Se genera una contraseña temporal que verás una sola vez.</p>
            <div className="ancho fila-btn"><button className="btn pri" type="submit">Crear</button><button className="btn" type="button" onClick={() => setNuevo(null)}>Cancelar</button></div>
          </form>
        )}
      </Dialogo>
    </>
  );
}
