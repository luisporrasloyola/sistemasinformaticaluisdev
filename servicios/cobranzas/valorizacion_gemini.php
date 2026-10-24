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
$last = (int) ($_SESSION['cv_gemini_last'] ?? 0);
if (time() - $last < 3) gemini_reply(429, 'Espere unos segundos antes de repetir la lectura.');
$_SESSION['cv_gemini_last'] = time();
session_write_close();
$image = file_get_contents($path);
if ($image === false) gemini_reply(400, 'No se pudo leer la imagen.');
$prompt = 'Lee exclusivamente el formulario PARTE DIARIO de esta imagen. Devuelve JSON con estas claves exactas: equipo, placa_serie, operador, fecha_servicio, hora_inicio, hora_final. Equipo es el texto manuscrito de la casilla "Equipo"; placa_serie es el texto manuscrito de "Placa/Serie". Operador es el nombre manuscrito junto a la firma bajo "ELABORADO POR" (casilla "NOMBRE"), no el nombre de "APROBADO POR" ni la firma dibujada. Conserva las iniciales y el orden tal como aparecen; no inventes apellidos. Fecha de servicio son las casillas DÍA, MES y AÑO. Hora de inicio es "Llegada a instalación del cliente" y hora final es "Salida de instalación del Cliente". Copia literalmente lo visible; no uses otras horas ni inventes caracteres. Si un dato es ilegible o ambiguo devuelve null para ese dato. Fecha en DD/MM/AAAA y horas en HH:mm (24 horas) cuando sean inequívocas. No incluyas datos del cliente ni observaciones.';
$body = json_encode([
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $info['mime'], 'data' => base64_encode($image)]]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 2048, 'thinkingConfig' => ['thinkingLevel' => 'MINIMAL']],
], JSON_UNESCAPED_UNICODE);
unset($image);
@set_time_limit(120);
$handle = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent');
curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 110]);
$response = curl_exec($handle);
$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
$curlError = curl_error($handle);
curl_close($handle);
if ($response === false) gemini_reply(503, $curlError ? 'La lectura tardó demasiado o no pudo conectarse. Intente nuevamente.' : 'No se pudo consultar Gemini.');
$payload = json_decode($response, true);
if ($status === 429) gemini_reply(429, 'La cuota gratuita de Gemini está temporalmente agotada. Intente más tarde o complete los datos manualmente.');
if ($status !== 200 || !is_array($payload)) gemini_reply(503, 'Gemini no está disponible en este momento. Puede completar los datos manualmente.');
if (($payload['candidates'][0]['finishReason'] ?? '') !== 'STOP') gemini_reply(503, 'La lectura quedó incompleta. Intente de nuevo o complete los datos manualmente.');
$raw = implode('', array_column($payload['candidates'][0]['content']['parts'] ?? [], 'text'));
$values = json_decode($raw, true);
if (!is_array($values)) gemini_reply(503, 'La lectura no produjo datos utilizables.');
$fields = [];
foreach (['equipo', 'placa_serie', 'operador', 'fecha_servicio', 'hora_inicio', 'hora_final'] as $name) {
    $value = $values[$name] ?? null;
    $fields[$name] = is_string($value) ? mb_substr(trim($value), 0, 120) : null;
}
gemini_reply(200, 'Compare cada propuesta con el documento antes de usarla.', $fields);
