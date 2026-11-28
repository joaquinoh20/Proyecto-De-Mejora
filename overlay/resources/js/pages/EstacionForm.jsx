import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { Cargando, useApi, useAvisos } from '../ui.jsx';

const VACIO = {
  codigo_activo: '', nombre_host: '', ubicacion_id: 1, puesto: '', responsable: '', proveedor_id: 0, fecha_compra: '', garantia_hasta: '',
  marca: '', modelo: '', numero_serie: '', cpu_modelo: '', cpu_nucleos: '', ram_gb: '', disco_tipo: 'SSD', disco_gb: '', gpu: '', so: '', modo: 'operativa',
};

export default function EstacionForm() {
  const { id } = useParams();
  const ir = useNavigate();
  const { avisar } = useAvisos();
  const { datos: cat } = useApi('/formulario-estacion');
  const { datos: ed } = useApi(id ? `/estaciones/${id}/editar` : null, { activo: !!id });
  const [v, setV] = useState(VACIO);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!ed) return;
    const e = ed.estacion;
    const t = (x) => (x === null || x === undefined ? '' : String(x));
    setV({ ...VACIO, ...Object.fromEntries(Object.keys(VACIO).map((k) => [k, t(e[k])])),
      ubicacion_id: e.ubicacion_id, proveedor_id: e.proveedor_id || 0, disco_tipo: e.disco_tipo || 'SSD',
      fecha_compra: t(e.fecha_compra).slice(0, 10), garantia_hasta: t(e.garantia_hasta).slice(0, 10),
      modo: e.estado === 'mantenimiento' ? 'mantenimiento' : e.estado === 'baja' ? 'baja' : 'operativa' });
  }, [ed]);

  if (!cat || (id && !ed)) return <Cargando />;
  const c = (k) => ({ value: v[k] ?? '', onChange: (e) => setV({ ...v, [k]: e.target.value }) });

  async function guardar(e) {
    e.preventDefault();
    setError(null);
    const r = id ? await api(`/estaciones/${id}`, { metodo: 'PUT', datos: v }) : await api('/estaciones', { datos: v });
    if (!r.ok) { setError(r.error); return; }
    avisar('ok', id ? 'Estación actualizada.' : 'Estación creada. Genera su token para instalar el agente.');
    ir(`/estaciones/${r.id}`);
  }

  return (
    <>
      {error && <div className="flash flash-error" role="alert">{error}</div>}
      <form className="panel form" onSubmit={guardar}>
        <h2 className="ancho">Identificación</h2>
        <label>Código de activo<input type="text" required maxLength={30} placeholder="PC-026" {...c('codigo_activo')} /></label>
        <label>Nombre del equipo (host)<input type="text" required maxLength={60} {...c('nombre_host')} /></label>
        <label>Ubicación<select {...c('ubicacion_id')}>{cat.ubicaciones.map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></label>
        <label>Puesto<input type="text" maxLength={80} {...c('puesto')} /></label>
        <label>Responsable del puesto (opcional)<input type="text" maxLength={80} {...c('responsable')} /></label>
        <h2 className="ancho">Compra y proveedor</h2>
        <label>Proveedor<select {...c('proveedor_id')}><option value="0">—</option>{cat.proveedores.map((x) => <option key={x.id} value={x.id}>{x.razon_social}</option>)}</select></label>
        <label>Fecha de compra<input type="date" {...c('fecha_compra')} /></label>
        <label>Garantía hasta<input type="date" {...c('garantia_hasta')} /></label>
        <h2 className="ancho">Características</h2>
        <label>Marca<input type="text" maxLength={60} {...c('marca')} /></label>
        <label>Modelo<input type="text" maxLength={80} {...c('modelo')} /></label>
        <label>N.° de serie<input type="text" maxLength={80} {...c('numero_serie')} /></label>
        <label>Procesador<input type="text" maxLength={120} {...c('cpu_modelo')} /></label>
        <label>Núcleos<input type="number" min={1} max={512} {...c('cpu_nucleos')} /></label>
        <label>RAM (GB)<input type="number" min={1} max={8192} {...c('ram_gb')} /></label>
        <label>Tipo de disco<select {...c('disco_tipo')}><option>SSD</option><option>HDD</option><option>NVMe</option></select></label>
        <label>Disco (GB)<input type="number" min={1} {...c('disco_gb')} /></label>
        <label>Gráficos<input type="text" maxLength={80} {...c('gpu')} /></label>
        <label>Sistema operativo<input type="text" maxLength={80} {...c('so')} /></label>
        {id && (
          <label>Situación de la estación<select {...c('modo')}>
            <option value="operativa">Operativa</option><option value="mantenimiento">En mantenimiento</option><option value="baja">De baja</option></select></label>
        )}
        <div className="ancho fila-btn">
          <button className="btn pri" type="submit">Guardar</button>
          <Link className="btn" to={id ? `/estaciones/${id}` : '/estaciones'}>Cancelar</Link>
        </div>
      </form>
    </>
  );
}
