import { useState } from 'react';
import { qs } from '../api.js';
import { Cargando, Paginador, fecha, useApi } from '../ui.jsx';

export default function Auditoria() {
  const [f, setF] = useState({ q: '', accion: '', desde: '', hasta: '' });
  const [aplicado, setAplicado] = useState({ ...f, p: 1 });
  const { datos: d } = useApi(`/auditoria${qs({ ...aplicado, p: aplicado.p > 1 ? aplicado.p : '' })}`);
  const c = (k) => ({ value: f[k], onChange: (e) => setF({ ...f, [k]: e.target.value }) });

  return (
    <>
      <form className="panel form mt" onSubmit={(e) => { e.preventDefault(); setAplicado({ ...f, p: 1 }); }}>
        <label>Buscar<input type="search" maxLength={60} {...c('q')} /></label>
        <label>Acción<select {...c('accion')}><option value="">Todas</option>{d?.acciones.map((a) => <option key={a} value={a}>{a}</option>)}</select></label>
        <label>Desde<input type="date" {...c('desde')} /></label>
        <label>Hasta<input type="date" {...c('hasta')} /></label>
        <div className="fila-btn">
          <button className="btn pri" type="submit">Filtrar</button>
          <button className="btn" type="button" onClick={() => { const v = { q: '', accion: '', desde: '', hasta: '' }; setF(v); setAplicado({ ...v, p: 1 }); }}>Limpiar</button>
        </div>
      </form>
      {!d ? <Cargando /> : (
        <div className="panel tw mt">
          <table>
            <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Objeto</th><th>Detalle</th><th>IP</th></tr></thead>
            <tbody>
              {d.registros.map((r) => (
                <tr key={r.id}>
                  <td className="nw">{fecha(r.creado_en, true)}</td><td>{r.usuario_nombre ?? '—'}</td><td><code>{r.accion}</code></td>
                  <td className="peq">{`${r.entidad ?? ''} ${r.entidad_id ?? ''}`.trim()}</td>
                  <td className="peq">{['{}', '[]'].includes(r.detalle) ? '' : r.detalle}</td><td className="peq nw">{r.ip ?? ''}</td>
                </tr>
              ))}
              {!d.registros.length && <tr><td colSpan={6} className="t2">Sin registros con esos filtros.</td></tr>}
            </tbody>
          </table>
          <Paginador pagina={d.pagina} paginas={d.paginas} total={d.total} onCambiar={(p) => setAplicado({ ...aplicado, p })} />
        </div>
      )}
    </>
  );
}
