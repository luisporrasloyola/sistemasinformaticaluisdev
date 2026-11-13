<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_match.php';
require_once __DIR__ . '/../../includes/finanzas_depositos_personal_lote.php';
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
$originalHash = strtolower(trim((string) ($_POST['original_hash'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/D', $originalHash)) deposit_gemini_reply(422, ['error' => 'No se pudo verificar la foto original. Vuelva a seleccionarla.']);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$isPdf = $mime === 'application/pdf' && strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'pdf';
$info = $isPdf ? null : @getimagesize($path);
if (!$isPdf && (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 2500 || $info[1] > 2500)) {
    deposit_gemini_reply(400, ['error' => 'Seleccione una imagen o PDF válido para la lectura.']);
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
Analiza este comprobante peruano. Identifica primero si es un lote con varios beneficiarios (modelo 6); en ese caso devuelve registros y no un importe total. Para modelos individuales devuelve JSON con las claves modelo, fecha, acreedor, monto, modo_pago. Reconoce los seis modelos descritos:
1. YAPE: diseño morado, marca yape y texto "¡Yapeaste!". modelo="YAPE", modo_pago="YAPE". Fecha junto al ícono de calendario. Acreedor: el nombre debajo del importe, copiado literalmente con cualquier asterisco visible. No uses "Santa Rosa de Lima", celular ni destino como acreedor. Monto: la cantidad principal bajo "¡Yapeaste!".
2. PLIN de Interbank: logotipo plin, texto "¡Pago exitoso!", importe en verde, secciones "Enviado a:" y "Fecha y hora:". modelo="PLIN", modo_pago="PLIN". Fecha: la de "Fecha y hora:". Acreedor: exclusivamente el nombre inmediatamente debajo de "Enviado a:", sin teléfono ni la palabra Plin. Monto: el importe principal en verde debajo de "¡Pago exitoso!".
   Variante PLIN de Interbank: fondo verde, logotipo Interbank, texto "Plineo exitoso", importe verde grande, fecha debajo del importe y sección "Enviado a". También usa modelo="PLIN" y modo_pago="PLIN". Fecha: la que aparece debajo del importe. Acreedor: solo el nombre junto a "Enviado a", antes del teléfono. Monto: el importe verde grande. Si el teléfono del destinatario termina con "- Yape", eso indica el destino, NO el modo de pago: sigue siendo PLIN porque el comprobante dice "Plineo exitoso". Por ejemplo, con "04 Nov 2026", "Jesus R Carrasquel L" y "S/ 200", devuelve fecha="04/11/2026", acreedor="Jesus R Carrasquel L", monto="200" y modo_pago="PLIN".
   Variante constancia de Pago Plin: logotipo Interbank, encabezado verde "Constancia de Pago Plin", saludo "Hola [remitente], te enviamos tu" y campos "Código de operación", "Fecha y hora", "Cuenta cargo", "Destinatario", "Destino" y "Monto y moneda". También usa modelo="PLIN" y modo_pago="PLIN" aunque tenga logotipo Interbank. Fecha: la de "Fecha y hora"; acreedor: únicamente el nombre junto a "Destinatario", nunca el del saludo ni el titular de "Cuenta cargo"; monto: el de "Monto y moneda" sin S/. Por ejemplo, "27 Oct 2026 11:43 AM", "CAMILA GUERRERO" y "S/ 129.80" devuelven fecha="27/10/2026", acreedor="Camila Guerrero", monto="129.8" y modo_pago="PLIN".
3. TRANSFERENCIA_INTERBANK tiene cuatro variantes sin logotipo plin. En todas, modelo="TRANSFERENCIA_INTERBANK" y modo_pago="Transferencia Interbank":
   a) Interbank personal: logotipo Interbank, círculo verde con marca de verificación, texto "Transferiste" y "de forma inmediata", secciones "Cuenta cargo:", "Cuenta destino:", "Fecha y hora:". Fecha: la de "Fecha y hora:". Acreedor: el nombre completo de la persona en "Cuenta destino:", después del banco receptor y antes del número de cuenta; NO uses el banco ni "Cuenta cargo" como acreedor. Monto: la cantidad principal verde debajo de "Transferiste".
   b) Interbank Empresas: logotipo "Interbank Empresas", título "Constancia de operación", marca verde de verificación, texto "Transferencia exitosa", secciones "Número de solicitud:", "Desde:", "Para:" y "Fecha y hora:". Fecha: la de "Fecha y hora:". Acreedor: exclusivamente el nombre de persona inmediatamente después de "Para:", antes del tipo de cuenta y del número de cuenta; NO uses el nombre de "Desde:" ni el banco. Monto: el importe principal verde debajo de "Transferencia exitosa". El número de solicitud no es el monto.
   c) Constancia de transferencia Interbank: logotipo Interbank, encabezado verde "Constancia de transferencia", saludo "Hola [nombre], te enviamos tu", y campos "Código de operación", "Fecha y hora", "Cuenta a cargo", "Cuenta destino", "Tipo de operación", "Monto y moneda", "Comisión" y "Monto total". Fecha: exclusivamente la de "Fecha y hora". Acreedor: únicamente el nombre junto a "Cuenta destino", antes del número de cuenta; NO uses el nombre del saludo, "Cuenta a cargo" ni los números de cuenta. Monto: el de "Monto y moneda", sin símbolo ni separador de millares; NO uses "Comisión" ni "Monto total". "Transferencia inmediata" identifica el tipo de operación, pero el modo_pago sigue siendo "Transferencia Interbank". Por ejemplo, con "31 Oct 2026 11:54 AM", "Leonel Alejandro Huaman Paredes" en "Cuenta destino" y "S/ 2,000.00" en "Monto y moneda", devuelve fecha="31/10/2026", acreedor="Leonel Alejandro Huaman Paredes", monto="2000" y modo_pago="Transferencia Interbank". Otro ejemplo: "21 Oct 2026 06:46 PM", "Maria Concepcion Chavez Saman" en "Cuenta destino" y "S/ 2,256.00" en "Monto y moneda" devuelve fecha="21/10/2026", acreedor="Maria Concepcion Chavez Saman", monto="2256" y modo_pago="Transferencia Interbank".
   d) Constancia de transferencia Interbank con destinatario oculto: logotipo Interbank, encabezado verde "Constancia de transferencia", saludo al remitente, campos "Fecha y hora", "Cuenta cargo", "Destinatario" y "Moneda y monto". Fecha: la de "Fecha y hora", incluso si la hora está en otra línea. Acreedor: copia literalmente el nombre en "Destinatario", conservando los asteriscos y el orden visible; nunca completes las letras ocultas ni uses el nombre del saludo. Ignora el número de cuenta debajo del destinatario. Monto: el de "Moneda y monto". Ejemplo: fecha "21 Oct 2026 6:21 PM", destinatario "DIAZ ASI** SAN** YOS**" y "S/ 510.00" devuelven fecha="21/10/2026", acreedor="DIAZ ASI** SAN** YOS**", monto="510" y modo_pago="Transferencia Interbank". El sistema vincula después ese nombre parcialmente oculto con un trabajador solo si la coincidencia con el padrón es única.
4. TRANSFERENCIA_BCP: logotipo BCP azul y naranja, texto "¡Transferencia exitosa!", importe principal azul grande, fecha debajo del importe y secciones "Enviado a", "Desde" y "Número de operación". modelo="TRANSFERENCIA_BCP" y modo_pago="Tranferencia BCP". Fecha: la que aparece inmediatamente debajo del importe principal, aunque incluya día de la semana y hora. Acreedor: únicamente el nombre de la persona junto a "Enviado a", antes del número de cuenta enmascarado; conserva mayúsculas, minúsculas, iniciales y punto final tal como se ven. NO uses "Corriente Soles", "Desde", número de cuenta ni "Número de operación" como acreedor. Monto: el importe azul grande después de S/, sin la coma de millares; por ejemplo, S/ 2,000.00 se convierte en "2000.00".
5. TRANSFERENCIA_MOVIMIENTO: pantalla blanca con título "Detalle de movimiento", importe negativo "-S/" y concepto "Cargo Transferencia". modelo="TRANSFERENCIA_MOVIMIENTO" y modo_pago="Transferencia". Fecha: exclusivamente "Fecha de operación", NO "Fecha de proceso". Acreedor: el texto completo de "Descripción", uniendo sus líneas en orden con un espacio, sin añadir ni reordenar nombres; NO uses "Canal" ni "N. de operación". Monto: el valor absoluto del importe principal negativo, como cadena positiva; por ejemplo "-S/ 300.00" devuelve "300.00". Con "08 nov 2026" y "Nicho Espinoza Franklin" seguido de "Alexan", devuelve fecha="08/11/2026", acreedor="Nicho Espinoza Franklin Alexan", monto="300" y modo_pago="Transferencia".
El logotipo Interbank por sí solo no identifica el modelo 2 ni 3. Si la foto no coincide claramente con uno de los modelos descritos, devuelve modelo="pendiente" y los otros cuatro campos null.
Para todos: fecha DD/MM/AAAA; monto como cadena numérica positiva sin S/ ni separadores de miles, con punto decimal solo si corresponde. Copia el nombre tal como aparece, sin inventar ni completar caracteres ocultos. Si un dato es ilegible o ambiguo, devuelve null para ese dato. Ignora hora, comisión, número de operación, número de cuenta y código de seguridad.
6. TRANSFERENCIA_INTERBANK_LOTE: captura de Interbank Empresas de "PAGOS VARIOS", "Información del lote" y tabla de "Beneficiario", "Monto" y "Estado" con dos o más personas. Devuelve modelo="TRANSFERENCIA_INTERBANK_LOTE" y registros=[{fecha,acreedor,monto,modo_pago,depositante,fila}]. Un registro por cada fila de beneficiario procesada: fecha de "Programación de envío" (no fecha de creación), acreedor = nombre del beneficiario antes del documento DNI, monto = importe de ESA fila (no "Total soles" del lote), modo_pago="Transferencia Interbank", depositante="Cuenta empresa" si el cargo es la cuenta de la empresa. "fila" es el rectángulo completo de la fila del beneficiario en coordenadas normalizadas [izquierda,arriba,derecha,abajo] de 0 a 1000 sobre toda la imagen, abarcando nombre y monto de esa fila, sin incluir otra fila ni información del resumen. Por ejemplo, con Angelo Diego Leon Corac S/950.00 y Fernando Gomero Dolo Res S/1507.70 en una imagen con fecha de envío 02/11/2026, devuelve dos registros independientes por 950 y 1507.70; NO uses el total del lote 2457.70 como depósito. Esta variante tiene prioridad sobre las variantes individuales si aparecen varios beneficiarios.
Para el modelo 6, enumera los registros estrictamente en el orden visual de arriba hacia abajo de la tabla de beneficiarios. Nunca reordenes por nombre ni por importe.
Si el archivo es un PDF de Interbank Empresas con "Detalle de solicitud", "Información de lote" y columnas "Beneficiario", "Tipo de abono", "Cuenta", "Monto" y "Estado", aplica el modelo 6 aunque el nombre del lote sea "pago planill" y no diga "PAGOS VARIOS". Devuelve una fila por cada beneficiario procesado de todas las páginas, en el orden visual. Toma la fecha de "Programación de envío" y el monto de la misma fila; no uses "Total soles" ni al creador/autorizador como beneficiario. El nombre del beneficiario puede estar dividido en dos líneas y hasta partir una palabra (por ejemplo "AULA R" o "CO RAC"): conserva esas letras y no mezcles el DNI, la cuenta ni otra fila en el nombre. Para este modelo devuelve modo_pago="Transferencia Interbank" y depositante="Cuenta empresa". Si hay seis beneficiarios, devuelve seis registros. No omitas filas ni inventes datos.
PROMPT;
$body = json_encode([
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($image)]]]]],
    'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 2048, 'thinkingConfig' => ['thinkingLevel' => 'MINIMAL']],
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
if (stripos((string) ($payload['error']['message'] ?? ''), 'source image could not be decoded') !== false) {
    deposit_gemini_reply(422, ['error' => 'No se pudo interpretar esta imagen. Compártala o expórtela nuevamente como JPG y vuelva a seleccionarla.']);
}
if ($status !== 200 || !is_array($payload) || ($payload['candidates'][0]['finishReason'] ?? '') !== 'STOP') {
    deposit_gemini_reply(503, ['error' => 'Gemini no pudo completar la lectura.']);
}
$raw = implode('', array_column($payload['candidates'][0]['content']['parts'] ?? [], 'text'));
$values = json_decode($raw, true);
if (!is_array($values)) deposit_gemini_reply(503, ['error' => 'La lectura no produjo datos utilizables.']);
$model = $values['modelo'] ?? '';
if ($model === 'TRANSFERENCIA_INTERBANK_LOTE') {
    if (!is_admin()) deposit_gemini_reply(403, ['error' => 'Los comprobantes con varios beneficiarios deben registrarse desde una cuenta de administrador.']);
    $rawRecords = $values['registros'] ?? null;
    if (!is_array($rawRecords) || count($rawRecords) < 2 || count($rawRecords) > 30) {
        deposit_gemini_reply(422, ['error' => 'No se pudieron identificar las filas del lote. Revise la foto.']);
    }
    $records = [];
    $workers = personal_deposit_workers();
    foreach ($rawRecords as $record) {
        if (!is_array($record)) continue;
        $date = trim((string) ($record['fecha'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!d/m/Y', $date);
        $creditor = mb_substr(trim((string) ($record['acreedor'] ?? '')), 0, 150);
        $linkedWorker = personal_deposit_batch_worker($creditor, $workers);
        $amount = str_replace(',', '', trim((string) ($record['monto'] ?? '')));
        if (!$parsedDate || $parsedDate->format('d/m/Y') !== $date || $creditor === ''
            || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) {
            deposit_gemini_reply(422, ['error' => 'La lectura del lote tiene datos o recortes incompletos. Use una imagen más nítida.']);
        }
        if (!$linkedWorker) deposit_gemini_reply(422, ['error' => 'No se pudo identificar con seguridad a todos los trabajadores del comprobante.']);
        $records[] = [
            'fecha' => $date, 'acreedor' => $linkedWorker['creditor'], 'monto' => $amount,
            'modo_pago' => 'Transferencia Interbank', 'depositante' => 'Cuenta empresa',
            'worker_id' => $linkedWorker['id'],
        ];
    }
    if (count($records) !== count($rawRecords)) deposit_gemini_reply(422, ['error' => 'No se pudieron identificar todos los beneficiarios.']);
    $token = bin2hex(random_bytes(16));
    session_start();
    $pending = array_filter($_SESSION['personal_deposit_batches'] ?? [], static fn(array $item): bool => ($item['expires'] ?? 0) > time());
    if (count($pending) >= 5) array_shift($pending);
    $pending[$token] = ['expires' => time() + 1200, 'original_hash' => $originalHash, 'records' => $records];
    $_SESSION['personal_deposit_batches'] = $pending;
    session_write_close();
    deposit_gemini_reply(200, ['model' => $model, 'records' => $records, 'batch_token' => $token]);
}
if (!in_array($model, ['YAPE', 'PLIN', 'TRANSFERENCIA_INTERBANK', 'TRANSFERENCIA_BCP', 'TRANSFERENCIA_MOVIMIENTO'], true)) deposit_gemini_reply(200, ['model' => 'pendiente', 'fields' => null]);

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
    'modo_pago' => match ($model) {
        'TRANSFERENCIA_INTERBANK' => 'Transferencia Interbank',
        'TRANSFERENCIA_BCP' => 'Tranferencia BCP',
        'TRANSFERENCIA_MOVIMIENTO' => 'Transferencia',
        default => $model,
    },
]]);
