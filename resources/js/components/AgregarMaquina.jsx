import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api.js';
import { Dialogo, useApi, useAvisos } from '../ui.jsx';

const VACIO = { codigo_activo: '', nombre_host: '', ubicacion_id: '', puesto: '', proveedor_nombre: '', marca: '', modelo: '' };

/** Alta rápida de una máquina: piso, proveedor (por nombre) y datos básicos. Al guardar se abre la sincronización. */
export default function AgregarMaquina({ abierto, onCerrar, onCreada }) {
  const { avisar } = useAvisos();
  const { datos: cat } = useApi('/formulario-estacion', { activo: abierto });
  const [v, setV] = useState(VACIO);
  const [error, setError] = useState(null);
  const [guardando, setGuardando] = useState(false);
  const c = (k) => ({ value: v[k], onChange: (e) => setV({ ...v, [k]: e.target.value }) });

  async function guardar(e) {
    e.preventDefault();
    setError(null);
    setGuardando(true);
    const r = await api('/estaciones', { datos: { ...v, ubicacion_id: Number(v.ubicacion_id || cat?.ubicaciones[0]?.id || 0) } });
    setGuardando(false);
    if (!r.ok) { setError(r.error); return; }
    avisar('ok', 'Máquina agregada. Ahora sincroniza el equipo.');
    setV(VACIO);
    onCreada(r.id);
  }

  return (
    <Dialogo abierto={abierto} onCerrar={onCerrar}>
      {abierto && (
        <form className="form" onSubmit={guardar}>
          <h2 className="ancho">Agregar máquina</h2>
          {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
          {!cat ? <p className="ancho vacio">Cargando…</p> : (
            <>
              <label>Código de activo<input type="text" required maxLength={30} placeholder="PC-026" {...c('codigo_activo')} /></label>
              <label>Nombre del equipo (host)<input type="text" required maxLength={60} {...c('nombre_host')} /></label>
              <label>Piso<select {...c('ubicacion_id')}>{cat.ubicaciones.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></label>
              <label>Puesto<input type="text" maxLength={80} {...c('puesto')} /></label>
              <label className="ancho">Nombre del proveedor
                <input type="text" list="lista-proveedores" maxLength={150} placeholder="Elige uno o escribe uno nuevo" {...c('proveedor_nombre')} />
                <datalist id="lista-proveedores">{cat.proveedores.map((x) => <option key={x.id} value={x.razon_social} />)}</datalist>
              </label>
              <label>Marca<input type="text" maxLength={60} {...c('marca')} /></label>
              <label>Modelo<input type="text" maxLength={80} {...c('modelo')} /></label>
              <p className="ancho t2 peq">Las demás características (procesador, RAM, disco…) las completa el agente al conectarse, o puedes editarlas después. <Link to="/estaciones/nueva">Formulario completo</Link></p>
              <div className="ancho fila-btn"><button className="btn pri" type="submit" disabled={guardando}>{guardando ? 'Guardando…' : 'Guardar y sincronizar'}</button><button className="btn" type="button" onClick={onCerrar}>Cancelar</button></div>
            </>
          )}
        </form>
      )}
    </Dialogo>
  );
}
