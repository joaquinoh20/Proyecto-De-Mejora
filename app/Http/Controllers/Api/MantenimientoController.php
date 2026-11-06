<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Atenciones de mantenimiento. Una falla real queda registrada para que el modelo de ML aprenda de ella. */
final class MantenimientoController extends BaseApi
{
    public function store(Request $r, int $id)
    {
        $u = $this->usuario($r);
        $tipo = (string) $r->input('tipo', '');
        $desc = limpio($r->input('descripcion', ''), 500);
        if (!Db::valor('SELECT 1 FROM estaciones WHERE id = :id', ['id' => $id]) || !in_array($tipo, ['preventivo', 'correctivo'], true) || $desc === '') {
            return $this->fallo('Completa el tipo y la descripción.');
        }
        $tf = (string) $r->input('tipo_falla', 'otro');
        $ff = $this->fecha($r->input('fecha_falla', ''));
        $esFalla = filter_var($r->input('es_falla', false), FILTER_VALIDATE_BOOLEAN);
        Db::transaccion(function () use ($id, $tipo, $desc, $u, $tf, $ff, $esFalla): void {
            $mid = (int) Db::valor('INSERT INTO mantenimientos (estacion_id, tipo, descripcion, tecnico_id) VALUES (:e, :t, :d, :u) RETURNING id',
                ['e' => $id, 't' => $tipo, 'd' => $desc, 'u' => (int) $u['id']]);
            Audit::registrar('mantenimiento.registrar', 'estacion', $id, ['mantenimiento' => $mid, 'tipo' => $tipo]);
            if ($esFalla && $tipo === 'correctivo' && in_array($tf, ['disco', 'sobrecalentamiento', 'ram', 'otro'], true) && $ff !== '') {
                Db::ejecutar("INSERT INTO fallas (estacion_id, fecha, tipo, descripcion) VALUES (:e, CAST(:f AS date) + time '12:00', :t, :d)",
                    ['e' => $id, 'f' => $ff, 't' => $tf, 'd' => $desc]);
                Audit::registrar('falla.registrar', 'estacion', $id, ['tipo' => $tf, 'fecha' => $ff]);
            }
        });
        return $this->ok(['mensaje' => 'Atención registrada.']);
    }
}
