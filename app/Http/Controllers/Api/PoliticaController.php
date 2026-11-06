<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Db;
use App\Servicios\Gemini;
use App\Servicios\Politicas;
use Illuminate\Http\Request;

/** Políticas de seguridad editables y estado de cumplimiento. */
final class PoliticaController extends BaseApi
{
    public function index(Request $r)
    {
        $token = Politicas::get('token_agente_vigencia_dias');
        $checks = [
            ['nombre' => 'Cuentas bloqueadas ahora', 'n' => (int) Db::valor('SELECT count(*) FROM usuarios WHERE bloqueado_hasta > now()'), 'ayuda' => 'Un administrador puede desbloquearlas en Usuarios.'],
            ['nombre' => 'Estaciones sin token de agente', 'n' => (int) Db::valor("SELECT count(*) FROM estaciones WHERE activo AND estado <> 'baja' AND agente_token_hash IS NULL"), 'ayuda' => 'No se pueden monitorear hasta generar su token.'],
            ['nombre' => "Tokens de agente que conviene rotar (> $token días)", 'n' => (int) Db::valor('SELECT count(*) FROM estaciones WHERE activo AND agente_token_creado < now() - make_interval(days => :d)', ['d' => $token]), 'ayuda' => 'Genera un token nuevo desde la ficha de la estación.'],
            ['nombre' => 'Estaciones sin reportar hace más de 1 día', 'n' => (int) Db::valor("SELECT count(*) FROM estaciones WHERE activo AND estado <> 'baja' AND (ultimo_latido IS NULL OR ultimo_latido < now() - interval '1 day')"), 'ayuda' => 'Revisar el agente o dar de baja el equipo.'],
            ['nombre' => 'Vistas de pantalla activas ahora', 'n' => (int) Db::valor('SELECT count(*) FROM sesiones_pantalla WHERE fin IS NULL AND expira_en > now()'), 'ayuda' => 'Informativo: se pueden ver en Pantallas.'],
        ];
        $grupos = [];
        foreach (Politicas::todas() as $clave => $p) {
            if (str_starts_with($clave, 'clave_')) { continue; }   // la contraseña es el DNI: no hay reglas de contraseña
            $grupos[$p['categoria']][] = [
                'clave' => $clave, 'valor' => $p['valor'], 'tipo' => $p['tipo'], 'descripcion' => $p['descripcion'],
                'min' => $p['min_valor'], 'max' => $p['max_valor'],
            ];
        }
        return $this->ok([
            'grupos' => $grupos, 'cumplimiento' => $checks,
            'gemini' => ['modelo' => Gemini::modelo(), 'permitida' => Gemini::permitida(), 'configurada' => Gemini::configurada()],
            'https' => $r->isSecure(),
            'puede_editar' => $this->puede($r, 'politicas.editar'),
        ]);
    }

    public function guardar(Request $r)
    {
        $u = $this->usuario($r);
        $enviado = (array) $r->input('p', []);
        $errores = [];
        foreach (Politicas::todas() as $clave => $p) {
            $valor = $p['tipo'] === 'bool'
                ? (filter_var($enviado[$clave] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
                : trim((string) ($enviado[$clave] ?? $p['valor']));
            if (($e = Politicas::guardar($clave, $valor, (int) $u['id'])) !== null) {
                $errores[] = $p['descripcion'] . ': ' . $e;
            }
        }
        if (Politicas::get('ia_umbral_critico') <= Politicas::get('ia_umbral_atencion')) {
            $errores[] = 'El umbral crítico debe ser mayor que el de atención.';
        }
        if ($errores) {
            return $this->fallo($errores[0], 422, ['errores' => $errores]);
        }
        return $this->ok(['mensaje' => 'Políticas guardadas. Los cambios quedaron en la auditoría.']);
    }
}
