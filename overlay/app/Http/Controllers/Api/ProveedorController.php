<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Proveedores de equipos, periféricos y servicios. */
final class ProveedorController extends BaseApi
{
    private const TIPOS = ['equipos' => 'Equipos', 'perifericos' => 'Periféricos', 'repuestos' => 'Repuestos', 'software' => 'Software', 'servicios' => 'Servicios'];

    public function index(Request $r)
    {
        $filas = Db::todos('SELECT p.*, (SELECT count(*) FROM estaciones e WHERE e.proveedor_id = p.id AND e.activo) AS n_est,
                                   (SELECT count(*) FROM componentes c WHERE c.proveedor_id = p.id) AS n_comp
                              FROM proveedores p ORDER BY p.activo DESC, p.razon_social');
        return $this->ok(['proveedores' => $filas, 'tipos' => self::TIPOS]);
    }

    public function guardar(Request $r)
    {
        $id = (int) $r->input('id', 0);
        $razon = limpio($r->input('razon_social', ''), 150);
        $ruc = limpio($r->input('ruc', ''), 11);
        $tipo = (string) $r->input('tipo', '');
        $correo = limpio($r->input('correo', ''), 120);
        $err = null;
        if ($razon === '') { $err = 'Escribe la razón social.'; }
        elseif ($ruc !== '' && !preg_match('/^\d{11}$/', $ruc)) { $err = 'El RUC debe tener 11 dígitos.'; }
        elseif (!isset(self::TIPOS[$tipo])) { $err = 'Elige el tipo de proveedor.'; }
        elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) { $err = 'El correo no es válido.'; }
        elseif ($ruc !== '' && Db::valor('SELECT 1 FROM proveedores WHERE ruc = :r AND id <> :id', ['r' => $ruc, 'id' => $id])) { $err = 'Ya existe un proveedor con ese RUC.'; }
        if ($err) {
            return $this->fallo($err);
        }
        $par = ['r' => $razon, 'ruc' => $ruc ?: null, 't' => $tipo, 'c' => limpio($r->input('contacto', ''), 100) ?: null,
                'te' => limpio($r->input('telefono', ''), 30) ?: null, 'co' => $correo ?: null, 'd' => limpio($r->input('direccion', ''), 200) ?: null];
        if ($id) {
            Db::ejecutar('UPDATE proveedores SET razon_social=:r, ruc=:ruc, tipo=:t, contacto=:c, telefono=:te, correo=:co, direccion=:d WHERE id=:id', $par + ['id' => $id]);
            Audit::registrar('proveedor.editar', 'proveedor', $id, ['razon_social' => $razon]);
        } else {
            $id = (int) Db::valor('INSERT INTO proveedores (razon_social, ruc, tipo, contacto, telefono, correo, direccion) VALUES (:r,:ruc,:t,:c,:te,:co,:d) RETURNING id', $par);
            Audit::registrar('proveedor.crear', 'proveedor', $id, ['razon_social' => $razon]);
        }
        return $this->ok(['id' => $id]);
    }

    public function estado(Request $r, int $id)
    {
        $n = Db::fila('UPDATE proveedores SET activo = NOT activo WHERE id = :id RETURNING activo, razon_social', ['id' => $id]);
        if (!$n) {
            return $this->fallo('No existe ese proveedor.', 404);
        }
        Audit::registrar($n['activo'] ? 'proveedor.activar' : 'proveedor.desactivar', 'proveedor', $id, ['razon_social' => $n['razon_social']]);
        return $this->ok(['activo' => (bool) $n['activo']]);
    }
}
