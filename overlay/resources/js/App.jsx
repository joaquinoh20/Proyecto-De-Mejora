import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from './auth.jsx';
import Layout from './components/Layout.jsx';
import { Cargando } from './ui.jsx';
import Auditoria from './pages/Auditoria.jsx';
import Cuenta from './pages/Cuenta.jsx';
import Dashboard from './pages/Dashboard.jsx';
import EstacionDetalle from './pages/EstacionDetalle.jsx';
import EstacionForm from './pages/EstacionForm.jsx';
import Estaciones from './pages/Estaciones.jsx';
import Historial from './pages/Historial.jsx';
import Login from './pages/Login.jsx';
import Pantallas from './pages/Pantallas.jsx';
import Politicas from './pages/Politicas.jsx';
import Proveedores from './pages/Proveedores.jsx';
import Reportes from './pages/Reportes.jsx';
import Usuarios from './pages/Usuarios.jsx';

/** Páginas que requieren sesión. Si hay que cambiar la contraseña, solo se permite «Mi cuenta». */
function Protegida({ permiso, titulo, children }) {
  const { usuario, listo, puede } = useAuth();
  const ubicacion = useLocation();
  if (!listo) return <Cargando />;
  if (!usuario) return <Navigate to="/login" replace state={{ desde: ubicacion.pathname + ubicacion.search }} />;
  if (usuario.debe_cambiar_clave && ubicacion.pathname !== '/cuenta') return <Navigate to="/cuenta" replace />;
  return (
    <Layout titulo={titulo}>
      {permiso && !puede(permiso)
        ? <div className="panel"><p>Tu rol (<strong>{usuario.rol}</strong>) no tiene permiso para esta sección.</p></div>
        : children}
    </Layout>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/" element={<Protegida permiso="dashboard.ver" titulo="Monitoreo con IA"><Dashboard /></Protegida>} />
      <Route path="/estaciones" element={<Protegida permiso="estaciones.ver" titulo="Estaciones"><Estaciones /></Protegida>} />
      <Route path="/estaciones/nueva" element={<Protegida permiso="estaciones.editar" titulo="Nueva estación"><EstacionForm /></Protegida>} />
      <Route path="/estaciones/:id" element={<Protegida permiso="estaciones.ver"><EstacionDetalle /></Protegida>} />
      <Route path="/estaciones/:id/editar" element={<Protegida permiso="estaciones.editar" titulo="Editar estación"><EstacionForm /></Protegida>} />
      <Route path="/historial" element={<Protegida permiso="historial.ver" titulo="Historial de movimientos"><Historial /></Protegida>} />
      <Route path="/pantallas" element={<Protegida permiso="pantallas.ver" titulo="Pantallas de las estaciones"><Pantallas /></Protegida>} />
      <Route path="/proveedores" element={<Protegida permiso="proveedores.ver" titulo="Proveedores"><Proveedores /></Protegida>} />
      <Route path="/reportes" element={<Protegida permiso="reportes.ver" titulo="Reporte diario"><Reportes /></Protegida>} />
      <Route path="/politicas" element={<Protegida permiso="politicas.ver" titulo="Políticas de seguridad"><Politicas /></Protegida>} />
      <Route path="/usuarios" element={<Protegida permiso="usuarios.gestionar" titulo="Usuarios"><Usuarios /></Protegida>} />
      <Route path="/auditoria" element={<Protegida permiso="auditoria.ver" titulo="Auditoría"><Auditoria /></Protegida>} />
      <Route path="/cuenta" element={<Protegida titulo="Mi cuenta"><Cuenta /></Protegida>} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
