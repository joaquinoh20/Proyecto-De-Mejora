// Cliente de la API del panel (Laravel). Usa la sesión por cookie y envía el token CSRF en X-XSRF-TOKEN.
const BASE = '/app-api';

let alSinSesion = () => {};
let alCambioClave = () => {};
export const alertarSesion = (sinSesion, cambioClave) => { alSinSesion = sinSesion; alCambioClave = cambioClave; };

function xsrf() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}

/**
 * api('/estaciones')                                  → GET
 * api('/tema', { datos: { tema: 'oscuro' } })         → POST con JSON
 * api('/estaciones/3', { metodo: 'PUT', datos })      → PUT
 * actividad: false  → consulta automática: no alarga la sesión (cierre por inactividad)
 * Devuelve siempre un objeto { ok, ... } (nunca lanza por errores HTTP).
 */
export async function api(ruta, { metodo, datos = null, actividad = true } = {}) {
  const m = metodo || (datos !== null ? 'POST' : 'GET');
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const op = { method: m, credentials: 'same-origin', headers };
  if (datos !== null) {
    headers['Content-Type'] = 'application/json';
    op.body = JSON.stringify(datos);
  }
  if (m !== 'GET') headers['X-XSRF-TOKEN'] = xsrf();
  if (!actividad) headers['X-Actividad'] = '0';

  let r;
  try {
    r = await fetch(BASE + ruta, op);
  } catch {
    return { ok: false, error: 'No se pudo conectar con el servidor.', _estado: 0 };
  }
  const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta no válida del servidor.' }));
  j._estado = r.status;
  if (!r.ok && !j.error) j.error = r.status === 419 ? 'La página caducó. Recárgala e intenta de nuevo.' : `Error ${r.status}`;
  if (r.status === 401 && j.motivo && j.motivo !== 'sin_sesion') alSinSesion(j.motivo);
  else if (r.status === 401) alSinSesion('sin_sesion');
  if (r.status === 403 && j.motivo === 'cambio_clave') alCambioClave();
  return j;
}

export const urlApi = (ruta) => BASE + ruta;
export const qs = (obj) => {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(obj)) if (v !== '' && v !== null && v !== undefined && v !== 0 && v !== '0') p.set(k, v);
  const s = p.toString();
  return s ? `?${s}` : '';
};
