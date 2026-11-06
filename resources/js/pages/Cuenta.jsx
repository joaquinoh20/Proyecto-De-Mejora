import { useAuth } from '../auth.jsx';

export default function Cuenta() {
  const { usuario } = useAuth();
  return (
    <section className="panel estrecho">
      <h2>Mi cuenta</h2>
      <p>Usuario: <strong>{usuario.usuario}</strong><br />Nombre: {usuario.usuario.nombre_completo}<br />Rol: {usuario.rol}</p>
      <p className="t2 peq">Tu contraseña es tu número de DNI. No se cambia desde el sistema ni vence.
        Si crees que alguien más la conoce o hubo un error al registrarla, avisa al administrador del sistema.</p>
    </section>
  );
}
