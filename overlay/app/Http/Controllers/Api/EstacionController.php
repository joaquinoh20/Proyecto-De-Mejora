<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Agente;
use App\Servicios\Audit;
use App\Servicios\Db;
use App\Servicios\Politicas;
use App\Servicios\Resumen;
use Illuminate\Http\Request;

/** Inventario de estaciones (características de cada computadora), ficha, edición, token del agente y telemetría. */
final class EstacionController extends BaseApi
{
    public function index(Request $r)
    {
        $q = limpio($r->query('q', ''), 60);
        $ub = (int) $r->query('ubicacion', 0);
        $es = (string) $r->query('estado', '');
        $pv = (int) $r->query('proveedor', 0);

        // Los filtros se agregan solo si vienen; los valores siempre van como parámetros
        $where = ['e.activo'];
        $p = [];
        if ($q !== '') {
            $where[] = '(e.codigo_activo ILIKE :q1 OR e.nombre_host ILIKE :q2 OR e.marca ILIKE :q3 OR e.modelo ILIKE :q4)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $p += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        if ($ub > 0) { $where[] = 'e.ubicacion_id = :ub'; $p['ub'] = $ub; }
        if (in_array($es, ['en_linea', 'sin_conexion', 'mantenimiento', 'baja'], true)) { $where[] = 'e.estado = :es'; $p['es'] = $es; }
        if ($pv > 0) { $where[] = 'e.proveedor_id = :pv'; $p['pv'] = $pv; }

        $filas = Db::todos(
            'SELECT e.id, e.codigo_activo, e.nombre_host, e.marca, e.modelo, e.cpu_modelo, e.ram_gb, e.disco_tipo, e.disco_gb, e.estado,
                    ub.nombre AS ubicacion, pr.razon_social AS proveedor, p.prob_falla, p.nivel
               FROM estaciones e JOIN ubicaciones ub ON ub.id = e.ubicacion_id
               LEFT JOIN proveedores pr ON pr.id = e.proveedor_id
               LEFT JOIN v_ultima_prediccion p ON p.estacion_id = e.id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY e.codigo_activo', $p);
        return $this->ok([
            'estaciones' => $filas,
            'ubicaciones' => Db::todos('SELECT id, nombre FROM ubicaciones ORDER BY id'),
            'proveedores' => Db::todos('SELECT id, razon_social FROM proveedores WHERE activo ORDER BY razon_social'),
        ]);
    }

    public function show(Request $r, int $id)
    {
        $e = Db::fila(
            'SELECT e.*, ub.nombre AS ubicacion, pr.razon_social AS proveedor, pr.ruc AS proveedor_ruc, pr.contacto AS proveedor_contacto,
                    pr.telefono AS proveedor_tel, pr.correo AS proveedor_correo
               FROM estaciones e JOIN ubicaciones ub ON ub.id = e.ubicacion_id LEFT JOIN proveedores pr ON pr.id = e.proveedor_id
              WHERE e.id = :id', ['id' => $id]);
        if (!$e) {
            return $this->fallo('No existe esa estación.', 404);
        }
        $tieneToken = $e['agente_token_hash'] !== null;
        unset($e['agente_token_hash']);   // el hash nunca sale del servidor
        $e['tiene_token'] = $tieneToken;
        $e['token_rotar'] = $e['agente_token_creado'] && strtotime($e['agente_token_creado']) < time() - Politicas::get('token_agente_vigencia_dias') * 86400;
        $e['garantia_dias'] = $e['garantia_hasta'] ? (int) floor((strtotime($e['garantia_hasta']) - time()) / 86400) : null;

        $pred = Db::fila('SELECT * FROM v_ultima_prediccion WHERE estacion_id = :id', ['id' => $id]);
        if ($pred) {
            $pred['factores'] = json_decode((string) $pred['factores'], true) ?: [];
            $pred['hace'] = hace($pred['calculado_en']);
        }
        $ult = Db::fila('SELECT * FROM telemetria WHERE estacion_id = :id ORDER BY tomado_en DESC LIMIT 1', ['id' => $id]);
        if ($ult) {
            $ult['hace'] = hace($ult['tomado_en']);
        }
        return $this->ok([
            'estacion' => $e + ['hace_latido' => hace($e['ultimo_latido'])],
            'prediccion' => $pred,
            'ultima' => $ult,
            'componentes' => Db::todos('SELECT c.*, pr.razon_social AS proveedor FROM componentes c LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                                         WHERE c.estacion_id = :id ORDER BY (c.estado = \'retirado\'), c.tipo, c.id DESC', ['id' => $id]),
            'dispositivos' => Db::todos('SELECT * FROM dispositivos_detectados WHERE estacion_id = :id ORDER BY presente DESC, nombre', ['id' => $id]),
            'movimientos' => Db::todos('SELECT * FROM v_movimientos WHERE estacion_id = :id ORDER BY fecha DESC LIMIT 15', ['id' => $id]),
            'mantenimientos' => Db::todos('SELECT m.*, u.nombre_completo AS tecnico FROM mantenimientos m LEFT JOIN usuarios u ON u.id = m.tecnico_id
                                            WHERE m.estacion_id = :id ORDER BY m.fecha DESC LIMIT 8', ['id' => $id]),
            'proveedores' => Db::todos('SELECT id, razon_social FROM proveedores WHERE activo ORDER BY razon_social'),
        ]);
    }

    /** Listas para el formulario de alta y edición. */
    public function formulario(Request $r)
    {
        return $this->ok([
            'ubicaciones' => Db::todos('SELECT id, nombre FROM ubicaciones ORDER BY id'),
            'proveedores' => Db::todos("SELECT id, razon_social FROM proveedores WHERE activo AND tipo IN ('equipos','repuestos','servicios') ORDER BY razon_social"),
        ]);
    }

    public function store(Request $r)
    {
        return $this->guardar($r, 0);
    }

    public function update(Request $r, int $id)
    {
        return $this->guardar($r, $id);
    }

    private function guardar(Request $r, int $id)
    {
        $e = $id ? Db::fila('SELECT * FROM estaciones WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$e) {
            return $this->fallo('No existe esa estación.', 404);
        }
        $ubicaciones = array_map('intval', array_column(Db::todos('SELECT id FROM ubicaciones'), 'id'));
        $i = static fn (string $k, int $max) => limpio($r->input($k, ''), $max);
        $v = [
            'codigo_activo' => strtoupper($i('codigo_activo', 30)), 'nombre_host' => $i('nombre_host', 60), 'ubicacion_id' => (int) $r->input('ubicacion_id', 0),
            'puesto' => $i('puesto', 80), 'responsable' => $i('responsable', 80), 'proveedor_id' => (int) $r->input('proveedor_id', 0) ?: null,
            'fecha_compra' => $i('fecha_compra', 10), 'garantia_hasta' => $i('garantia_hasta', 10), 'marca' => $i('marca', 60), 'modelo' => $i('modelo', 80),
            'numero_serie' => $i('numero_serie', 80), 'cpu_modelo' => $i('cpu_modelo', 120), 'cpu_nucleos' => $i('cpu_nucleos', 4), 'ram_gb' => $i('ram_gb', 5),
            'disco_tipo' => $i('disco_tipo', 5), 'disco_gb' => $i('disco_gb', 7), 'gpu' => $i('gpu', 80), 'so' => $i('so', 80),
        ];
        $modo = (string) $r->input('modo', 'operativa');

        $errores = [];
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-]{1,29}$/', $v['codigo_activo'])) { $errores[] = 'El código de activo solo admite letras, números y guiones (ej.: PC-026).'; }
        if ($v['nombre_host'] === '') { $errores[] = 'Escribe el nombre del equipo (host).'; }
        if (!in_array($v['ubicacion_id'], $ubicaciones, true)) { $errores[] = 'Elige una ubicación.'; }
        foreach (['fecha_compra' => 'compra', 'garantia_hasta' => 'garantía'] as $k => $n) {
            if ($v[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v[$k])) { $errores[] = "Fecha de $n no válida."; }
        }
        foreach (['cpu_nucleos' => [1, 512, 'núcleos'], 'ram_gb' => [1, 8192, 'RAM'], 'disco_gb' => [1, 1000000, 'disco']] as $k => [$mi, $ma, $n]) {
            if ($v[$k] !== '' && (!ctype_digit($v[$k]) || (int) $v[$k] < $mi || (int) $v[$k] > $ma)) { $errores[] = "Valor de $n no válido."; }
        }
        if (!in_array($v['disco_tipo'], ['SSD', 'HDD', 'NVMe', ''], true)) { $errores[] = 'Tipo de disco no válido.'; }
        if (Db::valor('SELECT 1 FROM estaciones WHERE codigo_activo = :c AND id <> :id', ['c' => $v['codigo_activo'], 'id' => $id])) { $errores[] = 'Ya existe una estación con ese código de activo.'; }
        if ($errores) {
            return $this->fallo($errores[0], 422, ['errores' => $errores]);
        }

        $nul = static fn ($x) => ($x === '' || $x === null) ? null : $x;
        $int = static fn ($x) => ($x === '' || $x === null) ? null : (int) $x;
        $par = [
            'cod' => $v['codigo_activo'], 'host' => $v['nombre_host'], 'ub' => $v['ubicacion_id'], 'pu' => $nul($v['puesto']), 're' => $nul($v['responsable']),
            'pr' => $v['proveedor_id'], 'fc' => $nul($v['fecha_compra']), 'gh' => $nul($v['garantia_hasta']),
            'ma' => $nul($v['marca']), 'mo' => $nul($v['modelo']), 'ns' => $nul($v['numero_serie']), 'cm' => $nul($v['cpu_modelo']),
            'cn' => $int($v['cpu_nucleos']), 'rg' => $int($v['ram_gb']), 'dt' => $nul($v['disco_tipo']), 'dg' => $int($v['disco_gb']), 'gp' => $nul($v['gpu']), 'so' => $nul($v['so']),
        ];
        if ($id) {
            $estado = match ($modo) {
                'mantenimiento' => 'mantenimiento',
                'baja' => 'baja',
                default => in_array($e['estado'], ['mantenimiento', 'baja'], true) ? 'sin_conexion' : $e['estado'],
            };
            Db::ejecutar('UPDATE estaciones SET codigo_activo=:cod, nombre_host=:host, ubicacion_id=:ub, puesto=:pu, responsable=:re, proveedor_id=:pr, fecha_compra=:fc, garantia_hasta=:gh,
                marca=:ma, modelo=:mo, numero_serie=:ns, cpu_modelo=:cm, cpu_nucleos=:cn, ram_gb=:rg, disco_tipo=:dt, disco_gb=:dg, gpu=:gp, so=:so, estado=:est WHERE id=:id',
                $par + ['est' => $estado, 'id' => $id]);
            Audit::registrar('estacion.editar', 'estacion', $id, ['codigo' => $v['codigo_activo'], 'modo' => $modo]);
        } else {
            $id = (int) Db::valor('INSERT INTO estaciones (codigo_activo, nombre_host, ubicacion_id, puesto, responsable, proveedor_id, fecha_compra, garantia_hasta, marca, modelo, numero_serie,
                cpu_modelo, cpu_nucleos, ram_gb, disco_tipo, disco_gb, gpu, so) VALUES (:cod,:host,:ub,:pu,:re,:pr,:fc,:gh,:ma,:mo,:ns,:cm,:cn,:rg,:dt,:dg,:gp,:so) RETURNING id', $par);
            Audit::registrar('estacion.crear', 'estacion', $id, ['codigo' => $v['codigo_activo']]);
        }
        return $this->ok(['id' => $id]);
    }

    /** Datos para precargar el formulario de edición (sin el hash del token). */
    public function editar(Request $r, int $id)
    {
        $e = Db::fila('SELECT * FROM estaciones WHERE id = :id', ['id' => $id]);
        if (!$e) {
            return $this->fallo('No existe esa estación.', 404);
        }
        unset($e['agente_token_hash']);
        return $this->ok(['estacion' => $e]);
    }

    public function token(Request $r, int $id)
    {
        $e = Db::fila('SELECT id, codigo_activo, nombre_host FROM estaciones WHERE id = :id', ['id' => $id]);
        if (!$e) {
            return $this->fallo('No existe esa estación.', 404);
        }
        $token = Agente::generarToken((int) $e['id']);
        Audit::registrar('agente.token_generado', 'estacion', $e['id'], ['codigo' => $e['codigo_activo']]);
        return $this->ok(['token' => $token, 'codigo' => $e['codigo_activo'], 'host' => $e['nombre_host']]);
    }

    public function telemetria(Request $r, int $id)
    {
        $horas = max(1, min(168, (int) $r->query('horas', 24)));
        // Hasta ~120 puntos: se promedia por intervalos para no enviar miles de filas
        $paso = max(60, (int) ceil($horas * 3600 / 120));
        $filas = Db::todos(
            "SELECT floor(extract(epoch FROM tomado_en) / :paso)::bigint * :paso2 AS t,
                    round(avg(cpu_pct)::numeric, 1) AS cpu, round(avg(ram_pct)::numeric, 1) AS ram,
                    round(avg(temp_c)::numeric, 1) AS temp, round(min(disco_salud_pct)::numeric, 1) AS salud
               FROM telemetria
              WHERE estacion_id = :e AND tomado_en > now() - make_interval(hours => :h)
              GROUP BY 1 ORDER BY 1",
            ['paso' => $paso, 'paso2' => $paso, 'e' => $id, 'h' => $horas]
        );
        $num = static fn ($v) => $v === null ? null : (float) $v;
        return $this->ok(['puntos' => array_map(static fn ($f) => [
            't' => (int) $f['t'], 'cpu' => $num($f['cpu']), 'ram' => $num($f['ram']), 'temp' => $num($f['temp']), 'salud' => $num($f['salud']),
        ], $filas)]);
    }

    public function ia(Request $r, int $id)
    {
        return $this->ok(Resumen::paraEstacion($id));
    }
}
