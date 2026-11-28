import { useEffect } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth.jsx';

const MENU = [
  ['/', 'Monitoreo con IA', 'dashboard.ver', true],
  ['/estaciones', 'Estaciones', 'estaciones.ver'],
  ['/historial', 'Historial', 'historial.ver'],
  ['/pantallas', 'Pantallas', 'pantallas.ver'],
  ['/proveedores', 'Proveedores', 'proveedores.ver'],
  ['/reportes', 'Reporte diario', 'reportes.ver'],
  ['/politicas', 'Políticas de seguridad', 'politicas.ver'],
  ['/usuarios', 'Usuarios', 'usuarios.gestionar'],
  ['/auditoria', 'Auditoría', 'auditoria.ver'],
];

export default function Layout({ titulo, children }) {
  const { usuario, puede, salir, cambiarTema } = useAuth();
  const ir = useNavigate();

  useEffect(() => { if (titulo) document.title = `${titulo} · AlfaVía Monitoreo`; }, [titulo]);

  return (
    <>
      <a className="saltar" href="#contenido">Saltar al contenido</a>
      <div className="app">
        <aside className="lado">
          <Link className="marca" to="/"><img src="/img/logo-alfavia.png" alt="AlfaVía" width="120" height="120" /></Link>
          <nav className="menu" aria-label="Principal">
            {MENU.filter(([, , perm]) => puede(perm)).map(([ruta, et, , exacto]) => (
              <NavLink key={ruta} to={ruta} end={!!exacto} className={({ isActive }) => (isActive ? 'activo' : undefined)}>{et}</NavLink>
            ))}
          </nav>
          <div className="lado-pie">
            <div className="tema" role="group" aria-label="Apariencia">
              {[['auto', 'Auto'], ['claro', 'Claro'], ['oscuro', 'Oscuro']].map(([t, et]) => (
                <button key={t} type="button" aria-pressed={usuario.tema === t} onClick={() => cambiarTema(t)}>{et}</button>
              ))}
            </div>
            <div className="yo"><strong>{usuario.nombre_completo}</strong><span>{usuario.rol}</span></div>
            <Link className="enlace-lado" to="/cuenta">Mi cuenta</Link>
            <button className="enlace-lado" type="button" onClick={async () => { await salir(); ir('/login'); }}>Cerrar sesión</button>
          </div>
        </aside>
        <main className="principal" id="contenido">
          {titulo && <header className="encabezado"><h1>{titulo}</h1></header>}
          {children}
        </main>
      </div>
    </>
  );
}
