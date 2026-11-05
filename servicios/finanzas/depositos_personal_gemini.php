<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_module_access('finanzas.depositos_personal');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function deposit_gemini_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    deposit_gemini_reply(403, ['error' => 'Solicitud no autorizada.']);
}

$key = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
if ($key === '') {
    $keyFiles = [];
    $configuredFile = trim((string) (getenv('GEMINI_API_KEY_FILE') ?: ''));
    if ($configuredFile !== '') {
        $keyFiles[] = $configuredFile;
    } else {
        $home = trim((string) (getenv('HOME') ?: ''));
        if ($home !== '') $keyFiles[] = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . 'lifemaquinarias_gemini.key';
        $appRoot = dirname(__DIR__, 2);
        if (basename($appRoot) === 'public_html') $keyFiles[] = dirname($appRoot) . DIRECTORY_SEPARATOR . 'lifemaquinarias_gemini.key';
    }
    foreach (array_unique($keyFiles) as $keyFile) {
        if (is_file($keyFile) && is_readable($keyFile)) {
            $key = trim((string) file_get_contents($keyFile));
            if ($key !== '') break;
        }
    }
}
if ($key === '') deposit_gemini_reply(503, ['error' => 'Gemini no está configurado en este servidor.']);

$file = $_FILES['imagen'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) < 1000 || ($file['size'] ?? 0) > 1500000) {
    deposit_gemini_reply(400, ['error' => 'La imagen de lectura debe medir menos de 1,5 MB.']);
}
$path = (string) $file['tmp_name'];
$info = @getimagesize($path);
if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 2500 || $info[1] > 2500) {
    deposit_gemini_reply(400, ['error' => 'Seleccione una imagen válida para la lectura.']);
}

$lock = fopen(sys_get_temp_dir() . '/lifemaquinarias_gemini_' . sha1(dirname(__DIR__, 2)) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) deposit_gemini_reply(429, ['error' => 'Hay otra lectura en curso. Intente nuevamente en unos momentos.']);
$last = (int) ($_SESSION['cv_gemini_last'] ?? 0);
if (time() - $last < 3) deposit_gemini_reply(429, ['error' => 'Espere unos segundos antes de repetir la lectura.']);
$_SESSION['cv_gemini_last'] = time();
session_write_close();

$image = file_get_contents($path);
if ($image === false) deposit_gemini_reply(400, ['error' => 'No se pudo leer la imagen.']);
$prompt = <<<'PROMPT'
Analiza este comprobante peruano. Devuelve exclusivamente JSON con las claves modelo, fecha, acreedor, monto, modo_pago. Reconoce SOLO estos cuatro tipos de comprobante y sus variantes descritas:
1. YAPE: diseño morado, marca yape y texto "¡Yapeaste!". modelo="YAPE", modo_pago="YAPE". Fecha junto al ícono de calendario. Acreedor: el nombre debajo del importe, copiado literalmente con cualquier asterisco visible. No uses "Santa Rosa de Lima", celular ni destino como acreedor. Monto: la cantidad principal bajo "¡Yapeaste!".
2. PLIN de Interbank: logotipo plin, texto "¡Pago exitoso!", importe en verde, secciones "Enviado a:" y "Fecha y hora:". modelo="PLIN", modo_pago="PLIN". Fecha: la de "Fecha y hora:". Acreedor: exclusivamente el nombre inmediatamente debajo de "Enviado a:", sin teléfono ni la palabra Plin. Monto: el importe principal en verde debajo de "¡Pago exitoso!".
   Variante PLIN de Interbank: fondo verde, logotipo Interbank, texto "Plineo exitoso", importe verde grande, fecha debajo del importe y sección "Enviado a". También usa modelo="PLIN" y modo_pago="PLIN". Fecha: la que aparece debajo del importe. Acreedor: solo el nombre junto a "Enviado a", antes del teléfono. Monto: el importe verde grande. Si el teléfono del destinatario termina con "- Yape", eso indica el destino, NO el modo de pago: sigue siendo PLIN porque el comprobante dice "Plineo exitoso". Por ejemplo, con "04 Nov 2026", "Jesus R Carrasquel L" y "S/ 200", devuelve fecha="04/11/2026", acreedor="Jesus R Carrasquel L", monto="200" y modo_pago="PLIN".
