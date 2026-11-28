<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Ciclo;
use App\Servicios\Db;
use App\Servicios\Panel;
use App\Servicios\Resumen;
use Illuminate\Http\Request;

/** Panel de monitoreo con IA: mosaico de estaciones, alertas y resumen «desde tu último ingreso». */
final class PanelController extends BaseApi
{
    public function dashboard(Request $r)
    {
        try {
            Ciclo::tick();   // detecta desconexiones y recalcula predicciones cuando toca
        } catch (\Throwable $e) {
            error_log('[AlfaVia] Ciclo: ' . $e->getMessage());
        }
        return response()->json(Panel::datos() + ['puede_atender' => $this->puede($r, 'alertas.atender')]);
    }

    public function resumen(Request $r)
    {
        $u = $this->usuario($r);
        $desde = (string) $r->session()->get('since', date('c', time() - 86400));
        $res = Resumen::paraIngreso((int) $u['id'], $desde);

        $mov = [];
        foreach ($res['datos']['desconexiones'] as $x) {
            $mov[] = ['t' => strtotime($x['cuando']), 'estacion' => $x['estacion'], 'titulo' => 'Estación desconectada',
                'detalle' => $x['reconectada_en'] ? '(volvió a conectarse)' : '(sigue sin conexión)', 'cuando' => fh($x['cuando'])];
        }
        foreach ($res['datos']['dispositivos'] as $x) {
            $mov[] = ['t' => strtotime($x['cuando']), 'estacion' => $x['estacion'], 'titulo' => 'Dispositivo desconectado',
                'detalle' => $x['nombre'] . ($x['sigue_desconectado'] ? ' (sigue desconectado)' : ' (ya se reconectó)'), 'cuando' => fh($x['cuando'])];
        }
        foreach ($res['datos']['componentes'] as $x) {
            $mov[] = ['t' => strtotime($x['cuando']), 'estacion' => $x['estacion'], 'titulo' => 'Componente ' . $x['accion'],
                'detalle' => $x['descripcion'], 'cuando' => fh($x['cuando'])];
        }
        usort($mov, static fn ($a, $b) => $b['t'] <=> $a['t']);

        return $this->ok([
            'texto' => $res['texto'], 'origen' => $res['origen'], 'modelo' => $res['modelo'], 'aviso' => $res['aviso'],
            'desde_texto' => fh($desde),
            'movimientos' => array_map(static fn ($m) => array_diff_key($m, ['t' => 1]), array_slice($mov, 0, 12)),
        ]);
    }

    public function atender(Request $r, int $id)
    {
        $n = Db::ejecutar('UPDATE alertas SET atendida_en = now(), atendida_por = :u WHERE id = :id AND atendida_en IS NULL',
            ['u' => (int) $this->usuario($r)['id'], 'id' => $id]);
        if ($n) {
            Audit::registrar('alerta.atender', 'alerta', $id);
        }
        return response()->json(['ok' => (bool) $n]);
    }
}
