import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api.js';
import { useAuth } from '../auth.jsx';
import { useAvisos } from '../ui.jsx';

export default function Cuenta() {
  const { usuario, actualizar } = useAuth();
  const { avisar } = useAvisos();
  const ir = useNavigate();
  const [f, setF] = useState({ actual: '', nueva: '', repite: '' });
  const [error, setError] = useState(null);
  const req = usuario.clave_requisitos;
  const cambiar = (k) => (e) => setF({ ...f, [k]: e.target.value });

  async function guardar(e) {
    e.preventDefault();
    setError(null);
    const r = await api('/cuenta/clave', { datos: f });
    if (!r.ok) { setError(r.error); return; }
    actualizar(r.usuario);
    avisar('ok', 'Contraseña actualizada.');
    ir('/', { replace: true });
  }

  return (
    <>
      {usuario.debe_cambiar_clave && <div className="flash flash-error" role="alert">Debes cambiar tu contraseña para continuar (primer ingreso, restablecimiento o vencimiento).</div>}
      {error && <div className="flash flash-error" role="alert">{error}</div>}
      <section className="panel estrecho">
        <h2>Cambiar contraseña</h2>
        <p className="t2">Usuario: <strong>{usuario.usuario}</strong> · Rol: {usuario.rol}<br />
          Requisitos: mínimo {req.minimo} caracteres{req.complejidad ? ', con mayúscula, minúscula, número y símbolo' : ''}. Vence cada {req.vigencia_dias} días.</p>
        <form className="form" onSubmit={guardar} autoComplete="off">
          <label className="ancho">Contraseña actual<input type="password" required autoComplete="current-password" maxLength={128} value={f.actual} onChange={cambiar('actual')} /></label>
          <label className="ancho">Nueva contraseña<input type="password" required autoComplete="new-password" maxLength={128} value={f.nueva} onChange={cambiar('nueva')} /></label>
          <label className="ancho">Repite la nueva contraseña<input type="password" required autoComplete="new-password" maxLength={128} value={f.repite} onChange={cambiar('repite')} /></label>
          <div className="ancho"><button className="btn pri" type="submit">Guardar contraseña</button></div>
        </form>
      </section>
    </>
  );
}
