<?php
declare(strict_types=1);

namespace App\Servicios;

/**
 * Cliente de la API de Gemini (Google AI). Se usa para redactar en lenguaje natural
 * el resumen de movimientos y la explicación de cada predicción del modelo de ML.
 * Nunca se envían capturas de pantalla ni nombres de personas: solo datos técnicos.
 */
final class Gemini
{
    public static function configurada(): bool
    {
        return Config::get('GEMINI_API_KEY') !== '';
    }

    /** ¿Se puede usar? Hay clave y la política de seguridad lo permite. */
    public static function permitida(): bool
    {
        return self::configurada() && Politicas::get('ia_enviar_a_gemini');
    }

    public static function modelo(): string
    {
        $m = Config::get('GEMINI_MODEL', 'gemini-2.5-flash');
        return preg_match('/^[a-z0-9][a-z0-9.\-]{2,60}$/', $m) ? $m : 'gemini-2.5-flash';
    }

    /** @throws \RuntimeException si la API falla o no devuelve texto */
    public static function generar(string $sistema, string $usuario, float $temperatura = 0.3, int $maxTokens = 2048): string
    {
        if (!self::permitida()) {
            throw new \RuntimeException('Gemini no está disponible (sin clave o desactivado por política).');
        }
        $base = Config::get('GEMINI_BASE', 'https://generativelanguage.googleapis.com/v1beta');
        if (!str_starts_with($base, 'https://') && !Config::esDev()) {
            throw new \RuntimeException('GEMINI_BASE debe usar https.');
        }
        $url = rtrim($base, '/') . '/models/' . self::modelo() . ':generateContent';
        $cuerpo = json_encode([
            'systemInstruction' => ['parts' => [['text' => $sistema]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $usuario]]]],
            'generationConfig' => ['temperature' => $temperatura, 'maxOutputTokens' => $maxTokens],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . Config::get('GEMINI_API_KEY'),   // en el encabezado, no en la URL
            ],
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('No se pudo conectar con Gemini: ' . $err);
        }
        $json = json_decode((string) $resp, true);
        if ($http >= 400) {
            $msg = is_array($json) ? ($json['error']['message'] ?? '') : '';
            throw new \RuntimeException("Gemini respondió HTTP $http. " . mb_substr($msg, 0, 200));
        }
        if (!is_array($json)) {
            throw new \RuntimeException('Respuesta de Gemini no válida.');
        }
        if (!empty($json['promptFeedback']['blockReason'])) {
            throw new \RuntimeException('Gemini bloqueó la consulta: ' . $json['promptFeedback']['blockReason']);
        }
        $texto = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $parte) {
            $texto .= $parte['text'] ?? '';
        }
        $texto = trim($texto);
        if ($texto === '') {
            throw new \RuntimeException('Gemini no devolvió texto (' . ($json['candidates'][0]['finishReason'] ?? 'sin motivo') . ').');
        }
        return $texto;
    }
}
