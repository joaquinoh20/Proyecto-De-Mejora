import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { alertarSesion, api } from './api.js';

const Ctx = createContext(null);
export const useAuth = () => useContext(Ctx);

export function aplicarTema(t) {
  const el = document.documentElement;
  if (t === 'claro') el.dataset.theme = 'light';
  else if (t === 'oscuro') el.dataset.theme = 'dark';
  else el.removeAttribute('data-theme');
}

export function AuthProvider({ children }) {
  const [usuario, setUsuario] = useState(null);
  const [listo, setListo] = useState(false);
  const [aviso, setAviso] = useState(null);   // texto para la pantalla de ingreso («sesión expirada»…)

  const poner = useCallback((u) => { setUsuario(u); if (u) aplicarTema(u.tema); }, []);

  useEffect(() => {
    alertarSesion(
      (motivo) => {
        setUsuario(null);
        setAviso(motivo === 'expirada' ? 'Tu sesión se cerró por inactividad. Ingresa de nuevo.'
          : motivo === 'invalida' ? 'Tu sesión ya no es válida. Ingresa de nuevo.' : null);
      },
      () => setUsuario((u) => (u ? { ...u, debe_cambiar_clave: true } : u)),
    );
    api('/yo').then((r) => { if (r.ok) poner(r.usuario); setListo(true); });
  }, [poner]);

  const valor = useMemo(() => ({
    usuario, listo, aviso, setAviso,
    puede: (permiso) => !!usuario && usuario.permisos.includes(permiso),
    async ingresar(nombre, clave) {
      const r = await api('/login', { datos: { usuario: nombre, clave } });
      if (r.ok) { setAviso(null); poner(r.usuario); }
      return r;
    },
    async salir() {
      await api('/logout', { datos: {} });
      setUsuario(null);
      setAviso('Cerraste sesión.');
    },
    actualizar: poner,
    async cambiarTema(t) {
      aplicarTema(t);
      setUsuario((u) => (u ? { ...u, tema: t } : u));
      await api('/tema', { datos: { tema: t } });
    },
  }), [usuario, listo, aviso, poner]);

  return <Ctx.Provider value={valor}>{children}</Ctx.Provider>;
}
