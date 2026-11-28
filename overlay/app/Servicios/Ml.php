<?php
declare(strict_types=1);

namespace App\Servicios;

/** Cliente del servicio de machine learning (Python / scikit-learn). */
final class Ml
{
    private static function llamar(string $metodo, string $ruta, ?array $cuerpo = null, int $timeout = 10): array
    {
        $ch = curl_init(rtrim(Config::get('ML_URL', 'http://127.0.0.1:5001'), '/') . $ruta);
        $cab = ['X-ML-Token: ' . Config::get('ML_TOKEN')];
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $timeout, CURLOPT_CUSTOMREQUEST => $metodo];
        if ($cuerpo !== null) {
            $cab[] = 'Content-Type: application/json';
            $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo);
        }
        $op[CURLOPT_HTTPHEADER] = $cab;
        curl_setopt_array($ch, $op);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new \RuntimeException('El servicio de ML no responde: ' . $err);
        }
        $json = json_decode((string) $resp, true);
        if ($http >= 400 || !is_array($json)) {
            throw new \RuntimeException("El servicio de ML devolvió HTTP $http: " . (is_array($json) ? ($json['error'] ?? '') : ''));
        }
        return $json;
    }

    /** Información del modelo o null si el servicio no está disponible. */
    public static function salud(): ?array
    {
        try {
            $r = self::llamar('GET', '/health', null, 3);
            return $r['modelo'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,array{id:int|string,features:array}> $items
     * @return array<int,array> resultados en el mismo orden
     */
    public static function predecir(array $items): array
    {
        if ($items === []) {
            return [];
        }
        return self::llamar('POST', '/predict', ['items' => array_values($items)])['resultados'] ?? [];
    }
}
