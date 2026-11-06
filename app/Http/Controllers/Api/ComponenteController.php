<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Componentes de cada PC: agregar, retirar e intercambiar (queda en el historial de movimientos). */
final class ComponenteController extends BaseApi
{
    public function accion(Request $r, int $id)
    {
        $u = $this->usuario($r);
        $est = Db::fila('SELECT id, codigo_activo FROM estaciones WHERE id = :id', ['id' => $id]);
        if (!$est) {
            return $this->fallo('No existe esa estación.', 404);
        }
        $eid = (int) $est['id'];
        $tipos = ['procesador', 'ram', 'disco', 'diadema', 'mouse', 'teclado', 'monitor', 'otro'];
        $accion = (string) $r->input('accion', '');
        $marca = limpio($r->input('marca', ''), 60) ?: null;
        $modelo = limpio($r->input('modelo', ''), 80) ?: null;
        $serie = limpio($r->input('serie', ''), 80) ?: null;
        $prov = (int) $r->input('proveedor', 0) ?: null;
        $motivo = limpio($r->input('motivo', ''), 200) ?: null;
        $nombre = static fn (?string $a, ?string $b) => trim(($a ?? '') . ' ' . ($b ?? ''));

        if ($accion === 'agregar') {
            $tipo = (string) $r->input('tipo', '');
            $fecha = $this->fecha($r->input('fecha', '')) ?: date('Y-m-d');
            if (!in_array($tipo, $tipos, true) || $modelo === null) {
                return $this->fallo('Completa el tipo y el modelo del componente.');
            }
            Db::transaccion(function () use ($eid, $tipo, $marca, $modelo, $serie, $prov, $fecha, $u, $nombre): void {
                $cid = (int) Db::valor('INSERT INTO componentes (estacion_id, tipo, marca, modelo, numero_serie, proveedor_id, fecha_instalacion)
                                        VALUES (:e, :t, :ma, :mo, :s, :p, :f) RETURNING id', ['e' => $eid, 't' => $tipo, 'ma' => $marca, 'mo' => $modelo, 's' => $serie, 'p' => $prov, 'f' => $fecha]);
                Db::ejecutar("INSERT INTO movimientos_componentes (componente_id, estacion_id, accion, descripcion, usuario_id)
                              VALUES (:c, :e, 'instalado', :d, :u)", ['c' => $cid, 'e' => $eid, 'd' => ucfirst($tipo) . ' ' . $nombre($marca, $modelo), 'u' => (int) $u['id']]);
                Audit::registrar('componente.agregar', 'componente', $cid, ['estacion' => $eid]);
            });
            return $this->ok(['mensaje' => 'Componente agregado.']);
        }

        $cid = (int) $r->input('componente', 0);
        $viejo = Db::fila("SELECT * FROM componentes WHERE id = :c AND estacion_id = :e AND estado <> 'retirado'", ['c' => $cid, 'e' => $eid]);
        if (!$viejo || !in_array($accion, ['retirar', 'intercambiar'], true)) {
            return $this->fallo('No se encontró el componente o ya estaba retirado.');
        }
        if ($motivo === null) {
            return $this->fallo('Escribe el motivo.');
        }
        if ($accion === 'intercambiar' && $modelo === null) {
            return $this->fallo('Escribe el modelo del componente nuevo.');
        }
        $descViejo = ucfirst($viejo['tipo']) . ' ' . $nombre($viejo['marca'], $viejo['modelo']);

        Db::transaccion(function () use ($accion, $viejo, $eid, $cid, $descViejo, $marca, $modelo, $serie, $prov, $motivo, $u, $nombre): void {
            Db::ejecutar("UPDATE componentes SET estado = 'retirado', notas = :n WHERE id = :id", ['n' => $motivo, 'id' => $cid]);
            if ($accion === 'intercambiar') {
                $nuevo = (int) Db::valor('INSERT INTO componentes (estacion_id, tipo, marca, modelo, numero_serie, proveedor_id, fecha_instalacion)
                                          VALUES (:e, :t, :ma, :mo, :s, :p, CURRENT_DATE) RETURNING id',
                    ['e' => $eid, 't' => $viejo['tipo'], 'ma' => $marca, 'mo' => $modelo, 's' => $serie, 'p' => $prov]);
                Db::ejecutar("INSERT INTO movimientos_componentes (componente_id, estacion_id, accion, descripcion, motivo, usuario_id)
                              VALUES (:c, :e, 'intercambiado', :d, :m, :u)",
                    ['c' => $nuevo, 'e' => $eid, 'd' => $descViejo . ' → ' . $nombre($marca, $modelo), 'm' => $motivo, 'u' => (int) $u['id']]);
                Audit::registrar('componente.intercambiar', 'componente', $cid, ['estacion' => $eid, 'nuevo' => $nuevo, 'motivo' => $motivo]);
            } else {
                Db::ejecutar("INSERT INTO movimientos_componentes (componente_id, estacion_id, accion, descripcion, motivo, usuario_id)
                              VALUES (:c, :e, 'retirado', :d, :m, :u)", ['c' => $cid, 'e' => $eid, 'd' => $descViejo, 'm' => $motivo, 'u' => (int) $u['id']]);
                Audit::registrar('componente.retirar', 'componente', $cid, ['estacion' => $eid, 'motivo' => $motivo]);
            }
        });
        return $this->ok(['mensaje' => $accion === 'intercambiar' ? 'Intercambio registrado.' : 'Componente retirado.']);
    }
}
