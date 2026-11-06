<?php

namespace App\Console\Commands;

use App\Servicios\Auth;
use App\Servicios\Db;
use Illuminate\Console\Command;

class CrearUsuarioCommand extends Command
{
    protected $signature = 'alfavia:crear-usuario {nombre} {apellido} {dni} {--rol=Administrador del Sistema}';
    protected $description = 'Crea un usuario nombre.apellido#alfavia cuya contraseña es su DNI (no se cambia después)';

    public function handle(): int
    {
        $usuario = Auth::nombreUsuario((string) $this->argument('nombre'), (string) $this->argument('apellido'));
        $dni = (string) $this->argument('dni');
        $rolId = Db::valor('SELECT id FROM roles WHERE nombre = :n', ['n' => (string) $this->option('rol')]);
        if ($usuario === null) { $this->error('Nombre o apellido no válidos (solo letras).'); return self::FAILURE; }
        if (($e = Auth::validarDni($dni)) !== null) { $this->error($e); return self::FAILURE; }
        if (!$rolId) { $this->error('Ese rol no existe. Roles: Administrador del Sistema, Jefe de Monitoreo, Ingeniero de Sistemas.'); return self::FAILURE; }
        if (Db::valor('SELECT 1 FROM usuarios WHERE lower(usuario) = :u', ['u' => $usuario])) { $this->error("El usuario $usuario ya existe."); return self::FAILURE; }
        Db::ejecutar('INSERT INTO usuarios (usuario, nombre_completo, clave_hash, rol_id, debe_cambiar_clave) VALUES (:u,:n,:h,:r,false)',
            ['u' => $usuario, 'n' => trim($this->argument('nombre') . ' ' . $this->argument('apellido')), 'h' => Auth::hash($dni), 'r' => (int) $rolId]);
        $this->info("Usuario creado: $usuario");
        $this->info('Contraseña: su número de DNI.');
        return self::SUCCESS;
    }
}
