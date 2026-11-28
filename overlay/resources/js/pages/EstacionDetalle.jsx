import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Cargando, Dialogo, Grafico, Insignia, Medidor, etiquetaEstado, etiquetaNivel, fecha, soloFecha, useApi, useAvisos } from '../ui.jsx';

const TIPOS = { procesador: 'Procesador', ram: 'Memoria RAM', disco: 'Disco', diadema: 'Diadema', mouse: 'Mouse', teclado: 'Teclado', monitor: 'Monitor', otro: 'Otro' };
const ESTADO_COMP = { optimo: ['Óptimo', 'ok'], desgaste: ['Desgaste', 'wn'], critico: ['Crítico', 'rk'], retirado: ['Retirado', 'off'] };

function Telemetria({ id }) {
  const { datos } = useApi(`/estaciones/${id}/telemetria?horas=24`);
  const defs = [['cpu', 'CPU', '%', 0, 100], ['ram', 'RAM', '%', 0, 100], ['temp', 'Temperatura', ' °C', 30, 90], ['salud', 'Salud del disco', '%', 0, 100]];
  return (
    <section className="panel" aria-labelledby="t-tel">
      <h2 id="t-tel">Telemetría · últimas 24 horas</h2>
      {!datos ? <Cargando /> : (
        <div className="graficos">
          {defs.map(([k, titulo, unidad, min, max]) => (
            <Grafico key={k} titulo={titulo} unidad={unidad} min={min} max={max} serie={datos.puntos.map((p) => ({ t: p.t, v: p[k] }))} />
          ))}
        </div>
      )}
    </section>
  );
}

function DiagnosticoIA({ id, pred }) {
  const [r, setR] = useState(null);
  const [cargando, setCargando] = useState(false);
  async function pedir() {
    setCargando(true);
    const x = await api(`/estaciones/${id}/ia`);
    setR(x);
    setCargando(false);
  }
  if (!pred) return <p className="vacio">Todavía no hay una predicción para esta estación (se necesita telemetría reciente).</p>;
  return (
    <>
      <p className="sin-margen"><strong>{pred.causa_probable ?? 'Sin causa destacada'}</strong>
        <span className="t2"> · comportamiento inusual: {Math.round(pred.anomalia * 100)}% · modelo {pred.modelo_version} · {pred.hace}</span></p>
      <ul className="mov">
        {pred.factores.map((f, i) => (
          <li key={i}><span>{f.etiqueta}</span><span className="num"><strong>{f.valor}</strong> <span className="t2">(peso {Math.round(f.aporte * 100)}%)</span></span></li>
        ))}
        {!pred.factores.length && <li className="t2">Ninguna variable se aleja de lo habitual.</li>}
      </ul>
      {(cargando || r) && <p className={`ia-texto${cargando ? ' cargando' : ''}`}>{cargando ? 'Consultando' : r.ok ? r.texto : (r.error || 'No se pudo generar la explicación.')}</p>}
      {r?.ok && (
        <div className="ia-pie">
          <span className="insignia ia">{r.origen === 'gemini' ? `Gemini (${r.modelo})` : 'Diagnóstico automático (sin Gemini)'}</span>
          {r.aviso && <span>{r.aviso}</span>}
        </div>
      )}
      <button className="btn mt" type="button" disabled={cargando} onClick={pedir}>{r ? 'Volver a consultar' : 'Explicar con IA'}</button>
    </>
  );
}