3. TRANSFERENCIA_INTERBANK tiene dos variantes sin logotipo plin. En ambas, modelo="TRANSFERENCIA_INTERBANK" y modo_pago="Tranferencia Interbank":
   a) Interbank personal: logotipo Interbank, círculo verde con marca de verificación, texto "Transferiste" y "de forma inmediata", secciones "Cuenta cargo:", "Cuenta destino:", "Fecha y hora:". Fecha: la de "Fecha y hora:". Acreedor: el nombre completo de la persona en "Cuenta destino:", después del banco receptor y antes del número de cuenta; NO uses el banco ni "Cuenta cargo" como acreedor. Monto: la cantidad principal verde debajo de "Transferiste".
   b) Interbank Empresas: logotipo "Interbank Empresas", título "Constancia de operación", marca verde de verificación, texto "Transferencia exitosa", secciones "Número de solicitud:", "Desde:", "Para:" y "Fecha y hora:". Fecha: la de "Fecha y hora:". Acreedor: exclusivamente el nombre de persona inmediatamente después de "Para:", antes del tipo de cuenta y del número de cuenta; NO uses el nombre de "Desde:" ni el banco. Monto: el importe principal verde debajo de "Transferencia exitosa". El número de solicitud no es el monto.
4. TRANSFERENCIA_BCP: logotipo BCP azul y naranja, texto "¡Transferencia exitosa!", importe principal azul grande, fecha debajo del importe y secciones "Enviado a", "Desde" y "Número de operación". modelo="TRANSFERENCIA_BCP" y modo_pago="Tranferencia BCP". Fecha: la que aparece inmediatamente debajo del importe principal, aunque incluya día de la semana y hora. Acreedor: únicamente el nombre de la persona junto a "Enviado a", antes del número de cuenta enmascarado; conserva mayúsculas, minúsculas, iniciales y punto final tal como se ven. NO uses "Corriente Soles", "Desde", número de cuenta ni "Número de operación" como acreedor. Monto: el importe azul grande después de S/, sin la coma de millares; por ejemplo, S/ 2,000.00 se convierte en "2000.00".
El logotipo Interbank por sí solo no identifica el modelo 2 ni 3. Si la foto no coincide claramente con uno de los modelos descritos, devuelve modelo="pendiente" y los otros cuatro campos null.
Para todos: fecha DD/MM/AAAA; monto como cadena numérica positiva sin S/ ni separadores de miles, con punto decimal solo si corresponde. Copia el nombre tal como aparece, sin inventar ni completar caracteres ocultos. Si un dato es ilegible o ambiguo, devuelve null para ese dato. Ignora hora, comisión, número de operación, número de cuenta y código de seguridad.
PROMPT;
$body = json_encode([
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $info['mime'], 'data' => base64_encode($image)]]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 512, 'thinkingConfig' => ['thinkingLevel' => 'MINIMAL']],
], JSON_UNESCAPED_UNICODE);
unset($image);
if ($body === false) deposit_gemini_reply(500, ['error' => 'No se pudo preparar la lectura.']);
@set_time_limit(120);
$handle = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent');
curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 110]);
$response = curl_exec($handle);
$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
curl_close($handle);
if ($response === false) deposit_gemini_reply(503, ['error' => 'La lectura tardó demasiado o no pudo conectarse.']);
$payload = json_decode($response, true);
if ($status === 429) deposit_gemini_reply(429, ['error' => 'La cuota de Gemini está temporalmente agotada. Intente más tarde.']);
if ($status !== 200 || !is_array($payload) || ($payload['candidates'][0]['finishReason'] ?? '') !== 'STOP') {
    deposit_gemini_reply(503, ['error' => 'Gemini no pudo completar la lectura.']);
}
$raw = implode('', array_column($payload['candidates'][0]['content']['parts'] ?? [], 'text'));
$values = json_decode($raw, true);
if (!is_array($values)) deposit_gemini_reply(503, ['error' => 'La lectura no produjo datos utilizables.']);
$model = $values['modelo'] ?? '';
if (!in_array($model, ['YAPE', 'PLIN', 'TRANSFERENCIA_INTERBANK', 'TRANSFERENCIA_BCP'], true)) deposit_gemini_reply(200, ['model' => 'pendiente', 'fields' => null]);

$date = is_string($values['fecha'] ?? null) ? trim($values['fecha']) : '';
$parsedDate = DateTimeImmutable::createFromFormat('!d/m/Y', $date);
$date = $parsedDate && $parsedDate->format('d/m/Y') === $date ? $date : null;
$creditor = is_string($values['acreedor'] ?? null) ? mb_substr(trim($values['acreedor']), 0, 150) : '';
$amount = is_string($values['monto'] ?? null) || is_numeric($values['monto'] ?? null) ? trim((string) $values['monto']) : '';
if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) $amount = null;
deposit_gemini_reply(200, ['model' => $model, 'fields' => [
    'fecha' => $date,
    'acreedor' => $creditor !== '' ? $creditor : null,
    'monto' => $amount,
    'modo_pago' => $model === 'TRANSFERENCIA_INTERBANK' ? 'Tranferencia Interbank' : ($model === 'TRANSFERENCIA_BCP' ? 'Tranferencia BCP' : $model),
]]);
