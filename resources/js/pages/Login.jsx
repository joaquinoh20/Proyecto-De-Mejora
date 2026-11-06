import { useEffect, useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth.jsx';

export default function Login() {
  const { usuario, listo, aviso, ingresar } = useAuth();
  const [nombre, setNombre] = useState('');
  const [clave, setClave] = useState('');
  const [error, setError] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const ir = useNavigate();
  const ubicacion = useLocation();

  useEffect(() => {
    document.title = 'Ingresar · AlfaVía Monitoreo';
    document.body.classList.add('publico');
    return () => document.body.classList.remove('publico');
  }, []);

  if (listo && usuario) return <Navigate to="/" replace />;

  async function enviar(e) {
    e.preventDefault();
    setEnviando(true);
    setError(null);
    const r = await ingresar(nombre, clave);
    setEnviando(false);
    if (!r.ok) { setError(r.error); setClave(''); return; }
    const desde = ubicacion.state?.desde;
    ir(desde && desde !== '/login' ? desde : '/', { replace: true });
  }

  return (
    <form className="login" onSubmit={enviar} autoComplete="on">
      <div className="logo"><img src="/img/logo-alfavia.png" alt="AlfaVía" /></div>
      <h1>Monitoreo de equipos</h1>
      <p className="t2">Ingresa con tus credenciales.</p>
      {aviso && <p className="flash" role="status">{aviso}</p>}
      {error && <p className="flash flash-error" role="alert">{error}</p>}
      <label>Usuario
        <input type="text" name="usuario" autoComplete="username" required autoFocus maxLength={60} placeholder="nombre.apellido#alfavia" value={nombre} onChange={(e) => setNombre(e.target.value)} />
      </label>
      <label>Contraseña (tu número de DNI)
        <input type="password" name="clave" autoComplete="current-password" required inputMode="numeric" maxLength={20} value={clave} onChange={(e) => setClave(e.target.value)} />
      </label>
      <button className="btn pri" type="submit" disabled={enviando}>{enviando ? 'Ingresando…' : 'Ingresar'}</button>
      <p className="t2 peq">Este sistema registra los ingresos y las acciones de cada usuario.</p>
    </form>
  );
}