function DialogoAgregar({ abierto, onCerrar, id, proveedores, alGuardar }) {
  const [f, setF] = useState({ tipo: 'procesador', proveedor: 0, marca: '', modelo: '', serie: '', fecha: new Date().toISOString().slice(0, 10) });
  const [error, setError] = useState(null);
  const c = (k) => ({ value: f[k], onChange: (e) => setF({ ...f, [k]: e.target.value }) });
  async function enviar(e) {
    e.preventDefault();
    const r = await api(`/estaciones/${id}/componentes`, { datos: { ...f, accion: 'agregar' } });
    if (!r.ok) { setError(r.error); return; }
    setError(null);
    alGuardar(r.mensaje);
  }
  return (
    <Dialogo abierto={abierto} onCerrar={onCerrar}>
      <form className="form" onSubmit={enviar}>
        <h2 className="ancho">Agregar componente</h2>
        {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
        <label>Tipo<select required {...c('tipo')}>{Object.entries(TIPOS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
        <label>Proveedor<select {...c('proveedor')}><option value="0">—</option>{proveedores.map((p) => <option key={p.id} value={p.id}>{p.razon_social}</option>)}</select></label>
        <label>Marca<input type="text" maxLength={60} {...c('marca')} /></label>
        <label>Modelo<input type="text" maxLength={80} required {...c('modelo')} /></label>
        <label>N.° de serie<input type="text" maxLength={80} {...c('serie')} /></label>
        <label>Fecha de instalación<input type="date" {...c('fecha')} /></label>
        <div className="ancho fila-btn"><button className="btn pri" type="submit">Guardar</button><button className="btn" type="button" onClick={onCerrar}>Cancelar</button></div>
      </form>
    </Dialogo>
  );
}

function DialogoCambiar({ comp, onCerrar, id, proveedores, alGuardar }) {
  const [f, setF] = useState({ accion: 'retirar', motivo: '', marca: '', modelo: '', serie: '', proveedor: 0 });
  const [error, setError] = useState(null);
  const c = (k) => ({ value: f[k], onChange: (e) => setF({ ...f, [k]: e.target.value }) });
  async function enviar(e) {
    e.preventDefault();
    const r = await api(`/estaciones/${id}/componentes`, { datos: { ...f, componente: comp.id } });
    if (!r.ok) { setError(r.error); return; }
    setError(null);
    alGuardar(r.mensaje);
  }
  return (
    <Dialogo abierto={!!comp} onCerrar={onCerrar}>
      <form className="form" onSubmit={enviar}>
        <h2 className="ancho">Retirar o intercambiar</h2>
        <p className="ancho t2 sin-margen">{comp && `${TIPOS[comp.tipo] ?? comp.tipo} · ${`${comp.marca ?? ''} ${comp.modelo ?? ''}`.trim()}`}</p>
        {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
        <label className="ancho">¿Qué se hizo?<select required {...c('accion')}>
          <option value="retirar">Se retiró (no se reemplaza)</option><option value="intercambiar">Se intercambió por otro</option></select></label>
        <label className="ancho">Motivo<input type="text" maxLength={200} required placeholder="Ej.: micrófono sin señal" {...c('motivo')} /></label>
        <p className="ancho t2 peq sin-margen">Solo si se intercambió, completa el componente nuevo:</p>
        <label>Marca<input type="text" maxLength={60} {...c('marca')} /></label>
        <label>Modelo<input type="text" maxLength={80} {...c('modelo')} /></label>
        <label>N.° de serie<input type="text" maxLength={80} {...c('serie')} /></label>
        <label>Proveedor<select {...c('proveedor')}><option value="0">—</option>{proveedores.map((p) => <option key={p.id} value={p.id}>{p.razon_social}</option>)}</select></label>
        <div className="ancho fila-btn"><button className="btn pri" type="submit">Registrar</button><button className="btn" type="button" onClick={onCerrar}>Cancelar</button></div>
      </form>
    </Dialogo>
  );
}

function DialogoMant({ abierto, onCerrar, id, alGuardar }) {
  const [f, setF] = useState({ tipo: 'preventivo', es_falla: false, tipo_falla: 'disco', fecha_falla: new Date().toISOString().slice(0, 10), descripcion: '' });
  const [error, setError] = useState(null);
  const c = (k) => ({ value: f[k], onChange: (e) => setF({ ...f, [k]: e.target.value }) });
  async function enviar(e) {
    e.preventDefault();
    const r = await api(`/estaciones/${id}/mantenimientos`, { datos: f });
    if (!r.ok) { setError(r.error); return; }
    setError(null);
    alGuardar(r.mensaje);
  }
  return (
    <Dialogo abierto={abierto} onCerrar={onCerrar}>
      <form className="form" onSubmit={enviar}>
        <h2 className="ancho">Registrar atención</h2>
        {error && <div className="ancho flash flash-error" role="alert">{error}</div>}
        <label>Tipo<select {...c('tipo')}><option value="preventivo">Preventivo</option><option value="correctivo">Correctivo</option></select></label>
        <label className="casilla ancho"><input type="checkbox" checked={f.es_falla} onChange={(e) => setF({ ...f, es_falla: e.target.checked })} /> Fue una falla real del equipo (sirve para entrenar al modelo de ML)</label>
        <label>Tipo de falla<select {...c('tipo_falla')}><option value="disco">Disco</option><option value="sobrecalentamiento">Sobrecalentamiento</option><option value="ram">Memoria RAM</option><option value="otro">Otra</option></select></label>
        <label>Fecha de la falla<input type="date" {...c('fecha_falla')} /></label>
        <label className="ancho">Descripción de lo realizado<textarea maxLength={500} required {...c('descripcion')} /></label>
        <div className="ancho fila-btn"><button className="btn pri" type="submit">Guardar</button><button className="btn" type="button" onClick={onCerrar}>Cancelar</button></div>
      </form>
    </Dialogo>
  );
}

export default function EstacionDetalle() {
  const { id } = useParams();
  const { puede } = useAuth();
  const { avisar } = useAvisos();
  const { datos: d, error, recargar } = useApi(`/estaciones/${id}`);
  const [dlg, setDlg] = useState(null);          // 'agregar' | 'mant'
  const [cambiar, setCambiar] = useState(null);  // componente a retirar o intercambiar
  const [token, setToken] = useState(null);

  useEffect(() => { document.title = d ? `${d.estacion.codigo_activo} · AlfaVía Monitoreo` : 'Estación · AlfaVía Monitoreo'; }, [d]);
  useEffect(() => { setToken(null); }, [id]);

  if (error) return <div className="panel"><p>{error}</p><Link className="btn" to="/estaciones">Volver</Link></div>;
  if (!d) return <Cargando />;

  const { estacion: e, prediccion: pred, ultima: ult } = d;
  const [et, ec] = etiquetaEstado(e.estado);
  const guardado = (msg) => { setDlg(null); setCambiar(null); avisar('ok', msg); recargar(true); };

  async function generarToken() {
    if (!window.confirm('Se generará un token nuevo y el agente actual dejará de funcionar hasta que lo actualices. ¿Continuar?')) return;
    const r = await api(`/estaciones/${id}/token`, { datos: {} });
    if (r.ok) { setToken(r); recargar(true); } else avisar('error', r.error);
  }

  return (
    <>
      <header className="encabezado"><h1>{e.codigo_activo} · {e.nombre_host}</h1></header>
      <div className="fila-btn">
        <Insignia texto={et} clase={ec} /> <span className="t2">{e.ubicacion} · {e.puesto ?? ''} · último latido {e.hace_latido}</span>
        <span className="encabezado-der" />
        <Link className="btn peq" to="/estaciones">← Estaciones</Link>
        <Link className="btn peq" to={`/historial?estacion=${id}`}>Historial de movimientos</Link>
        {puede('estaciones.editar') && <Link className="btn peq" to={`/estaciones/${id}/editar`}>Editar</Link>}
        {puede('pantallas.ver') && e.estado === 'en_linea' && <Link className="btn peq pri" to={`/pantallas?estacion=${id}`}>Ver pantalla</Link>}
      </div>

      {token && (
        <section className="panel estrecho mt">
          <h2>Copia este token ahora</h2>
          <p>Solo se muestra esta vez. En la base de datos queda guardado únicamente su hash.</p>
          <p><code>{token.token}</code></p>
          <p className="t2 peq">En la PC <strong>{token.host}</strong>, copia <code>agent/config.example.json</code> como <code>config.json</code>, pega este token en <code>token</code> y escribe la dirección del servidor en <code>servidor</code>.</p>
          <button className="btn peq" type="button" onClick={() => setToken(null)}>Ya lo copié</button>
        </section>
      )}

      <div className="cols mt">
        <div>
          <section className="panel" aria-labelledby="t-ia">
            <div className="panel-cab"><h2 id="t-ia">Diagnóstico de la IA</h2>
              {pred && (() => { const [nt, nc] = etiquetaNivel(pred.nivel); return <Insignia texto={`${Math.round(pred.prob_falla * 100)}% de falla en 7 días · ${nt}`} clase={nc} />; })()}
            </div>
            <DiagnosticoIA key={id} id={id} pred={pred} />
          </section>

          <Telemetria id={id} />

          <section className="panel" aria-labelledby="t-comp">
            <div className="panel-cab"><h2 id="t-comp">Componentes y periféricos</h2>
              {puede('componentes.editar') && <button className="btn peq" type="button" onClick={() => setDlg('agregar')}>Agregar componente</button>}</div>
            <div className="tw"><table>
              <thead><tr><th>Tipo</th><th>Marca y modelo</th><th>N.° de serie</th><th>Proveedor</th><th>Instalado</th><th>Estado</th>{puede('componentes.editar') && <th />}</tr></thead>
              <tbody>
                {d.componentes.map((c) => {
                  const [ce, cc] = ESTADO_COMP[c.estado] || [c.estado, 'off'];
                  return (
                    <tr key={c.id} className={c.estado === 'retirado' ? 'retirado' : ''}>
                      <td>{TIPOS[c.tipo] ?? c.tipo}</td>
                      <td>{`${c.marca ?? ''} ${c.modelo ?? ''}`.trim()}</td>
                      <td className="peq">{c.numero_serie ?? '—'}</td>
                      <td>{c.proveedor ?? '—'}</td>
                      <td className="nw">{soloFecha(c.fecha_instalacion)}</td>
                      <td><Insignia texto={ce} clase={cc} /></td>
                      {puede('componentes.editar') && <td className="nw">{c.estado !== 'retirado' && <button className="btn peq" type="button" onClick={() => setCambiar(c)}>Retirar / intercambiar</button>}</td>}
                    </tr>
                  );
                })}
                {!d.componentes.length && <tr><td colSpan={7} className="vacio">Sin componentes registrados.</td></tr>}
              </tbody>
            </table></div>
          </section>

          <section className="panel" aria-labelledby="t-mov">
            <h2 id="t-mov">Últimos movimientos</h2>
            <ul className="mov">
              {d.movimientos.map((m, i) => <li key={i}><span><strong>{m.titulo}</strong> {m.detalle}</span><time>{fecha(m.fecha)}</time></li>)}
              {!d.movimientos.length && <li className="t2">Sin movimientos registrados.</li>}
            </ul>
          </section>

          <section className="panel" aria-labelledby="t-mant">
            <div className="panel-cab"><h2 id="t-mant">Mantenimiento e incidencias</h2>
              {puede('mantenimiento.registrar') && <button className="btn peq" type="button" onClick={() => setDlg('mant')}>Registrar atención</button>}</div>
            <ul className="mov">
              {d.mantenimientos.map((m) => (
                <li key={m.id}><span><Insignia texto={m.tipo} clase={m.tipo === 'correctivo' ? 'wn' : 'ok'} /> {m.descripcion} <span className="t2">· {m.tecnico ?? '—'}</span></span><time>{fecha(m.fecha)}</time></li>
              ))}
              {!d.mantenimientos.length && <li className="t2">Sin atenciones registradas.</li>}
            </ul>
          </section>
        </div>

        <div>
          <section className="panel" aria-labelledby="t-ahora">
            <h2 id="t-ahora">Ahora</h2>
            <Medidor nombre="CPU" valor={ult?.cpu_pct} unidad="%" aviso={70} critico={90} />
            <Medidor nombre="RAM" valor={ult?.ram_pct} unidad="%" aviso={75} critico={90} />
            <Medidor nombre="Temperatura" valor={ult?.temp_c} unidad=" °C" aviso={65} critico={80} />
            <Medidor nombre="Disco usado" valor={ult?.disco_usado_pct} unidad="%" aviso={80} critico={92} />
            <Medidor nombre="Salud del disco" valor={ult?.disco_salud_pct} unidad="%" aviso={80} critico={60} invertido />
            <p className="t2 peq">Medición: {ult ? ult.hace : 'sin datos'}</p>
          </section>

          <section className="panel" aria-labelledby="t-car">
            <h2 id="t-car">Características de la computadora</h2>
            <dl className="carac">
              <div><dt>Marca y modelo</dt><dd>{`${e.marca ?? ''} ${e.modelo ?? ''}`.trim() || '—'}</dd></div>
              <div><dt>N.° de serie</dt><dd>{e.numero_serie ?? '—'}</dd></div>
              <div><dt>Procesador</dt><dd>{e.cpu_modelo ?? '—'}{e.cpu_nucleos ? ` · ${e.cpu_nucleos} núcleos` : ''}</dd></div>
              <div><dt>Memoria RAM</dt><dd>{e.ram_gb ?? '—'} GB</dd></div>
              <div><dt>Almacenamiento</dt><dd>{`${e.disco_tipo ?? ''} ${e.disco_gb ?? '—'}`.trim()} GB</dd></div>
              <div><dt>Gráficos</dt><dd>{e.gpu ?? '—'}</dd></div>
              <div><dt>Sistema operativo</dt><dd>{e.so ?? '—'}</dd></div>
              <div><dt>Nombre del equipo</dt><dd>{e.nombre_host}</dd></div>
              <div><dt>Dirección IP</dt><dd>{e.ip ?? '—'}</dd></div>
              <div><dt>Dirección MAC</dt><dd>{e.mac ?? '—'}</dd></div>
              <div><dt>Fecha de compra</dt><dd>{soloFecha(e.fecha_compra)}</dd></div>
              <div><dt>Garantía hasta</dt><dd>{soloFecha(e.garantia_hasta)} {e.garantia_dias === null ? '' : e.garantia_dias >= 0 ? <span className="t2">({e.garantia_dias} días)</span> : <Insignia texto="Vencida" clase="wn" />}</dd></div>
              <div><dt>Agente instalado</dt><dd>{e.agente_version ?? 'sin agente'}</dd></div>
            </dl>
          </section>

          <section className="panel" aria-labelledby="t-prov">
            <h2 id="t-prov">Proveedor del equipo</h2>
            {e.proveedor ? (
              <dl className="carac">
                <div><dt>Razón social</dt><dd>{e.proveedor}</dd></div>
                <div><dt>RUC</dt><dd>{e.proveedor_ruc ?? '—'}</dd></div>
                <div><dt>Contacto</dt><dd>{e.proveedor_contacto ?? '—'}</dd></div>
                <div><dt>Teléfono</dt><dd>{e.proveedor_tel ?? '—'}</dd></div>
                <div><dt>Correo</dt><dd>{e.proveedor_correo ?? '—'}</dd></div>
              </dl>
            ) : <p className="vacio">Sin proveedor asignado.</p>}
          </section>

          <section className="panel" aria-labelledby="t-dis">
            <h2 id="t-dis">Dispositivos detectados</h2>
            <ul className="mov">
              {d.dispositivos.map((x) => (
                <li key={x.id}><span>{x.nombre} <span className="t2">({x.categoria})</span></span>
                  {x.presente ? <Insignia texto="Conectado" clase="ok" /> : <Insignia texto={`Desconectado desde ${fecha(x.visto_ultima)}`} clase="rk" />}</li>
              ))}
              {!d.dispositivos.length && <li className="t2">El agente todavía no reportó dispositivos.</li>}
            </ul>
          </section>

          {puede('estaciones.editar') && (
            <section className="panel" aria-labelledby="t-tok">
              <h2 id="t-tok">Agente de la estación</h2>
              <p className="t2 peq">Token creado: {e.agente_token_creado ? fecha(e.agente_token_creado) : 'nunca'} {e.token_rotar && <Insignia texto="Conviene rotarlo" clase="wn" />}</p>
              <button className="btn peq" type="button" onClick={generarToken}>{e.tiene_token ? 'Generar token nuevo' : 'Generar token'}</button>
            </section>
          )}
        </div>
      </div>

      <DialogoAgregar abierto={dlg === 'agregar'} onCerrar={() => setDlg(null)} id={id} proveedores={d.proveedores} alGuardar={guardado} />
      <DialogoCambiar key={cambiar?.id ?? 'x'} comp={cambiar} onCerrar={() => setCambiar(null)} id={id} proveedores={d.proveedores} alGuardar={guardado} />
      <DialogoMant abierto={dlg === 'mant'} onCerrar={() => setDlg(null)} id={id} alGuardar={guardado} />
    </>
  );
}
