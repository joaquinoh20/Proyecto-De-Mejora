<?php

namespace App\Console\Commands;

use App\Servicios\Auth;
use App\Servicios\Db;
use Illuminate\Console\Command;

class CrearUsuarioCommand extends Command
{
    protected $signature = 'alfavia:crear-usuario {usuario} {nombre} {--rol=Administrador del Sistema}';
    protected $description = 'Crea un usuario con una contraseña temporal (que deberá cambiar al ingresar)';

    public function handle(): int
    {
        $usuario = strtolower((string) $this->argument('usuario'));
        $rolId = Db::valor('SELECT id FROM roles WHERE nombre = :n', ['n' => (string) $this->option('rol')]);
        if (!$rolId) {
            $this->error('Ese rol no existe.');
            return self::FAILURE;
        }
        if (Db::valor('SELECT 1 FROM usuarios WHERE lower(usuario) = :u', ['u' => $usuario])) {
            $this->error('El usuario ya existe.');
            return self::FAILURE;
        }
        $t = Auth::claveTemporal();
        Db::ejecutar('INSERT INTO usuarios (usuario, nombre_completo, clave_hash, rol_id, debe_cambiar_clave) VALUES (:u,:n,:h,:r,true)',
            ['u' => $usuario, 'n' => (string) $this->argument('nombre'), 'h' => Auth::hash($t), 'r' => (int) $rolId]);
        $this->info("Usuario creado: $usuario");
        $this->info("Contraseña temporal: $t   (deberás cambiarla al ingresar)");
        return self::SUCCESS;
    }
}
