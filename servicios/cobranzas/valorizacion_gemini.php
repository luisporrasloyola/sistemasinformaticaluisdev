<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_module_access('cobranzas.valorizacion');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function gemini_reply(int $status, string $message, ?array $fields = null): never
{
    http_response_code($status);
    echo json_encode($fields === null ? ['error' => $message] : ['message' => $message, 'fields' => $fields], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) gemini_reply(403, 'Solicitud no autorizada.');
$key = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
if ($key === '') {
    $keyFiles = [];
    $configuredFile = trim((string) (getenv('GEMINI_API_KEY_FILE') ?: ''));
    if ($configuredFile !== '') {
        $keyFiles[] = $configuredFile;
    } else {
        $home = trim((string) (getenv('HOME') ?: ''));
        if ($home !== '') {
            $keyFiles[] = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . 'lifemaquinarias_gemini.key';
        }
        $appRoot = dirname(__DIR__, 2);
        if (basename($appRoot) === 'public_html') {
            $keyFiles[] = dirname($appRoot) . DIRECTORY_SEPARATOR . 'lifemaquinarias_gemini.key';
        }
    }
    foreach (array_unique($keyFiles) as $keyFile) {
        if (is_file($keyFile) && is_readable($keyFile)) {
            $key = trim((string) file_get_contents($keyFile));
            if ($key !== '') break;
        }
    }
}
if ($key === '') gemini_reply(503, 'Gemini no está configurado en este servidor.');
$file = $_FILES['imagen'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 1500000 || ($file['size'] ?? 0) < 1000) {
    gemini_reply(400, 'La imagen de lectura debe medir menos de 1,5 MB.');
}
$path = (string) $file['tmp_name'];
$info = @getimagesize($path);
if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 2500 || $info[1] > 2500) {
    gemini_reply(400, 'Seleccione una imagen válida para la lectura.');
}
$lock = fopen(sys_get_temp_dir() . '/lifemaquinarias_gemini_' . sha1(dirname(__DIR__, 2)) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) gemini_reply(429, 'Hay otra lectura en curso. Intente nuevamente en unos momentos.');
session_write_close();
$image = file_get_contents($path);
if ($image === false) gemini_reply(400, 'No se pudo leer la imagen.');
$prompt = 'Lee exclusivamente el formulario PARTE DIARIO de esta imagen. Devuelve JSON con estas claves exactas: equipo, operador, fecha_servicio, hora_inicio, hora_final. Equipo es únicamente el texto manuscrito de la casilla "Equipo"; ignora por completo la casilla "Placa/Serie" y no agregues su contenido al equipo. Operador es el nombre manuscrito junto a la firma bajo "ELABORADO POR" (casilla "NOMBRE"), no el nombre de "APROBADO POR" ni la firma dibujada. Conserva las iniciales y el orden tal como aparecen; no inventes apellidos. Fecha de servicio son las casillas DÍA, MES y AÑO. Hora de inicio es "Llegada a instalación del cliente" y hora final es "Salida de instalación del Cliente". Copia literalmente lo visible; no uses otras horas ni inventes caracteres. Si un dato es ilegible o ambiguo devuelve null para ese dato. Fecha en DD/MM/AAAA y horas en HH:mm (24 horas) cuando sean inequívocas. No incluyas datos del cliente ni observaciones.';
$body = json_encode([
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $info['mime'], 'data' => base64_encode($image)]]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 1024, 'thinkingConfig' => ['thinkingLevel' => 'MINIMAL']],
], JSON_UNESCAPED_UNICODE);
unset($image);
@set_time_limit(120);
$response = false;
$status = 0;
$curlNumber = 0;
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $handle = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent');
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 110]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $elapsed = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
    $curlNumber = curl_errno($handle);
    curl_close($handle);
    // Un segundo intento breve ayuda cuando Google devuelve un 500/503 transitorio.
    // No se repiten errores de clave, cuota ni solicitudes que ya tardaron mucho.
    if ($attempt === 1 && in_array($status, [500, 503], true) && $elapsed < 20) {
        usleep(random_int(500000, 1200000));
        continue;
    }
    break;
}
if ($response === false) {
    error_log('Valorización Gemini: fallo de conexión cURL ' . $curlNumber . ', HTTP ' . $status);
    gemini_reply(503, 'No se pudo conectar con la API de Gemini. Intente nuevamente.');
}
$payload = json_decode($response, true);
if ($status !== 200 || !is_array($payload)) {
    $apiStatus = (string) ($payload['error']['status'] ?? '');
    $apiStatus = preg_match('/^[A-Z_]{1,40}$/D', $apiStatus) ? $apiStatus : 'SIN_ESTADO';
    error_log('Valorización Gemini: API HTTP ' . $status . ', estado ' . $apiStatus);
    $message = match ($status) {
        400 => 'Gemini rechazó la imagen o los parámetros de lectura (HTTP 400). Intente con otra foto o informe al administrador.',
        401, 403 => 'La clave de la API de Gemini no tiene acceso. Revise su configuración en Google AI Studio (HTTP ' . $status . ').',
        402 => 'La cuenta de la API de Gemini requiere revisar su saldo o facturación (HTTP 402).',
        404 => 'El modelo configurado no está disponible para esta clave de Gemini (HTTP 404).',
        429 => 'La API de Gemini alcanzó un límite de solicitudes o cuota (HTTP 429). Intente más tarde.',
        500, 503, 504 => 'La API de Gemini está temporalmente sobrecargada (HTTP ' . $status . '). Intente nuevamente.',
        default => 'La API de Gemini rechazó la lectura (HTTP ' . $status . '). Informe al administrador.',
    };
    gemini_reply(in_array($status, [400, 401, 402, 403, 404, 429], true) ? $status : 503, $message);
}
if (($payload['candidates'][0]['finishReason'] ?? '') !== 'STOP') gemini_reply(503, 'La lectura quedó incompleta. Intente de nuevo o complete los datos manualmente.');
$raw = implode('', array_column($payload['candidates'][0]['content']['parts'] ?? [], 'text'));
$values = json_decode($raw, true);
if (!is_array($values)) gemini_reply(503, 'La lectura no produjo datos utilizables.');
$fields = [];
foreach (['equipo', 'operador', 'fecha_servicio', 'hora_inicio', 'hora_final'] as $name) {
    $value = $values[$name] ?? null;
    $fields[$name] = is_string($value) ? mb_substr(trim($value), 0, 120) : null;
}
gemini_reply(200, 'Compare cada propuesta con el documento antes de usarla.', $fields);
